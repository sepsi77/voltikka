<?php

namespace App\Services\DTO;

class HeatingSystemCost
{
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly float $annualCost,
        public readonly float $electricityCost,
        public readonly float $fuelCost,
        public readonly float $investment,
        public readonly float $annualSavings,
        public readonly ?float $paybackYears,
        public readonly float $annualizedTotalCost,
        public readonly float $co2KgPerYear,
        public readonly ?string $notes = null,
    ) {
    }

    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'annualCost' => $this->annualCost,
            'electricityCost' => $this->electricityCost,
            'fuelCost' => $this->fuelCost,
            'investment' => $this->investment,
            'annualSavings' => $this->annualSavings,
            'paybackYears' => $this->paybackYears,
            'annualizedTotalCost' => $this->annualizedTotalCost,
            'co2KgPerYear' => $this->co2KgPerYear,
            'notes' => $this->notes,
        ];
    }
}
