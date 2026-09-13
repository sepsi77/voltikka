<?php

// Offline evidence only. No application .env, normal database or external request.
use App\Models\FixedContractPriceForecast;
use App\Services\PriceForecasting\FixedTermForecastEvaluationService;
use App\Services\PriceForecasting\FixedTermPriceForecastService;
use App\Services\PriceForecasting\ForecastOutlook;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

function check(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}
function jsonFile(string $file): array
{
    return json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
}
function near(float $a, float $b, string $message): void
{
    check(abs($a - $b) < 0.000051, "$message: $a != $b");
}
$root = dirname(__DIR__, 2);
$export = $root.'/tasks/supplier-premium-coverage/export-20260913T094138Z';
check(hash_file('sha256', $export.'/manifest.json') === '6b7dd7093cafab8f91bb8417becba321747da4bc4334a19924374cc6be05efb6', 'Manifest hash');
$manifest = jsonFile($export.'/manifest.json');
check($manifest['status'] === 'complete', 'Incomplete export');
$inputs = $verified = [];
foreach ($manifest['queries'] as $name => $query) {
    check(basename($query['file']) === $query['file'], 'Unsafe export path');
    $path = $export.'/'.$query['file'];
    check(hash_file('sha256', $path) === $query['sha256'], "Hash $name");
    check(filesize($path) === $query['bytes'], "Bytes $name");
    $data = jsonFile($path);
    check(count($data) === $query['row_count'] && count($data) === $query['expected_count'], "Rows $name");
    $verified[$name] = ['sha256' => $query['sha256'], 'rows' => count($data)];
    if (in_array($name, ['statistics', 'forecasts'], true)) {
        $inputs[$name] = $data;
    }
}
unset($data);
$inputs['statistics'] = array_values(array_filter($inputs['statistics'], fn ($r) => $r['method_version'] === 'unit_statistics_v1'
    && $r['metric_key'] === 'energy_price' && $r['consumption_kwh'] === null
    && in_array($r['segment_key'], ['fixed_term_6', 'fixed_term_12', 'fixed_term_24'], true)));
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
check(! file_exists($_ENV['APP_CONFIG_CACHE']), 'Config isolation path exists');
require $root.'/laravel/vendor/autoload.php';
$app = require $root.'/laravel/bootstrap/app.php';
$app->useEnvironmentPath(__DIR__)->loadEnvironmentFrom('nonexistent-replay.env');
check(! file_exists(__DIR__.'/nonexistent-replay.env'), 'Environment isolation path exists');
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
CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-13 12:00:00', 'Europe/Helsinki'));
Illuminate\Support\Carbon::setTestNow(CarbonImmutable::getTestNow());
config(['canonical_pricing.enabled' => true, 'price_forecasting.fixed_term.model_version' => 'fixed_term_historical_change_v1',
    'price_forecasting.fixed_term.minimum_history_observations' => 20,
    'price_forecasting.fixed_term.direction_threshold_cents_per_kwh' => 0.15,
    'price_forecasting.fixed_term.default_horizon_days' => 30]);
