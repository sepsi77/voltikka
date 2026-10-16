<?php

// Read-only parity check: historical v3 (in memory) vs stored v3 rows, per contract and consumption.
// Usage: DB_CONNECTION=sqlite DB_DATABASE=<copy> CACHE_STORE=array QUEUE_CONNECTION=sync \
//   php compare.php <out-dir> <date> [<date> ...]

require '/Users/seppo/code/voltikka/laravel/vendor/autoload.php';
$app = require '/Users/seppo/code/voltikka/laravel/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\ContractStatistics\AsOfAnnualCostCalculator;
use App\Services\ContractStatistics\Enums\AnnualCostMethodVersion;
use Illuminate\Support\Facades\DB;

$out = $argv[1];
$dates = [];
foreach (array_slice($argv, 2) as $arg) {
    if (str_contains($arg, '..')) {
        [$from, $to] = explode('..', $arg);
        for ($d = Carbon\CarbonImmutable::parse($from); $d->lte(Carbon\CarbonImmutable::parse($to)); $d = $d->addDay()) {
            $dates[] = $d->toDateString();
        }
    } else {
        $dates[] = $arg;
    }
}
$expected = getenv('DB_DATABASE');
$file = collect(DB::select('pragma database_list'))->firstWhere('name', 'main')->file;
if (realpath($file) !== realpath($expected) || str_contains($file, 'voltikka/laravel/database')) {
    fwrite(STDERR, "Refusing: effective database is $file\n");
    exit(2);
}
DB::statement('PRAGMA query_only = ON');
if (getenv('LINEAGE_EXPERIMENT')) {
    require __DIR__.'/LineageResolver.php';
    app()->bind(App\Services\ContractStatistics\HistoricalPriceEpisodeResolver::class, App\Services\ContractStatistics\LineageHistoricalPriceEpisodeResolver::class);
    fwrite(STDERR, "lineage experiment
");
}
config()->set('contract_statistics.annual_cost.active_method_version', 'annual_cost_as_of_v3');

foreach ($dates as $date) {
    $started = microtime(true);
    app()->forgetScopedInstances();
    $calculator = app(AsOfAnnualCostCalculator::class);
    $results = $calculator->calculate($date, AnnualCostMethodVersion::AsOfV3);
    $stored = DB::table('contract_price_annual_costs')
        ->whereDate('snapshot_date', $date)
        ->where('method_version', 'annual_cost_as_of_v3')
        ->get(['contract_id', 'segment_key', 'consumption_kwh', 'annual_cost', 'estimate_method', 'estimate_basis', 'calculation_basis', 'price_episode_started_at', 'provenance', 'source_interpretation_id'])
        ->keyBy(fn ($row) => $row->contract_id.'|'.$row->consumption_kwh);
    $rows = [];
    $seen = [];
    foreach ($results as $result) {
        $key = $result->contractId.'|'.$result->consumptionKwh;
        $seen[$key] = true;
        $storedRow = $stored[$key] ?? null;
        $rows[] = [
            'contract' => $result->contractId,
            'kwh' => $result->consumptionKwh,
            'h_segment' => $result->segmentKey,
            'h_cost' => $result->isAvailable() ? round($result->totalCost, 4) : null,
            'h_method' => $result->estimateMethod,
            'h_basis' => $result->estimateBasis,
            'h_calc' => $result->calculationBasis->value,
            'h_reason' => $result->unavailableReason,
            'h_start' => $result->priceEpisodeStartedAt?->toDateString(),
            'h_interp' => $result->sourceEvidenceIds['interpretation_id'] ?? null,
            'h_flags' => $result->provenanceFlags,
            's_segment' => $storedRow?->segment_key,
            's_cost' => $storedRow === null ? null : round((float) $storedRow->annual_cost, 4),
            's_method' => $storedRow?->estimate_method,
            's_basis' => $storedRow?->estimate_basis,
            's_calc' => $storedRow?->calculation_basis,
            's_start' => $storedRow?->price_episode_started_at === null ? null : substr($storedRow->price_episode_started_at, 0, 10),
            's_interp' => $storedRow?->source_interpretation_id,
            's_flags' => $storedRow === null ? null : (json_decode($storedRow->provenance, true)['flags'] ?? null),
        ];
    }
    foreach ($stored as $key => $storedRow) {
        if (! isset($seen[$key])) {
            $rows[] = [
                'contract' => $storedRow->contract_id, 'kwh' => (int) $storedRow->consumption_kwh,
                'h_segment' => null, 'h_cost' => null, 'h_method' => null, 'h_basis' => null, 'h_calc' => null,
                'h_reason' => 'not_in_historical_universe', 'h_start' => null, 'h_interp' => null, 'h_flags' => [],
                's_segment' => $storedRow->segment_key, 's_cost' => round((float) $storedRow->annual_cost, 4),
                's_method' => $storedRow->estimate_method, 's_basis' => $storedRow->estimate_basis,
                's_calc' => $storedRow->calculation_basis,
                's_start' => $storedRow->price_episode_started_at === null ? null : substr($storedRow->price_episode_started_at, 0, 10),
                's_interp' => $storedRow->source_interpretation_id,
                's_flags' => json_decode($storedRow->provenance, true)['flags'] ?? null,
            ];
        }
    }
    file_put_contents("$out/$date.json", json_encode($rows));
    fprintf(STDOUT, "%s rows=%d stored=%d %.1fs peak=%.0fMB\n", $date, count($rows), $stored->count(), microtime(true) - $started, memory_get_peak_usage(true) / 1048576);
}
