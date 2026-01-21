<?php

namespace App\Services;

use App\Models\ElectricitySource;
use App\Services\DTO\CO2EmissionsResult;

/**
 * Calculates CO2 emissions for electricity contracts based on energy source mix.
 *
 * Emission factors are derived from Finnish technological standards and
 * Energy Authority (Energiavirasto) 2024 reporting data.
 */
class CO2EmissionsCalculator
{
    /**
     * Emission factors in gCO2/kWh.
     *
     * Sources:
     * - Fossil fuels: Statistics Finland, IPCC Guidelines for National GHG Inventories
     * - Residual Mix: Energiavirasto "National Residual Mix 2024" (published June 2025)
     * - Physical Grid Average: Fingrid & Statistics Finland 2024 data
     */
    public const EMISSION_FACTORS = [
        // Fossil fuels (with specific factors when breakdown is available)
        'coal' => 846.0,        // Hard coal at ~40% efficiency
        'natural_gas' => 366.0, // CCGT at ~55% efficiency
        'oil' => 650.0,         // Peak-load turbines
        'peat' => 1060.0,       // Highest carbon intensity

        // Generic fossil (when no breakdown available)
        'fossil_generic' => 650.0,

        // Zero-emission sources (operational emissions only, no LCA)
        'nuclear' => 0.0,
        'wind' => 0.0,
        'solar' => 0.0,
        'hydro' => 0.0,
        'biomass' => 0.0,

        // Finnish Residual Mix 2024 (Energiavirasto)
        // Applied to unspecified/unreported portions
        'residual_mix' => 390.93,
    ];

    /**
     * Finnish grid benchmarks for comparison.
     *
     * IMPORTANT: There's a big difference between physical and contractual emissions!
     *
     * Physical Grid Average (~35 gCO₂/kWh):
     *   The actual average emissions of all electricity produced in Finland.
     *   In 2024, 95% of Finnish production was fossil-free (nuclear, wind, hydro).
     *   This is what's physically flowing in the grid.
     *
     * Residual Mix (~391 gCO₂/kWh):
     *   The emissions assigned to contracts without Guarantees of Origin.
     *   Clean producers sell their "green attributes" to specific buyers,
     *   leaving the statistical residual concentrated with fossil fuels.
     *   This is 10x higher than the physical average!
     */
    public const FINLAND_BENCHMARKS = [
        // Physical grid average - what's actually produced in Finland
        'physical_grid_average' => 35.0, // Fingrid & Statistics Finland 2024

        // Residual mix - contractual attribution for unspecified sources
        'residual_mix' => 390.93, // Energiavirasto 2024

        // Average car fleet emissions for driving equivalency calculations
        // Traficom/Sitra data - reflects actual cars on road (avg age 12-13 years)
        // New cars are ~50-70 g/km but fleet average is higher due to older vehicles
        'car_fleet_g_per_km' => 140.0,
    ];

    /**
     * Detailed source citations for emission factors.
     * Used for displaying calculation methodology to users.
     */
    public const EMISSION_FACTOR_SOURCES = [
        'coal' => [
            'value' => 846.0,
            'unit' => 'gCO2/kWh',
            'source' => 'Statistics Finland, IPCC Guidelines',
            'calculation' => '94 gCO2/MJ × 3.6 MJ/kWh ÷ 0.40 (efficiency)',
            'notes' => 'Kivihiili, lauhdevoimala ~40% hyötysuhde',
        ],
        'natural_gas' => [
            'value' => 366.0,
            'unit' => 'gCO2/kWh',
            'source' => 'Statistics Finland',
            'calculation' => '56 gCO2/MJ × 3.6 MJ/kWh ÷ 0.55 (efficiency)',
            'notes' => 'Maakaasu, CCGT-voimala ~55% hyötysuhde',
        ],
        'oil' => [
            'value' => 650.0,
            'unit' => 'gCO2/kWh',
            'source' => 'Statistics Finland',
            'notes' => 'Öljy, huippukuormaturbiinit',
        ],
        'peat' => [
            'value' => 1060.0,
            'unit' => 'gCO2/kWh',
            'source' => 'Statistics Finland, IPCC Guidelines',
            'calculation' => '107 gCO2/MJ × 3.6 MJ/kWh ÷ 0.38 (efficiency)',
            'notes' => 'Turve, korkein hiili-intensiteetti Suomen polttoaineista',
        ],
        'fossil_generic' => [
            'value' => 650.0,
            'unit' => 'gCO2/kWh',
            'source' => 'Weighted average estimate',
            'notes' => 'Käytetään kun fossiilisten polttoaineiden tarkempi erittely puuttuu',
        ],
        'nuclear' => [
            'value' => 0.0,
            'unit' => 'gCO2/kWh',
            'source' => 'EU Guarantee of Origin standard',
            'notes' => 'Ydinvoima, ei käytönaikaisia CO2-päästöjä',
        ],
        'wind' => [
            'value' => 0.0,
            'unit' => 'gCO2/kWh',
            'source' => 'EU Guarantee of Origin standard',
            'notes' => 'Tuulivoima',
        ],
        'solar' => [
            'value' => 0.0,
            'unit' => 'gCO2/kWh',
            'source' => 'EU Guarantee of Origin standard',
            'notes' => 'Aurinkovoima',
        ],
        'hydro' => [
            'value' => 0.0,
            'unit' => 'gCO2/kWh',
            'source' => 'EU Guarantee of Origin standard',
            'notes' => 'Vesivoima',
        ],
        'biomass' => [
            'value' => 0.0,
            'unit' => 'gCO2/kWh',
            'source' => 'EU Renewable Energy Directive',
            'notes' => 'Biomassa (kestävyyskriteerit täyttävä), biogeeninen hiili',
        ],
        'residual_mix' => [
            'value' => 390.93,
            'unit' => 'gCO2/kWh',
            'source' => 'Energiavirasto (Finnish Energy Authority)',
            'publication' => 'National Residual Mix 2024',
            'published' => 'June 2025',
            'methodology' => 'Shifted Issuing Based Methodology',
            'notes' => 'Jäännösjakauma, käytetään kun energialähteitä ei ole raportoitu',
        ],
    ];

