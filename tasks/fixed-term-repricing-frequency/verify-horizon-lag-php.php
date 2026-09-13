<?php
// Research only. Use actual local services with an isolated in-memory fixture.
use App\Services\CanonicalPricing\PricingMode;
use App\Services\PriceForecasting\FixedTermHedgeCostService;
use App\Services\PriceForecasting\FixedTermPriceForecastService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

function check(bool $ok, string $message): void
{
    if (! $ok) {
        throw new RuntimeException($message);
    }
}
function near(float $a, float $b): void
{
    check(abs($a - $b) < 1e-9, "Arithmetic mismatch: $a / $b");
}
function csvRows(string $name): array
{
    $f = fopen(__DIR__.'/'.$name, 'r');
    $header = fgetcsv($f, escape: '');
    $rows = [];
    while (($row = fgetcsv($f, escape: '')) !== false) {
        $rows[] = array_combine($header, $row);
    }
    fclose($f);
    return $rows;
}
$root = dirname(__DIR__, 2);
$export = $root.'/tasks/supplier-premium-coverage/export-20260913T094138Z';
$manifest = json_decode(file_get_contents($export.'/manifest.json'), true, 512, JSON_THROW_ON_ERROR);
check(hash_file('sha256', $export.'/manifest.json') === explode(' ', trim(file_get_contents($export.'/manifest.sha256')))[0], 'Manifest hash');
$inputs = [];
foreach (['futures', 'statistics'] as $key) {
    $q = $manifest['queries'][$key];
    $path = $export.'/'.$q['file'];
    check(hash_file('sha256', $path) === $q['sha256'] && filesize($path) === $q['bytes'], 'Payload identity');
    $inputs[$key] = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    check(count($inputs[$key]) === $q['row_count'] && count($inputs[$key]) === $q['expected_count'], 'Payload count');
}
foreach ([
    'APP_ENV' => 'testing', 'APP_CONFIG_CACHE' => __DIR__.'/nonexistent-horizon-config.php',
    'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => ':memory:', 'DB_URL' => '', 'DATABASE_URL' => '',
    'SENTRY_LARAVEL_DSN' => '', 'SENTRY_DSN' => '', 'SENTRY_ENABLE_LOGS' => 'false',
    'SENTRY_TRACES_SAMPLE_RATE' => '0', 'SENTRY_PROFILES_SAMPLE_RATE' => '0',
    'LOG_CHANNEL' => 'null', 'LOG_STACK' => 'single', 'CACHE_STORE' => 'array',
    'QUEUE_CONNECTION' => 'sync', 'SESSION_DRIVER' => 'array',
] as $key => $value) {
    putenv("$key=$value");
    $_ENV[$key] = $_SERVER[$key] = $value;
}
check(! file_exists($_ENV['APP_CONFIG_CACHE']), 'Config isolation');
require $root.'/laravel/vendor/autoload.php';
$app = require $root.'/laravel/bootstrap/app.php';
$app->useEnvironmentPath(__DIR__)->loadEnvironmentFrom('nonexistent-horizon.env');
check(! file_exists(__DIR__.'/nonexistent-horizon.env'), 'Environment isolation');
$app->afterBootstrapping(Illuminate\Foundation\Bootstrap\LoadConfiguration::class, function ($app): void {
    $app['config']->set('database.default', 'sqlite');
    $app['config']->set('database.connections', ['sqlite' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]]);
    $app['config']->set('sentry.dsn', null);
    $app['config']->set('sentry.enable_logs', false);
    $app['config']->set('logging.default', 'null');
    $app['config']->set('cache.default', 'array');
});
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
Http::preventStrayRequests();
check(app()->environment('testing'), 'Environment');
check(DB::connection()->getDriverName() === 'sqlite' && DB::connection()->getConfig('database') === ':memory:', 'Connection');
check(DB::connection()->getPdo()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite', 'PDO');
$dbs = DB::select('PRAGMA database_list');
check(count($dbs) === 1 && $dbs[0]->name === 'main' && $dbs[0]->file === '', 'Memory proof');
echo "PROVED testing / sqlite / :memory: / PRAGMA file empty before fixture creation\n";
// Minimal research fixture. No migrations and no existing database.
Schema::create('electricity_futures_eod_prices', function (Blueprint $t): void {
    $t->integer('id');
    foreach (['area', 'product', 'trade_date', 'maturity_type', 'maturity'] as $c) {
        $t->string($c);
    }
    $t->decimal('settlement_price', 12, 4);
});
Schema::create('contract_price_daily_statistics', function (Blueprint $t): void {
    $t->integer('id');
    foreach (['stat_date', 'segment_key', 'metric_key', 'pricing_basis', 'method_version'] as $c) {
        $t->string($c);
    }
    $t->integer('consumption_kwh')->nullable();
    $t->integer('contract_count');
    foreach (['median_value', 'p20_value', 'p80_value'] as $c) {
        $t->decimal($c, 12, 4)->nullable();
    }
});
foreach (['futures' => 'electricity_futures_eod_prices', 'statistics' => 'contract_price_daily_statistics'] as $key => $table) {
    $columns = array_flip(Schema::getColumnListing($table));
    foreach (array_chunk($inputs[$key], 100) as $chunk) {
        DB::table($table)->insert(array_map(fn ($r) => array_intersect_key($r, $columns), $chunk));
    }
    check(DB::table($table)->count() === count($inputs[$key]), 'Fixture rows');
}
config(['price_forecasting.fixed_term' => [
    'model_version' => 'fixed_term_ewma_gap_v3', 'area' => 'FI', 'vat_multiplier' => 1.255,
    'ewma_alpha' => 0.25, 'gap_closure_lambda' => 0.30, 'minimum_history_observations' => 10,
    'default_horizon_days' => 30, 'durations_months' => [6, 12, 24], 'target_quantiles' => ['median'],
]]);
$hedge = new FixedTermHedgeCostService;
$hedgeChecks = 0;
foreach (csvRows('horizon-lag-hedges.csv') as $r) {
    $h = $hedge->calculate(CarbonImmutable::parse($r['date']), (int) $r['term']);
    $price = $h['price_cents_per_kwh'] ?? null;
    check(($price === null) === ($r['price'] === ''), 'H coverage');
    if ($price !== null) {
        near($price, (float) $r['price']);
        check($h['trade_date'] === $r['trade'] && $h['delivery_start_month'] === $r['basket'], 'H provenance');
    }
    $hedgeChecks++;
}
$services = [
    'observed_seller_data' => new FixedTermPriceForecastService($hedge, new PricingMode(false, false)),
    'canonical_calculation' => new FixedTermPriceForecastService($hedge, new PricingMode(true, false)),
];
$cache = [];
$forecastChecks = 0;
foreach (csvRows('horizon-lag-forecasts.csv') as $r) {
    $key = $r['basis'].'|'.$r['issue'].'|'.$r['horizon'];
    if (! isset($cache[$key])) {
        $cache[$key] = $services[$r['basis']]->buildForecasts(CarbonImmutable::parse($r['issue']), (int) $r['horizon'])->keyBy('duration_months');
    }
    $f = $cache[$key]->get((int) $r['term']);
    check(($f === null) === ($r['gap'] === ''), 'Forecast coverage');
    if ($f !== null) {
        near($f['forecast_price_cents_per_kwh'], (float) $r['gap']);
        check($f['source_metadata']['history_observations'] === (int) $r['history_count'], 'History count');
        check($f['target_date'] === $r['target'], 'Exact target');
        check($f['source_metadata']['historical_retail_source_end_date'] === $r['history_end'], 'History end');
    }
    $forecastChecks++;
}
$result = ['hedge_checks' => $hedgeChecks, 'forecast_checks' => $forecastChecks, 'status' => 'passed', 'database' => ':memory:'];
file_put_contents(__DIR__.'/horizon-lag-php-checks.json', json_encode($result, JSON_PRETTY_PRINT)."\n");
echo json_encode($result).PHP_EOL;
