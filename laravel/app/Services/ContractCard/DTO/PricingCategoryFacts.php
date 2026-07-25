<?php

namespace App\Services\ContractCard\DTO;

use App\Services\ContractCard\Enums\PricingCategory;
use Carbon\CarbonImmutable;

/**
 * The typed pricing-mechanism facts behind a card's category, resolved once per contract.
 *
 * Kept separate from the category enum because the band copy needs the mechanism detail
 * (which cadence, when the next reset lands), not only which of the three buckets the
 * contract falls into.
 */
readonly class PricingCategoryFacts
{
    /**
     * @param string|null $cadence One of monthly/quarterly/seasonal/other when a reset schedule
     *                             is present, null otherwise.
     * @param CarbonImmutable|null $nextReset First day of the next pricing period, derived from
     *                                        `recurring_schedule.current_period_end` + 1 day.
     *                                        Null when the seller did not disclose the boundary,
     *                                        or when the disclosed boundary is already in the past.
     */
    public function __construct(
        public PricingCategory $category,
        public bool $isSpot = false,
        public bool $isReset = false,
        public ?string $cadence = null,
        public ?CarbonImmutable $nextReset = null,
        public bool $hasConsumptionEffect = false,
    ) {
    }

    /**
     * Fill in the period boundary from a second source.
     *
     * The resolver only sees `canonical_pricing`, so it can only use the seller's disclosed
     * `current_period_end`. Many reset lineages leave that null while the market-reset
     * estimator still derives a tail start from the cadence calendar. Using it keeps the
     * band and the receipt row stating the same date instead of one going silent.
     */
    public function withNextReset(?CarbonImmutable $nextReset): self
    {
        if ($nextReset === null || $this->nextReset !== null) {
            return $this;
        }

        return new self(
            category: $this->category,
            isSpot: $this->isSpot,
            isReset: $this->isReset,
            cadence: $this->cadence,
            nextReset: $nextReset,
            hasConsumptionEffect: $this->hasConsumptionEffect,
        );
    }
}
