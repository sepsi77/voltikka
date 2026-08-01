<?php

namespace Tests\Feature;

use App\Livewire\SolarCalculator;
use App\Models\SpotPriceAverage;
use App\Services\DTO\SolarEstimateResult;
use App\Services\SolarCalculatorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class SolarCalculatorSavingsTest extends TestCase
{
    use RefreshDatabase;

    private function mockSolarCalculation(float $annualKwh = 5000.0): void
    {
        $mockResult = new SolarEstimateResult(
            annual_kwh: $annualKwh,
            monthly_kwh: array_fill(0, 12, $annualKwh / 12),
            assumptions: ['tilt' => 35, 'azimuth' => 0, 'loss_percent' => 14],
        );

        $this->mock(SolarCalculatorService::class)
            ->shouldReceive('calculate')
            ->andReturn($mockResult);
    }

    // =========================================================================
    // Component Initialization Tests
    // =========================================================================

    public function test_component_initializes_with_spot_price_average(): void
    {
        // Create spot price average data
        SpotPriceAverage::create([
            'region' => 'FI',
            'period_type' => SpotPriceAverage::PERIOD_ROLLING_365D,
            'period_start' => now()->subDays(365),
            'period_end' => now(),
            'avg_price_with_tax' => 7.85,
            'avg_price_without_tax' => 6.28,
            'hours_count' => 8760,
        ]);

        $component = Livewire::test(SolarCalculator::class);

        $this->assertEqualsWithDelta(7.85, $component->get('manualPrice'), 0.01);
    }

    public function test_component_uses_fallback_price_when_no_spot_data(): void
    {
        // No spot price data in database
        $component = Livewire::test(SolarCalculator::class);

        // Should use fallback of 8.0 c/kWh
        $this->assertEquals(8.0, $component->get('manualPrice'));
    }

    public function test_component_has_default_self_consumption(): void
    {
        $component = Livewire::test(SolarCalculator::class);

        // Default scenario is 'with_battery' which has 70% self-consumption
        $component->assertSet('selfConsumptionScenario', 'with_battery');
        $this->assertEquals(70, $component->get('selfConsumptionPercent'));
    }

    // =========================================================================
    // Self-Consumption Slider Tests
    // =========================================================================

    public function test_self_consumption_defaults_to_with_battery_scenario(): void
    {
        $component = Livewire::test(SolarCalculator::class);

        // Default scenario is 'with_battery' = 70%
        $component->assertSet('selfConsumptionScenario', 'with_battery');
        $this->assertEquals(70, $component->get('selfConsumptionPercent'));
    }

    public function test_self_consumption_can_be_adjusted(): void
    {
        $component = Livewire::test(SolarCalculator::class)
            ->set('selfConsumptionScenario', 'no_battery');

        // 'no_battery' scenario = 30%
        $this->assertEquals(30, $component->get('selfConsumptionPercent'));

        $component->set('selfConsumptionScenario', 'smart_system');

        // 'smart_system' scenario = 90%
        $this->assertEquals(90, $component->get('selfConsumptionPercent'));
    }

    // =========================================================================
    // Manual Price Entry Tests
    // =========================================================================

    public function test_manual_price_can_be_changed(): void
    {
        Livewire::test(SolarCalculator::class)
            ->set('manualPrice', 12.5)
            ->assertSet('manualPrice', 12.5);
    }

    public function test_negative_manual_price_is_corrected_with_a_visible_notice(): void
    {
        Livewire::test(SolarCalculator::class)
            ->set('manualPrice', -5)
            ->assertSet('manualPrice', 0.0)
            ->assertSet('manualPriceNotice', 'Sähkön hinnan pitää olla välillä 0–50 c/kWh. Käytämme lähintä sallittua arvoa.')
            ->assertSee('Sähkön hinnan pitää olla välillä 0–50 c/kWh. Käytämme lähintä sallittua arvoa.');
    }

    public function test_effective_price_returns_manual_price(): void
    {
        $component = Livewire::test(SolarCalculator::class)
            ->set('manualPrice', 9.5);

        $this->assertEqualsWithDelta(9.5, $component->get('effectivePrice'), 0.01);
    }

    // =========================================================================
    // Savings Calculation Tests
    // =========================================================================

    public function test_savings_calculated_with_manual_price(): void
    {
        $this->mockSolarCalculation(5000.0);

        $component = Livewire::test(SolarCalculator::class)
            ->call('selectAddress', 'Test Address', 60.0, 25.0)
            ->set('manualPrice', 10.0) // 10 c/kWh
            ->set('selfConsumptionScenario', 'no_battery'); // 30%

        // 5000 kWh * 30% self-consumption * 10 c/kWh / 100 = 150 EUR
        $savings = $component->get('annualSavings');
        $this->assertEqualsWithDelta(150.0, $savings, 0.01);
    }

    public function test_savings_calculated_with_different_self_consumption(): void
    {
        $this->mockSolarCalculation(5000.0);

        $component = Livewire::test(SolarCalculator::class)
            ->call('selectAddress', 'Test Address', 60.0, 25.0)
            ->set('manualPrice', 8.0) // 8 c/kWh
            ->set('selfConsumptionScenario', 'with_battery'); // 70%

        // 5000 kWh * 70% self-consumption * 8 c/kWh / 100 = 280 EUR
        $savings = $component->get('annualSavings');
        $this->assertEqualsWithDelta(280.0, $savings, 0.01);
    }

    public function test_savings_zero_without_production_results(): void
    {
        $component = Livewire::test(SolarCalculator::class)
            ->set('manualPrice', 10.0);

        // No address selected, no production results
        $savings = $component->get('annualSavings');
        $this->assertEquals(0, $savings);
    }

    public function test_savings_zero_when_price_is_null(): void
    {
        $this->mockSolarCalculation(5000.0);

        $component = Livewire::test(SolarCalculator::class)
            ->call('selectAddress', 'Test Address', 60.0, 25.0)
            ->set('manualPrice', null);

        $savings = $component->get('annualSavings');
        $this->assertEquals(0, $savings);
    }

    public function test_savings_zero_when_price_is_zero(): void
    {
        $this->mockSolarCalculation(5000.0);

        $component = Livewire::test(SolarCalculator::class)
            ->call('selectAddress', 'Test Address', 60.0, 25.0)
            ->set('manualPrice', 0);

        // hasSavings should be false when price is 0
        $this->assertFalse($component->get('hasSavings'));
    }

    public function test_savings_recalculates_when_self_consumption_changes(): void
    {
        $this->mockSolarCalculation(5000.0);

        $component = Livewire::test(SolarCalculator::class)
            ->call('selectAddress', 'Test Address', 60.0, 25.0)
            ->set('manualPrice', 10.0)
            ->set('selfConsumptionScenario', 'no_battery'); // 30%

        // 5000 * 0.3 * 10 / 100 = 150 EUR
        $this->assertEqualsWithDelta(150.0, $component->get('annualSavings'), 0.01);

        $component->set('selfConsumptionScenario', 'smart_system'); // 90%
        // 5000 * 0.9 * 10 / 100 = 450 EUR
        $this->assertEqualsWithDelta(450.0, $component->get('annualSavings'), 0.01);
    }

    public function test_savings_recalculates_when_price_changes(): void
    {
        $this->mockSolarCalculation(5000.0);

        $component = Livewire::test(SolarCalculator::class)
            ->call('selectAddress', 'Test Address', 60.0, 25.0)
            ->set('manualPrice', 10.0)
            ->set('selfConsumptionScenario', 'no_battery'); // 30%

        // 5000 * 0.3 * 10 / 100 = 150 EUR
        $this->assertEqualsWithDelta(150.0, $component->get('annualSavings'), 0.01);

        $component->set('manualPrice', 15.0);
        // 5000 * 0.3 * 15 / 100 = 225 EUR
        $this->assertEqualsWithDelta(225.0, $component->get('annualSavings'), 0.01);
    }

    // =========================================================================
    // UI Display Tests
    // =========================================================================

    public function test_savings_section_displayed_when_results_exist(): void
    {
        $this->mockSolarCalculation(5000.0);

        Livewire::test(SolarCalculator::class)
            ->call('selectAddress', 'Test Address', 60.0, 25.0)
            ->set('manualPrice', 10.0)
            ->assertSee('Arvioitu säästö')
            ->assertSee('350'); // 350 EUR savings (5000 * 0.70 * 10 / 100) - default scenario is 'with_battery' (70%)
    }

    public function test_self_consumption_scenarios_displayed(): void
    {
        Livewire::test(SolarCalculator::class)
            ->assertSee('Oman käytön osuus')
            ->assertSee('Akun kanssa'); // Default scenario label ('with_battery')
    }

    public function test_price_input_displayed(): void
    {
        Livewire::test(SolarCalculator::class)
            ->assertSee('Sähkön hinta');
    }

    public function test_spot_price_hint_text_displayed(): void
    {
        Livewire::test(SolarCalculator::class)
            ->assertSee('spot-hinnan keskiarvo');
    }

    // =========================================================================
    // Computed Property Tests
    // =========================================================================

    public function test_has_savings_computed_property(): void
    {
        $this->mockSolarCalculation(5000.0);

        $component = Livewire::test(SolarCalculator::class)
            ->call('selectAddress', 'Test Address', 60.0, 25.0)
            ->set('manualPrice', 10.0);

        $this->assertTrue($component->get('hasSavings'));
    }

    public function test_has_savings_false_without_results(): void
    {
        $component = Livewire::test(SolarCalculator::class)
            ->set('manualPrice', 10.0);

        // No address selected, no results
        $this->assertFalse($component->get('hasSavings'));
    }

    public function test_has_savings_false_without_price(): void
    {
        $this->mockSolarCalculation(5000.0);

        $component = Livewire::test(SolarCalculator::class)
            ->call('selectAddress', 'Test Address', 60.0, 25.0)
            ->set('manualPrice', null);

        $this->assertFalse($component->get('hasSavings'));
    }
}
