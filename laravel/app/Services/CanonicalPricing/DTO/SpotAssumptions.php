<?php

namespace App\Services\CanonicalPricing\DTO;

/**
 * Rolling-365-day spot averages (VAT included, c/kWh) used to estimate Spot and
 * recurring-reset energy prices. Mirrors the values the legacy calculator receives.
 */
readonly class SpotAssumptions
{
    public function __construct(
        public ?float $dayAvgWithTax,
        public ?float $nightAvgWithTax,
    ) {
    }

    public function isAvailable(): bool
    {
        return $this->dayAvgWithTax !== null && $this->nightAvgWithTax !== null;
    }
}
