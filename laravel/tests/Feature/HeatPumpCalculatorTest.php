<?php

namespace Tests\Feature;

use App\Livewire\HeatPumpCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class HeatPumpCalculatorTest extends TestCase
{
    use RefreshDatabase;

    public function test_heat_pump_calculator_page_loads(): void
    {
        $this->get('/lampopumput/laskuri')
            ->assertStatus(200)
            ->assertSeeLivewire(HeatPumpCalculator::class);
    }

    public function test_heat_pump_calculator_parent_redirects_to_calculator(): void
    {
        $this->get('/lampopumput')
            ->assertRedirect('/lampopumput/laskuri');
    }

    public function test_heat_pump_calculator_has_correct_title(): void
    {
        $this->get('/lampopumput/laskuri')
            ->assertStatus(200)
            ->assertSee('Lämpöpumppulaskuri');
    }

    public function test_component_renders_with_default_values(): void
    {
        Livewire::test(HeatPumpCalculator::class)
            ->assertSet('livingArea', 150)
            ->assertSet('roomHeight', 2.5)
            ->assertSet('buildingRegion', 'central')
            ->assertSet('buildingEnergyEfficiency', '2000')
            ->assertSet('numPeople', 4)
            ->assertSet('inputMode', 'model_based')
            ->assertSet('currentHeatingMethod', 'electricity');
    }

    public function test_model_based_calculation_works(): void
    {
        $component = Livewire::test(HeatPumpCalculator::class)
            ->assertSet('inputMode', 'model_based')
            ->set('livingArea', 150)
            ->set('buildingRegion', 'central')
            ->set('buildingEnergyEfficiency', '2000')
            ->set('numPeople', 4);

        // Should have results
        $this->assertTrue($component->get('hasResults'));
        $this->assertNotEmpty($component->get('currentSystem'));
        $this->assertNotEmpty($component->get('alternatives'));

        // Should see current system label
        $component->assertSee('Suora sähkölämmitys');
    }

    public function test_bill_based_calculation_works_for_electricity(): void
    {
        $component = Livewire::test(HeatPumpCalculator::class)
            ->set('inputMode', 'bill_based')
            ->set('currentHeatingMethod', 'electricity')
            ->set('electricityKwhPerYear', 20000);

        // Should have results
        $this->assertTrue($component->get('hasResults'));

        // Heating energy need should match the input
        $this->assertEquals(20000, $component->get('heatingEnergyNeed'));
    }

    public function test_bill_based_calculation_works_for_oil(): void
    {
        $component = Livewire::test(HeatPumpCalculator::class)
            ->set('inputMode', 'bill_based')
            ->set('currentHeatingMethod', 'oil')
            ->set('oilLitersPerYear', 2000);

        // Should have results
        $this->assertTrue($component->get('hasResults'));

        // Heating energy need should be: 2000 liters × 10 kWh/l × 0.75 = 15000 kWh
        $this->assertEquals(15000, $component->get('heatingEnergyNeed'));
    }

    public function test_bill_based_calculation_works_for_district_heating(): void
    {
        $component = Livewire::test(HeatPumpCalculator::class)
            ->set('inputMode', 'bill_based')
            ->set('currentHeatingMethod', 'district_heating')
            ->set('districtHeatingEurosPerYear', 1620)
            ->set('districtHeatingPrice', 10.8);

        // Should have results
        $this->assertTrue($component->get('hasResults'));

        // Heating energy need should be: 1620 / 0.108 = 15000 kWh
        $this->assertEqualsWithDelta(15000, $component->get('heatingEnergyNeed'), 1);
    }

    public function test_changing_heating_method_updates_results(): void
    {
        $component = Livewire::test(HeatPumpCalculator::class)
            ->set('currentHeatingMethod', 'electricity');

        $electricCost = $component->get('currentSystem')['annualCost'];

        $component->set('currentHeatingMethod', 'oil');
        $oilCost = $component->get('currentSystem')['annualCost'];

        // Costs should be different for different heating methods
        $this->assertNotEquals($electricCost, $oilCost);
    }

    public function test_changing_living_area_updates_results(): void
    {
        $component = Livewire::test(HeatPumpCalculator::class)
            ->set('livingArea', 100);

        $smallAreaEnergy = $component->get('heatingEnergyNeed');

        $component->set('livingArea', 200);
        $largeAreaEnergy = $component->get('heatingEnergyNeed');

        // Larger area should have higher energy need
        $this->assertGreaterThan($smallAreaEnergy, $largeAreaEnergy);
    }

    public function test_changing_region_updates_results(): void
    {
        $component = Livewire::test(HeatPumpCalculator::class)
            ->set('buildingRegion', 'south');

        $southEnergy = $component->get('heatingEnergyNeed');

        $component->set('buildingRegion', 'north');
        $northEnergy = $component->get('heatingEnergyNeed');

        // North region should have higher energy need
        $this->assertGreaterThan($southEnergy, $northEnergy);
    }

    public function test_advanced_settings_toggle_works(): void
    {
        $component = Livewire::test(HeatPumpCalculator::class)
            ->assertSet('showAdvancedSettings', false)
            ->call('toggleAdvancedSettings')
            ->assertSet('showAdvancedSettings', true)
            ->call('toggleAdvancedSettings')
            ->assertSet('showAdvancedSettings', false);
    }

    public function test_changing_electricity_price_updates_results(): void
    {
        $component = Livewire::test(HeatPumpCalculator::class)
            ->set('electricityPrice', 10);

        $lowPriceCost = $component->get('currentSystem')['annualCost'];

        $component->set('electricityPrice', 20);
        $highPriceCost = $component->get('currentSystem')['annualCost'];

        // Higher electricity price should result in higher cost
        $this->assertGreaterThan($lowPriceCost, $highPriceCost);
    }

    public function test_alternatives_are_sorted_by_annualized_cost(): void
    {
        $component = Livewire::test(HeatPumpCalculator::class);

        $alternatives = $component->get('alternatives');
        $previousCost = 0;

        foreach ($alternatives as $alternative) {
            $this->assertGreaterThanOrEqual($previousCost, $alternative['annualizedTotalCost']);
            $previousCost = $alternative['annualizedTotalCost'];
        }
    }

    public function test_custom_investment_is_used(): void
    {
        $component = Livewire::test(HeatPumpCalculator::class)
            ->call('updateInvestment', 'ground_source_hp', 25000);

        $alternatives = $component->get('alternatives');
        $groundSource = collect($alternatives)->firstWhere('key', 'ground_source_hp');

        $this->assertEquals(25000, $groundSource['investment']);
    }

    public function test_validation_shows_error_for_invalid_living_area(): void
    {
        Livewire::test(HeatPumpCalculator::class)
            ->set('livingArea', 10) // Too small
            ->assertSet('errorMessage', 'Pinta-ala pitää olla välillä 20-500 m².');
    }

    public function test_bill_based_mode_requires_consumption_input(): void
    {
        Livewire::test(HeatPumpCalculator::class)
            ->set('inputMode', 'bill_based')
            ->set('currentHeatingMethod', 'electricity')
            ->set('electricityKwhPerYear', null)
            ->assertSet('errorMessage', 'Syötä sähkönkulutus kWh.');
    }

    public function test_route_is_named_correctly(): void
    {
        $this->assertEquals('/lampopumput/laskuri', route('heatpump.calculator', [], false));
    }

    public function test_page_contains_faq_schema(): void
    {
        $this->get('/lampopumput/laskuri')
            ->assertStatus(200)
            ->assertSee('"@type": "FAQPage"', false);
    }

    public function test_page_contains_results_section_with_alternatives(): void
    {
        // Default values should produce results
        $this->get('/lampopumput/laskuri')
            ->assertStatus(200)
            ->assertSee('Maalämpöpumppu')
            ->assertSee('Ilma-vesilämpöpumppu')
            ->assertSee('Ilmalämpöpumppu');
    }

    public function test_computed_properties_work_correctly(): void
    {
        $component = Livewire::test(HeatPumpCalculator::class);

        $this->assertTrue($component->get('hasResults'));
        $this->assertNotEmpty($component->get('currentSystem'));
        $this->assertNotEmpty($component->get('alternatives'));
        $this->assertGreaterThan(0, $component->get('totalEnergyNeed'));
        $this->assertGreaterThan(0, $component->get('heatingEnergyNeed'));
        $this->assertGreaterThan(0, $component->get('hotWaterEnergyNeed'));
    }
}
