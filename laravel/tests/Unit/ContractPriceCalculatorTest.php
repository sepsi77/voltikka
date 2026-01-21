<?php

namespace Tests\Unit;

use App\Services\ContractPriceCalculator;
use App\Services\DTO\EnergyUsage;
use App\Services\DTO\ContractPricingResult;
use App\Enums\MeteringType;
use PHPUnit\Framework\TestCase;

class ContractPriceCalculatorTest extends TestCase
{
    private ContractPriceCalculator $calculator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->calculator = new ContractPriceCalculator();
    }

    /**
     * Test that winter price months are correctly defined.
     * Jan, Feb, Mar, Nov, Dec are winter months.
     */
    public function test_winter_price_months_are_correct(): void
    {
        $winterMonths = ContractPriceCalculator::WINTER_PRICE_MONTHS;

        $this->assertTrue($winterMonths[0]);  // January - winter
        $this->assertTrue($winterMonths[1]);  // February - winter
        $this->assertTrue($winterMonths[2]);  // March - winter
        $this->assertFalse($winterMonths[3]); // April - summer
        $this->assertFalse($winterMonths[4]); // May - summer
        $this->assertFalse($winterMonths[5]); // June - summer
        $this->assertFalse($winterMonths[6]); // July - summer
        $this->assertFalse($winterMonths[7]); // August - summer
        $this->assertFalse($winterMonths[8]); // September - summer
        $this->assertFalse($winterMonths[9]); // October - summer
        $this->assertTrue($winterMonths[10]); // November - winter
        $this->assertTrue($winterMonths[11]); // December - winter
    }

    /**
     * Test night time shares for different usage types.
     */
    public function test_night_time_shares(): void
    {
        $shares = ContractPriceCalculator::NIGHT_TIME_SHARES;

        $this->assertEquals(0, $shares['sauna']);
        $this->assertEquals(0.9, $shares['electricity_vehicle']);
        $this->assertEquals(0.9, $shares['water']);
        $this->assertEquals(0.33, $shares['room_heating']);
        $this->assertEquals(0, $shares['cooling']);
        $this->assertEquals(0.15, $shares['default']);
    }

    /**
     * Test general metering calculation with simple usage.
     */
    public function test_general_metering_calculation(): void
    {
        // Create mock price components (5 c/kWh general rate, 3 EUR/month fixed)
        $priceComponents = [
            ['price_component_type' => 'General', 'price' => 5.0],
            ['price_component_type' => 'Monthly', 'price' => 3.0],
        ];

        // 5000 kWh annual basic living (no heating, no extras)
        $usage = new EnergyUsage(
            total: 5000,
            basicLiving: 5000
        );

        $contract = ['contract_type' => 'Fixed', 'metering' => 'General'];

        $result = $this->calculator->calculate($priceComponents, $contract, $usage);

        $this->assertInstanceOf(ContractPricingResult::class, $result);

        // 5000 kWh * 5 c/kWh = 25000 cents = 250 EUR energy cost
        // 3 EUR/month * 12 = 36 EUR fixed cost
        // Total: 286 EUR
        $this->assertEqualsWithDelta(286.0, $result->totalCost, 0.01);

        // Monthly average should be ~23.83 EUR
        $this->assertEqualsWithDelta(23.83, $result->avgMonthlyCost, 0.01);

        // Check that general_kwh_price is set
        $this->assertEquals(5.0, $result->generalKwhPrice);
    }

    /**
     * Test time-based metering calculation (day/night rates).
     */
    public function test_time_metering_calculation(): void
    {
        // Day rate 6 c/kWh, Night rate 4 c/kWh, 2 EUR/month fixed
        $priceComponents = [
            ['price_component_type' => 'DayTime', 'price' => 6.0],
            ['price_component_type' => 'NightTime', 'price' => 4.0],
            ['price_component_type' => 'Monthly', 'price' => 2.0],
        ];

        // 12000 kWh annual basic living
        $usage = new EnergyUsage(
            total: 12000,
            basicLiving: 12000
        );

        $contract = ['contract_type' => 'Fixed', 'metering' => 'Time'];

        $result = $this->calculator->calculate($priceComponents, $contract, $usage);

        // With 15% night usage (default share):
        // Monthly usage: 1000 kWh
        // Day usage: 850 kWh/month * 6 c = 5100 c
        // Night usage: 150 kWh/month * 4 c = 600 c
        // Total per month: 5700 c = 57 EUR + 2 EUR fixed = 59 EUR
        // Annual: 59 * 12 = 708 EUR
        $this->assertEqualsWithDelta(708.0, $result->totalCost, 0.01);

        $this->assertEquals(6.0, $result->daytimeKwhPrice);
        $this->assertEquals(4.0, $result->nighttimeKwhPrice);
    }

    /**
     * Test seasonal metering calculation (winter day / other times).
     */
    public function test_seasonal_metering_calculation(): void
    {
        // Winter day 8 c/kWh, Other 5 c/kWh, 2 EUR/month fixed
        $priceComponents = [
            ['price_component_type' => 'SeasonalWinterDay', 'price' => 8.0],
            ['price_component_type' => 'SeasonalOther', 'price' => 5.0],
            ['price_component_type' => 'Monthly', 'price' => 2.0],
        ];

        // 12000 kWh annual basic living (1000 kWh/month)
        $usage = new EnergyUsage(
            total: 12000,
            basicLiving: 12000
        );

        $contract = ['contract_type' => 'Fixed', 'metering' => 'Seasonal'];

        $result = $this->calculator->calculate($priceComponents, $contract, $usage);

        $this->assertInstanceOf(ContractPricingResult::class, $result);
        $this->assertEquals(8.0, $result->seasonalWinterDayKwhPrice);
        $this->assertEquals(5.0, $result->seasonalOtherKwhPrice);

        // For seasonal: 5 winter months (Jan, Feb, Mar, Nov, Dec), 7 summer months
        // Winter months have 30% higher consumption than summer (factor 1.156 vs 0.889)
        //
        // Winter: 1155.6 kWh/month (1000 × 1.156), 15% night share
        //   - Day portion: 1155.6 × 0.85 × 8 c = 7858 c = 78.58 EUR
        //   - Night portion: 1155.6 × 0.15 × 5 c = 867 c = 8.67 EUR
        //   - Total per winter month: 87.25 EUR + 2 EUR fixed = 89.25 EUR
        //   - Winter total: 5 × 89.25 = 446.25 EUR
        //
        // Summer: 888.9 kWh/month (1000 × 0.889)
        //   - Energy: 888.9 × 5 c = 4444 c = 44.44 EUR + 2 EUR fixed = 46.44 EUR
        //   - Summer total: 7 × 46.44 = 325.11 EUR
        //
        // Annual total: 446.25 + 325.11 = 771.36 EUR
        $this->assertEqualsWithDelta(771.33, $result->totalCost, 0.1);
    }

    /**
     * Test fixed monthly fee only (no per-kWh rates).
     */
    public function test_fixed_monthly_fee_only(): void
    {
        // Only monthly fixed fee, no per-kWh rates
        $priceComponents = [
            ['price_component_type' => 'Monthly', 'price' => 50.0],
        ];

        $usage = new EnergyUsage(
            total: 5000,
            basicLiving: 5000
        );

        $contract = ['contract_type' => 'Fixed', 'metering' => 'General'];

        $result = $this->calculator->calculate($priceComponents, $contract, $usage);

        // 50 EUR/month * 12 = 600 EUR annual
        $this->assertEquals(600.0, $result->totalCost);
        $this->assertEquals(50.0, $result->avgMonthlyCost);
        $this->assertEquals(50.0, $result->monthlyFixedFee);
    }

    /**
     * Test spot price contract calculation.
     */
    public function test_spot_price_contract(): void
    {
        // Spot margin 0.5 c/kWh, 2 EUR/month fixed
        $priceComponents = [
            ['price_component_type' => 'General', 'price' => 0.5],
            ['price_component_type' => 'Monthly', 'price' => 2.0],
        ];

        $usage = new EnergyUsage(
            total: 5000,
            basicLiving: 5000
        );

        $contract = ['contract_type' => 'Spot', 'metering' => 'General'];

        // Provide spot prices (day: 4 c/kWh, night: 3 c/kWh)
        $result = $this->calculator->calculate(
            $priceComponents,
            $contract,
            $usage,
            spotPriceDay: 4.0,
            spotPriceNight: 3.0
        );

        // Spot margin is set
        $this->assertEquals(0.5, $result->spotPriceMargin);

        // Spot contracts use time-based metering
        // Effective day rate: 4 + 0.5 = 4.5 c/kWh
        // Effective night rate: 3 + 0.5 = 3.5 c/kWh
        // Monthly usage: 416.67 kWh
        // Day usage: 354.17 kWh * 4.5 c = 1593.75 c
        // Night usage: 62.5 kWh * 3.5 c = 218.75 c
        // Monthly energy cost: 1812.5 c = 18.125 EUR + 2 EUR fixed = 20.125 EUR
        // Annual: 20.125 * 12 = 241.5 EUR
        $this->assertEqualsWithDelta(241.5, $result->totalCost, 0.5);
    }

    /**
     * Test electricity vehicle usage (90% night time).
     */
    public function test_electricity_vehicle_usage(): void
    {
        $priceComponents = [
            ['price_component_type' => 'DayTime', 'price' => 6.0],
            ['price_component_type' => 'NightTime', 'price' => 3.0],
            ['price_component_type' => 'Monthly', 'price' => 2.0],
        ];

        // 3000 kWh basic living + 2000 kWh EV
        $usage = new EnergyUsage(
            total: 5000,
            basicLiving: 3000,
            electricityVehicle: 2000
        );

        $contract = ['contract_type' => 'Fixed', 'metering' => 'Time'];

        $result = $this->calculator->calculate($priceComponents, $contract, $usage);

        // Basic living: 250 kWh/month
        //   - Day: 212.5 kWh * 6 c = 1275 c
        //   - Night: 37.5 kWh * 3 c = 112.5 c
        //   - Subtotal: 1387.5 c
        //
        // EV: 166.67 kWh/month (90% night)
        //   - Day: 16.67 kWh * 6 c = 100 c
        //   - Night: 150 kWh * 3 c = 450 c
        //   - Subtotal: 550 c
        //
        // Monthly total: 1937.5 c = 19.375 EUR + 2 EUR fixed = 21.375 EUR
        // Annual: 21.375 * 12 = 256.5 EUR
        $this->assertEqualsWithDelta(256.5, $result->totalCost, 0.5);
    }

    /**
     * Test water heating usage (90% night time).
     */
    public function test_water_heating_usage(): void
    {
        $priceComponents = [
            ['price_component_type' => 'DayTime', 'price' => 6.0],
            ['price_component_type' => 'NightTime', 'price' => 3.0],
            ['price_component_type' => 'Monthly', 'price' => 0.0],
        ];

        // 1200 kWh water heating only
        $usage = new EnergyUsage(
            total: 1200,
            basicLiving: 0,
            water: 1200
        );

        $contract = ['contract_type' => 'Fixed', 'metering' => 'Time'];

        $result = $this->calculator->calculate($priceComponents, $contract, $usage);

        // Water heating: 100 kWh/month (90% night)
        //   - Day: 10 kWh * 6 c = 60 c
        //   - Night: 90 kWh * 3 c = 270 c
        //   - Subtotal: 330 c = 3.30 EUR
        // Annual: 3.30 * 12 = 39.60 EUR
        $this->assertEqualsWithDelta(39.60, $result->totalCost, 0.01);
    }

    /**
     * Test sauna usage (0% night time - daytime only).
     */
    public function test_sauna_usage(): void
    {
        $priceComponents = [
            ['price_component_type' => 'DayTime', 'price' => 6.0],
            ['price_component_type' => 'NightTime', 'price' => 3.0],
            ['price_component_type' => 'Monthly', 'price' => 0.0],
        ];

        // 600 kWh sauna only
        $usage = new EnergyUsage(
            total: 600,
            basicLiving: 0,
            sauna: 600
        );

        $contract = ['contract_type' => 'Fixed', 'metering' => 'Time'];

        $result = $this->calculator->calculate($priceComponents, $contract, $usage);

        // Sauna: 50 kWh/month (0% night - all daytime)
        //   - Day: 50 kWh * 6 c = 300 c = 3 EUR
        // Annual: 3 * 12 = 36 EUR
        $this->assertEqualsWithDelta(36.0, $result->totalCost, 0.01);
    }

    /**
     * Test cooling usage (only June-August, daytime only).
     */
    public function test_cooling_usage(): void
    {
        $priceComponents = [
            ['price_component_type' => 'General', 'price' => 5.0],
            ['price_component_type' => 'Monthly', 'price' => 0.0],
        ];

        // 300 kWh cooling only
        $usage = new EnergyUsage(
            total: 300,
            basicLiving: 0,
            cooling: 300
        );

        $contract = ['contract_type' => 'Fixed', 'metering' => 'General'];

        $result = $this->calculator->calculate($priceComponents, $contract, $usage);

        // Cooling: 300 kWh total over 3 months (100 kWh/month in June, July, August)
        //   - 100 kWh * 5 c = 500 c = 5 EUR per month
        //   - Annual: 5 * 3 = 15 EUR
        $this->assertEqualsWithDelta(15.0, $result->totalCost, 0.01);

        // Verify cooling only appears in summer months
        $this->assertEquals(0.0, $result->monthlyCosts[0]); // January
        $this->assertEquals(0.0, $result->monthlyCosts[4]); // May
        $this->assertGreaterThan(0, $result->monthlyCosts[5]); // June
        $this->assertGreaterThan(0, $result->monthlyCosts[6]); // July
        $this->assertGreaterThan(0, $result->monthlyCosts[7]); // August
        $this->assertEquals(0.0, $result->monthlyCosts[8]); // September
    }

    /**
     * Test room heating with monthly breakdown.
     */
    public function test_room_heating_with_monthly_breakdown(): void
    {
        $priceComponents = [
            ['price_component_type' => 'General', 'price' => 5.0],
            ['price_component_type' => 'Monthly', 'price' => 0.0],
        ];

        // Monthly heating breakdown (higher in winter)
        $monthlyHeating = [500, 450, 350, 200, 100, 50, 30, 50, 100, 200, 350, 450]; // Total: 2830 kWh

        $usage = new EnergyUsage(
            total: 2830,
            basicLiving: 0,
            roomHeating: 2830,
            heatingElectricityUseByMonth: $monthlyHeating
        );

        $contract = ['contract_type' => 'Fixed', 'metering' => 'General'];

        $result = $this->calculator->calculate($priceComponents, $contract, $usage);

        // January: 500 kWh * 5 c = 2500 c = 25 EUR
        $this->assertEqualsWithDelta(25.0, $result->monthlyCosts[0], 0.01);

        // July: 30 kWh * 5 c = 150 c = 1.5 EUR
        $this->assertEqualsWithDelta(1.5, $result->monthlyCosts[6], 0.01);

        // Total: 2830 kWh * 5 c = 14150 c = 141.5 EUR
        $this->assertEqualsWithDelta(141.5, $result->totalCost, 0.01);
    }

    /**
     * Test room heating with monthly breakdown and seasonal pricing.
     */
    public function test_room_heating_seasonal_with_monthly_breakdown(): void
    {
        $priceComponents = [
            ['price_component_type' => 'SeasonalWinterDay', 'price' => 8.0],
            ['price_component_type' => 'SeasonalOther', 'price' => 4.0],
            ['price_component_type' => 'Monthly', 'price' => 0.0],
        ];

        // Monthly heating breakdown
        $monthlyHeating = [400, 400, 300, 200, 100, 50, 30, 50, 100, 200, 300, 400]; // Total: 2530

        $usage = new EnergyUsage(
            total: 2530,
            basicLiving: 0,
            roomHeating: 2530,
            heatingElectricityUseByMonth: $monthlyHeating
        );

        $contract = ['contract_type' => 'Fixed', 'metering' => 'Seasonal'];

        $result = $this->calculator->calculate($priceComponents, $contract, $usage);

        // January (winter month): 400 kWh
        //   - Day (67%): 268 kWh * 8 c = 2144 c
        //   - Night (33%): 132 kWh * 4 c = 528 c
        //   - Total: 2672 c = 26.72 EUR
        $this->assertEqualsWithDelta(26.72, $result->monthlyCosts[0], 0.1);

        // July (summer month): 30 kWh
        //   - All at other rate: 30 kWh * 4 c = 120 c = 1.2 EUR
        $this->assertEqualsWithDelta(1.2, $result->monthlyCosts[6], 0.01);
    }

    /**
     * Test abnormally low price detection (should be treated as spot).
     */
    public function test_low_price_treated_as_spot(): void
    {
        // General rate of 0.5 c/kWh is abnormally low (< 0.8)
        // Should be treated as spot margin
        $priceComponents = [
            ['price_component_type' => 'General', 'price' => 0.5],
            ['price_component_type' => 'Monthly', 'price' => 2.0],
        ];

        $usage = new EnergyUsage(
            total: 5000,
            basicLiving: 5000
        );

        // Not marked as Spot, but low price
        $contract = ['contract_type' => 'Fixed', 'metering' => 'General'];

        // Provide spot prices
        $result = $this->calculator->calculate(
            $priceComponents,
            $contract,
            $usage,
            spotPriceDay: 4.0,
            spotPriceNight: 3.0
        );

        // Should detect and use spot pricing
        $this->assertEquals(0.5, $result->spotPriceMargin);
    }

    /**
     * Test combined usage scenario.
     */
    public function test_combined_usage_scenario(): void
    {
        $priceComponents = [
            ['price_component_type' => 'General', 'price' => 5.0],
            ['price_component_type' => 'Monthly', 'price' => 3.0],
        ];

        // Realistic household consumption
        $usage = new EnergyUsage(
            total: 15000,
            basicLiving: 5000,
            roomHeating: 8000,
            water: 1000,
            sauna: 500,
            electricityVehicle: 500
        );

        $contract = ['contract_type' => 'Fixed', 'metering' => 'General'];

        $result = $this->calculator->calculate($priceComponents, $contract, $usage);

        // With general metering, all at 5 c/kWh
        // 15000 kWh * 5 c = 75000 c = 750 EUR energy
        // 3 EUR * 12 = 36 EUR fixed
        // Total: 786 EUR
        $this->assertEqualsWithDelta(786.0, $result->totalCost, 0.01);
        $this->assertCount(12, $result->monthlyCosts);
    }

    /**
     * Test that monthly costs array always has 12 elements.
     */
    public function test_monthly_costs_has_twelve_elements(): void
    {
        $priceComponents = [
            ['price_component_type' => 'General', 'price' => 5.0],
            ['price_component_type' => 'Monthly', 'price' => 2.0],
        ];

        $usage = new EnergyUsage(total: 1000, basicLiving: 1000);
        $contract = ['contract_type' => 'Fixed', 'metering' => 'General'];

        $result = $this->calculator->calculate($priceComponents, $contract, $usage);

        $this->assertCount(12, $result->monthlyCosts);
    }

    /**
     * Test zero consumption scenario.
     */
    public function test_zero_consumption(): void
    {
        $priceComponents = [
            ['price_component_type' => 'General', 'price' => 5.0],
            ['price_component_type' => 'Monthly', 'price' => 3.0],
        ];

        $usage = new EnergyUsage(total: 0, basicLiving: 0);
        $contract = ['contract_type' => 'Fixed', 'metering' => 'General'];

        $result = $this->calculator->calculate($priceComponents, $contract, $usage);

        // Only fixed costs
        $this->assertEquals(36.0, $result->totalCost);
        $this->assertEquals(3.0, $result->avgMonthlyCost);
    }

    /**
     * Test cooling with seasonal pricing uses other rate (summer).
     */
    public function test_cooling_uses_seasonal_other_rate(): void
    {
        $priceComponents = [
            ['price_component_type' => 'SeasonalWinterDay', 'price' => 10.0],
            ['price_component_type' => 'SeasonalOther', 'price' => 5.0],
            ['price_component_type' => 'Monthly', 'price' => 0.0],
        ];

        // 300 kWh cooling
        $usage = new EnergyUsage(
            total: 300,
            basicLiving: 0,
            cooling: 300
        );

        $contract = ['contract_type' => 'Fixed', 'metering' => 'Seasonal'];

        $result = $this->calculator->calculate($priceComponents, $contract, $usage);

        // Cooling happens in summer, so use seasonal_other rate
        // 300 kWh / 3 months = 100 kWh/month
        // 100 kWh * 5 c = 500 c = 5 EUR per month
        // 3 months * 5 EUR = 15 EUR
        $this->assertEqualsWithDelta(15.0, $result->totalCost, 0.01);
    }

    /**
     * Test cooling with time-based pricing uses day rate.
     */
    public function test_cooling_uses_time_day_rate(): void
    {
        $priceComponents = [
            ['price_component_type' => 'DayTime', 'price' => 6.0],
            ['price_component_type' => 'NightTime', 'price' => 3.0],
            ['price_component_type' => 'Monthly', 'price' => 0.0],
        ];

        // 300 kWh cooling
        $usage = new EnergyUsage(
            total: 300,
            basicLiving: 0,
            cooling: 300
        );

        $contract = ['contract_type' => 'Fixed', 'metering' => 'Time'];

        $result = $this->calculator->calculate($priceComponents, $contract, $usage);

        // Cooling happens during daytime
        // 300 kWh / 3 months = 100 kWh/month
        // 100 kWh * 6 c = 600 c = 6 EUR per month
        // 3 months * 6 EUR = 18 EUR
        $this->assertEqualsWithDelta(18.0, $result->totalCost, 0.01);
    }

    /**
     * Test bathroom underfloor heating (uses default night share).
     */
    public function test_bathroom_underfloor_heating(): void
    {
        $priceComponents = [
            ['price_component_type' => 'DayTime', 'price' => 6.0],
            ['price_component_type' => 'NightTime', 'price' => 4.0],
            ['price_component_type' => 'Monthly', 'price' => 0.0],
        ];

        // 1200 kWh bathroom underfloor heating
        $usage = new EnergyUsage(
            total: 1200,
            basicLiving: 0,
            bathroomUnderfloorHeating: 1200
        );

        $contract = ['contract_type' => 'Fixed', 'metering' => 'Time'];

        $result = $this->calculator->calculate($priceComponents, $contract, $usage);

        // Bathroom heating uses default night share (15%)
        // 100 kWh/month
        //   - Day: 85 kWh * 6 c = 510 c
        //   - Night: 15 kWh * 4 c = 60 c
        //   - Total: 570 c = 5.70 EUR
        // Annual: 5.70 * 12 = 68.40 EUR
        $this->assertEqualsWithDelta(68.40, $result->totalCost, 0.01);
    }
}
