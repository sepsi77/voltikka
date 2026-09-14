<?php

// Isolated offline production-service replay. Outputs JSON to stdout only.
use App\Models\FixedContractPriceForecast;
use App\Services\PriceForecasting\FixedTermForecastEvaluationService;
use App\Services\PriceForecasting\FixedTermPriceForecastService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

function check(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}
function jsonFile(string $path): array
{
    return json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
}
$root = dirname(__DIR__, 2);
foreach ([
    'APP_ENV' => 'testing', 'APP_CONFIG_CACHE' => __DIR__.'/nonexistent-replay-config.php',
    'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => ':memory:', 'DB_URL' => '', 'DATABASE_URL' => '',
    'SENTRY_LARAVEL_DSN' => '', 'SENTRY_DSN' => '', 'SENTRY_ENABLE_LOGS' => 'false',
    'SENTRY_TRACES_SAMPLE_RATE' => '0', 'SENTRY_PROFILES_SAMPLE_RATE' => '0',
    'LOG_CHANNEL' => 'null', 'LOG_STACK' => 'single', 'CACHE_STORE' => 'array',
    'QUEUE_CONNECTION' => 'sync', 'SESSION_DRIVER' => 'array',
] as $key => $value) {
    putenv("$key=$value");
    $_ENV[$key] = $_SERVER[$key] = $value;
}
check(! file_exists($_ENV['APP_CONFIG_CACHE']), 'Unexpected cached configuration');
check(ini_get('allow_url_fopen') === '0', 'URL streams must be disabled');
foreach (['curl_exec', 'curl_multi_exec', 'fsockopen', 'pfsockopen', 'stream_socket_client'] as $function) {
    check(! function_exists($function), 'Network function enabled: '.$function);
}
require $root.'/laravel/vendor/autoload.php';
$app = require $root.'/laravel/bootstrap/app.php';
$app->useEnvironmentPath(__DIR__)->loadEnvironmentFrom('nonexistent-replay.env');
check(! file_exists(__DIR__.'/nonexistent-replay.env'), 'Unexpected environment file');
$app->afterBootstrapping(LoadConfiguration::class, function ($app): void {
    $app['config']->set('database.default', 'sqlite');
    $app['config']->set('database.connections', ['sqlite' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]]);
    $app['config']->set('sentry.dsn', null);
    $app['config']->set('sentry.enable_logs', false);
    $app['config']->set('logging.default', 'null');
    $app['config']->set('cache.default', 'array');
});
$app->make(Kernel::class)->bootstrap();
Http::preventStrayRequests();
config(['price_forecasting.fixed_term.model_version' => 'fixed_term_futures_adjusted_v1',
    'price_forecasting.fixed_term.minimum_history_observations' => 20,
    'price_forecasting.fixed_term.direction_threshold_cents_per_kwh' => 0.15,
    'price_forecasting.fixed_term.default_horizon_days' => 30]);
