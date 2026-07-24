<?php

namespace App\Services\CanonicalPricing\DTO;

use App\Services\CanonicalPricing\Enums\PhaseKind;

/**
 * One canonical pricing phase (schema-v3 `$defs.phase`).
 *
 * A phase with no billed components represents an UNKNOWN-coverage period (an
 * unknown or empty future/recurring schedule). It must never be read as a €0 period.
 */
readonly class PricingPhase
{
    /**
     * @param list<CanonicalComponent> $components
     */
    public function __construct(
        public string $label,
        public PhaseKind $phaseKind,
        public PhaseBoundary $starts,
        public PhaseBoundary $ends,
        public array $components,
    ) {
    }

    /**
     * @return list<CanonicalComponent>
     */
    public function billedComponents(): array
    {
        return array_values(array_filter($this->components, fn (CanonicalComponent $c) => $c->isBilled()));
    }

    /**
     * A phase that provides no billed component is an unknown-coverage placeholder.
     */
    public function hasKnownPricing(): bool
    {
        return $this->billedComponents() !== [];
    }

    /**
     * Any billed component the calculator cannot cost (percent/unknown unit, `other` type).
     */
    public function hasUncostableComponent(): bool
    {
        foreach ($this->components as $component) {
            if ($component->isUncostableWhenBilled()) {
                return true;
            }
        }

        return false;
    }
}
