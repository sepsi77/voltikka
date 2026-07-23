<?php

declare(strict_types=1);

use App\Services\ContractInterpretation\ContractInterpretationValidator;
use Illuminate\Contracts\Console\Kernel;

error_reporting(E_ALL & ~E_DEPRECATED);
ini_set('display_errors', '0');

$runDirectory = $argv[1] ?? null;
if (! is_string($runDirectory) || ! is_dir($runDirectory)) {
    fwrite(STDERR, "Usage: php validate_run.php RUN_DIRECTORY\n");
    exit(2);
}

$root = dirname(__DIR__, 3);
$laravel = $root.'/laravel';
require $laravel.'/vendor/autoload.php';
$app = require $laravel.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$metadata = json_decode(
    (string) file_get_contents($runDirectory.'/metadata.json'),
    true,
    512,
    JSON_THROW_ON_ERROR,
);
$schemaPath = __DIR__.'/'.basename((string) ($metadata['schema'] ?? 'schema-v3.json'));
config()->set('contract_interpretation.schema_path', $schemaPath);

/** @var ContractInterpretationValidator $validator */
$validator = $app->make(ContractInterpretationValidator::class);
$results = [];

foreach (glob($runDirectory.'/raw/*.json') ?: [] as $path) {
    $record = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    if (($record['ok'] ?? false) !== true || ! is_array($record['output'] ?? null) || ! is_array($record['input'] ?? null)) {
        continue;
    }

    $errors = $validator->validate($record['output'], $record['input']);
    $validation = [
        'model' => $record['model'],
        'rank' => $record['rank'],
        'contract_id' => $record['contract_id'],
        'valid' => $errors === [],
        'error_count' => count($errors),
        'errors' => $errors,
    ];
    $record['production_validation'] = $validation;
    file_put_contents(
        $path,
        json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n",
    );
    $results[] = $validation;
}

usort($results, fn (array $left, array $right): int => [$left['model'], $left['rank']] <=> [$right['model'], $right['rank']]);
$summary = [
    'cases' => count($results),
    'passed' => count(array_filter($results, fn (array $result): bool => $result['valid'])),
    'failed' => count(array_filter($results, fn (array $result): bool => ! $result['valid'])),
    'results' => $results,
];
file_put_contents(
    $runDirectory.'/production-validation-summary.json',
    json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n",
);
echo json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n";
