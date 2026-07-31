<?php

namespace App\Services\CanonicalPricing;

use App\Services\ContractStatistics\ContractPriceBasis;

/**
 * Immutable request-scoped snapshot of the pricing feature flags.
 */
readonly class PricingMode
{
    public function __construct(
        private bool $canonicalPricingEnabled,
        private bool $resetForwardShiftEnabled,
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            canonicalPricingEnabled: (bool) config('canonical_pricing.enabled', false),
            resetForwardShiftEnabled: (bool) config('canonical_pricing.reset_forward_shift.enabled', false),
        );
    }

    public function enabled(): bool
    {
        return $this->canonicalPricingEnabled;
    }

    public function resetForwardShiftEnabled(): bool
    {
        return $this->resetForwardShiftEnabled;
    }

    public function expectedContractPriceBasis(): ContractPriceBasis
    {
        return ContractPriceBasis::forCanonical($this->canonicalPricingEnabled);
    }

    public function cacheMarker(): string
    {
        return sprintf(
            'c%dr%d',
            (int) $this->canonicalPricingEnabled,
            (int) $this->resetForwardShiftEnabled,
        );
    }
}
