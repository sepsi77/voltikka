<?php

namespace App\Services\CanonicalPricing\SupplierAdjusted\DTO;

readonly class SupplierAdjustedCandidate
{
    public function __construct(
        public string $contractId,
        public float $currentEnergyPriceCentsPerKwh,
        public float $monthlyFeeEur,
        /** @var array<string, float>|null Exact normalized canonical buckets, never an inferred average. */
        public ?array $energyRates = null,
        public ?string $metering = null,
        public bool $includesVat = true,
        public string $pricingMechanism = 'FixedPrice',
        public bool $normalTariffEvidence = false,
    ) {}

    /** @return array<string, float>|null */
    public function normalizedEnergyRates(): ?array
    {
        $rates = $this->energyRates;
        if ($rates === null) {
            // Old callers can prove a singleton only when metering is not a multi-rate tariff.
            if ($this->metering !== null && $this->metering !== 'General') {
                return null;
            }
            $rates = ['energy_general' => $this->currentEnergyPriceCentsPerKwh];
        }
        ksort($rates);

        return $rates;
    }

    public function hasSameEnergySignature(self $other): bool
    {
        $rates = $this->normalizedEnergyRates();
        $otherRates = $other->normalizedEnergyRates();
        if ($rates === null || $otherRates === null || $rates === []
            || array_keys($rates) !== array_keys($otherRates)
            || ($this->metering ?? 'General') !== ($other->metering ?? 'General')
            || $this->includesVat !== $other->includesVat
            || $this->pricingMechanism !== $other->pricingMechanism) {
            return false;
        }
        foreach ($rates as $type => $rate) {
            if (! is_finite($rate) || ! is_finite($otherRates[$type])
                || abs($rate - $otherRates[$type]) > 0.0001) {
                return false;
            }
        }

        return true;
    }
}
