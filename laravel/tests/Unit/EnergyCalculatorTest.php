<?php

namespace Tests\Unit;

use App\Enums\BuildingEnergyRating;
use App\Enums\BuildingRegion;
use App\Enums\BuildingType;
use App\Enums\HeatingMethod;
use App\Enums\SupplementaryHeatingMethod;
use App\Services\DTO\EnergyCalculatorRequest;
use App\Services\DTO\EnergyCalculatorResult;
use App\Services\EnergyCalculator;
use PHPUnit\Framework\TestCase;

class EnergyCalculatorTest extends TestCase
{
    private EnergyCalculator $calculator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->calculator = new EnergyCalculator();
    }

    // =========================================================================
    // Basic Electricity Usage Tests
    // =========================================================================

    public function test_basic_electricity_usage_for_one_person_in_small_apartment(): void
    {
        // Formula: num_people * 400 + floor_area * 30
        // 1 * 400 + 50 * 30 = 400 + 1500 = 1900
        $result = $this->calculator->calculateBasicElectricityUsage(1, 50);
        $this->assertEquals(1900, $result);
    }

    public function test_basic_electricity_usage_for_four_people_in_large_house(): void
    {
        // 4 * 400 + 150 * 30 = 1600 + 4500 = 6100
        $result = $this->calculator->calculateBasicElectricityUsage(4, 150);
        $this->assertEquals(6100, $result);
    }

    public function test_basic_electricity_usage_for_two_people_in_average_apartment(): void
    {
        // 2 * 400 + 80 * 30 = 800 + 2400 = 3200
        $result = $this->calculator->calculateBasicElectricityUsage(2, 80);
        $this->assertEquals(3200, $result);
    }

    // =========================================================================
    // Sauna Electricity Tests
    // =========================================================================

    public function test_sauna_electricity_need_for_always_on_type(): void
    {
        // Always on type uses fixed 2750 kWh/year
        $result = $this->calculator->calculateSaunaElectricityNeed(0, true);
        $this->assertEquals(2750, $result);
    }

    public function test_sauna_electricity_need_for_regular_usage_twice_per_week(): void
    {
        // Formula: usage_per_week * 7.5 * 52
        // 2 * 7.5 * 52 = 780
        $result = $this->calculator->calculateSaunaElectricityNeed(2, false);
        $this->assertEquals(780, $result);
    }

    public function test_sauna_electricity_need_for_regular_usage_five_times_per_week(): void
    {
        // 5 * 7.5 * 52 = 1950
        $result = $this->calculator->calculateSaunaElectricityNeed(5, false);
        $this->assertEquals(1950, $result);
    }

    public function test_sauna_electricity_need_zero_usage(): void
    {
        $result = $this->calculator->calculateSaunaElectricityNeed(0, false);
        $this->assertEquals(0, $result);
    }

    // =========================================================================
    // Electric Vehicle Charging Tests
    // =========================================================================

    public function test_electric_vehicle_charging_need_for_500km_per_month(): void
    {
        // Formula: km_per_month * 0.199 * 12
        // 500 * 0.199 * 12 = 1194
        $result = $this->calculator->calculateElectricVehicleChargingNeed(500);
        $this->assertEquals(1194, $result);
    }

    public function test_electric_vehicle_charging_need_for_1000km_per_month(): void
    {
        // 1000 * 0.199 * 12 = 2388
        $result = $this->calculator->calculateElectricVehicleChargingNeed(1000);
        $this->assertEquals(2388, $result);
    }

    public function test_electric_vehicle_charging_need_for_zero_km(): void
    {
        $result = $this->calculator->calculateElectricVehicleChargingNeed(0);
        $this->assertEquals(0, $result);
    }

    // =========================================================================
    // Building Heating Energy Need Tests
    // =========================================================================

    public function test_building_heating_energy_need_south_region_2000s_house(): void
    {
        // living_space * 2.6 (avg room height) * heating_factor
        // For south region, 2000 building: factor = 42
        // 100 * 2.6 * 42 = 10920
        $result = $this->calculator->calculateBuildingHeatingEnergyNeed(
            100,
            BuildingEnergyRating::Year2000,
            BuildingRegion::South
        );
        $this->assertEquals(10920, $result);
    }

    public function test_building_heating_energy_need_north_region_older_house(): void
    {
        // For north region, older building: factor = 99
        // 120 * 2.6 * 99 = 30888
        $result = $this->calculator->calculateBuildingHeatingEnergyNeed(
            120,
            BuildingEnergyRating::Older,
            BuildingRegion::North
        );
        $this->assertEquals(30888, $result);
    }

    public function test_building_heating_energy_need_central_region_passive_house(): void
    {
        // For central region, passive building: factor = 10
        // 150 * 2.6 * 10 = 3900
        $result = $this->calculator->calculateBuildingHeatingEnergyNeed(
            150,
            BuildingEnergyRating::Passive,
            BuildingRegion::Central
        );
        $this->assertEquals(3900, $result);
    }

    // =========================================================================
    // Bathroom Underfloor Heating Tests
    // =========================================================================

    public function test_bathroom_underfloor_heating_5_sqm(): void
    {
        // Formula: area * 200
        // 5 * 200 = 1000
        $result = $this->calculator->calculateBathroomUnderfloorHeatingEnergyUsage(5);
        $this->assertEquals(1000, $result);
    }

    public function test_bathroom_underfloor_heating_10_sqm(): void
    {
        // 10 * 200 = 2000
        $result = $this->calculator->calculateBathroomUnderfloorHeatingEnergyUsage(10);
        $this->assertEquals(2000, $result);
    }

    public function test_bathroom_underfloor_heating_zero_area(): void
    {
        $result = $this->calculator->calculateBathroomUnderfloorHeatingEnergyUsage(0);
        $this->assertEquals(0, $result);
    }

    // =========================================================================
    // Water Heating Tests
    // =========================================================================

    public function test_water_heating_energy_usage_for_one_person(): void
    {
        // Formula: inhabitants * 120 * 0.4 * 365 * 58 / 1000 + boiler_loss
        // 1 * 120 * 0.4 * 365 * 58 / 1000 = 1016.16
        // Boiler size for 1 person: 1 * 120 * 0.4 * 1.2 = 57.6
        // First boiler size > 57.6 is 100, so loss = 640
        // Total = 1016.16 + 640 = 1656.16
        $result = $this->calculator->calculateWaterHeatingEnergyUsage(1);
        $this->assertEqualsWithDelta(1656.16, $result, 1);
    }

    public function test_water_heating_energy_usage_for_four_people(): void
    {
        // 4 * 120 * 0.4 * 365 * 58 / 1000 = 4064.64
        // Boiler size: 4 * 120 * 0.4 * 1.2 = 230.4
        // First boiler size > 230.4 is 300, so loss = 1300
        // Total = 4064.64 + 1300 = 5364.64
        $result = $this->calculator->calculateWaterHeatingEnergyUsage(4);
        $this->assertEqualsWithDelta(5364.64, $result, 1);
    }

    // =========================================================================
    // Oil Consumption Tests
    // =========================================================================

    public function test_oil_consumption_for_modern_building(): void
    {
        // For 2000 building: efficiency = 0.8
        // oil_consumption = energy_need / 0.8 / 10 (oil energy density)
        // 10000 / 0.8 / 10 = 1250 liters
        $result = $this->calculator->calculateOilConsumption(10000, BuildingEnergyRating::Year2000);
        $this->assertEquals(1250, $result);
    }

    public function test_oil_consumption_for_1990s_building(): void
    {
        // efficiency = 0.75
        // 10000 / 0.75 / 10 = 1333.33
        $result = $this->calculator->calculateOilConsumption(10000, BuildingEnergyRating::Year1990);
        $this->assertEqualsWithDelta(1333.33, $result, 0.1);
    }

    public function test_oil_consumption_for_very_old_building(): void
    {
        // efficiency = 0.65
        // 10000 / 0.65 / 10 = 1538.46
        $result = $this->calculator->calculateOilConsumption(10000, BuildingEnergyRating::Older);
        $this->assertEqualsWithDelta(1538.46, $result, 0.1);
    }

    // =========================================================================
    // Firewood Consumption Tests
    // =========================================================================

    public function test_firewood_need_for_modern_building(): void
    {
        // efficiency = 0.8
        // energy_density = 1500 kWh/pinokuutio
        // ratio = 0.67
        // 10000 / 0.8 / 1500 / 0.67 = 12.44 irtokuutio
        $result = $this->calculator->calculateFirewoodNeed(10000, BuildingEnergyRating::Year2000);
        $this->assertEqualsWithDelta(12.44, $result, 0.1);
    }

    public function test_firewood_need_for_old_building(): void
    {
        // efficiency = 0.65
        // 10000 / 0.65 / 1500 / 0.67 = 15.31 irtokuutio
        $result = $this->calculator->calculateFirewoodNeed(10000, BuildingEnergyRating::Year1980);
        $this->assertEqualsWithDelta(15.31, $result, 0.1);
    }

    // =========================================================================
    // Pellets Consumption Tests
    // =========================================================================

    public function test_pellets_need(): void
    {
        // energy_density = 4.7 kWh/kg
        // burner_efficiency = 0.85
        // 10000 / 0.85 / 4.7 = 2503.13 kg
        $result = $this->calculator->calculatePelletsNeed(10000);
        $this->assertEqualsWithDelta(2503.13, $result, 0.1);
    }

    // =========================================================================
    // Heating Use By Month Tests
    // =========================================================================

    public function test_heating_use_by_month_distribution(): void
    {
        $result = $this->calculator->calculateHeatingUseByMonth(10000);

        // Should return array of 12 values
        $this->assertCount(12, $result);

        // Sum should equal the input
        $this->assertEqualsWithDelta(10000, array_sum($result), 1);

        // January should be highest, July/August should be lowest
        $this->assertGreaterThan($result[5], $result[0]); // Jan > Jun
        $this->assertGreaterThan($result[6], $result[0]); // Jan > Jul
        $this->assertEquals(0, $result[5]); // June = 0
        $this->assertEquals(0, $result[6]); // July = 0
    }

    // =========================================================================
    // Full Energy Estimation Tests
    // =========================================================================

    public function test_estimate_apartment_with_external_heating(): void
    {
        $request = new EnergyCalculatorRequest(
            livingArea: 60,
            numPeople: 2,
            buildingType: BuildingType::Apartment,
            externalHeating: true,
            externalHeatingWater: true,
        );

        $result = $this->calculator->estimate($request);

        // Only basic living electricity should be calculated
        // 2 * 400 + 60 * 30 = 800 + 1800 = 2600
        $this->assertInstanceOf(EnergyCalculatorResult::class, $result);
        $this->assertEquals(2600, $result->basicLiving);
        $this->assertEquals(2600, $result->total);
        $this->assertNull($result->roomHeating);
        $this->assertNull($result->water);
    }

    public function test_estimate_house_with_electric_heating(): void
    {
        $request = new EnergyCalculatorRequest(
            livingArea: 120,
            numPeople: 4,
            buildingType: BuildingType::DetachedHouse,
            heatingMethod: HeatingMethod::Electricity,
            buildingEnergyEfficiency: BuildingEnergyRating::Year2000,
            buildingRegion: BuildingRegion::Central,
            externalHeating: false,
            externalHeatingWater: false,
        );

        $result = $this->calculator->estimate($request);

        // Basic living: 4 * 400 + 120 * 30 = 1600 + 3600 = 5200
        $this->assertEquals(5200, $result->basicLiving);

        // Heating: 120 * 2.6 * 51 = 15912 kWh (efficiency 1.0)
        $this->assertEqualsWithDelta(15912, $result->roomHeating, 100);

        // Water heating should be included
        $this->assertNotNull($result->water);

        // Total should be sum of all components
        $this->assertGreaterThan($result->basicLiving, $result->total);
    }

    public function test_estimate_house_with_ground_heat_pump(): void
    {
        $request = new EnergyCalculatorRequest(
            livingArea: 150,
            numPeople: 4,
            buildingType: BuildingType::DetachedHouse,
            heatingMethod: HeatingMethod::GroundHeatPump,
            buildingEnergyEfficiency: BuildingEnergyRating::Year2010,
            buildingRegion: BuildingRegion::South,
            externalHeating: false,
            externalHeatingWater: false,
        );

        $result = $this->calculator->estimate($request);

        // Ground heat pump efficiency = 2.9
        // Heating need: 150 * 2.6 * 32 = 12480 kWh
        // Electricity need: 12480 / 2.9 = 4303.45
        $this->assertNotNull($result->roomHeating);
        $this->assertLessThan(12480, $result->roomHeating); // Should be reduced by efficiency
    }

    public function test_estimate_house_with_oil_heating(): void
    {
        $request = new EnergyCalculatorRequest(
            livingArea: 100,
            numPeople: 2,
            buildingType: BuildingType::DetachedHouse,
            heatingMethod: HeatingMethod::Oil,
            buildingEnergyEfficiency: BuildingEnergyRating::Year1990,
            buildingRegion: BuildingRegion::Central,
            externalHeating: false,
            externalHeatingWater: false,
        );

        $result = $this->calculator->estimate($request);

        // Oil heating: no electricity for heating
        $this->assertEqualsWithDelta(300, $result->roomHeating, 1); // Fixed energy cost only

        // Should have oil consumption
        $this->assertNotNull($result->heatingOilConsumption);
        $this->assertNotNull($result->heatingOilCost);
        $this->assertGreaterThan(0, $result->heatingOilConsumption);
    }

    public function test_estimate_house_with_fireplace_heating(): void
    {
        $request = new EnergyCalculatorRequest(
            livingArea: 80,
            numPeople: 2,
            buildingType: BuildingType::DetachedHouse,
            heatingMethod: HeatingMethod::Fireplace,
            buildingEnergyEfficiency: BuildingEnergyRating::Year1970,
            buildingRegion: BuildingRegion::Central,
            externalHeating: false,
            externalHeatingWater: false,
        );

        $result = $this->calculator->estimate($request);

        // Should have firewood consumption
        $this->assertNotNull($result->firewoodConsumption);
        $this->assertNotNull($result->firewoodCost);
        $this->assertGreaterThan(0, $result->firewoodConsumption);
    }

    public function test_estimate_with_supplementary_heat_pump(): void
    {
        $request = new EnergyCalculatorRequest(
            livingArea: 120,
            numPeople: 4,
            buildingType: BuildingType::DetachedHouse,
            heatingMethod: HeatingMethod::Electricity,
            supplementaryHeating: SupplementaryHeatingMethod::HeatPump,
            buildingEnergyEfficiency: BuildingEnergyRating::Year2000,
            buildingRegion: BuildingRegion::Central,
            externalHeating: false,
            externalHeatingWater: true,
        );

        $result = $this->calculator->estimate($request);

        // With supplementary heat pump (40% share, 2.2 efficiency)
        // Total heating electricity should be lower than pure electric
        $this->assertNotNull($result->roomHeating);
    }

    public function test_estimate_with_all_appliances(): void
    {
        $request = new EnergyCalculatorRequest(
            livingArea: 150,
            numPeople: 4,
            buildingType: BuildingType::DetachedHouse,
            heatingMethod: HeatingMethod::AirToWaterHeatPump,
            buildingEnergyEfficiency: BuildingEnergyRating::Year2010,
            buildingRegion: BuildingRegion::South,
            electricVehicleKmsPerMonth: 1000,
            bathroomHeatingArea: 8,
            saunaUsagePerWeek: 3,
            externalHeating: false,
            externalHeatingWater: false,
            cooling: true,
        );

        $result = $this->calculator->estimate($request);

        // All components should be present
        $this->assertNotNull($result->roomHeating);
        $this->assertNotNull($result->water);
        $this->assertNotNull($result->electricityVehicle);
        $this->assertNotNull($result->bathroomUnderfloorHeating);
        $this->assertNotNull($result->sauna);
        $this->assertNotNull($result->cooling);

        // EV: 1000 * 0.199 * 12 = 2388
        $this->assertEquals(2388, $result->electricityVehicle);

        // Bathroom: 8 * 200 = 1600
        $this->assertEquals(1600, $result->bathroomUnderfloorHeating);

        // Sauna: 3 * 7.5 * 52 = 1170
        $this->assertEquals(1170, $result->sauna);

        // Cooling: fixed 240 kWh
        $this->assertEquals(240, $result->cooling);
    }

    public function test_estimate_with_always_on_sauna(): void
    {
        $request = new EnergyCalculatorRequest(
            livingArea: 100,
            numPeople: 2,
            buildingType: BuildingType::DetachedHouse,
            saunaIsAlwaysOnType: true,
            externalHeating: true,
            externalHeatingWater: true,
        );

        $result = $this->calculator->estimate($request);

        // Always on sauna: 2750 kWh
        $this->assertEquals(2750, $result->sauna);
    }

    public function test_estimate_with_district_heating(): void
    {
        $request = new EnergyCalculatorRequest(
            livingArea: 100,
            numPeople: 3,
            buildingType: BuildingType::DetachedHouse,
            heatingMethod: HeatingMethod::DistrictHeating,
            buildingEnergyEfficiency: BuildingEnergyRating::Year2000,
            buildingRegion: BuildingRegion::South,
            externalHeating: false,
            externalHeatingWater: false,
        );

        $result = $this->calculator->estimate($request);

        // District heating: fixed 700 kWh electricity + no water heating electricity
        $this->assertEquals(700, $result->roomHeating);
        $this->assertEquals(0, $result->water);
    }

    public function test_heating_electricity_use_by_month_is_included(): void
    {
        $request = new EnergyCalculatorRequest(
            livingArea: 120,
            numPeople: 4,
            buildingType: BuildingType::DetachedHouse,
            heatingMethod: HeatingMethod::Electricity,
            buildingEnergyEfficiency: BuildingEnergyRating::Year2000,
            buildingRegion: BuildingRegion::Central,
            externalHeating: false,
            externalHeatingWater: true,
        );

        $result = $this->calculator->estimate($request);

        // Should have monthly breakdown
        $this->assertNotNull($result->heatingElectricityUseByMonth);
        $this->assertCount(12, $result->heatingElectricityUseByMonth);

        // Sum should equal room heating
        $this->assertEqualsWithDelta($result->roomHeating, array_sum($result->heatingElectricityUseByMonth), 1);
    }

    public function test_result_can_be_converted_to_energy_usage_dto(): void
    {
        $request = new EnergyCalculatorRequest(
            livingArea: 80,
            numPeople: 2,
            buildingType: BuildingType::Apartment,
            externalHeating: true,
            externalHeatingWater: true,
        );

        $result = $this->calculator->estimate($request);
        $energyUsage = $result->toEnergyUsage();

        $this->assertEquals($result->total, $energyUsage->total);
        $this->assertEquals($result->basicLiving, $energyUsage->basicLiving);
    }
}
