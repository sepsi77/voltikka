<?php

namespace App\Services\DTO;

class SolarEstimateResult
{
    /**
     * @param  array<int, float>  $monthly_kwh  Array of 12 monthly kWh values (Jan-Dec)
     * @param  array<string, mixed>  $assumptions  Assumptions used in the calculation
     */
    public function __construct(
        public readonly float $annual_kwh,
        public readonly array $monthly_kwh,
        public readonly array $assumptions,
    ) {
    }

    /**
     * @return array{annual_kwh: float, monthly_kwh: array<int, float>, assumptions: array<string, mixed>}
     */
    public function toArray(): array
    {
        return [
            'annual_kwh' => $this->annual_kwh,
            'monthly_kwh' => $this->monthly_kwh,
            'assumptions' => $this->assumptions,
        ];
    }
}
