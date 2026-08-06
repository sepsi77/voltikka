<?php

namespace App\Services\CanonicalPricing\DTO;

use Carbon\CarbonImmutable;

/**
 * Rolling-365-day Spot evidence (VAT included, c/kWh). The overall average and
 * period dates support the forward shape estimate. They stay optional so direct
 * two-value construction continues to select the rolling fallback.
 */
readonly class SpotAssumptions
{
    public function __construct(
        public ?float $dayAvgWithTax,
        public ?float $nightAvgWithTax,
        public ?float $overallAvgWithTax = null,
        public ?CarbonImmutable $periodStart = null,
        public ?CarbonImmutable $periodEnd = null,
    ) {}

    public function isAvailable(): bool
    {
        return $this->dayAvgWithTax !== null && $this->nightAvgWithTax !== null;
    }
}