    /**
     * Calculate CO2 emissions for a contract's energy source mix.
     *
     * @param  ElectricitySource|null  $source  Energy source data (null = 100% residual mix)
     * @param  float  $annualConsumptionKwh  Annual electricity consumption in kWh
     */
    public function calculate(?ElectricitySource $source, float $annualConsumptionKwh): CO2EmissionsResult
    {
        if ($source === null) {
            return $this->createResidualOnlyResult($annualConsumptionKwh);
        }

        $breakdown = $this->getSourceBreakdown($source);
        $reportedTotal = $this->calculateReportedTotal($breakdown);

        // If nothing is reported (all zeros), use 100% residual mix
        if ($reportedTotal <= 0) {
            return $this->createResidualOnlyResult($annualConsumptionKwh);
        }

        // Calculate residual percentage (what's not accounted for)
        $residualPercent = max(0, 100 - $reportedTotal);

        // Calculate emissions for each source
        $emissionsBySource = $this->calculateEmissionsBySource($breakdown, $residualPercent, $annualConsumptionKwh);
        $totalEmissionsKg = array_sum($emissionsBySource);

        // Calculate weighted average emission factor
        $emissionFactor = $annualConsumptionKwh > 0
            ? ($totalEmissionsKg * 1000) / $annualConsumptionKwh
            : 0;

        return new CO2EmissionsResult(
            totalEmissionsKg: $totalEmissionsKg,
            emissionFactorGPerKwh: $emissionFactor,
            annualConsumptionKwh: $annualConsumptionKwh,
            reportedSourcesPercent: min(100, $reportedTotal),
            residualMixPercent: $residualPercent,
            emissionsBySource: $emissionsBySource,
        );
    }

    /**
     * Calculate emission factor only (gCO2/kWh) without consumption.
     */
    public function calculateEmissionFactor(?ElectricitySource $source): float
    {
        if ($source === null) {
            return self::EMISSION_FACTORS['residual_mix'];
        }

        $breakdown = $this->getSourceBreakdown($source);
        $reportedTotal = $this->calculateReportedTotal($breakdown);

        if ($reportedTotal <= 0) {
            return self::EMISSION_FACTORS['residual_mix'];
        }

        $residualPercent = max(0, 100 - $reportedTotal);
        $weightedFactor = 0.0;

        // Calculate weighted emission factor
        foreach ($breakdown as $sourceType => $percent) {
            if ($percent > 0) {
                $factor = $this->getEmissionFactorForSource($sourceType);
                $weightedFactor += ($percent / 100) * $factor;
            }
        }

        // Add residual mix contribution
        if ($residualPercent > 0) {
            $weightedFactor += ($residualPercent / 100) * self::EMISSION_FACTORS['residual_mix'];
        }

        return $weightedFactor;
    }