$connection = DB::connection();
check(app()->environment('testing'), 'Wrong environment');
check($connection->getDriverName() === 'sqlite' && $connection->getConfig('database') === ':memory:', 'Wrong database');
check($connection->getPdo()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite', 'Wrong PDO');
$databases = DB::select('PRAGMA database_list');
check(count($databases) === 1 && $databases[0]->name === 'main' && $databases[0]->file === '', 'Not memory SQLite');
$proof = ['environment' => 'testing', 'driver' => 'sqlite', 'database' => ':memory:', 'pragma_file' => '', 'network_disabled' => true, 'before_schema_and_load' => true];
fwrite(STDERR, 'PROVED before schema/load: '.json_encode($proof).PHP_EOL);

$export = $root.'/tasks/supplier-premium-coverage/export-20260913T094138Z';
check(hash_file('sha256', $export.'/manifest.json') === '6b7dd7093cafab8f91bb8417becba321747da4bc4334a19924374cc6be05efb6', 'Manifest hash');
$manifest = jsonFile($export.'/manifest.json');
check($manifest['status'] === 'complete', 'Incomplete export');
$inputs = $hashes = [];
foreach ($manifest['queries'] as $name => $query) {
    check(basename($query['file']) === $query['file'], 'Unsafe path');
    $path = $export.'/'.$query['file'];
    check(hash_file('sha256', $path) === $query['sha256'] && filesize($path) === $query['bytes'], 'Export hash/size '.$name);
    $data = jsonFile($path);
    check(count($data) === $query['row_count'] && count($data) === $query['expected_count'], 'Export count '.$name);
    $hashes[$query['file']] = $query['sha256'];
    if (in_array($name, ['statistics', 'futures', 'forecasts'], true)) {
        $inputs[$name] = $data;
    }
}
unset($data);
foreach (['2026_04_29_000002_create_contract_price_daily_statistics_table.php', '2026_05_23_000001_create_fixed_contract_price_forecasts_table.php', '2026_05_22_000001_create_electricity_futures_eod_prices_table.php'] as $migration) {
    (require $root.'/laravel/database/migrations/'.$migration)->up();
}
Schema::table('contract_price_daily_statistics', function (Blueprint $table): void {
    $table->decimal('median_value', 12, 4)->nullable();
    $table->string('pricing_basis', 40)->default('observed_seller_data')->index();
    $table->string('method_version', 80)->nullable();
    $table->string('calculation_basis', 80)->nullable();
    $table->string('estimate_basis', 80)->nullable();
    $table->string('compatibility_key', 120)->nullable();
    $table->json('basis_counts')->nullable();
});
foreach (['statistics' => 'contract_price_daily_statistics', 'futures' => 'electricity_futures_eod_prices', 'forecasts' => 'fixed_contract_price_forecasts'] as $name => $table) {
    $rows = $name === 'statistics' ? array_values(array_filter($inputs[$name], fn ($r) => $r['method_version'] === 'unit_statistics_v1' && $r['metric_key'] === 'energy_price' && $r['consumption_kwh'] === null && in_array($r['segment_key'], ['fixed_term_6', 'fixed_term_12', 'fixed_term_24'], true))) : $inputs[$name];
    foreach (array_chunk($rows, 100) as $chunk) {
        DB::table($table)->insert($chunk);
    }
    check(DB::table($table)->count() === count($rows), 'Load count '.$name);
}
$originalIds = DB::table('fixed_contract_price_forecasts')->pluck('id')->all();
$original = json_encode(DB::table('fixed_contract_price_forecasts')->orderBy('id')->get()->all(), JSON_THROW_ON_ERROR);
(require $root.'/laravel/database/migrations/2026_09_14_000001_make_forecast_gap_diagnostics_nullable.php')->up();
$predictionsPath = $root.'/tasks/forecast-futures-increment-test/predictions.json';
$predictionsHash = hash_file('sha256', $predictionsPath);
$primary = array_values(array_filter(jsonFile($predictionsPath), fn ($p) => $p['cohort'] === 'fixed' && $p['horizon'] === 30));
$groups = [];
foreach ($primary as $p) {
    $groups[$p['issue'].'|'.$p['basis']][] = $p;
}
$matched = [];
foreach ($groups as $group) {
    $issue = $group[0]['issue'];
    config(['canonical_pricing.enabled' => $group[0]['basis'] === 'canonical_calculation']);
    app()->forgetScopedInstances();
    $rows = app(FixedTermPriceForecastService::class)->buildForecasts(CarbonImmutable::parse($issue), 30, array_column($group, 'term'), ['median']);
    check($rows->count() === count($group), 'Missing primary predictions '.$issue);
    foreach ($group as $p) {
        $row = $rows->firstWhere('duration_months', $p['term']);
        $expected = $p['models']['production_futures'];
        check(abs($row['expected_change_cents_per_kwh'] - $expected['saved_delta']) < 1e-9, 'Frozen delta '.$p['pair_id']);
        check(abs($row['forecast_price_cents_per_kwh'] - $expected['forecast']) < 1e-9, 'Frozen forecast '.$p['pair_id']);
        check(abs($row['source_metadata']['mean_change_cents_per_kwh'] + $row['source_metadata']['futures_contribution_cents_per_kwh'] - $expected['raw_delta']) < 1e-9, 'Frozen raw fit '.$p['pair_id']);
        $matched[] = ['pair_id' => $p['pair_id'], 'row' => $row];
        FixedContractPriceForecast::create($row);
    }
}
config(['canonical_pricing.enabled' => true]);
app()->forgetScopedInstances();
$latest = app(FixedTermPriceForecastService::class)->buildForecasts(CarbonImmutable::parse('2026-09-13'), 30);
check($latest->count() === 9, 'Latest nine lanes');
$evaluated = app(FixedTermForecastEvaluationService::class)->evaluateMatured(CarbonImmutable::parse('2026-09-13'), 30, 'fixed_term_futures_adjusted_v1');
check($evaluated['evaluated'] === count($primary), 'New model evaluation count');
check(json_encode(DB::table('fixed_contract_price_forecasts')->whereIn('id', $originalIds)->orderBy('id')->get()->all(), JSON_THROW_ON_ERROR) === $original, 'Old records changed');
foreach ($hashes as $file => $hash) {
    check(hash_file('sha256', $export.'/'.$file) === $hash, 'Export changed');
}
check(hash_file('sha256', $predictionsPath) === $predictionsHash, 'Frozen predictions changed');
check(DB::select('PRAGMA integrity_check')[0]->integrity_check === 'ok', 'SQLite integrity');
echo json_encode(['proof' => $proof, 'export_hashes' => $hashes, 'predictions_sha256' => $predictionsHash,
    'primary_count' => count($matched), 'evaluated' => $evaluated['evaluated'], 'original_forecast_count' => count($originalIds),
    'original_forecasts_unchanged' => true, 'matched' => $matched, 'latest' => $latest->all()], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT).PHP_EOL;
