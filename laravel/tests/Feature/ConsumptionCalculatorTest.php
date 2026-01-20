<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ConsumptionCalculatorTest extends TestCase
{
    use RefreshDatabase;

    public function test_calculator_page_is_accessible(): void
    {
        $response = $this->get('/laskuri');

        $response->assertStatus(200);
    }

    public function test_calculator_page_renders_livewire_component(): void
    {
        $response = $this->get('/laskuri');

        $response->assertStatus(200);
        $response->assertSeeLivewire('consumption-calculator');
    }

    public function test_calculator_shows_presets_by_default(): void
    {
        Livewire::test('consumption-calculator')
            ->assertSet('activeTab', 'presets')
            ->assertSee('Valmiit profiilit')
            ->assertSee('Pieni yksiö')
            ->assertSee('Kerrostalo 2 hlö');
    }

    public function test_calculator_shows_calculator_tab_when_toggled(): void
    {
        Livewire::test('consumption-calculator')
            ->call('setActiveTab', 'calculator')
            ->assertSet('activeTab', 'calculator')
            ->assertSee('Asuntotyyppi')
            ->assertSee('Asuinpinta-ala');
    }

    public function test_selecting_preset_updates_calculation(): void
    {
        Livewire::test('consumption-calculator')
            ->call('selectPreset', 'small_apartment')
            ->assertSet('selectedPreset', 'small_apartment')
            ->assertSet('livingArea', 35)
            ->assertSet('numPeople', 1)
            ->assertSet('buildingType', 'apartment');
    }

    public function test_preset_with_heating_enables_heating_options(): void
    {
        Livewire::test('consumption-calculator')
            ->call('selectPreset', 'medium_house_heat_pump')
            ->assertSet('includeHeating', true)
            ->assertSet('heatingMethod', 'air_to_water_heat_pump');
    }

    public function test_selecting_building_type_updates_state(): void
    {
        Livewire::test('consumption-calculator')
            ->call('setActiveTab', 'calculator')
            ->call('selectBuildingType', 'detached_house')
            ->assertSet('buildingType', 'detached_house')
            ->assertSet('selectedPreset', null);
    }

    public function test_toggling_heating_shows_heating_options(): void
    {
        $component = Livewire::test('consumption-calculator')
            ->call('setActiveTab', 'calculator')
            ->assertSet('includeHeating', false)
            ->call('toggleIncludeHeating')
            ->assertSet('includeHeating', true);
    }

    public function test_changing_living_area_recalculates(): void
    {
        $component = Livewire::test('consumption-calculator')
            ->call('setActiveTab', 'calculator')
            ->set('livingArea', 100)
            ->set('numPeople', 2);

        $result = $component->get('calculationResult');

        // Basic living: 2 * 400 + 100 * 30 = 3800
        $this->assertEquals(3800, $result['basic_living']);
    }

    public function test_sauna_usage_adds_to_total(): void
    {
        $component = Livewire::test('consumption-calculator')
            ->call('setActiveTab', 'calculator')
            ->set('livingArea', 80)
            ->set('numPeople', 2)
            ->set('saunaUsagePerWeek', 2);

        $result = $component->get('calculationResult');

        // Sauna: 2 * 7.5 * 52 = 780
        $this->assertEquals(780, $result['sauna']);
    }

    public function test_electric_vehicle_adds_to_total(): void
    {
        $component = Livewire::test('consumption-calculator')
            ->call('setActiveTab', 'calculator')
            ->set('livingArea', 80)
            ->set('numPeople', 2)
            ->set('electricVehicleKmsPerMonth', 1000);

        $result = $component->get('calculationResult');

        // EV: 1000 * 0.199 * 12 = 2388
        // Note: Python code has a bug where EV is added twice
        $this->assertEquals(2388, $result['electricity_vehicle']);
    }

    public function test_cooling_adds_fixed_amount(): void
    {
        $component = Livewire::test('consumption-calculator')
            ->call('setActiveTab', 'calculator')
            ->set('livingArea', 80)
            ->set('numPeople', 2)
            ->call('toggleCooling');

        $result = $component->get('calculationResult');

        // Cooling: fixed 240 kWh
        $this->assertEquals(240, $result['cooling']);
    }

    public function test_bathroom_heating_adds_based_on_area(): void
    {
        $component = Livewire::test('consumption-calculator')
            ->call('setActiveTab', 'calculator')
            ->set('livingArea', 80)
            ->set('numPeople', 2)
            ->set('bathroomHeatingArea', 5);

        $result = $component->get('calculationResult');

        // Bathroom: 5 * 200 = 1000
        $this->assertEquals(1000, $result['bathroom_underfloor_heating']);
    }

    public function test_heating_calculation_with_electric_heat(): void
    {
        $component = Livewire::test('consumption-calculator')
            ->call('setActiveTab', 'calculator')
            ->set('livingArea', 100)
            ->set('numPeople', 4)
            ->set('buildingType', 'detached_house')
            ->call('toggleIncludeHeating')
            ->set('heatingMethod', 'electricity')
            ->set('buildingRegion', 'central')
            ->set('buildingEnergyEfficiency', '2000');

        $result = $component->get('calculationResult');

        // Should have heating and water included
        $this->assertArrayHasKey('room_heating', $result);
        $this->assertArrayHasKey('water', $result);
        $this->assertGreaterThan(5000, $result['total']);
    }

    public function test_heating_calculation_with_heat_pump(): void
    {
        $component = Livewire::test('consumption-calculator')
            ->call('setActiveTab', 'calculator')
            ->set('livingArea', 150)
            ->set('numPeople', 4)
            ->set('buildingType', 'detached_house')
            ->call('toggleIncludeHeating')
            ->set('heatingMethod', 'ground_heat_pump')
            ->set('buildingRegion', 'south')
            ->set('buildingEnergyEfficiency', '2010');

        $result = $component->get('calculationResult');

        // Heat pump reduces electricity need
        $this->assertArrayHasKey('room_heating', $result);
        // With ground heat pump (COP 2.9), heating should be lower than with direct electric
    }

    public function test_compare_contracts_redirects_with_consumption(): void
    {
        Livewire::test('consumption-calculator')
            ->set('livingArea', 80)
            ->set('numPeople', 2)
            ->call('compareContracts')
            ->assertRedirect('/?consumption=' . (2 * 400 + 80 * 30));
    }

    public function test_page_has_correct_title(): void
    {
        $response = $this->get('/laskuri');

        $response->assertSee('Sähkönkulutuslaskuri');
    }

    public function test_all_presets_have_valid_consumption_values(): void
    {
        $component = Livewire::test('consumption-calculator');
        $presets = $component->get('presets');

        foreach ($presets as $key => $preset) {
            $this->assertArrayHasKey('consumption', $preset);
            $this->assertGreaterThan(0, $preset['consumption']);
            $this->assertArrayHasKey('config', $preset);
            $this->assertArrayHasKey('livingArea', $preset['config']);
            $this->assertArrayHasKey('numPeople', $preset['config']);
        }
    }

    public function test_modifying_form_clears_preset_selection(): void
    {
        Livewire::test('consumption-calculator')
            ->call('selectPreset', 'small_apartment')
            ->assertSet('selectedPreset', 'small_apartment')
            ->set('livingArea', 50)
            ->assertSet('selectedPreset', null);
    }

    public function test_minimum_values_are_enforced(): void
    {
        $component = Livewire::test('consumption-calculator')
            ->call('setActiveTab', 'calculator')
            ->set('livingArea', 5)  // Below minimum of 10
            ->set('numPeople', 0);  // Below minimum of 1

        // The calculate method should enforce minimums
        $result = $component->get('calculationResult');
        // Basic living with minimums: 1 * 400 + 10 * 30 = 700
        $this->assertEquals(700, $result['basic_living']);
    }

    public function test_results_section_displays_breakdown(): void
    {
        Livewire::test('consumption-calculator')
            ->call('setActiveTab', 'calculator')
            ->set('livingArea', 100)
            ->set('numPeople', 2)
            ->set('saunaUsagePerWeek', 2)
            ->assertSee('Perussähkö')
            ->assertSee('Sauna');
    }

    public function test_calculator_navigation_is_in_header(): void
    {
        $response = $this->get('/');

        $response->assertSee('Laskuri');
        $response->assertSee('/laskuri');
    }
}