    /**
     * Get source breakdown from ElectricitySource model.
     * Uses detailed breakdown when available, falls back to totals.
     *
     * @return array<string, float> Source type => percentage
     */
    private function getSourceBreakdown(ElectricitySource $source): array
    {
        $breakdown = [];

        // Fossil sources - prefer detailed breakdown over total
        $fossilDetailedSum = ($source->fossil_coal ?? 0)
            + ($source->fossil_natural_gas ?? 0)
            + ($source->fossil_oil ?? 0)
            + ($source->fossil_peat ?? 0);

        if ($fossilDetailedSum > 0) {
            // Use detailed breakdown
            $breakdown['coal'] = $source->fossil_coal ?? 0;
            $breakdown['natural_gas'] = $source->fossil_natural_gas ?? 0;
            $breakdown['oil'] = $source->fossil_oil ?? 0;
            $breakdown['peat'] = $source->fossil_peat ?? 0;
        } elseif (($source->fossil_total ?? 0) > 0) {
            // Use generic fossil factor for undifferentiated fossil
            $breakdown['fossil_generic'] = $source->fossil_total;
        }

        // Renewable sources - prefer detailed breakdown over total
        $renewableDetailedSum = ($source->renewable_wind ?? 0)
            + ($source->renewable_solar ?? 0)
            + ($source->renewable_hydro ?? 0)
            + ($source->renewable_biomass ?? 0)
            + ($source->renewable_general ?? 0);

        if ($renewableDetailedSum > 0) {
            // Use detailed breakdown
            $breakdown['wind'] = $source->renewable_wind ?? 0;
            $breakdown['solar'] = $source->renewable_solar ?? 0;
            $breakdown['hydro'] = $source->renewable_hydro ?? 0;
            $breakdown['biomass'] = $source->renewable_biomass ?? 0;
            if (($source->renewable_general ?? 0) > 0) {
                $breakdown['renewable_general'] = $source->renewable_general;
            }
        } elseif (($source->renewable_total ?? 0) > 0) {
            // Use generic renewable (still zero emission) for undifferentiated renewable
            $breakdown['renewable_unspecified'] = $source->renewable_total;
        }

        // Nuclear - zero emission
        $breakdown['nuclear'] = $source->nuclear_total ?? 0;

        return $breakdown;
    }

    /**
     * Calculate total percentage of reported sources.
     */
    private function calculateReportedTotal(array $breakdown): float
    {
        return array_sum($breakdown);
    }

    /**
     * Calculate emissions for each source category.
     *
     * @return array<string, float> Source => emissions in kg CO2
     */
    private function calculateEmissionsBySource(
        array $breakdown,
        float $residualPercent,
        float $annualConsumptionKwh
    ): array {
        $emissions = [];

        foreach ($breakdown as $sourceType => $percent) {
            if ($percent > 0) {
                $factor = $this->getEmissionFactorForSource($sourceType);
                $consumptionKwh = ($percent / 100) * $annualConsumptionKwh;
                $emissionsKg = ($consumptionKwh * $factor) / 1000; // g to kg
                $emissions[$sourceType] = $emissionsKg;
            }
        }

        // Add residual mix emissions
        if ($residualPercent > 0) {
            $consumptionKwh = ($residualPercent / 100) * $annualConsumptionKwh;
            $emissionsKg = ($consumptionKwh * self::EMISSION_FACTORS['residual_mix']) / 1000;
            $emissions['residual_mix'] = $emissionsKg;
        }

        return $emissions;
    }

    /**
     * Get emission factor for a source type.
     */
    private function getEmissionFactorForSource(string $sourceType): float
    {
        return match ($sourceType) {
            'coal' => self::EMISSION_FACTORS['coal'],
            'natural_gas' => self::EMISSION_FACTORS['natural_gas'],
            'oil' => self::EMISSION_FACTORS['oil'],
            'peat' => self::EMISSION_FACTORS['peat'],
            'fossil_generic' => self::EMISSION_FACTORS['fossil_generic'],
            'nuclear' => self::EMISSION_FACTORS['nuclear'],
            'wind' => self::EMISSION_FACTORS['wind'],
            'solar' => self::EMISSION_FACTORS['solar'],
            'hydro' => self::EMISSION_FACTORS['hydro'],
            'biomass' => self::EMISSION_FACTORS['biomass'],
            'renewable_general', 'renewable_unspecified' => 0.0, // Unspecified renewable is still zero
            default => self::EMISSION_FACTORS['residual_mix'],
        };
    }

    /**
     * Create result for 100% residual mix (no source data).
     */
    private function createResidualOnlyResult(float $annualConsumptionKwh): CO2EmissionsResult
    {
        $factor = self::EMISSION_FACTORS['residual_mix'];
        $totalEmissionsKg = ($annualConsumptionKwh * $factor) / 1000;

        return new CO2EmissionsResult(
            totalEmissionsKg: $totalEmissionsKg,
            emissionFactorGPerKwh: $factor,
            annualConsumptionKwh: $annualConsumptionKwh,
            reportedSourcesPercent: 0,
            residualMixPercent: 100,
            emissionsBySource: ['residual_mix' => $totalEmissionsKg],
        );
    }
}
