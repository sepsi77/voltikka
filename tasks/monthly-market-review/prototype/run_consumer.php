<?php

/*
 * Consumer layer prototype: price every contract for each Datahub home type with the canonical
 * bill-comparison period engine (hourly profile option). Local SQLite copy only; no writes.
 *
 * Run from laravel/:
 *   CONSUMER_INPUTS=<consumer-inputs.json> CONSUMER_OUT=<rows.csv> php artisan tinker ../tasks/monthly-market-review/prototype/run_consumer.php
 *
 * Past month: household contracts on sale on day 1 whose current source episode is the episode
 * that covered day 1 (exact terms of that date); priced against the real hourly spot.
 * Coming months: active household contracts; priced against each analog price path.
 * Contracts whose annual kWh limits exclude the home type are marked, not priced away.
 */

use App\Models\ElectricityContract;
use App\Models\SpotPriceHour;
use App\Services\CanonicalPricing\CanonicalContractPricingService;
use App\Services\CanonicalPricing\DTO\CanonicalPeriodPricingRequest;
use App\Services\CanonicalPricing\DTO\HistoricalSpotPrice;
use App\Services\ContractCard\PricingCategoryResolver;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

$in = json_decode(file_get_contents(getenv('CONSUMER_INPUTS')), true, flags: JSON_THROW_ON_ERROR);
$outPath = getenv('CONSUMER_OUT');
$pricing = app(CanonicalContractPricingService::class);
$categories = app(PricingCategoryResolver::class);
$vat = (float) $in['vat'];

// Annual kWh per home type: the last 12 complete months of Datahub data before the past month ends.
$pastStart = CarbonImmutable::parse($in['past']['BE01']['start'], 'Europe/Helsinki');
$annualFrom = $pastStart->addMonth()->subYear()->utc()->format('Y-m-d H:i:s');
$annualTo = $pastStart->addMonth()->utc()->format('Y-m-d H:i:s');
$annualKwh = [];
foreach (['BE01', 'BE02', 'BE03'] as $g) {
    $annualKwh[$g] = (int) round((float) DB::table('fingrid_hourly_values')
        ->where('dataset_id', 364)->where('series_key', "UserGroup={$g}")
        ->where('utc_datetime', '>=', $annualFrom)->where('utc_datetime', '<', $annualTo)
        ->sum('value'));
}

$bucket = function (ElectricityContract $c) use ($categories): string {
    $f = $categories->resolve($c);

    return match (true) {
        $f->isSpot => 'spot',
        $f->isReset => 'market_reset',
        $f->hasConsumptionEffect => 'consumption_effect',
        default => $c->contract_type === 'FixedTerm' ? 'fixed_term' : 'fixed_open_ended',
    };
};

$fits = function (ElectricityContract $c, int $annual): bool {
    $max = $c->consumption_limitation_max_x_kwh_per_y;
    $min = $c->consumption_limitation_min_x_kwh_per_y;

    return ! (($max !== null && $max > 0 && $annual > $max) || ($min !== null && $min > 0 && $annual < $min));
};

$rows = [];
$run = function (string $scenario, string $group, $contracts, CanonicalPeriodPricingRequest $request) use ($pricing, $bucket, $fits, $annualKwh, &$rows): void {
    $evaluations = $pricing->periodEvaluationsForContracts($contracts, $request);
    foreach ($contracts as $c) {
        $period = $evaluations[$c->id]['period'] ?? null;
        $rows[] = [
            'scenario' => $scenario,
            'group' => $group,
            'kwh' => round($request->periodKwh, 1),
            'annual_kwh' => $request->annualizedKwh,
            'contract_id' => $c->id,
            'company' => $c->company?->name ?? $c->company_name,
            'name' => $c->name,
            'bucket' => $bucket($c),
            'pricing_model' => $c->pricing_model,
            'contract_type' => $c->contract_type,
            'fixed_time_range' => $c->fixed_time_range,
            'metering' => $c->metering,
            'national' => (int) $c->availability_is_national,
            'fits_limits' => (int) $fits($c, $annualKwh[$group]),
            'available' => (int) ($period?->isAvailable() ?? false),
            'reason' => $period?->unavailableReason?->value ?? ($period === null ? 'no_evaluation' : ''),
            'actual_eur' => $period?->periodTotal !== null ? round($period->periodTotal, 2) : '',
            'normal_eur' => $period?->normalPeriodTotal !== null ? round($period->normalPeriodTotal, 2) : '',
            'monthly_fee' => $period?->monthlyFixedFee,
            'spot_margin' => $period?->relevantSpotMargin(),
            'uses_spot' => (int) ($period?->usesSpot ?? false),
        ];
    }
};

