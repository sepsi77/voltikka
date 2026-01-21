<?php

namespace Tests\Unit;

use App\Models\ElectricitySource;
use App\Services\CO2EmissionsCalculator;
use App\Services\DTO\CO2EmissionsResult;
use PHPUnit\Framework\TestCase;

class CO2EmissionsCalculatorTest extends TestCase
{
    private CO2EmissionsCalculator $calculator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->calculator = new CO2EmissionsCalculator();
    }

    /**
     * Test emission factors are correctly defined.
     */
    public function test_emission_factors_are_defined(): void
    {
        $factors = CO2EmissionsCalculator::EMISSION_FACTORS;

        // Fossil fuels
        $this->assertEquals(846.0, $factors['coal']);
        $this->assertEquals(366.0, $factors['natural_gas']);
        $this->assertEquals(650.0, $factors['oil']);
        $this->assertEquals(1060.0, $factors['peat']);
        $this->assertEquals(650.0, $factors['fossil_generic']);

        // Zero-emission sources
        $this->assertEquals(0.0, $factors['nuclear']);
        $this->assertEquals(0.0, $factors['wind']);
        $this->assertEquals(0.0, $factors['solar']);
        $this->assertEquals(0.0, $factors['hydro']);
        $this->assertEquals(0.0, $factors['biomass']);

        // Residual mix
        $this->assertEquals(390.93, $factors['residual_mix']);
    }

    /**
     * Test 100% renewable energy has zero emissions.
     */
    public function test_fully_renewable_has_zero_emissions(): void
    {
        $source = new ElectricitySource([
            'renewable_total' => 100.0,
            'renewable_wind' => 60.0,
            'renewable_hydro' => 30.0,
            'renewable_solar' => 10.0,
            'fossil_total' => 0.0,
            'nuclear_total' => 0.0,
        ]);

        $result = $this->calculator->calculate($source, 5000);

        $this->assertInstanceOf(CO2EmissionsResult::class, $result);
        $this->assertEquals(0.0, $result->totalEmissionsKg);
        $this->assertEquals(0.0, $result->emissionFactorGPerKwh);
        $this->assertEquals(100.0, $result->reportedSourcesPercent);
        $this->assertEquals(0.0, $result->residualMixPercent);
    }

    /**
     * Test 100% nuclear energy has zero emissions.
     */
    public function test_fully_nuclear_has_zero_emissions(): void
    {
        $source = new ElectricitySource([
            'renewable_total' => 0.0,
            'fossil_total' => 0.0,
            'nuclear_total' => 100.0,
        ]);

        $result = $this->calculator->calculate($source, 5000);

        $this->assertEquals(0.0, $result->totalEmissionsKg);
        $this->assertEquals(0.0, $result->emissionFactorGPerKwh);
    }

    /**
     * Test 100% fossil (generic) emissions calculation.
     */
    public function test_fully_fossil_generic_emissions(): void
    {
        $source = new ElectricitySource([
            'renewable_total' => 0.0,
            'fossil_total' => 100.0,
            'nuclear_total' => 0.0,
        ]);

        $result = $this->calculator->calculate($source, 5000);

        // 5000 kWh * 650 g/kWh = 3,250,000 g = 3250 kg
        $this->assertEqualsWithDelta(3250.0, $result->totalEmissionsKg, 0.01);
        $this->assertEquals(650.0, $result->emissionFactorGPerKwh);
    }

    /**
     * Test detailed fossil breakdown uses specific factors.
     */
    public function test_detailed_fossil_breakdown(): void
    {
        $source = new ElectricitySource([
            'renewable_total' => 0.0,
            'fossil_total' => 100.0,
            'fossil_coal' => 50.0,      // 846 g/kWh
            'fossil_natural_gas' => 50.0, // 366 g/kWh
            'nuclear_total' => 0.0,
        ]);

        $result = $this->calculator->calculate($source, 1000);

        // 500 kWh coal * 846 g/kWh = 423,000 g = 423 kg
        // 500 kWh gas * 366 g/kWh = 183,000 g = 183 kg
        // Total: 606 kg
        $expectedEmissions = (500 * 846 + 500 * 366) / 1000;
        $this->assertEqualsWithDelta($expectedEmissions, $result->totalEmissionsKg, 0.01);

        // Weighted factor: 0.5 * 846 + 0.5 * 366 = 606 g/kWh
        $this->assertEqualsWithDelta(606.0, $result->emissionFactorGPerKwh, 0.01);
    }

    /**
     * Test peat has highest emission factor.
     */
    public function test_peat_highest_emissions(): void
    {
        $source = new ElectricitySource([
            'renewable_total' => 0.0,
            'fossil_total' => 100.0,
            'fossil_peat' => 100.0,
            'nuclear_total' => 0.0,
        ]);

        $result = $this->calculator->calculate($source, 1000);

        // 1000 kWh * 1060 g/kWh = 1,060,000 g = 1060 kg
        $this->assertEqualsWithDelta(1060.0, $result->totalEmissionsKg, 0.01);
        $this->assertEquals(1060.0, $result->emissionFactorGPerKwh);
    }

    /**
     * Test realistic Finnish electricity mix (from user's example).
     */
    public function test_realistic_finnish_mix(): void
    {
        $source = new ElectricitySource([
            'renewable_total' => 23.14,
            'renewable_general' => 23.14,
            'fossil_total' => 27.33,
            'nuclear_total' => 49.53,
        ]);

        $result = $this->calculator->calculate($source, 5000);

        // Reported: 23.14 + 27.33 + 49.53 = 100%
        $this->assertEqualsWithDelta(100.0, $result->reportedSourcesPercent, 0.01);
        $this->assertEquals(0.0, $result->residualMixPercent);

        // Emissions from fossil only (renewable and nuclear are zero)
        // 5000 kWh * 27.33% * 650 g/kWh = 888,225 g = 888.225 kg
        $expectedEmissions = (5000 * 0.2733 * 650) / 1000;
        $this->assertEqualsWithDelta($expectedEmissions, $result->totalEmissionsKg, 1.0);

        // Weighted factor: 0.2733 * 650 = 177.645 g/kWh
        $this->assertEqualsWithDelta(177.645, $result->emissionFactorGPerKwh, 0.1);
    }

    /**
     * Test incomplete source data uses residual mix.
     */
    public function test_incomplete_source_data_uses_residual(): void
    {
        $source = new ElectricitySource([
            'renewable_total' => 50.0,
            'renewable_wind' => 50.0,
            'fossil_total' => 20.0,
            'nuclear_total' => 0.0,
            // Total: 70%, missing 30%
        ]);

        $result = $this->calculator->calculate($source, 1000);

        $this->assertEqualsWithDelta(70.0, $result->reportedSourcesPercent, 0.01);
        $this->assertEqualsWithDelta(30.0, $result->residualMixPercent, 0.01);

        // Emissions:
        // Wind: 500 kWh * 0 g/kWh = 0 kg
        // Fossil: 200 kWh * 650 g/kWh = 130,000 g = 130 kg
        // Residual: 300 kWh * 390.93 g/kWh = 117,279 g = 117.279 kg
        // Total: ~247.279 kg
        $expectedEmissions = (200 * 650 + 300 * 390.93) / 1000;
        $this->assertEqualsWithDelta($expectedEmissions, $result->totalEmissionsKg, 0.1);

        // Check residual mix is in breakdown
        $this->assertArrayHasKey('residual_mix', $result->emissionsBySource);
    }

    /**
     * Test all zeros uses 100% residual mix.
     */
    public function test_all_zeros_uses_full_residual(): void
    {
        $source = new ElectricitySource([
            'renewable_total' => 0.0,
            'fossil_total' => 0.0,
            'nuclear_total' => 0.0,
        ]);

        $result = $this->calculator->calculate($source, 5000);

        $this->assertEquals(0.0, $result->reportedSourcesPercent);
        $this->assertEquals(100.0, $result->residualMixPercent);
        $this->assertEquals(390.93, $result->emissionFactorGPerKwh);

        // 5000 kWh * 390.93 g/kWh = 1,954,650 g = 1954.65 kg
        $expectedEmissions = (5000 * 390.93) / 1000;
        $this->assertEqualsWithDelta($expectedEmissions, $result->totalEmissionsKg, 0.01);
    }

    /**
     * Test null source uses 100% residual mix.
     */
    public function test_null_source_uses_full_residual(): void
    {
        $result = $this->calculator->calculate(null, 5000);

        $this->assertEquals(0.0, $result->reportedSourcesPercent);
        $this->assertEquals(100.0, $result->residualMixPercent);
        $this->assertEquals(390.93, $result->emissionFactorGPerKwh);

        $expectedEmissions = (5000 * 390.93) / 1000;
        $this->assertEqualsWithDelta($expectedEmissions, $result->totalEmissionsKg, 0.01);
    }

    /**
     * Test calculateEmissionFactor returns correct value.
     */
    public function test_calculate_emission_factor_only(): void
    {
        $source = new ElectricitySource([
            'renewable_total' => 50.0,
            'renewable_wind' => 50.0,
            'fossil_total' => 50.0,
            'nuclear_total' => 0.0,
        ]);

        $factor = $this->calculator->calculateEmissionFactor($source);

        // 50% wind (0) + 50% fossil (650) = 325 g/kWh
        $this->assertEqualsWithDelta(325.0, $factor, 0.01);
    }

    /**
     * Test emission factor for null source.
     */
    public function test_emission_factor_null_source(): void
    {
        $factor = $this->calculator->calculateEmissionFactor(null);
        $this->assertEquals(390.93, $factor);
    }

    /**
     * Test emissions by source breakdown.
     */
    public function test_emissions_by_source_breakdown(): void
    {
        $source = new ElectricitySource([
            'renewable_total' => 40.0,
            'renewable_wind' => 40.0,
            'fossil_total' => 30.0,
            'nuclear_total' => 30.0,
        ]);

        $result = $this->calculator->calculate($source, 1000);

        // Wind: 400 kWh * 0 = 0 kg
        $this->assertEqualsWithDelta(0.0, $result->emissionsBySource['wind'], 0.01);

        // Fossil: 300 kWh * 650 g/kWh = 195,000 g = 195 kg
        $this->assertEqualsWithDelta(195.0, $result->emissionsBySource['fossil_generic'], 0.01);

        // Nuclear: 300 kWh * 0 = 0 kg
        $this->assertEqualsWithDelta(0.0, $result->emissionsBySource['nuclear'], 0.01);
    }

    /**
     * Test result DTO toArray method.
     */
    public function test_result_to_array(): void
    {
        $source = new ElectricitySource([
            'renewable_total' => 100.0,
            'renewable_wind' => 100.0,
            'fossil_total' => 0.0,
            'nuclear_total' => 0.0,
        ]);

        $result = $this->calculator->calculate($source, 5000);
        $array = $result->toArray();

        $this->assertArrayHasKey('total_emissions_kg', $array);
        $this->assertArrayHasKey('total_emissions_tonnes', $array);
        $this->assertArrayHasKey('emission_factor_g_per_kwh', $array);
        $this->assertArrayHasKey('annual_consumption_kwh', $array);
        $this->assertArrayHasKey('reported_sources_percent', $array);
        $this->assertArrayHasKey('residual_mix_percent', $array);
        $this->assertArrayHasKey('emissions_by_source', $array);
    }

    /**
     * Test helper methods on result DTO.
     */
    public function test_result_helper_methods(): void
    {
        $source = new ElectricitySource([
            'renewable_total' => 0.0,
            'fossil_total' => 100.0,
            'nuclear_total' => 0.0,
        ]);

        $result = $this->calculator->calculate($source, 1000);

        // 1000 kWh * 650 g/kWh = 650 kg = 0.65 tonnes
        $this->assertEqualsWithDelta(0.65, $result->getTotalEmissionsTonnes(), 0.001);

        // Flight hours: 650 kg / 250 kg/hour = 2.6 hours
        $this->assertEqualsWithDelta(2.6, $result->getFlightHoursEquivalent(), 0.01);

        // Driving km: 650,000 g / 120 g/km = 5416.67 km
        $this->assertEqualsWithDelta(5416.67, $result->getDrivingKmEquivalent(), 1.0);
    }

    /**
     * Test zero consumption returns zero emissions.
     */
    public function test_zero_consumption(): void
    {
        $source = new ElectricitySource([
            'renewable_total' => 50.0,
            'fossil_total' => 50.0,
            'nuclear_total' => 0.0,
        ]);

        $result = $this->calculator->calculate($source, 0);

        $this->assertEquals(0.0, $result->totalEmissionsKg);
        $this->assertEquals(0.0, $result->emissionFactorGPerKwh);
    }

    /**
     * Test source totals exceeding 100% are capped.
     */
    public function test_source_totals_exceeding_hundred(): void
    {
        // Sometimes API returns values that don't quite add up
        $source = new ElectricitySource([
            'renewable_total' => 60.0,
            'renewable_wind' => 60.0,
            'fossil_total' => 30.0,
            'nuclear_total' => 20.0,
            // Total: 110%
        ]);

        $result = $this->calculator->calculate($source, 1000);

        // Should handle gracefully - no residual when over 100%
        $this->assertEquals(0.0, $result->residualMixPercent);
        $this->assertGreaterThanOrEqual(100.0, $result->reportedSourcesPercent);
    }

    /**
     * Test mixed renewable types calculation.
     */
    public function test_mixed_renewable_types(): void
    {
        $source = new ElectricitySource([
            'renewable_total' => 100.0,
            'renewable_wind' => 40.0,
            'renewable_hydro' => 30.0,
            'renewable_solar' => 20.0,
            'renewable_biomass' => 10.0,
            'fossil_total' => 0.0,
            'nuclear_total' => 0.0,
        ]);

        $result = $this->calculator->calculate($source, 5000);

        // All renewable sources have zero emissions
        $this->assertEquals(0.0, $result->totalEmissionsKg);

        // Verify breakdown includes all types
        $this->assertArrayHasKey('wind', $result->emissionsBySource);
        $this->assertArrayHasKey('hydro', $result->emissionsBySource);
        $this->assertArrayHasKey('solar', $result->emissionsBySource);
        $this->assertArrayHasKey('biomass', $result->emissionsBySource);
    }

    /**
     * Test typical household consumption scenarios.
     */
    public function test_typical_household_scenarios(): void
    {
        // Scenario 1: Green electricity contract (100% renewable)
        $greenSource = new ElectricitySource([
            'renewable_total' => 100.0,
            'renewable_wind' => 100.0,
        ]);
        $greenResult = $this->calculator->calculate($greenSource, 5000);
        $this->assertEquals(0.0, $greenResult->totalEmissionsKg);

        // Scenario 2: Spot market contract (uses residual mix)
        $spotResult = $this->calculator->calculate(null, 5000);
        $this->assertGreaterThan(0, $spotResult->totalEmissionsKg);

        // Scenario 3: Nuclear-heavy contract
        $nuclearSource = new ElectricitySource([
            'renewable_total' => 20.0,
            'fossil_total' => 10.0,
            'nuclear_total' => 70.0,
        ]);
        $nuclearResult = $this->calculator->calculate($nuclearSource, 5000);

        // Should have low emissions (only 10% fossil)
        // 5000 kWh * 10% * 650 g/kWh = 325,000 g = 325 kg
        $this->assertEqualsWithDelta(325.0, $nuclearResult->totalEmissionsKg, 1.0);
    }

    /**
     * Test general renewable (unspecified type) is treated as zero emission.
     */
    public function test_renewable_general_zero_emission(): void
    {
        $source = new ElectricitySource([
            'renewable_total' => 100.0,
            'renewable_general' => 100.0, // Unspecified renewable type
            'fossil_total' => 0.0,
            'nuclear_total' => 0.0,
        ]);

        $result = $this->calculator->calculate($source, 5000);

        $this->assertEquals(0.0, $result->totalEmissionsKg);
        $this->assertEquals(0.0, $result->emissionFactorGPerKwh);
    }
}
