<?php

namespace App\Services\CanonicalPricing\DTO;

use App\Services\CanonicalPricing\Enums\AllowanceCadence;

/**
 * One phase's monthly package: one fee, included energy, and the excess-use rate.
 */
readonly class IncludedEnergyPackageData
{
    public function __construct(
        public float $monthlyFeeEur,
        public float $includedKwh,
        public AllowanceCadence $allowanceCadence,
        public float $excessRateCentsPerKwh,
    ) {}

    /**
     * @return array<string, float|string>
     */
    public function toArray(): array
    {
        return [
            'monthly_fee_eur' => $this->monthlyFeeEur,
            'included_kwh' => $this->includedKwh,
            'allowance_cadence' => $this->allowanceCadence->value,
            'excess_rate_cents_per_kwh' => $this->excessRateCentsPerKwh,
        ];
    }
}
