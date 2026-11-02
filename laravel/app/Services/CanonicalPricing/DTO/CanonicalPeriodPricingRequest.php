<?php

namespace App\Services\CanonicalPricing\DTO;

use Carbon\CarbonImmutable;

/**
 * Facts supplied by a bill comparison for one exact counterfactual period.
 *
 * `hourlyConsumptionWeights` is an optional consumption shape: UTC hour-start Unix
 * timestamp => non-negative weight. They are weights, not kWh: the calculator scales
 * them so the period total stays `periodKwh`. Every hour of the period window needs a
 * finite weight >= 0 and the window sum must be > 0; keys outside the window are
 * ignored. Null keeps the flat-hourly consumption and 85/15 Time/Season conventions.
 *
 * @param  list<HistoricalSpotPrice>  $historicalSpotPrices
 * @param  array<int, float|int>|null  $hourlyConsumptionWeights
 */
readonly class CanonicalPeriodPricingRequest
{
    public function __construct(
        public CarbonImmutable $startDate,
        public CarbonImmutable $endDate,
        public float $periodKwh,
        public int $annualizedKwh,
        public array $historicalSpotPrices,
        public ?array $hourlyConsumptionWeights = null,
    ) {}
}
