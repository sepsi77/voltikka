<?php

namespace App\Services\CanonicalPricing\MarketReset\DTO;

use Carbon\CarbonImmutable;

/** Consumption-free canonical energy frame. Dates come from the billing calculator. */
final readonly class ResetPremiumCandidate
{
    public function __construct(
        public string $contractId,
        public array $energyRates,
        public string $metering,
        public bool $includesVat,
        public string $cadence,
        public CarbonImmutable $currentPeriodStart,
        public CarbonImmutable $anchorPeriodMonth,
        public CarbonImmutable $tailStart,
        public array $tailMonthKeys,
        public string $pricingMechanism = 'FixedPrice',
    ) {}

    public function normalizedEnergyRates(): array
    {
        $rates = $this->energyRates;
        ksort($rates);

        return $rates;
    }

    public function referenceKindPreference(): array
    {
        return ResetEstimateRequest::referenceKinds($this->cadence);
    }
}
