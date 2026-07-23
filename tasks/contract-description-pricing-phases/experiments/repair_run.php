<?php

declare(strict_types=1);

use App\Services\ContractInterpretation\ContractInterpretationValidator;
use App\Services\ContractInterpretation\OpenRouterContractInterpretationClient;
use Illuminate\Contracts\Console\Kernel;

error_reporting(E_ALL & ~E_DEPRECATED);
ini_set('display_errors', '0');

$runDirectory = $argv[1] ?? null;
$maxRepairs = min(2, max(0, (int) ($argv[2] ?? 2)));
if (! is_string($runDirectory) || ! is_dir($runDirectory)) {
    fwrite(STDERR, "Usage: php repair_run.php RUN_DIRECTORY [MAX_REPAIRS]\n");
    exit(2);
}

$root = dirname(__DIR__, 3);
$laravel = $root.'/laravel';
require $laravel.'/vendor/autoload.php';
$app = require $laravel.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$metadata = json_decode((string) file_get_contents($runDirectory.'/metadata.json'), true, 512, JSON_THROW_ON_ERROR);
config()->set('contract_interpretation.schema_path', __DIR__.'/'.basename((string) ($metadata['schema'] ?? 'schema-v3.json')));
config()->set('contract_interpretation.prompt_path', __DIR__.'/'.basename((string) ($metadata['prompt'] ?? 'system-prompt-v12.md')));
config()->set('contract_interpretation.model', $metadata['models'][0] ?? config('contract_interpretation.model'));
config()->set('contract_interpretation.reasoning_effort', $metadata['reasoning_effort'] ?? 'low');

/** @var ContractInterpretationValidator $validator */
$validator = $app->make(ContractInterpretationValidator::class);
/** @var OpenRouterContractInterpretationClient $client */
$client = $app->make(OpenRouterContractInterpretationClient::class);
$results = [];

foreach (glob($runDirectory.'/raw/*.json') ?: [] as $path) {
    $record = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    if (($record['ok'] ?? false) !== true || ! is_array($record['output'] ?? null) || ! is_array($record['input'] ?? null)) {
        continue;
    }

    $output = $record['output'];
    $errors = $validator->validate($output, $record['input']);
    $attempts = [];
    for ($repair = 1; $errors !== [] && $repair <= $maxRepairs; $repair++) {
        $response = $client->repair($record['input'], $output, $errors);
        $output = $response['output'];
        $errors = $validator->validate($output, $record['input']);
        $attempts[] = [
            'repair' => $repair,
            'output' => $output,
            'validation_errors' => $errors,
            'usage' => $response['usage'],
            'provider' => $response['provider'],
            'provider_response_id' => $response['response_id'],
            'latency_ms' => $response['latency_ms'],
        ];
    }

    $record['repair_attempts'] = $attempts;
    $record['final_production_validation'] = [
        'valid' => $errors === [],
        'error_count' => count($errors),
        'errors' => $errors,
    ];
    file_put_contents(
        $path,
        json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n",
    );
    $results[] = [
        'model' => $record['model'],
        'rank' => $record['rank'],
        'contract_id' => $record['contract_id'],
        'initial_valid' => ($record['production_validation']['valid'] ?? false) === true,
        'repair_count' => count($attempts),
        'final_valid' => $errors === [],
        'final_error_count' => count($errors),
        'reported_repair_cost_usd' => array_sum(array_map(
            fn (array $attempt): float => (float) ($attempt['usage']['cost'] ?? 0),
            $attempts,
        )),
    ];
}

usort($results, fn (array $left, array $right): int => [$left['model'], $left['rank']] <=> [$right['model'], $right['rank']]);
$summary = [
    'cases' => count($results),
    'initial_passes' => count(array_filter($results, fn (array $result): bool => $result['initial_valid'])),
    'repaired_cases' => count(array_filter($results, fn (array $result): bool => $result['repair_count'] > 0)),
    'final_passes' => count(array_filter($results, fn (array $result): bool => $result['final_valid'])),
    'final_failures' => count(array_filter($results, fn (array $result): bool => ! $result['final_valid'])),
    'reported_repair_cost_usd' => array_sum(array_column($results, 'reported_repair_cost_usd')),
    'results' => $results,
];
file_put_contents(
    $runDirectory.'/repair-summary.json',
    json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n",
);
echo json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n";
