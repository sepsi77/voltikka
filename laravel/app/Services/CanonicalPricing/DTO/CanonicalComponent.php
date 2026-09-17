<?php

namespace App\Services\CanonicalPricing\DTO;

use App\Services\CanonicalPricing\Enums\ComponentType;
use App\Services\CanonicalPricing\Enums\ComponentUnit;
use App\Services\CanonicalPricing\Enums\EnergyPriceRuleKind;
use App\Services\CanonicalPricing\Enums\PriceRole;

/**
 * One priced component, with optional source-backed V5 facts. Legacy rules remain Unknown.
 */
readonly class CanonicalComponent
{
    public function __construct(
        public ComponentType $type,
        public ?float $amount,
        public ?float $normalAmount,
        public ComponentUnit $unit,
        public PriceRole $priceRole,
        public string $vatStatus = 'unknown',
        public EnergyPriceRule $energyRule = new EnergyPriceRule,
    ) {}

    public function withVatBasis(bool $includeVat, float $vatMultiplier): self
    {
        $target = $includeVat ? 'included' : 'excluded';
        $multiplier = match ($this->vatStatus) {
            'excluded' => $includeVat ? $vatMultiplier : 1.0,
            'included' => $includeVat ? 1.0 : 1.0 / $vatMultiplier,
            default => 1.0,
        };

        // Percentages and opaque units are not monetary amounts.
        if (! $this->unit->isCostable()) {
            return $this;
        }

        return new self(
            type: $this->type,
            amount: $this->amount === null ? null : $this->amount * $multiplier,
            normalAmount: $this->normalAmount === null ? null : $this->normalAmount * $multiplier,
            unit: $this->unit,
            priceRole: $this->priceRole,
            vatStatus: $target,
            energyRule: $this->energyRule->withMonetaryMultiplier($multiplier),
        );
    }

    /** Remove only a genuine sourced energy offer, never transfer its lock to the normal price. */
    public function withoutEnergyOffer(): self
    {
        $rule = $this->energyRule;
        $basis = $rule->normalBasis;
        $genuine = $rule->kind->isDiscount()
            ? $rule->discountValue > 0
            : ($this->priceRole === PriceRole::Introductory && $this->normalAmount !== null && $this->normalAmount > $this->amount);
        if (! $this->type->isPerKwhEnergy() || ! $genuine || $this->normalAmount === null
            || $basis === null || $basis->kind === EnergyPriceRuleKind::Unknown) {
            return $this;
        }

        return new self($this->type, $this->normalAmount, null, $this->unit, PriceRole::Normal, $this->vatStatus,
            new EnergyPriceRule($basis->kind, $basis->starts, $basis->ends));
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
