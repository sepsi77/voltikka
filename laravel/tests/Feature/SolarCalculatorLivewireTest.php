<?php

namespace Tests\Feature;

use App\Livewire\SolarCalculator;
use App\Services\DigitransitGeocodingService;
use App\Services\DTO\GeocodingResult;
use App\Services\DTO\SolarEstimateResult;
use App\Services\SolarCalculatorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class SolarCalculatorLivewireTest extends TestCase
{
    use RefreshDatabase;

    public function test_solar_calculator_page_loads(): void
    {
        $this->get('/aurinkopaneelit/laskuri')
            ->assertStatus(200)
            ->assertSeeLivewire(SolarCalculator::class);
    }

    public function test_solar_calculator_parent_redirects_to_calculator(): void
    {
        $this->get('/aurinkopaneelit')
            ->assertRedirect('/aurinkopaneelit/laskuri');
    }

    public function test_solar_calculator_has_correct_title(): void
    {
        $this->get('/aurinkopaneelit/laskuri')
            ->assertStatus(200)
            ->assertSee('Aurinkopaneelilaskuri');
    }

    public function test_component_renders_with_default_values(): void
    {
        Livewire::test(SolarCalculator::class)
            ->assertSet('systemKwp', 5.0)
            ->assertSet('shadingLevel', 'none')
            ->assertSet('addressQuery', '')
            ->assertSet('selectedLat', null)
            ->assertSet('selectedLon', null);
    }

    public function test_example_heading_uses_default_system_size(): void
    {
        $this->mock(SolarCalculatorService::class)
            ->shouldReceive('calculate')
            ->once()
            ->andReturn(new SolarEstimateResult(4500.0, array_fill(0, 12, 375), []));

        Livewire::test(SolarCalculator::class)
            ->assertSee('5,0 kWp:n järjestelmä Helsingissä.');
    }

    public function test_example_heading_updates_with_changed_system_size(): void
    {
        $this->mock(SolarCalculatorService::class)
            ->shouldReceive('calculate')
            ->twice()
            ->andReturn(new SolarEstimateResult(7200.0, array_fill(0, 12, 600), []));

        Livewire::test(SolarCalculator::class)
            ->set('systemKwp', 8.0)
            ->assertSee('8,0 kWp:n järjestelmä Helsingissä.')
            ->assertDontSee('5,0 kWp:n järjestelmä Helsingissä.');
    }

    public function test_address_search_shows_suggestions(): void
    {
        $mockResults = [
            new GeocodingResult('Mannerheimintie 1, Helsinki', 60.1699, 24.9384),
            new GeocodingResult('Mannerheimintie 10, Helsinki', 60.1710, 24.9395),
        ];

        $this->mock(DigitransitGeocodingService::class)
            ->shouldReceive('search')
            ->with('Mannerheimintie')
            ->once()
            ->andReturn($mockResults);

        Livewire::test(SolarCalculator::class)
            ->set('addressQuery', 'Mannerheimintie')
            ->assertSet('showSuggestions', true)
            ->assertCount('addressSuggestions', 2);
    }

    public function test_selecting_address_triggers_calculation(): void
    {
        $mockResult = new SolarEstimateResult(
            annual_kwh: 4500.0,
            monthly_kwh: [200, 250, 350, 450, 550, 600, 580, 520, 400, 300, 200, 100],
            assumptions: ['tilt' => 35, 'azimuth' => 0, 'loss_percent' => 14],
        );

        // mount() computes the default-location (Helsinki) example, then selectAddress recalculates.
        $this->mock(SolarCalculatorService::class)
            ->shouldReceive('calculate')
            ->twice()
            ->andReturn($mockResult);

        Livewire::test(SolarCalculator::class)
            ->call('selectAddress', 'Mannerheimintie 1, Helsinki', 60.1699, 24.9384)
            ->assertSet('selectedLat', 60.1699)
            ->assertSet('selectedLon', 24.9384)
            ->assertSet('showSuggestions', false)
            ->assertSee('4 500');
    }

    public function test_changing_system_size_triggers_recalculation(): void
    {
        $mockResult = new SolarEstimateResult(
            annual_kwh: 9000.0,
            monthly_kwh: [400, 500, 700, 900, 1100, 1200, 1160, 1040, 800, 600, 400, 200],
            assumptions: ['tilt' => 35, 'azimuth' => 0, 'loss_percent' => 14],
        );

        // mount() example + selectAddress + size change.
        $this->mock(SolarCalculatorService::class)
            ->shouldReceive('calculate')
            ->times(3)
            ->andReturn($mockResult);

        Livewire::test(SolarCalculator::class)
            ->call('selectAddress', 'Test Address', 60.0, 25.0)
            ->set('systemKwp', 10.0)
            ->assertSee('9 000');
    }

    public function test_changing_shading_level_triggers_recalculation(): void
    {
        $mockResult = new SolarEstimateResult(
            annual_kwh: 4000.0,
            monthly_kwh: [180, 220, 310, 400, 490, 535, 517, 464, 357, 268, 178, 89],
            assumptions: ['tilt' => 35, 'azimuth' => 0, 'loss_percent' => 19],
        );

        // mount() example + selectAddress + shading change.
        $this->mock(SolarCalculatorService::class)
            ->shouldReceive('calculate')
            ->times(3)
            ->andReturn($mockResult);

        Livewire::test(SolarCalculator::class)
            ->call('selectAddress', 'Test Address', 60.0, 25.0)
            ->set('shadingLevel', 'some')
            ->assertSee('4 000');
    }

    public function test_clear_address_resets_state(): void
    {
        $mockResult = new SolarEstimateResult(
            annual_kwh: 4500.0,
            monthly_kwh: [200, 250, 350, 450, 550, 600, 580, 520, 400, 300, 200, 100],
            assumptions: [],
        );

        // mount() example + selectAddress.
        $this->mock(SolarCalculatorService::class)
            ->shouldReceive('calculate')
            ->twice()
            ->andReturn($mockResult);

        Livewire::test(SolarCalculator::class)
            ->call('selectAddress', 'Test Address', 60.0, 25.0)
            ->call('clearAddress')
            ->assertSet('addressQuery', '')
            ->assertSet('selectedLat', null)
            ->assertSet('selectedLon', null)
            ->assertSet('calculationResult', []);
    }

    public function test_short_address_query_does_not_search(): void
    {
        // Should not call the service for queries < 2 characters
        $this->mock(DigitransitGeocodingService::class)
            ->shouldNotReceive('search');

        Livewire::test(SolarCalculator::class)
            ->set('addressQuery', 'M')
            ->assertSet('showSuggestions', false)
            ->assertSet('addressSuggestions', []);
    }

    public function test_computed_properties_work_correctly(): void
    {
        $mockResult = new SolarEstimateResult(
            annual_kwh: 4800.0,
            monthly_kwh: [200, 250, 350, 450, 550, 600, 580, 520, 400, 300, 200, 100],
            assumptions: [],
        );

        // mount() example + selectAddress.
        $this->mock(SolarCalculatorService::class)
            ->shouldReceive('calculate')
            ->twice()
            ->andReturn($mockResult);

        $component = Livewire::test(SolarCalculator::class)
            ->call('selectAddress', 'Test Address', 60.0, 25.0);

        $this->assertTrue($component->get('hasResults'));
        $this->assertEquals(4800.0, $component->get('annualKwh'));
        $this->assertCount(12, $component->get('monthlyKwh'));
        $this->assertEquals(600, $component->get('maxMonthlyKwh'));
    }

    public function test_system_size_is_clamped_to_max(): void
    {
        // mount() example + the rebuild after the size change; both hit the mocked service.
        $this->mock(SolarCalculatorService::class)
            ->shouldReceive('calculate')
            ->andReturn(new SolarEstimateResult(4500.0, array_fill(0, 12, 375), []));

        Livewire::test(SolarCalculator::class)
            ->set('systemKwp', 30.0)
            ->assertSet('systemKwp', 20.0)
            ->assertNotSet('systemKwpNotice', null)
            ->assertSee('20,0 kWp:n järjestelmä Helsingissä.')
            ->assertDontSee('30,0 kWp:n järjestelmä Helsingissä.');
    }

    public function test_blank_system_size_is_normalized_instead_of_throwing(): void
    {
        $this->mock(SolarCalculatorService::class)
            ->shouldReceive('calculate')
            ->andReturn(new SolarEstimateResult(4500.0, array_fill(0, 12, 375), []));

        Livewire::test(SolarCalculator::class)
            ->set('systemKwp', '')
            ->assertSet('systemKwp', 0.5)
            ->assertNotSet('systemKwpNotice', null);
    }

    public function test_unknown_address_shows_notice_instead_of_silent_empty(): void
    {
        $this->mock(SolarCalculatorService::class)
            ->shouldReceive('calculate')
            ->andReturn(new SolarEstimateResult(4500.0, array_fill(0, 12, 375), []));

        $this->mock(DigitransitGeocodingService::class)
            ->shouldReceive('search')
            ->andReturn([]);

        Livewire::test(SolarCalculator::class)
            ->set('addressQuery', 'qwertyxyz')
            ->assertSet('showSuggestions', false)
            ->assertNotSet('addressNotice', null)
            ->assertSee('Osoitetta ei löytynyt');
    }

    public function test_route_is_named_correctly(): void
    {
        $this->assertEquals('/aurinkopaneelit/laskuri', route('solar.calculator', [], false));
    }
}
