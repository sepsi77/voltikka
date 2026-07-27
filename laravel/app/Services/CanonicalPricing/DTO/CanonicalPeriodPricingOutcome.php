<?php

namespace App\Services\CanonicalPricing\DTO;

use App\Services\CanonicalPricing\Enums\ContractComparability;
use App\Services\CanonicalPricing\Enums\PeriodPricingUnavailableReason;

/**
 * A canonical cost for one exact bill period. This is separate from the
 * 12-month outcome because period Spot observations and period savings have a
 * different basis.
 */
readonly class CanonicalPeriodPricingOutcome
{
    /**
     * @param  list<float>  $spotMargins
     * @param  list<array<string, mixed>>  $phaseBreakdown
     * @param  list<string>  $assumptions
     */
    public function __construct(
        public ?float $periodTotal,
        public ?float $normalPeriodTotal,
        public float $measuredDiscountSavings,
        public ContractComparability $comparability,
        public ?PeriodPricingUnavailableReason $unavailableReason,
        public bool $usesSpot,
        public ?float $monthlyFixedFee,
        public ?float $generalKwhPrice,
        public ?float $daytimeKwhPrice,
        public ?float $nighttimeKwhPrice,
        public ?float $seasonalWinterDayKwhPrice,
        public ?float $seasonalOtherKwhPrice,
        public array $spotMargins,
        public array $phaseBreakdown,
        public array $assumptions,
        public string $pricingBasis = 'canonical',
    ) {}

    public function isAvailable(): bool
    {
        return $this->periodTotal !== null && $this->unavailableReason === null;
    }

    public function hasPromotion(): bool
    {
        return $this->measuredDiscountSavings > 0.0000001;
    }

    public function relevantSpotMargin(): ?float
    {
        return $this->spotMargins[0] ?? null;
    }
}
