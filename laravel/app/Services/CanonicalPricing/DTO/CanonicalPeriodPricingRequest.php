<?php

namespace App\Services\CanonicalPricing\DTO;

use Carbon\CarbonImmutable;

/**
 * Facts supplied by a bill comparison for one exact counterfactual period.
 *
 * @param  list<HistoricalSpotPrice>  $historicalSpotPrices
 */
readonly class CanonicalPeriodPricingRequest
{
    public function __construct(
        public CarbonImmutable $startDate,
        public CarbonImmutable $endDate,
        public float $periodKwh,
        public int $annualizedKwh,
        public array $historicalSpotPrices,
    ) {}
}
