<?php

namespace App\Services\CanonicalPricing\SupplierAdjusted\DTO;

use Carbon\CarbonImmutable;

readonly class SupplierAdjustedEstimateRequest
{
    /**
     * @param list<string> $tailMonthKeys
     * @param array<string, float> $monthWeights
     */
    public function __construct(
        public CarbonImmutable $asOfDate,
        public PriceEpisodeAnchor $priceEpisodeAnchor,
        public array $tailMonthKeys,
        public float $currentEnergyPriceCentsPerKwh,
        public float $monthlyFeeEur,
        public array $monthWeights,
    ) {}
}
