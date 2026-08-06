<?php

namespace App\Services\CanonicalPricing\SupplierAdjusted\DTO;

readonly class SupplierAdjustedCandidate
{
    public function __construct(
        public string $contractId,
        public float $currentEnergyPriceCentsPerKwh,
        public float $monthlyFeeEur,
    ) {}
}
