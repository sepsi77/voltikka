<?php

// Local evidence only. Never use an existing database or an application .env file.
use App\Models\FixedContractPriceForecast;
use App\Services\CanonicalPricing\PricingMode;
use App\Services\PriceForecasting\FixedTermForecastEvaluationService;
use App\Services\PriceForecasting\FixedTermHedgeCostService;
use App\Services\PriceForecasting\FixedTermPriceForecastService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
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
function near(float $a, float $b, string $message, float $epsilon = 0.000051): void
{
    check(abs($a - $b) <= $epsilon, "$message: $a != $b");
}
$root = dirname(__DIR__, 2);
$export = $root.'/tasks/supplier-premium-coverage/export-20260913T094138Z';
$manifestHash = hash_file('sha256', $export.'/manifest.json');
$manifest = jsonFile($export.'/manifest.json');
check($manifest['status'] === 'complete', 'Incomplete manifest');
$inputs = [];
$verified = [];
foreach ($manifest['queries'] as $name => $query) {
    check(basename($query['file']) === $query['file'], 'Unsafe manifest path');
    $path = $export.'/'.$query['file'];
    check(hash_file('sha256', $path) === $query['sha256'], "Hash: $name");
    check(filesize($path) === $query['bytes'], "Bytes: $name");
    $rows = jsonFile($path);
    check(count($rows) === $query['row_count'] && count($rows) === $query['expected_count'], "Count: $name");
    $verified[$name] = ['rows' => count($rows), 'sha256' => $query['sha256']];
    if (in_array($name, ['futures', 'statistics', 'forecasts'])) {
        $inputs[$name] = $rows;
    }
}
unset($rows);
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
Carbon::setTestNow(CarbonImmutable::parse('2026-09-13 12:00:00', 'Europe/Helsinki'));
CarbonImmutable::setTestNow(Carbon::getTestNow());
config(['price_forecasting.fixed_term' => [
    'model_version' => 'fixed_term_ewma_gap_v3', 'area' => 'FI', 'vat_multiplier' => 1.255,
    'ewma_alpha' => 0.25, 'gap_closure_lambda' => 0.30, 'direction_threshold_cents_per_kwh' => 0.15,
    'minimum_history_observations' => 10, 'default_horizon_days' => 30,
    'durations_months' => [6, 12, 24], 'target_quantiles' => ['median', 'p20', 'p80'],
]]);
$tables = ['forecasts' => 'fixed_contract_price_forecasts', 'statistics' => 'contract_price_daily_statistics', 'futures' => 'electricity_futures_eod_prices'];
function fixture(array $inputs, bool $canonicalOnly): array
{
    global $root, $tables;
    DB::purge('sqlite'); // A new, separate in-memory database for each run.
    $connection = DB::connection();
    check(app()->environment('testing'), 'Wrong environment');
    check($connection->getDriverName() === 'sqlite' && $connection->getConfig('database') === ':memory:', 'Wrong database');
    check($connection->getPdo()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite', 'Wrong PDO');
    $databases = DB::select('PRAGMA database_list');
    check(count($databases) === 1 && $databases[0]->name === 'main' && $databases[0]->file === '', 'Not memory SQLite');
    $proof = ['driver' => 'sqlite', 'configured_database' => ':memory:', 'pragma_file' => '', 'environment' => app()->environment()];
    echo 'PROVED before schema/load: '.json_encode($proof).PHP_EOL;
    foreach (['2026_04_29_000002_create_contract_price_daily_statistics_table.php', '2026_05_22_000001_create_electricity_futures_eod_prices_table.php', '2026_05_23_000001_create_fixed_contract_price_forecasts_table.php'] as $migration) {
        (require $root.'/laravel/database/migrations/'.$migration)->up();
    }
    // Statistics-only portions of the April 29, July 27 and August 6 migrations.
    // Do not run their unrelated snapshot/annual-cost schema or data backfills.
    Schema::table('contract_price_daily_statistics', function (Blueprint $table): void {
        $table->decimal('median_value', 12, 4)->nullable();
        $table->string('pricing_basis', 40)->default('observed_seller_data')->index();
        $table->string('method_version', 80)->nullable();
        $table->string('calculation_basis', 80)->nullable();
        $table->string('estimate_basis', 80)->nullable();
        $table->string('compatibility_key', 120)->nullable();
        $table->json('basis_counts')->nullable();
        $table->dropUnique('contract_price_daily_stats_unique');
        $table->unique(['stat_date', 'segment_key', 'metric_key', 'consumption_kwh', 'method_version'], 'contract_price_daily_stats_method_unique');
    });
    foreach ($tables as $name => $table) {
        foreach (array_chunk($inputs[$name], 100) as $chunk) {
            DB::table($table)->insert($chunk);
        }
        check(DB::table($table)->count() === count($inputs[$name]), "Loaded count $name");
    }
    check(DB::select('PRAGMA integrity_check')[0]->integrity_check === 'ok', 'SQLite integrity');
    if ($canonicalOnly) {
        DB::table($tables['statistics'])->where('pricing_basis', 'observed_seller_data')->delete();
    }
    return $proof;
}
function snapshot(bool $completedOnly = false): string
{
    $query = DB::table('fixed_contract_price_forecasts')->orderBy('id');
    if ($completedOnly) {
        $query->whereNotNull('actual_price_cents_per_kwh');
    }
    return json_encode($query->get()->all(), JSON_THROW_ON_ERROR);
}
function keyFor(array $row): string
{
    return substr($row['forecast_date'], 0, 10).'|'.$row['duration_months'].'|'.$row['target_quantile'];
}
// Independent raw-array curve reconstruction: no Eloquent or hedge service calls.
$curves = [];
foreach ($inputs['futures'] as $row) {
    if ($row['area'] === 'FI' && $row['product'] === 'Base') {
        $curves[$row['trade_date']][$row['maturity_type']][$row['maturity']] = (float) $row['settlement_price'];
    }
}
ksort($curves);
function rawH(string $date, int $term): ?float
{
    global $curves;
    static $cache = [];
    $key = "$date|$term";
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }
    $curve = null;
    foreach ($curves as $trade => $candidate) {
        if ($trade >= $date) {
            break;
        }
        $curve = $candidate;
    }
    if ($curve === null) {
        return $cache[$key] = null;
    }
    $month = (new DateTimeImmutable($date))->modify('first day of next month');
    $sum = $days = 0;
    for ($i = 0; $i < $term; $i++, $month = $month->modify('+1 month')) {
        $year = $month->format('Y');
        $quarter = sprintf('%s%02d', $year, intdiv((int) $month->format('n') - 1, 3) * 3 + 1);
        $price = $curve['month'][$month->format('Ym')] ?? $curve['quarter'][$quarter] ?? $curve['year'][$year.'01'] ?? null;
        if ($price === null) {
            return $cache[$key] = null;
        }
        $weight = (int) $month->format('t');
        $sum += $price * $weight;
        $days += $weight;
    }
    return $cache[$key] = $sum / $days * 1.255 / 10;
}
function independentHistory(array $forecast, bool $canonicalOnly): array
{
    global $inputs;
    $date = $forecast['forecast_date'];
    $term = $forecast['duration_months'];
    $column = $forecast['target_quantile'].'_value';
    $owned = [];
    $transition = null;
    foreach ($inputs['statistics'] as $stat) {
        if ($stat['segment_key'] !== 'fixed_term_'.$term || $stat['metric_key'] !== 'energy_price' || $stat['method_version'] !== 'unit_statistics_v1' || $stat['consumption_kwh'] !== null || $stat['stat_date'] > $date) {
            continue;
        }
        if ($stat['pricing_basis'] === 'canonical_calculation') {
            $transition = $transition === null ? $stat['stat_date'] : min($transition, $stat['stat_date']);
        }
        $k = $stat['stat_date'].'|'.$stat['pricing_basis'];
        if (! isset($owned[$k]) || $stat['id'] > $owned[$k]['id']) {
            $owned[$k] = $stat;
        }
    }
    ksort($owned);
    $ewma = null;
    $counts = [];
    $dates = [];
    foreach ($owned as $stat) {
        $d = $stat['stat_date'];
        $basis = $stat['pricing_basis'];
        if ($d >= $date || ($basis === 'observed_seller_data' && ($canonicalOnly || ($transition !== null && $d >= $transition)))) {
            continue;
        }
        $h = rawH($d, $term);
        if ($h === null || $stat[$column] === null || ! is_finite((float) $stat[$column])) {
            continue;
        }
        $premium = (float) $stat[$column] - $h;
        $ewma = $ewma === null ? $premium : 0.75 * $ewma + 0.25 * $premium;
        $counts[$basis] = ($counts[$basis] ?? 0) + 1;
        $dates[] = $d;
    }
    ksort($counts);
    return ['ewma' => $ewma, 'counts' => $counts, 'dates' => $dates, 'transition' => $transition];
}
function category(string $direction): string
{
    return in_array($direction, ['rising', 'falling']) ? $direction : 'flat';
}
function verifyEvaluation(array $result): array
{
    foreach ($result['forecasts'] as $row) {
        $meta = $row->source_metadata;
        near($row->forecast_error_cents_per_kwh, $row->forecast_price_cents_per_kwh - $row->actual_price_cents_per_kwh, 'Signed error');
        near($row->absolute_error_cents_per_kwh, abs($row->forecast_price_cents_per_kwh - $row->actual_price_cents_per_kwh), 'Absolute error');
        near($meta['unchanged_price_absolute_error_cents_per_kwh'], abs($row->current_price_cents_per_kwh - $row->actual_price_cents_per_kwh), 'Baseline error');
        check($meta['actual_retail_pricing_basis'] === $meta['current_retail_pricing_basis'], 'Cross-basis evaluation');
        $change = $row->actual_price_cents_per_kwh - $row->current_price_cents_per_kwh;
        $threshold = $meta['evaluation_direction_threshold_cents_per_kwh'];
        $actualCategory = $change >= $threshold ? 'rising' : ($change <= -$threshold ? 'falling' : 'flat');
        check($row->direction_correct === (category($row->direction) === $actualCategory), 'Independent direction accuracy');
    }
    return array_diff_key($result, ['forecasts' => true]);
}
$proof = fixture($inputs, false);
$hedge = new FixedTermHedgeCostService;
$service = new FixedTermPriceForecastService($hedge, new PricingMode(true, false));
$evaluator = new FixedTermForecastEvaluationService($service);
$before = snapshot();
$completedBefore = snapshot(true);
$originalDry = $evaluator->evaluateMatured(CarbonImmutable::now(), dryRun: true);
$originalSummary = verifyEvaluation($originalDry);
check(snapshot() === $before && snapshot(true) === $completedBefore, 'Original dry run changed bytes');
$oldEvaluated = [];
foreach ($originalDry['forecasts'] as $row) {
    if ($row->model_version === 'fixed_term_ewma_gap_v2') {
        $oldEvaluated[keyFor($row->toArray())] = $row->toArray();
    }
}
$applied = $evaluator->evaluateMatured(CarbonImmutable::now());
check($applied['evaluated'] === $originalDry['evaluated'], 'Apply differs');
check(snapshot(true) !== $completedBefore, 'Apply did not write');
foreach (json_decode($before, true) as $row) {
    $after = (array) DB::table('fixed_contract_price_forecasts')->find($row['id']);
    if ($row['actual_price_cents_per_kwh'] !== null) {
        check(json_encode($after) === json_encode($row), 'Completed original changed');
    }
    foreach ($row as $field => $value) {
        if (! in_array($field, ['actual_price_cents_per_kwh', 'actual_change_cents_per_kwh', 'forecast_error_cents_per_kwh', 'absolute_error_cents_per_kwh', 'actual_direction', 'direction_correct', 'evaluated_at', 'updated_at', 'source_metadata'])) {
            check($after[$field] === $value, "Apply changed forecast field $field");
        }
    }
}
$afterApply = snapshot();
check($evaluator->evaluateMatured(CarbonImmutable::now())['evaluated'] === 0 && snapshot() === $afterApply, 'Apply not idempotent');
$hCheck = ['stored_median_rows' => 0, 'stored_max_difference' => 0.0, 'php_raw_checks' => 0, 'stored_differences_above_rounding' => []];
for ($d = CarbonImmutable::parse('2026-04-08'); $d <= CarbonImmutable::parse('2026-09-13'); $d = $d->addDay()) {
    foreach ([6, 12, 24] as $term) {
        $raw = rawH($d->toDateString(), $term);
        $php = $hedge->calculate($d, $term)['price_cents_per_kwh'] ?? null;
        check(($raw === null) === ($php === null), 'Raw/PHP coverage mismatch');
        if ($raw !== null) {
            near($raw, $php, 'Full-history raw/PHP H', 1e-10);
        }
        $hCheck['php_raw_checks']++;
    }
}
foreach ($inputs['forecasts'] as $row) {
    if ($row['target_quantile'] !== 'median') {
        continue;
    }
    $raw = rawH($row['forecast_date'], $row['duration_months']);
    $php = $hedge->calculate(CarbonImmutable::parse($row['forecast_date']), $row['duration_months']);
    check($raw !== null && $php['price_cents_per_kwh'] !== null, 'Missing stored hedge');
    near($raw, $php['price_cents_per_kwh'], 'PHP/raw H', 1e-10);
    $hCheck['stored_median_rows']++;
    if (abs($raw - $row['hedge_cost_cents_per_kwh']) > 0.000051) {
        $hCheck['stored_differences_above_rounding'][] = ['date' => $row['forecast_date'], 'term' => $row['duration_months'], 'stored' => (float) $row['hedge_cost_cents_per_kwh'], 'replay' => $raw, 'stored_trade_date' => $row['futures_trade_date'], 'replay_trade_date' => $php['trade_date']];
    }
    $hCheck['stored_max_difference'] = max($hCheck['stored_max_difference'], abs($raw - $row['hedge_cost_cents_per_kwh']));
}
$old = [];
$versions = [];
foreach ($inputs['forecasts'] as $row) {
    $versions[$row['model_version']] = ($versions[$row['model_version']] ?? 0) + 1;
    if ($row['model_version'] === 'fixed_term_ewma_gap_v2') {
        $row['source_metadata'] = json_decode($row['source_metadata'], true, 512, JSON_THROW_ON_ERROR);
        $old[keyFor($row)] = $row;
    }
}
$runs = [];
foreach (['continuity' => false, 'canonical_only' => true] as $name => $canonicalOnly) {
    fixture($inputs, $canonicalOnly);
    $originalBytes = snapshot();
    $generated = [];
    $calendar = [];
    for ($date = CarbonImmutable::parse('2026-07-27'); $date <= CarbonImmutable::parse('2026-09-13'); $date = $date->addDay()) {
        $rows = $service->buildForecasts($date);
        $calendar[$date->toDateString()] = $rows->count();
        foreach ($rows as $row) {
            $history = independentHistory($row, $canonicalOnly);
            $meta = $row['source_metadata'];
            check($meta['historical_retail_pricing_basis_counts'] === $history['counts'], 'History basis counts');
            check($meta['history_observations'] === count($history['dates']), 'History count');
            check($meta['historical_retail_source_start_date'] === $history['dates'][0], 'History start');
            check($meta['historical_retail_source_end_date'] === end($history['dates']), 'History end');
            check($meta['historical_retail_transition_date'] === $history['transition'], 'Transition');
            check($meta['confidence_history_observations'] === ($history['counts']['canonical_calculation'] ?? 0), 'Confidence count');
            near($history['ewma'], $row['normal_retail_premium_cents_per_kwh'], 'Independent EWMA');
            $raw = rawH($row['forecast_date'], $row['duration_months']);
            near($raw, $hedge->calculate($date, $row['duration_months'])['price_cents_per_kwh'], 'Replay raw H', 1e-10);
            $hCheck['php_raw_checks']++;
            near($row['forecast_price_cents_per_kwh'], $row['current_price_cents_per_kwh'] + 0.30 * ($raw + $history['ewma'] - $row['current_price_cents_per_kwh']), 'Independent forecast');
            $generated[keyFor($row)] = $row;
        }
    }
    check(snapshot() === $originalBytes, 'Builder changed original table');
    foreach ($generated as $row) {
        FixedContractPriceForecast::create($row);
    }
    $beforeDry = snapshot();
    $result = $evaluator->evaluateMatured(CarbonImmutable::now(), modelVersion: 'fixed_term_ewma_gap_v3', dryRun: true);
    $summary = verifyEvaluation($result);
    check(snapshot() === $beforeDry, 'Replay dry run changed table');
    $evaluation = [];
    foreach ($result['forecasts'] as $row) {
        $evaluation[keyFor($row->toArray())] = $row->toArray();
    }
    $originalAfter = DB::table('fixed_contract_price_forecasts')->where('model_version', '!=', 'fixed_term_ewma_gap_v3')->orderBy('id')->get()->all();
    check(json_encode($originalAfter, JSON_THROW_ON_ERROR) === $originalBytes, 'Original versions changed');
    $runs[$name] = ['calendar' => $calendar, 'generated' => $generated, 'evaluation_summary' => $summary, 'evaluated' => $evaluation];
}
function metrics(array $rows): array
{
    $n = count($rows);
    check($n > 0, 'Empty accuracy cohort');
    $ae = $se = $bias = $baseline = $hits = 0;
    foreach ($rows as $row) {
        $error = $row['forecast_price_cents_per_kwh'] - $row['actual_price_cents_per_kwh'];
        $ae += abs($error);
        $se += $error ** 2;
        $bias += $error;
        $baseline += abs($row['current_price_cents_per_kwh'] - $row['actual_price_cents_per_kwh']);
        $hits += (int) $row['direction_correct'];
    }
    return ['n' => $n, 'mae' => $ae / $n, 'rmse' => sqrt($se / $n), 'bias' => $bias / $n, 'baseline_mae' => $baseline / $n, 'direction_correct' => $hits, 'direction_accuracy' => $hits / $n];
}
$accuracy = [];
foreach (['median', 'p20', 'p80'] as $q) {
    foreach ([6, 12, 24, 'all'] as $term) {
        $filter = fn ($row) => $row['target_quantile'] === $q && ($term === 'all' || $row['duration_months'] === $term);
        $new = array_filter($runs['continuity']['evaluated'], $filter);
        $matchedOld = array_intersect_key(array_filter($oldEvaluated, $filter), $new);
        $matchedNew = array_intersect_key($new, $matchedOld);
        foreach ($matchedNew as $key => $row) {
            near($row['actual_price_cents_per_kwh'], $matchedOld[$key]['actual_price_cents_per_kwh'], 'Matched actual');
            near($row['current_price_cents_per_kwh'], $matchedOld[$key]['current_price_cents_per_kwh'], 'Matched baseline');
        }
        $counter = array_filter($runs['canonical_only']['evaluated'], $filter);
        $accuracy[$q][$term] = [
            'continuity_full' => metrics($new), 'v2_matched' => metrics($matchedOld), 'v3_matched' => metrics($matchedNew),
            'canonical_only_full' => metrics($counter), 'continuity_on_counter_cohort' => metrics(array_intersect_key($new, $counter)),
            'matched_dates' => array_values(array_unique(array_column($matchedNew, 'forecast_date'))),
            'direction_switches' => count(array_filter($matchedNew, fn ($r) => $r['direction'] !== $matchedOld[keyFor($r)]['direction'])),
            'direction_category_switches' => count(array_filter($matchedNew, fn ($r) => category($r['direction']) !== category($matchedOld[keyFor($r)]['direction']))),
            'switch_records' => array_values(array_map(fn ($r) => ['forecast_date' => substr($r['forecast_date'], 0, 10), 'target_date' => substr($r['target_date'], 0, 10), 'term' => $r['duration_months'], 'old' => $matchedOld[keyFor($r)]['direction'], 'new' => $r['direction']], array_filter($matchedNew, fn ($r) => $r['direction'] !== $matchedOld[keyFor($r)]['direction']))),
        ];
    }
}
$current = [];
$comparison = [];
foreach ($runs['continuity']['generated'] as $key => $row) {
    if (! isset($old[$key])) {
        continue;
    }
    $previous = $old[$key];
    $meta = $row['source_metadata'];
    $entry = [
        'forecast_date' => $row['forecast_date'], 'target_date' => $row['target_date'], 'duration_months' => $row['duration_months'], 'quantile' => $row['target_quantile'],
        'v2_forecast' => (float) $previous['forecast_price_cents_per_kwh'], 'v3_forecast' => $row['forecast_price_cents_per_kwh'],
        'canonical_only_forecast' => $runs['canonical_only']['generated'][$key]['forecast_price_cents_per_kwh'] ?? null,
        'current_price' => $row['current_price_cents_per_kwh'], 'hedge_cost' => $row['hedge_cost_cents_per_kwh'], 'v2_hedge_cost' => (float) $previous['hedge_cost_cents_per_kwh'],
        'v2_normal_premium' => (float) $previous['normal_retail_premium_cents_per_kwh'], 'v3_normal_premium' => $row['normal_retail_premium_cents_per_kwh'],
        'v2_history' => $previous['source_metadata']['history_observations'], 'v3_history' => $meta['history_observations'],
        'observed_history' => $meta['historical_retail_pricing_basis_counts']['observed_seller_data'] ?? 0,
        'canonical_history' => $meta['historical_retail_pricing_basis_counts']['canonical_calculation'] ?? 0,
        'history_start' => $meta['historical_retail_source_start_date'], 'history_end' => $meta['historical_retail_source_end_date'],
        'transition' => $meta['historical_retail_transition_date'], 'confidence' => $row['confidence'],
        'v2_direction' => $previous['direction'], 'v3_direction' => $row['direction'],
        'actual' => $runs['continuity']['evaluated'][$key]['actual_price_cents_per_kwh'] ?? null,
    ];
    $comparison[] = $entry;
    if ($row['forecast_date'] === '2026-09-13') {
        check($meta['historical_retail_source_end_date'] === '2026-09-12', 'Frozen learning');
        check($meta['history_observations'] > $previous['source_metadata']['history_observations'], 'Frozen history count');
        $current[] = $entry;
    }
}
check(count($current) === 9, 'Current rows missing');
foreach ($manifest['queries'] as $query) {
    check(hash_file('sha256', $export.'/'.$query['file']) === $query['sha256'], 'Export modified');
}
check(hash_file('sha256', $export.'/manifest.json') === $manifestHash, 'Manifest modified');
$output = [
    'as_of' => '2026-09-13', 'manifest_sha256' => $manifestHash, 'verified_export' => $verified, 'database_proof' => $proof,
    'original_versions' => $versions, 'original_completed_rows' => count(json_decode($completedBefore, true)),
    'original_dry_run' => $originalSummary, 'original_v2_evaluated_rows' => count($oldEvaluated),
    'checks' => ['original_dry_run_byte_identical' => true, 'completed_rows_preserved' => true, 'apply_numeric_fields_preserved' => true, 'apply_idempotent' => true, 'all_original_versions_preserved' => true, 'export_unchanged' => true, 'independent_ewma_and_errors' => true],
    'hedge_checks' => $hCheck, 'current' => $current, 'accuracy' => $accuracy, 'runs' => $runs,
];
file_put_contents(__DIR__.'/replay-results.json', json_encode($output, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n");
$csv = fopen(__DIR__.'/replay-results.csv', 'w');
fputcsv($csv, array_keys($comparison[0]), escape: '');
foreach ($comparison as $row) {
    fputcsv($csv, $row, escape: '');
}
fclose($csv);
$md = "# Local PHP forecast replay — 2026-09-13\n\n";
$md .= "## Scope and limits\n\nActual revised FixedTermPriceForecastService, FixedTermHedgeCostService and FixedTermForecastEvaluationService ran locally. This is a retrospective replay of export-time records, not an archived-vintage-perfect trial or proof of historical HTML. Older source values and curve availability can differ from the original run. No production operation, app edit, dependency install, or existing local database use occurred.\n\n";
$md .= "V3 uses observed_prefix_canonical_continuation_v1. Continuity is an explicit assumption: ordinary unit quantities are comparable, but discounts, corrected rates, eligibility and classification can differ. It is not cross-basis equivalence. All statistics use unit_statistics_v1. The September 12 annual_cost_as_of_v2 change does not restrict this unit history. Alpha 0.25, lambda 0.30, minimum 10 and direction threshold 0.15 are fixed. Confidence counts only canonical evidence.\n\n";
$md .= "## Isolation and data checks\n\nAPP_ENV=testing, absent config-cache and environment-file paths, empty DB URLs, SQLite :memory:, disabled Sentry, null logging and array cache are set before bootstrap. Connections are reduced to SQLite before providers boot. HTTP stray requests are blocked. Before each schema/load, PDO driver, configured :memory: and PRAGMA main file='' are checked and printed. Three independent memory fixtures load complete forecasts/statistics/futures; only the counterfactual fixture then removes observed statistics. No runtime feature was added. Three actual create-table migrations run, with statistics-only column/index changes derived from the April 29, July 27 and August 6 migrations.\n\n";
$md .= "Manifest SHA256: `$manifestHash`. All manifest files pass hash, byte and expected/actual count checks before and after the run. The manifest itself is unchanged. Completeness means the manifest's bounded export, not all production tables. Loaded rows: 1,314 forecasts; 477 unit statistics; 2,239 FI Base futures.\n\n";
$md .= "## Coverage and learning\n\n| Fixture | Generated dates | Rows | First date | Matured dates per term/quantile | Evaluated rows |\n|---|---:|---:|---|---:|---:|\n";
foreach ($runs as $name => $run) {
    $dates = array_keys(array_filter($run['calendar']));
    $maturedDates = array_unique(array_map(fn ($r) => substr($r['forecast_date'], 0, 10), $run['evaluated']));
    $md .= sprintf("| %s | %d | %d | %s | %d | %d |\n", $name, count($dates), count($run['generated']), $dates[0], count($maturedDates), count($run['evaluated']));
}
$md .= "\nThe full July 27–September 13 calendar has 49 days. Continuity has no gaps. Canonical-only skips July 27–August 5 because fewer than 10 prior canonical days exist. Its first run is August 6. Continuity matured issue dates are July 27–August 14 (targets August 26–September 13); canonical-only has August 6–14 (targets September 5–13). Stored v2 lacks August 2–4, plus August 22 in the full replay interval. Thus stored-v2 matching has 16 matured dates per term/quantile, not 19. Full exact-date CSV comparisons contain ".count($comparison)." rows.\n\n";
$md .= "Independent raw-array history selection and EWMA checks confirm every generated row's counts, bounds and premium. On September 13, each of nine rows has 157 accepted days: 109 observed (April 9–July 26) plus 48 canonical (July 27–September 12). April 8 statistics exist but have no strictly earlier futures trade in this export. Current day September 13 never enters learning. The boundary is derived from canonical presence, not hardcoded. V2 remains at 109; v3 advances through September 12. Confidence remains low (48 current-basis days, below 120). Canonical-only has 48 accepted days; its current forecasts equal continuity at four decimals after 48 EWMA updates.\n\n";
$md .= "## September 13 median forecasts\n\nPrices and premiums are c/kWh, VAT included. Target date is October 13; no actual exists yet. Other quantiles are in JSON/CSV.\n\n| Term | Current | Stored v2 | V3 | Canonical only | V2 normal premium | V3 normal premium | V2 direction → V3 |\n|---|---:|---:|---:|---:|---:|---:|---|\n";
foreach ($current as $r) {
    if ($r['quantile'] === 'median') {
        $md .= sprintf("| %d | %.4f | %.4f | %.4f | %.4f | %.4f | %.4f | %s → %s |\n", $r['duration_months'], $r['current_price'], $r['v2_forecast'], $r['v3_forecast'], $r['canonical_only_forecast'], $r['v2_normal_premium'], $r['v3_normal_premium'], $r['v2_direction'], $r['v3_direction']);
    }
}
$md .= "\n## Original evaluation safety\n\nThe original full-table ID-ordered JSON byte string, including JSON metadata strings and every column, is identical before/after the actual dry run. Original versions: v1=909, v2=405. The 639 completed original evaluations remain byte-identical. Actual dry run: ".$originalSummary['evaluated']." evaluated, ".$originalSummary['missing_actual']." missing actual, ".$originalSummary['unsupported_provenance']." unsupported. All 144 new evaluations are canonical-current v2 with exact same-basis canonical targets (16 dates × 3 terms × 3 quantiles). The 270 missing records are v1 observed-basis targets; canonical values are not relabelled as equivalent actuals. Original completed evaluations are not used for the canonical accuracy cohort.\n\nLocal apply evaluates the same 144 rows, preserves every original forecast field, and leaves completed records unchanged. Repeat apply evaluates zero rows and leaves the full table byte-identical. Separate v3 dry runs evaluate 171 continuity and 81 canonical-only rows, with no missing/unsupported targets. Their full tables remain byte-identical on dry run. In both replay fixtures all original versions remain unchanged after v3 insertion. Independent signed error, absolute error, baseline and direction-category checks pass.\n\n";
$md .= "## Matched accuracy — median is the main result\n\nExact same issue date, target date, 30-day horizon, term and quantile are required. V2 and v3 use identical exported canonical current and target prices; these are stored statistical outcomes, not invented or projected actuals. MAE/RMSE/bias use forecast minus actual. Direction accuracy uses rising/falling/flat categories; slight moves map to flat. All metrics are c/kWh except direction.\n\n| Quantile | Term | N | V2 MAE | V3 MAE | Unchanged MAE | V2 RMSE | V3 RMSE | V2 correct | V3 correct | Label/category switches |\n|---|---|---:|---:|---:|---:|---:|---:|---:|---:|---|\n";
foreach ($accuracy as $q => $terms) {
    foreach ($terms as $term => $a) {
        $v2 = $a['v2_matched'];
        $v3 = $a['v3_matched'];
        $md .= sprintf("| %s | %s | %d | %.4f | %.4f | %.4f | %.4f | %.4f | %d/%d | %d/%d | %d/%d |\n", $q, $term, $v2['n'], $v2['mae'], $v3['mae'], $v3['baseline_mae'], $v2['rmse'], $v3['rmse'], $v2['direction_correct'], $v2['n'], $v3['direction_correct'], $v3['n'], $a['direction_switches'], $a['direction_category_switches']);
    }
}
$md .= "\nV3 repairs frozen input learning; it does not beat the unchanged-price baseline on these aggregate quantiles. Median six-month MAE and direction accuracy are worse than stored v2. All matched errors are negative (underprediction), so signed mean bias is the negative MAE. Results do not establish measured forecasting superiority. Switches compare old/new predictions on the same cohort, not a forecast direction against a target direction or adjacent issue dates. JSON contains each switch's issue date, target date, term and labels.\n\n## Full and counterfactual cohorts\n\n| Quantile | Full continuity N / MAE / baseline | Counterfactual N | Canonical-only MAE | Continuity on same counterfactual cohort MAE | Same-cohort baseline |\n|---|---|---:|---:|---:|---:|\n";
foreach ($accuracy as $q => $terms) {
    $a = $terms['all'];
    $f = $a['continuity_full'];
    $c = $a['canonical_only_full'];
    $md .= sprintf("| %s | %d / %.4f / %.4f | %d | %.6f | %.6f | %.6f |\n", $q, $f['n'], $f['mae'], $f['baseline_mae'], $c['n'], $c['mae'], $a['continuity_on_counter_cohort']['mae'], $c['baseline_mae']);
}
$md .= "\nDo not compare the full 19-date and counterfactual nine-date errors as if the cohorts were equal. JSON includes term-level results, RMSE, bias and direction accuracy for each separate cohort. The counterfactual reduces warm-up coverage by 10 days and matured coverage by 90 rows.\n\n## Independent hedge check\n\nRaw exported curves are reconstructed with native DateTimeImmutable and arrays: strictly earlier trade, next full delivery month, day weighting, month→quarter→year fallback, VAT 1.255. Actual PHP H matches independent H to 1e-10 across the complete April 8–September 13 daily calendar for all terms and all 438 stored median rows. April 8 correctly returns no coverage. There are ".$hCheck['php_raw_checks']." calendar/generated checks in addition to 438 stored checks. Of 438 stored H values, 435 match export-time reconstruction within four-decimal rounding. The three exceptions are May 26: original trade date May 22 versus export-time latest May 25; maximum difference ".sprintf('%.6f', $hCheck['stored_max_difference'])." c/kWh. These are not evidence of an H algorithm error. They demonstrate why this is not a vintage-perfect replay. Exact exception records are in JSON.\n\n## Verification\n\n- `php -l tasks/fix-forecast-learning-evaluation/replay.php`: passed.\n- `php -d memory_limit=512M tasks/fix-forecast-learning-evaluation/replay.php > /tmp/voltikka-forecast-replay.log 2>&1`: passed; all fail guards and numeric checks passed. PHP 8.5 reports existing vendor PDO MySQL constant deprecations; no database connection to MySQL occurs.\n- Manager-reported finalized-code run: `cd laravel && php artisan test`: 2,321 passed / 11,192 assertions, 92.86 seconds. Log `/tmp/voltikka-forecast-continuity-tests.log`. The manager reports no app changes during that run. The replay agent did not rerun the full suite.\n\nArtifacts: this report, replay-results.json (full generated/evaluated rows and all cohorts), replay-results.csv (stored-v2 exact-date comparisons). No production rollout is authorized by these results.\n";
file_put_contents(__DIR__.'/replay-results.md', $md);
echo json_encode(array_diff_key($output, ['runs' => true, 'verified_export' => true, 'accuracy' => true]), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;
