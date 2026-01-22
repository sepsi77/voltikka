<?php

namespace Tests\Unit;

use App\Enums\BuildingEnergyRating;
use App\Enums\BuildingRegion;
use App\Enums\CurrentHeatingMethod;
use App\Services\DTO\HeatPumpComparisonRequest;
use App\Services\DTO\HeatPumpComparisonResult;
use App\Services\DTO\HeatingSystemCost;
use App\Services\HeatPumpComparisonService;
use PHPUnit\Framework\TestCase;

class HeatPumpComparisonServiceTest extends TestCase
{
    private HeatPumpComparisonService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new HeatPumpComparisonService();
    }

    // =========================================================================
    // Calculate Heating Energy Need Tests (Model-Based)
    // =========================================================================

    public function test_heating_energy_need_south_region_2000s_building(): void
    {
        $request = new HeatPumpComparisonRequest(
            livingArea: 150,
            roomHeight: 2.5,
            region: BuildingRegion::South,
            energyRating: BuildingEnergyRating::Year2000,
            numPeople: 4,
            inputMode: 'model_based',
            currentHeatingMethod: CurrentHeatingMethod::Electricity,
        );

        $result = $this->service->calculateHeatingEnergyNeed($request);

        // 150 * 2.5 * 42 (south, 2000) = 15750
        $this->assertEquals(15750, $result);
    }

    public function test_heating_energy_need_central_region_1990s_building(): void
    {
        $request = new HeatPumpComparisonRequest(
            livingArea: 120,
            roomHeight: 2.6,
            region: BuildingRegion::Central,
            energyRating: BuildingEnergyRating::Year1990,
            numPeople: 3,
            inputMode: 'model_based',
            currentHeatingMethod: CurrentHeatingMethod::Oil,
        );

        $result = $this->service->calculateHeatingEnergyNeed($request);

        // 120 * 2.6 * 57 (central, 1990) = 17784
        $this->assertEquals(17784, $result);
    }

    public function test_heating_energy_need_north_region_older_building(): void
    {
        $request = new HeatPumpComparisonRequest(
            livingArea: 100,
            roomHeight: 2.5,
            region: BuildingRegion::North,
            energyRating: BuildingEnergyRating::Older,
            numPeople: 2,
            inputMode: 'model_based',
            currentHeatingMethod: CurrentHeatingMethod::Electricity,
        );

        $result = $this->service->calculateHeatingEnergyNeed($request);

        // 100 * 2.5 * 99 (north, older) = 24750
        $this->assertEquals(24750, $result);
    }

    public function test_heating_energy_need_passive_house(): void
    {
        $request = new HeatPumpComparisonRequest(
            livingArea: 200,
            roomHeight: 2.7,
            region: BuildingRegion::Central,
            energyRating: BuildingEnergyRating::Passive,
            numPeople: 5,
            inputMode: 'model_based',
            currentHeatingMethod: CurrentHeatingMethod::Electricity,
        );

        $result = $this->service->calculateHeatingEnergyNeed($request);

        // 200 * 2.7 * 10 (central, passive) = 5400
        $this->assertEquals(5400, $result);
    }

    // =========================================================================
    // Reverse Calculate From Bill Tests
    // =========================================================================

    public function test_reverse_calculate_from_oil_consumption(): void
    {
        $request = new HeatPumpComparisonRequest(
            livingArea: 150,
            roomHeight: 2.5,
            region: BuildingRegion::South,
            energyRating: BuildingEnergyRating::Year2000,
            numPeople: 4,
            inputMode: 'bill_based',
            currentHeatingMethod: CurrentHeatingMethod::Oil,
            oilLitersPerYear: 2000,
        );

        $result = $this->service->reverseCalculateFromBill($request);

        // 2000 liters × 10 kWh/l × 0.75 efficiency = 15000 kWh
        $this->assertEquals(15000, $result);
    }

    public function test_reverse_calculate_from_electricity_consumption(): void
    {
        $request = new HeatPumpComparisonRequest(
            livingArea: 150,
            roomHeight: 2.5,
            region: BuildingRegion::South,
            energyRating: BuildingEnergyRating::Year2000,
            numPeople: 4,
            inputMode: 'bill_based',
            currentHeatingMethod: CurrentHeatingMethod::Electricity,
            electricityKwhPerYear: 18000,
        );

        $result = $this->service->reverseCalculateFromBill($request);

        // Direct electricity: kWh directly
        $this->assertEquals(18000, $result);
    }

    public function test_reverse_calculate_from_district_heating_cost(): void
    {
        $request = new HeatPumpComparisonRequest(
            livingArea: 150,
            roomHeight: 2.5,
            region: BuildingRegion::South,
            energyRating: BuildingEnergyRating::Year2000,
            numPeople: 4,
            inputMode: 'bill_based',
            currentHeatingMethod: CurrentHeatingMethod::DistrictHeating,
            districtHeatingEurosPerYear: 1620,
            districtHeatingPrice: 10.8, // c/kWh
        );

        $result = $this->service->reverseCalculateFromBill($request);

        // 1620 € / (10.8 c/kWh / 100) = 1620 / 0.108 = 15000 kWh
        $this->assertEqualsWithDelta(15000, $result, 0.01);
    }

    public function test_reverse_calculate_from_district_heating_zero_price(): void
    {
        $request = new HeatPumpComparisonRequest(
            livingArea: 150,
            roomHeight: 2.5,
            region: BuildingRegion::South,
            energyRating: BuildingEnergyRating::Year2000,
            numPeople: 4,
            inputMode: 'bill_based',
            currentHeatingMethod: CurrentHeatingMethod::DistrictHeating,
            districtHeatingEurosPerYear: 1620,
            districtHeatingPrice: 0, // Invalid: zero price
        );

        $result = $this->service->reverseCalculateFromBill($request);

        // Should handle gracefully
        $this->assertEquals(0, $result);
    }

    // =========================================================================
    // Hot Water Energy Need Tests
    // =========================================================================

    public function test_hot_water_energy_need_for_four_people(): void
    {
        $result = $this->service->calculateHotWaterEnergyNeed(4);

        // 4 × 120 l/day × 0.4 × 365 days × 58 kWh/1000L = 4064.64 kWh
        $this->assertEqualsWithDelta(4064.64, $result, 1);
    }

    public function test_hot_water_energy_need_for_one_person(): void
    {
        $result = $this->service->calculateHotWaterEnergyNeed(1);

        // 1 × 120 × 0.4 × 365 × 58 / 1000 = 1016.16 kWh
        $this->assertEqualsWithDelta(1016.16, $result, 1);
    }

    // =========================================================================
    // Annuity Calculation Tests
    // =========================================================================

    public function test_annuity_calculation_with_interest(): void
    {
        // 20000 € investment, 2% interest, 15 years
        $result = $this->service->calculateAnnuity(20000, 0.02, 15);

        // P × r(1+r)^n / ((1+r)^n - 1)
        // 20000 × 0.02 × (1.02)^15 / ((1.02)^15 - 1)
        // 20000 × 0.02 × 1.3459 / (1.3459 - 1)
        // 20000 × 0.02692 / 0.3459
        // ≈ 1557.52
        $this->assertEqualsWithDelta(1557.52, $result, 10);
    }

    public function test_annuity_calculation_zero_interest(): void
    {
        // 15000 € investment, 0% interest, 15 years
        $result = $this->service->calculateAnnuity(15000, 0, 15);

        // Simple division: 15000 / 15 = 1000
        $this->assertEquals(1000, $result);
    }

    public function test_annuity_calculation_zero_investment(): void
    {
        $result = $this->service->calculateAnnuity(0, 0.02, 15);
        $this->assertEquals(0, $result);
    }

    // =========================================================================
    // Payback Calculation Tests
    // =========================================================================

    public function test_payback_calculation_with_savings(): void
    {
        // 20000 € investment, 2000 €/year savings
        $result = $this->service->calculatePayback(20000, 2000);

        // 20000 / 2000 = 10 years
        $this->assertEquals(10, $result);
    }

    public function test_payback_calculation_zero_savings(): void
    {
        $result = $this->service->calculatePayback(20000, 0);

        // No savings = no payback
        $this->assertNull($result);
    }

    public function test_payback_calculation_negative_savings(): void
    {
        $result = $this->service->calculatePayback(20000, -500);

        // Alternative costs more = no payback
        $this->assertNull($result);
    }

    // =========================================================================
    // Full Comparison Tests
    // =========================================================================

    public function test_compare_electric_heating_model_based(): void
    {
        $request = new HeatPumpComparisonRequest(
            livingArea: 150,
            roomHeight: 2.5,
            region: BuildingRegion::Central,
            energyRating: BuildingEnergyRating::Year2000,
            numPeople: 4,
            inputMode: 'model_based',
            currentHeatingMethod: CurrentHeatingMethod::Electricity,
            electricityPrice: 15.0, // 15 c/kWh
        );

        $result = $this->service->compare($request);

        $this->assertInstanceOf(HeatPumpComparisonResult::class, $result);

        // Heating energy need: 150 * 2.5 * 51 = 19125 kWh
        $this->assertEquals(19125, $result->heatingEnergyNeed);

        // Hot water: 4 × 120 × 0.4 × 365 × 58 / 1000 ≈ 4065 kWh
        $this->assertEqualsWithDelta(4064.64, $result->hotWaterEnergyNeed, 1);

        // Total: 19125 + 4065 ≈ 23190 kWh
        $this->assertEqualsWithDelta(23189.64, $result->totalEnergyNeed, 1);

        // Current system should be direct electric heating
        $this->assertEquals('current', $result->currentSystem->key);
        $this->assertEquals('Suora sähkölämmitys', $result->currentSystem->label);

        // Annual electricity cost: 23190 kWh × 0.15 €/kWh ≈ 3478.5 €
        $this->assertEqualsWithDelta(3478.45, $result->currentSystem->annualCost, 10);

        // CO2: 23190 kWh × 210 g/kWh / 1000 ≈ 4869.8 kg
        $this->assertEqualsWithDelta(4869.8, $result->currentSystem->co2KgPerYear, 10);

        // Should have alternatives
        $this->assertNotEmpty($result->alternatives);

        // Ground source HP should be among alternatives
        $groundSource = collect($result->alternatives)->firstWhere('key', 'ground_source_hp');
        $this->assertNotNull($groundSource);

        // Ground source electricity cost should be much lower (SPF 2.9)
        // 23190 / 2.9 × 0.15 ≈ 1199 €
        $this->assertLessThan($result->currentSystem->annualCost, $groundSource->annualCost);
    }

    public function test_compare_oil_heating_model_based(): void
    {
        $request = new HeatPumpComparisonRequest(
            livingArea: 150,
            roomHeight: 2.5,
            region: BuildingRegion::Central,
            energyRating: BuildingEnergyRating::Year1990,
            numPeople: 4,
            inputMode: 'model_based',
            currentHeatingMethod: CurrentHeatingMethod::Oil,
            oilPrice: 1.40, // €/liter
            electricityPrice: 15.0,
        );

        $result = $this->service->compare($request);

        $this->assertEquals('current', $result->currentSystem->key);
        $this->assertEquals('Öljylämmitys', $result->currentSystem->label);

        // Should have fuel cost (oil)
        $this->assertGreaterThan(0, $result->currentSystem->fuelCost);

        // Heating energy need: 150 * 2.5 * 57 = 21375 kWh
        $this->assertEquals(21375, $result->heatingEnergyNeed);
    }

    public function test_compare_district_heating_model_based(): void
    {
        $request = new HeatPumpComparisonRequest(
            livingArea: 150,
            roomHeight: 2.5,
            region: BuildingRegion::South,
            energyRating: BuildingEnergyRating::Year2010,
            numPeople: 4,
            inputMode: 'model_based',
            currentHeatingMethod: CurrentHeatingMethod::DistrictHeating,
            districtHeatingPrice: 10.8, // c/kWh
            electricityPrice: 15.0,
        );

        $result = $this->service->compare($request);

        $this->assertEquals('current', $result->currentSystem->key);
        $this->assertEquals('Kaukolämpö', $result->currentSystem->label);

        // Should have fuel cost (district heating)
        $this->assertGreaterThan(0, $result->currentSystem->fuelCost);

        // CO2 for district heating is lower than electricity
        // 160 g/kWh vs 210 g/kWh
        $heatingEnergy = 150 * 2.5 * 32; // 12000 kWh
        $expectedCo2Approx = ($heatingEnergy + $result->hotWaterEnergyNeed) * 160 / 1000;
        $this->assertEqualsWithDelta($expectedCo2Approx, $result->currentSystem->co2KgPerYear, 50);
    }

    public function test_compare_bill_based_oil(): void
    {
        $request = new HeatPumpComparisonRequest(
            livingArea: 150,
            roomHeight: 2.5,
            region: BuildingRegion::Central,
            energyRating: BuildingEnergyRating::Year1990,
            numPeople: 4,
            inputMode: 'bill_based',
            currentHeatingMethod: CurrentHeatingMethod::Oil,
            oilLitersPerYear: 2500,
            oilPrice: 1.40,
            electricityPrice: 15.0,
        );

        $result = $this->service->compare($request);

        // Should use bill-based energy need: 2500 × 10 × 0.75 = 18750 kWh
        $this->assertEquals(18750, $result->heatingEnergyNeed);
    }

    public function test_alternatives_sorted_by_annualized_cost(): void
    {
        $request = new HeatPumpComparisonRequest(
            livingArea: 150,
            roomHeight: 2.5,
            region: BuildingRegion::Central,
            energyRating: BuildingEnergyRating::Year2000,
            numPeople: 4,
            inputMode: 'model_based',
            currentHeatingMethod: CurrentHeatingMethod::Electricity,
            electricityPrice: 15.0,
        );

        $result = $this->service->compare($request);

        // Verify alternatives are sorted by annualized total cost
        $previousCost = 0;
        foreach ($result->alternatives as $alternative) {
            $this->assertGreaterThanOrEqual($previousCost, $alternative->annualizedTotalCost);
            $previousCost = $alternative->annualizedTotalCost;
        }
    }

    public function test_custom_investments_are_used(): void
    {
        $request = new HeatPumpComparisonRequest(
            livingArea: 150,
            roomHeight: 2.5,
            region: BuildingRegion::Central,
            energyRating: BuildingEnergyRating::Year2000,
            numPeople: 4,
            inputMode: 'model_based',
            currentHeatingMethod: CurrentHeatingMethod::Electricity,
            electricityPrice: 15.0,
            investments: [
                'ground_source_hp' => 25000, // Custom price (default is 20000)
            ],
        );

        $result = $this->service->compare($request);

        $groundSource = collect($result->alternatives)->firstWhere('key', 'ground_source_hp');
        $this->assertEquals(25000, $groundSource->investment);
    }

    public function test_annual_savings_and_payback_calculated(): void
    {
        $request = new HeatPumpComparisonRequest(
            livingArea: 150,
            roomHeight: 2.5,
            region: BuildingRegion::Central,
            energyRating: BuildingEnergyRating::Year2000,
            numPeople: 4,
            inputMode: 'model_based',
            currentHeatingMethod: CurrentHeatingMethod::Electricity,
            electricityPrice: 15.0,
        );

        $result = $this->service->compare($request);

        foreach ($result->alternatives as $alternative) {
            // Annual savings should be current cost - alternative cost
            $expectedSavings = $result->currentSystem->annualCost - $alternative->annualCost;
            $this->assertEqualsWithDelta($expectedSavings, $alternative->annualSavings, 0.01);

            // Payback should be investment / savings (if savings > 0)
            if ($alternative->annualSavings > 0) {
                $expectedPayback = $alternative->investment / $alternative->annualSavings;
                $this->assertEqualsWithDelta($expectedPayback, $alternative->paybackYears, 0.1);
            }
        }
    }

    public function test_result_can_be_converted_to_array(): void
    {
        $request = new HeatPumpComparisonRequest(
            livingArea: 150,
            roomHeight: 2.5,
            region: BuildingRegion::Central,
            energyRating: BuildingEnergyRating::Year2000,
            numPeople: 4,
            inputMode: 'model_based',
            currentHeatingMethod: CurrentHeatingMethod::Electricity,
        );

        $result = $this->service->compare($request);
        $array = $result->toArray();

        $this->assertIsArray($array);
        $this->assertArrayHasKey('heatingEnergyNeed', $array);
        $this->assertArrayHasKey('hotWaterEnergyNeed', $array);
        $this->assertArrayHasKey('totalEnergyNeed', $array);
        $this->assertArrayHasKey('currentSystem', $array);
        $this->assertArrayHasKey('alternatives', $array);

        $this->assertIsArray($array['currentSystem']);
        $this->assertArrayHasKey('key', $array['currentSystem']);
        $this->assertArrayHasKey('label', $array['currentSystem']);
        $this->assertArrayHasKey('annualCost', $array['currentSystem']);
    }

    // =========================================================================
    // CO2 Emissions Tests
    // =========================================================================

    public function test_co2_emissions_electricity(): void
    {
        $request = new HeatPumpComparisonRequest(
            livingArea: 100,
            roomHeight: 2.5,
            region: BuildingRegion::South,
            energyRating: BuildingEnergyRating::Year2000,
            numPeople: 2,
            inputMode: 'model_based',
            currentHeatingMethod: CurrentHeatingMethod::Electricity,
        );

        $result = $this->service->compare($request);

        // Total energy: 100 * 2.5 * 42 + 2 × 120 × 0.4 × 365 × 58 / 1000
        // = 10500 + 2032.32 = 12532.32 kWh
        // CO2: 12532.32 × 210 / 1000 = 2631.79 kg
        $this->assertEqualsWithDelta(2631.79, $result->currentSystem->co2KgPerYear, 10);
    }

    public function test_co2_emissions_ground_source_heat_pump(): void
    {
        $request = new HeatPumpComparisonRequest(
            livingArea: 100,
            roomHeight: 2.5,
            region: BuildingRegion::South,
            energyRating: BuildingEnergyRating::Year2000,
            numPeople: 2,
            inputMode: 'model_based',
            currentHeatingMethod: CurrentHeatingMethod::Electricity,
        );

        $result = $this->service->compare($request);

        $groundSource = collect($result->alternatives)->firstWhere('key', 'ground_source_hp');

        // Ground source HP uses 12532.32 / 2.9 = 4321.49 kWh electricity
        // CO2: 4321.49 × 210 / 1000 = 907.51 kg
        $this->assertEqualsWithDelta(907.51, $groundSource->co2KgPerYear, 50);

        // Should be less than direct electric
        $this->assertLessThan($result->currentSystem->co2KgPerYear, $groundSource->co2KgPerYear);
    }
}
