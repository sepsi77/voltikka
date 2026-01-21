<?php

namespace App\Services\DTO;

class CO2EmissionsResult
{
    public function __construct(
        /** Total CO2 emissions in kg for the given consumption */
        public readonly float $totalEmissionsKg,
        /** Emission factor in gCO2/kWh (weighted average) */
        public readonly float $emissionFactorGPerKwh,
        /** Annual electricity consumption in kWh used for calculation */
        public readonly float $annualConsumptionKwh,
        /** Percentage of energy from sources with known emission factors */
        public readonly float $reportedSourcesPercent,
        /** Percentage of energy attributed to residual mix */
        public readonly float $residualMixPercent,
        /** Breakdown of emissions by source category (source => kg CO2) */
        public readonly array $emissionsBySource,
    ) {
    }

    /**
     * Get total emissions in tonnes CO2.
     */
    public function getTotalEmissionsTonnes(): float
    {
        return $this->totalEmissionsKg / 1000;
    }

    /**
     * Get equivalent number of flight hours (avg 250kg CO2/hour).
     */
    public function getFlightHoursEquivalent(): float
    {
        return $this->totalEmissionsKg / 250;
    }

    /**
     * Get equivalent driving distance in km.
     * Uses 140g CO2/km - average Finnish car fleet (Traficom/Sitra data).
     * Reflects actual cars on road (avg age 12-13 years), not just new models.
     */
    public function getDrivingKmEquivalent(): float
    {
        return ($this->totalEmissionsKg * 1000) / 140;
    }

    public function toArray(): array
    {
        return [
            'total_emissions_kg' => round($this->totalEmissionsKg, 2),
            'total_emissions_tonnes' => round($this->getTotalEmissionsTonnes(), 3),
            'emission_factor_g_per_kwh' => round($this->emissionFactorGPerKwh, 2),
            'annual_consumption_kwh' => $this->annualConsumptionKwh,
            'reported_sources_percent' => round($this->reportedSourcesPercent, 2),
            'residual_mix_percent' => round($this->residualMixPercent, 2),
            'emissions_by_source' => array_map(fn($v) => round($v, 2), $this->emissionsBySource),
        ];
    }
}