// Past month.
$p = $in['past']['BE01'];
$dayOne = CarbonImmutable::parse($p['start'], 'Europe/Helsinki');
$d1Start = $dayOne->utc()->format('Y-m-d H:i:s');
$d1End = $dayOne->addDay()->utc()->format('Y-m-d H:i:s');
$pastIds = DB::table('contract_source_observations as o')
    ->join('electricity_contracts as c', 'c.id', '=', 'o.contract_id')
    ->whereIn('c.target_group', ['Household', 'Both'])
    ->where('o.first_observed_at', '<', $d1End)->where('o.last_observed_at', '>=', $d1Start)
    ->whereColumn('c.current_source_observation_id', 'o.id')
    ->distinct()->pluck('o.contract_id');
$onSaleDayOne = DB::table('contract_source_observations as o')
    ->join('electricity_contracts as c', 'c.id', '=', 'o.contract_id')
    ->whereIn('c.target_group', ['Household', 'Both'])
    ->where('o.first_observed_at', '<', $d1End)->where('o.last_observed_at', '>=', $d1Start)
    ->distinct()->count('o.contract_id');
$pastContracts = ElectricityContract::query()->whereIn('id', $pastIds)->with('company')->get();
$pastEnd = CarbonImmutable::parse($p['end'], 'Europe/Helsinki');
$spotHistory = SpotPriceHour::query()->where('region', 'FI')
    ->where('utc_datetime', '>=', $dayOne->utc()->format('Y-m-d H:i:s'))
    ->where('utc_datetime', '<', $pastEnd->addDay()->utc()->format('Y-m-d H:i:s'))
    ->get()
    ->map(fn (SpotPriceHour $h) => new HistoricalSpotPrice(
        startsAtUtc: CarbonImmutable::parse($h->getRawOriginal('utc_datetime'), 'UTC'),
        centsPerKwhWithTax: (float) $h->price_with_tax,
        centsPerKwhWithoutTax: (float) $h->price_without_tax,
    ))->values()->all();

foreach (['BE01', 'BE02', 'BE03'] as $g) {
    $past = $in['past'][$g];
    $weights = [];
    foreach ($past['weights'] as $ts => $w) {
        $weights[(int) $ts] = (float) $w;
    }
    $run("past_{$past['month']}", $g, $pastContracts, new CanonicalPeriodPricingRequest(
        startDate: $dayOne->startOfDay(),
        endDate: $pastEnd->startOfDay(),
        periodKwh: (float) $past['kwh'],
        annualizedKwh: $annualKwh[$g],
        historicalSpotPrices: $spotHistory,
        hourlyConsumptionWeights: $weights,
    ));
}

// Coming months.
$active = ElectricityContract::query()->active()->whereIn('target_group', ['Household', 'Both'])->with('company')->get();
$fStart = CarbonImmutable::parse($in['forward_period']['start'], 'Europe/Helsinki');
$fEnd = CarbonImmutable::parse($in['forward_period']['end'], 'Europe/Helsinki');
foreach ($in['forward'] as $back => $scen) {
    $prices = [];
    foreach ($scen['prices'] as $ts => $c) {
        $prices[] = new HistoricalSpotPrice(
            startsAtUtc: CarbonImmutable::createFromTimestampUTC((int) $ts),
            centsPerKwhWithTax: (float) $c * $vat,
            centsPerKwhWithoutTax: (float) $c,
        );
    }
    foreach (['BE01', 'BE02', 'BE03'] as $g) {
        $weights = [];
        foreach ($scen['groups'][$g] as $ts => $w) {
            $weights[(int) $ts] = (float) $w;
        }
        $run("forward_analog_{$back}", $g, $active, new CanonicalPeriodPricingRequest(
            startDate: $fStart->startOfDay(),
            endDate: $fEnd->startOfDay(),
            periodKwh: array_sum($weights),
            annualizedKwh: $annualKwh[$g],
            historicalSpotPrices: $prices,
            hourlyConsumptionWeights: $weights,
        ));
    }
}

$fh = fopen($outPath, 'w');
fputcsv($fh, array_keys($rows[0]), escape: '');
foreach ($rows as $r) {
    fputcsv($fh, $r, escape: '');
}
fclose($fh);

echo json_encode([
    'annual_kwh' => $annualKwh,
    'past_on_sale_day_one' => $onSaleDayOne,
    'past_exact_terms' => $pastContracts->count(),
    'active_now' => $active->count(),
    'rows' => count($rows),
]), PHP_EOL;
