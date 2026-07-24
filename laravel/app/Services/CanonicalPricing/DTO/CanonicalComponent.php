<?php

namespace App\Services\CanonicalPricing\DTO;

use App\Services\CanonicalPricing\Enums\ComponentType;
use App\Services\CanonicalPricing\Enums\ComponentUnit;
use App\Services\CanonicalPricing\Enums\PriceRole;

/**
 * One priced component inside a canonical pricing phase (schema-v3 `$defs.component`).
 */
readonly class CanonicalComponent
{
    public function __construct(
        public ComponentType $type,
        public ?float $amount,
        public ?float $normalAmount,
        public ComponentUnit $unit,
        public PriceRole $priceRole,
    ) {
    }

    /**
     * Whether this component contributes a billed amount to the 12-month cost timeline.
     * Disclosure-only consumption-effect ranges (typical/bound) do not.
     */
    public function isBilled(): bool
    {
        if ($this->type === ComponentType::ConsumptionEffect) {
            return false;
        }

        if ($this->priceRole->isDisclosureOnly()) {
            return false;
        }

        return $this->amount !== null;
    }

    /**
     * Whether this component, if it governs any window segment, is one the calculator
     * cannot cost (unknown/percent unit or an opaque `other` type). Such a component
     * forces the contract out of comparison. Disclosure-only rows are exempt because
     * they never enter the cost timeline.
     */
    public function isUncostableWhenBilled(): bool
    {
        if ($this->type === ComponentType::ConsumptionEffect || $this->priceRole->isDisclosureOnly()) {
            return false;
        }

        if ($this->type === ComponentType::Other) {
            return true;
        }

        return ! $this->unit->isCostable();
    }
}