$connection = DB::connection();
check(app()->environment('testing'), 'Wrong environment');
check($connection->getDriverName() === 'sqlite' && $connection->getConfig('database') === ':memory:', 'Wrong database');
check($connection->getPdo()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite', 'Wrong PDO');
$databases = DB::select('PRAGMA database_list');
check(count($databases) === 1 && $databases[0]->name === 'main' && $databases[0]->file === '', 'Not memory SQLite');
$proof = ['driver' => 'sqlite', 'configured_database' => ':memory:', 'pragma_file' => '', 'environment' => app()->environment()];
echo 'PROVED before schema/load: '.json_encode($proof).PHP_EOL;
foreach (['2026_04_29_000002_create_contract_price_daily_statistics_table.php', '2026_05_23_000001_create_fixed_contract_price_forecasts_table.php'] as $migration) {
    (require $root.'/laravel/database/migrations/'.$migration)->up();
}
// Statistics-only portions of the original migrations. No annual or contract tables.
Schema::table('contract_price_daily_statistics', function (Blueprint $table): void {
    $table->decimal('median_value', 12, 4)->nullable();
    $table->string('pricing_basis', 40)->default('observed_seller_data')->index();
    $table->string('method_version', 80)->nullable();
    $table->string('calculation_basis', 80)->nullable();
    $table->string('estimate_basis', 80)->nullable();
    $table->string('compatibility_key', 120)->nullable();
    $table->json('basis_counts')->nullable();
});
foreach (['statistics' => 'contract_price_daily_statistics', 'forecasts' => 'fixed_contract_price_forecasts'] as $name => $table) {
    $rows = $name === 'statistics' ? array_values(array_filter($inputs[$name], fn ($r) => $r['method_version'] === 'unit_statistics_v1' && $r['metric_key'] === 'energy_price' && $r['consumption_kwh'] === null && in_array($r['segment_key'], ['fixed_term_6', 'fixed_term_12', 'fixed_term_24'], true))) : $inputs[$name];
    foreach (array_chunk($rows, 100) as $chunk) {
        DB::table($table)->insert($chunk);
    }
    check(DB::table($table)->count() === count($rows), "Loaded $name");
}
function snapshot(bool $originalOnly = false): string
{
    return json_encode(DB::table('fixed_contract_price_forecasts')->when($originalOnly, fn ($q) => $q->where('model_version', '!=', 'fixed_term_historical_change_v1'))->orderBy('id')->get()->all(), JSON_THROW_ON_ERROR);
}
unset($rows, $chunk, $inputs['forecasts']);
$original = snapshot();
$nullable = require $root.'/laravel/database/migrations/2026_09_14_000001_make_forecast_gap_diagnostics_nullable.php';
$nullable->up();
check(snapshot() === $original, 'Nullable migration changed original forecasts');

// Independent raw-array ownership and pair mean. No service helpers or Eloquent.
function rawFit(array $statistics, string $issue, int $term, string $quantile, int $horizon): array
{
    $owned = [];
    $boundary = null;
    foreach ($statistics as $row) {
        $date = substr($row['stat_date'], 0, 10);
        if ($row['method_version'] !== 'unit_statistics_v1' || $row['metric_key'] !== 'energy_price'
            || $row['consumption_kwh'] !== null || $row['segment_key'] !== 'fixed_term_'.$term || $date > $issue) {
            continue;
        }
        $basis = $row['pricing_basis'];
        if (! in_array($basis, ['observed_seller_data', 'canonical_calculation'], true)) {
            continue;
        }
        if ($basis === 'canonical_calculation' && ($boundary === null || $date < $boundary)) {
            $boundary = $date;
        }
        if (! isset($owned[$basis][$date]) || $row['id'] > $owned[$basis][$date]['id']) {
            $owned[$basis][$date] = $row;
        }
    }
    $column = $quantile.'_value';
    $current = $owned['canonical_calculation'][$issue][$column] ?? null;
    check($current !== null && is_finite((float) $current), 'Independent current missing');
    $timeline = [];
    foreach ($owned as $basis => $dates) {
        foreach ($dates as $date => $row) {
            if ($date >= $issue || ($basis === 'observed_seller_data' && $boundary !== null && $date >= $boundary)) {
                continue;
            }
            $timeline[$date] = $row;
        }
    }
    ksort($timeline);
    $pairs = $counts = [];
    foreach ($timeline as $date => $start) {
        $targetDate = (new DateTimeImmutable($date))->modify("+$horizon days")->format('Y-m-d');
        $target = $timeline[$targetDate] ?? null;
        if ($targetDate >= $issue || $target === null || $target['pricing_basis'] !== $start['pricing_basis']
            || $start[$column] === null || $target[$column] === null
            || ! is_finite((float) $start[$column]) || ! is_finite((float) $target[$column])) {
            continue;
        }
        $change = (float) $target[$column] - (float) $start[$column];
        $pairs[] = ['start' => $date, 'target' => $targetDate, 'start_id' => $start['id'], 'target_id' => $target['id'], 'basis' => $start['pricing_basis'], 'change' => $change];
        $counts[$start['pricing_basis']] = ($counts[$start['pricing_basis']] ?? 0) + 1;
    }
    ksort($counts);
    check(count($pairs) >= 20, 'Independent history floor');
    return ['pairs' => $pairs, 'counts' => $counts, 'boundary' => $boundary, 'mean' => array_sum(array_column($pairs, 'change')) / count($pairs), 'current' => (float) $current];
}
$service = app(FixedTermPriceForecastService::class);
$fits = $currentRows = [];
$dates = ['2026-09-13'];
for ($date = new DateTimeImmutable('2026-07-27'); $date <= new DateTimeImmutable('2026-08-14'); $date = $date->modify('+1 day')) {
    $dates[] = $date->format('Y-m-d');
}
sort($dates);
DB::enableQueryLog();
foreach ($dates as $issue) {
    $rows = $service->buildForecasts(CarbonImmutable::parse($issue));
    check($rows->count() === 9, "Nine forecasts $issue");
    foreach ($rows as $row) {
        $raw = rawFit($inputs['statistics'], $issue, $row['duration_months'], $row['target_quantile'], 30);
        $m = $row['source_metadata'];
        check(count($raw['pairs']) === $m['pair_count'], 'Pair count');
        check($m['pair_count'] === $m['unique_issue_days'], 'Unique days');
        check($raw['counts'] === $m['historical_retail_pricing_basis_counts'], 'Basis counts');
        check($raw['boundary'] === $m['historical_retail_transition_date'], 'Boundary');
        check(($raw['counts']['canonical_calculation'] ?? 0) === $m['confidence_history_observations'], 'Confidence count');
        $first = $raw['pairs'][0];
        $last = $raw['pairs'][array_key_last($raw['pairs'])];
        check([$first['start'], $last['start'], $first['target'], $last['target']] === [$m['pair_start_min'], $m['pair_start_max'], $m['pair_target_min'], $m['pair_target_max']], 'Bounds');
        near($raw['mean'], $m['mean_change_cents_per_kwh'], 'Mean');
        near(round($raw['mean'], 4), $row['expected_change_cents_per_kwh'], 'Stored mean');
        near(round($raw['current'] + round($raw['mean'], 4), 4), $row['forecast_price_cents_per_kwh'], 'Prediction');
        $fits[$issue.'|'.$row['duration_months'].'|'.$row['target_quantile']] = [
            'pair_count' => count($raw['pairs']), 'pair_sha256' => hash('sha256', json_encode($raw['pairs'], JSON_THROW_ON_ERROR)),
            'mean' => $raw['mean'], 'current' => $raw['current'], 'basis_counts' => $raw['counts'], 'boundary' => $raw['boundary'],
            'start_min' => $first['start'], 'start_max' => $last['start'], 'target_min' => $first['target'], 'target_max' => $last['target'],
        ];
        if ($issue === '2026-09-13') {
            $currentRows[] = $row;
        }
    }
}
foreach (DB::getQueryLog() as $query) {
    check(! str_contains($query['query'], 'futures'), 'Generation queried futures');
}
DB::disableQueryLog();
check(snapshot() === $original, 'Build changed original rows');
check(Artisan::call('forecasting:run-fixed-contracts', ['--as-of' => '2026-09-13', '--dry-run' => true]) === 0, 'Dry run');
$dryOutput = Artisan::output();
check(snapshot() === $original, 'Dry run changed rows');
foreach ($dates as $issue) {
    if ($issue === '2026-09-13') {
        continue;
    }
    foreach ($service->buildForecasts(CarbonImmutable::parse($issue)) as $row) {
        FixedContractPriceForecast::create($row);
    }
}
$staged = snapshot();
$evaluation = app(FixedTermForecastEvaluationService::class);
$dry = $evaluation->evaluateMatured(CarbonImmutable::parse('2026-09-13'), 30, 'fixed_term_historical_change_v1', true);
check(snapshot() === $staged, 'Dry evaluation changed stage');
$applied = $evaluation->evaluateMatured(CarbonImmutable::parse('2026-09-13'), 30, 'fixed_term_historical_change_v1');
check($dry['evaluated'] === $applied['evaluated'], 'Dry/apply count');
check($dry['forecasts']->map->toArray()->all() === $applied['forecasts']->map->toArray()->all(), 'Dry/apply exact row identities and results');
check(snapshot(true) === $original, 'Evaluation changed original rows');
$metrics = $matched = [];
foreach ($applied['forecasts'] as $row) {
    $rawTarget = rawFit($inputs['statistics'], $row->target_date->toDateString(), $row->duration_months, $row->target_quantile, 30)['current'];
    near($rawTarget, (float) $row->actual_price_cents_per_kwh, 'Exact raw target');
    $key = $row->duration_months.'|'.$row->target_quantile;
    $metrics[$key] ??= ['n' => 0, 'absolute_error_sum' => 0, 'unchanged_error_sum' => 0, 'correct' => 0, 'unchanged_correct' => 0];
    $g = &$metrics[$key];
    $g['n']++;
    $g['absolute_error_sum'] += abs($row->forecast_price_cents_per_kwh - $rawTarget);
    $g['unchanged_error_sum'] += abs($row->current_price_cents_per_kwh - $rawTarget);
    $actualDelta = round($rawTarget - $row->current_price_cents_per_kwh, 4);
    $actualCategory = $actualDelta >= 0.15 ? 'up' : ($actualDelta <= -0.15 ? 'down' : 'stable');
    $forecastDelta = (float) $row->expected_change_cents_per_kwh;
    $predictedCategory = $forecastDelta >= 0.15 ? 'up' : ($forecastDelta <= -0.15 ? 'down' : 'stable');
    $g['correct'] += (int) ($actualCategory === $predictedCategory);
    $g['unchanged_correct'] += (int) ($actualCategory === 'stable');
    check((bool) $row->direction_correct === ($actualCategory === $predictedCategory), 'Independent direction');
    unset($g);
    $matched[] = ['issue' => $row->forecast_date->toDateString(), 'target' => $row->target_date->toDateString(), 'term' => $row->duration_months, 'quantile' => $row->target_quantile, 'current' => $row->current_price_cents_per_kwh, 'forecast' => $row->forecast_price_cents_per_kwh, 'actual' => $rawTarget, 'outcome' => $row->source_metadata['direction_outcome']];
}
foreach ($metrics as &$g) {
    $g['mae'] = $g['absolute_error_sum'] / $g['n'];
    $g['unchanged_mae'] = $g['unchanged_error_sum'] / $g['n'];
}
unset($g);
ksort($metrics);
$beforeReport = snapshot();
check(Artisan::call('forecasting:report-fixed-contracts') === 0, 'Report');
check(snapshot() === $beforeReport, 'Report changed rows');
$nullable->down();
check(snapshot() === $beforeReport, 'Down changed rows');
check(DB::select('PRAGMA integrity_check')[0]->integrity_check === 'ok', 'Integrity');
$result = ['proof' => $proof, 'verified_export' => $verified, 'original_forecast_sha256' => hash('sha256', $original),
    'original_forecasts_preserved' => true, 'dry_run_preserved' => true, 'dry_evaluation_preserved' => true,
    'generation_fits_checked' => count($fits), 'current' => $currentRows, 'metrics' => $metrics,
    'evaluated' => $applied['evaluated'], 'missing_actual' => $applied['missing_actual'], 'unsupported_provenance' => $applied['unsupported_provenance'],
    'fits' => $fits, 'matched' => $matched, 'dry_output' => $dryOutput];
file_put_contents(__DIR__.'/replay-results.json', json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL);
echo json_encode(['fits_checked' => count($fits), 'evaluated' => $applied['evaluated'], 'metrics' => $metrics], JSON_PRETTY_PRINT).PHP_EOL;
