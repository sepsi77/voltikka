<?php

namespace App\Services\CanonicalPricing\SupplierAdjusted\DTO;

use App\Services\CanonicalPricing\Enums\ComparisonPolicy;
use App\Services\CanonicalPricing\ForwardPremium\PremiumEstimate;
use Carbon\CarbonImmutable;

readonly class SupplierAdjustedEstimateRequest
{
    /**
     * @param  list<string>  $tailMonthKeys
     * @param  array<string, float>  $monthWeights
     */
    public function __construct(
        public CarbonImmutable $asOfDate,
        public PriceEpisodeAnchor $priceEpisodeAnchor,
        public array $tailMonthKeys,
        public float $currentEnergyPriceCentsPerKwh,
        public float $monthlyFeeEur,
        public array $monthWeights,
        // The provider supplies VAT-inclusive prices; the caller selects the bill basis.
        public float $marketPriceMultiplier = 1.0,
        public array $energyRates = [],
        public ?PremiumEstimate $premium = null,
        public array $bucketMonthWeights = [],
        public string $pricingMechanism = 'FixedPrice',
        public ComparisonPolicy $policy = ComparisonPolicy::Historical,
        public ?float $seasonalAnchorEnergyPriceCentsPerKwh = null,
    ) {}
}
