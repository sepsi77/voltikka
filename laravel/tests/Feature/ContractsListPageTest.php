<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\ElectricityContract;
use App\Models\ElectricitySource;
use App\Models\PriceComponent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ContractsListPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Create test companies
        Company::create([
            'name' => 'Test Energia Oy',
            'name_slug' => 'test-energia-oy',
            'company_url' => 'https://testenergia.fi',
            'logo_url' => 'https://storage.example.com/logos/test-energia.png',
        ]);

        Company::create([
            'name' => 'Halpa Sähkö Ab',
            'name_slug' => 'halpa-sahko-ab',
            'company_url' => 'https://halpasahko.fi',
            'logo_url' => 'https://storage.example.com/logos/halpa.png',
        ]);
    }

    /**
     * Test that the contracts listing page is accessible.
     */
    public function test_contracts_page_is_accessible(): void
    {
        $response = $this->get('/');

        $response->assertStatus(200);
    }

    /**
     * Test that the contracts page displays the Livewire component.
     */
    public function test_contracts_page_renders_livewire_component(): void
    {
        $response = $this->get('/');

        $response->assertStatus(200);
        $response->assertSeeLivewire('contracts-list');
    }

    /**
     * Test that contracts are displayed on the page.
     */
    public function test_contracts_are_displayed(): void
    {
        // Create a test contract
        ElectricityContract::create([
            'id' => 'contract-1',
            'company_name' => 'Test Energia Oy',
            'name' => 'Perus Sähkö',
            'name_slug' => 'perus-sahko',
            'contract_type' => 'Fixed',
            'metering' => 'General',
            'availability_is_national' => true,
        ]);

        PriceComponent::create([
            'id' => 'pc-general-1',
            'electricity_contract_id' => 'contract-1',
            'price_component_type' => 'General',
            'price_date' => now()->format('Y-m-d'),
            'price' => 5.5,
            'payment_unit' => 'c/kWh',
        ]);

        PriceComponent::create([
            'id' => 'pc-monthly-1',
            'electricity_contract_id' => 'contract-1',
            'price_component_type' => 'Monthly',
            'price_date' => now()->format('Y-m-d'),
            'price' => 2.95,
            'payment_unit' => 'EUR/month',
        ]);

        Livewire::test('contracts-list')
            ->assertSee('Perus Sähkö')
            ->assertSee('Test Energia Oy');
    }

    /**
     * Test that the page displays company logos.
     */
    public function test_company_logos_are_displayed(): void
    {
        ElectricityContract::create([
            'id' => 'contract-1',
            'company_name' => 'Test Energia Oy',
            'name' => 'Perus Sähkö',
            'contract_type' => 'Fixed',
            'metering' => 'General',
            'availability_is_national' => true,
        ]);

        PriceComponent::create([
            'id' => 'pc-general-1',
            'electricity_contract_id' => 'contract-1',
            'price_component_type' => 'General',
            'price_date' => now()->format('Y-m-d'),
            'price' => 5.5,
            'payment_unit' => 'c/kWh',
        ]);

        Livewire::test('contracts-list')
            ->assertSeeHtml('https://storage.example.com/logos/test-energia.png');
    }

    /**
     * Test consumption preset buttons are displayed.
     */
    public function test_consumption_presets_are_displayed(): void
    {
        Livewire::test('contracts-list')
            ->assertSee('Pieni yksiö')           // 2000 kWh
            ->assertSee('Kerrostalo 2 hlö')      // 3500 kWh
            ->assertSee('Kerrostalo perhe')       // 5000 kWh
            ->assertSee('Pieni omakotitalo')     // 5000 kWh
            ->assertSee('Omakotitalo + ILP')     // 8000 kWh
            ->assertSee('Suuri talo + sähkö')    // 18000 kWh
            ->assertSee('Suuri talo + MLP');     // 12000 kWh
    }

    /**
     * Test presets/calculator tabs are displayed.
     */
    public function test_presets_calculator_tabs_are_displayed(): void
    {
        Livewire::test('contracts-list')
            ->assertSee('Valmiit profiilit')
            ->assertSee('Laskuri');
    }

    /**
     * Test selecting a preset updates consumption.
     */
    public function test_selecting_preset_updates_consumption(): void
    {
        Livewire::test('contracts-list')
            ->call('selectPreset', 'small_apartment')
            ->assertSet('consumption', 2000)
            ->assertSet('selectedPreset', 'small_apartment')
            ->call('selectPreset', 'large_house_electric')
            ->assertSet('consumption', 18000)
            ->assertSet('selectedPreset', 'large_house_electric');
    }

    /**
     * Test switching between tabs works.
     */
    public function test_tab_switching_works(): void
    {
        Livewire::test('contracts-list')
            ->assertSet('activeTab', 'presets')
            ->call('setActiveTab', 'calculator')
            ->assertSet('activeTab', 'calculator')
            ->call('setActiveTab', 'presets')
            ->assertSet('activeTab', 'presets');
    }

    /**
     * Test changing consumption updates the displayed costs.
     */
    public function test_changing_consumption_updates_costs(): void
    {
        ElectricityContract::create([
            'id' => 'contract-1',
            'company_name' => 'Test Energia Oy',
            'name' => 'Perus Sähkö',
            'contract_type' => 'Fixed',
            'metering' => 'General',
            'availability_is_national' => true,
        ]);

        PriceComponent::create([
            'id' => 'pc-general-1',
            'electricity_contract_id' => 'contract-1',
            'price_component_type' => 'General',
            'price_date' => now()->format('Y-m-d'),
            'price' => 5.5,
            'payment_unit' => 'c/kWh',
        ]);

        PriceComponent::create([
            'id' => 'pc-monthly-1',
            'electricity_contract_id' => 'contract-1',
            'price_component_type' => 'Monthly',
            'price_date' => now()->format('Y-m-d'),
            'price' => 2.95,
            'payment_unit' => 'EUR/month',
        ]);

        // Default consumption (5000 kWh)
        // Total = (5.5 * 5000 / 100) + (2.95 * 12) = 275 + 35.4 = 310.4 EUR/year

        // Change to 10000 kWh
        // Total = (5.5 * 10000 / 100) + (2.95 * 12) = 550 + 35.4 = 585.4 EUR/year
        Livewire::test('contracts-list')
            ->set('consumption', 10000)
            ->assertSee('585'); // Approximate match for the annual cost
    }

    /**
     * Test that contracts are sorted by annual cost.
     */
    public function test_contracts_are_sorted_by_cost(): void
    {
        // Create a more expensive contract first
        ElectricityContract::create([
            'id' => 'expensive-contract',
            'company_name' => 'Test Energia Oy',
            'name' => 'Kallis Sähkö',
            'contract_type' => 'Fixed',
            'metering' => 'General',
            'availability_is_national' => true,
        ]);

        PriceComponent::create([
            'id' => 'pc-general-expensive',
            'electricity_contract_id' => 'expensive-contract',
            'price_component_type' => 'General',
            'price_date' => now()->format('Y-m-d'),
            'price' => 10.0, // Higher price
            'payment_unit' => 'c/kWh',
        ]);

        PriceComponent::create([
            'id' => 'pc-monthly-expensive',
            'electricity_contract_id' => 'expensive-contract',
            'price_component_type' => 'Monthly',
            'price_date' => now()->format('Y-m-d'),
            'price' => 5.0,
            'payment_unit' => 'EUR/month',
        ]);

        // Create a cheaper contract second
        ElectricityContract::create([
            'id' => 'cheap-contract',
            'company_name' => 'Halpa Sähkö Ab',
            'name' => 'Halpa Sähkö',
            'contract_type' => 'Fixed',
            'metering' => 'General',
            'availability_is_national' => true,
        ]);

        PriceComponent::create([
            'id' => 'pc-general-cheap',
            'electricity_contract_id' => 'cheap-contract',
            'price_component_type' => 'General',
            'price_date' => now()->format('Y-m-d'),
            'price' => 4.0, // Lower price
            'payment_unit' => 'c/kWh',
        ]);

        PriceComponent::create([
            'id' => 'pc-monthly-cheap',
            'electricity_contract_id' => 'cheap-contract',
            'price_component_type' => 'Monthly',
            'price_date' => now()->format('Y-m-d'),
            'price' => 2.0,
            'payment_unit' => 'EUR/month',
        ]);

        $component = Livewire::test('contracts-list');

        // Get the contracts from the component
        $contracts = $component->viewData('contracts');

        // Verify the cheaper contract is first
        $this->assertEquals('cheap-contract', $contracts->first()->id);
        $this->assertEquals('expensive-contract', $contracts->last()->id);
    }

    /**
     * Test that energy source badges are displayed.
     */
    public function test_energy_source_badges_are_displayed(): void
    {
        ElectricityContract::create([
            'id' => 'contract-1',
            'company_name' => 'Test Energia Oy',
            'name' => 'Vihreä Sähkö',
            'contract_type' => 'Fixed',
            'metering' => 'General',
            'availability_is_national' => true,
        ]);

        PriceComponent::create([
            'id' => 'pc-general-1',
            'electricity_contract_id' => 'contract-1',
            'price_component_type' => 'General',
            'price_date' => now()->format('Y-m-d'),
            'price' => 5.5,
            'payment_unit' => 'c/kWh',
        ]);

        ElectricitySource::create([
            'contract_id' => 'contract-1',
            'renewable_total' => 100.0,
            'wind_power' => 60.0,
            'solar_power' => 20.0,
            'hydropower' => 20.0,
            'nuclear_total' => 0.0,
            'fossil_total' => 0.0,
        ]);

        Livewire::test('contracts-list')
            ->assertSee('100%') // Renewable percentage
            ->assertSee('Vihreä Sähkö');
    }

    /**
     * Test that price breakdown is shown.
     */
    public function test_price_breakdown_is_shown(): void
    {
        ElectricityContract::create([
            'id' => 'contract-1',
            'company_name' => 'Test Energia Oy',
            'name' => 'Perus Sähkö',
            'contract_type' => 'Fixed',
            'metering' => 'General',
            'availability_is_national' => true,
        ]);

        PriceComponent::create([
            'id' => 'pc-general-1',
            'electricity_contract_id' => 'contract-1',
            'price_component_type' => 'General',
            'price_date' => now()->format('Y-m-d'),
            'price' => 5.5,
            'payment_unit' => 'c/kWh',
        ]);

        PriceComponent::create([
            'id' => 'pc-monthly-1',
            'electricity_contract_id' => 'contract-1',
            'price_component_type' => 'Monthly',
            'price_date' => now()->format('Y-m-d'),
            'price' => 2.95,
            'payment_unit' => 'EUR/month',
        ]);

        Livewire::test('contracts-list')
            ->assertSee('5,5') // Price in c/kWh (Finnish format)
            ->assertSee('2,95'); // Monthly fee (Finnish format)
    }

    /**
     * Test that contract type is displayed.
     */
    public function test_contract_type_is_displayed(): void
    {
        ElectricityContract::create([
            'id' => 'spot-contract',
            'company_name' => 'Test Energia Oy',
            'name' => 'Spot Sähkö',
            'contract_type' => 'Spot',
            'metering' => 'General',
            'availability_is_national' => true,
        ]);

        PriceComponent::create([
            'id' => 'pc-general-1',
            'electricity_contract_id' => 'spot-contract',
            'price_component_type' => 'General',
            'price_date' => now()->format('Y-m-d'),
            'price' => 0.5, // Spot margin
            'payment_unit' => 'c/kWh',
        ]);

        Livewire::test('contracts-list')
            ->assertSee('Spot'); // Contract type label
    }

    /**
     * Test clicking a preset button changes consumption.
     */
    public function test_clicking_preset_changes_consumption(): void
    {
        Livewire::test('contracts-list')
            ->call('setConsumption', 2000)
            ->assertSet('consumption', 2000)
            ->call('setConsumption', 18000)
            ->assertSet('consumption', 18000);
    }

    /**
     * Test the page has correct title.
     */
    public function test_page_has_correct_title(): void
    {
        $response = $this->get('/');

        $response->assertSee('Voltikka'); // App name in title or header
    }

    /**
     * Test that postcode filter works without eager loading all availability postcodes.
     * This verifies the memory optimization - filtering by postcode should use an exists
     * query rather than loading all pivot records.
     */
    public function test_postcode_filter_works_without_eager_loading_all_postcodes(): void
    {
        // Create test postcodes
        \App\Models\Postcode::create([
            'postcode' => '00100',
            'postcode_fi_name' => 'Helsinki',
            'postcode_fi_name_slug' => 'helsinki',
            'postcode_sv_name' => 'Helsingfors',
            'postcode_sv_name_slug' => 'helsingfors',
            'municipal_name_fi' => 'Helsinki',
            'municipal_name_fi_slug' => 'helsinki',
            'municipal_name_sv' => 'Helsingfors',
            'municipal_name_sv_slug' => 'helsingfors',
        ]);

        \App\Models\Postcode::create([
            'postcode' => '33100',
            'postcode_fi_name' => 'Tampere',
            'postcode_fi_name_slug' => 'tampere',
            'postcode_sv_name' => 'Tammerfors',
            'postcode_sv_name_slug' => 'tammerfors',
            'municipal_name_fi' => 'Tampere',
            'municipal_name_fi_slug' => 'tampere',
            'municipal_name_sv' => 'Tammerfors',
            'municipal_name_sv_slug' => 'tammerfors',
        ]);

        // Create national contract (available everywhere)
        ElectricityContract::create([
            'id' => 'national-contract',
            'company_name' => 'Test Energia Oy',
            'name' => 'Valtakunnallinen Sähkö',
            'contract_type' => 'Fixed',
            'metering' => 'General',
            'availability_is_national' => true,
        ]);

        PriceComponent::create([
            'id' => 'pc-national',
            'electricity_contract_id' => 'national-contract',
            'price_component_type' => 'General',
            'price_date' => now()->format('Y-m-d'),
            'price' => 5.0,
            'payment_unit' => 'c/kWh',
        ]);

        // Create local contract (only in Helsinki)
        ElectricityContract::create([
            'id' => 'helsinki-contract',
            'company_name' => 'Test Energia Oy',
            'name' => 'Helsinki Sähkö',
            'contract_type' => 'Fixed',
            'metering' => 'General',
            'availability_is_national' => false,
        ]);

        PriceComponent::create([
            'id' => 'pc-helsinki',
            'electricity_contract_id' => 'helsinki-contract',
            'price_component_type' => 'General',
            'price_date' => now()->format('Y-m-d'),
            'price' => 4.5,
            'payment_unit' => 'c/kWh',
        ]);

        // Link Helsinki contract to Helsinki postcode only
        \Illuminate\Support\Facades\DB::table('contract_postcode')->insert([
            'contract_id' => 'helsinki-contract',
            'postcode' => '00100',
        ]);

        // Test: Filter by Helsinki postcode - should see both contracts
        $component = Livewire::test('contracts-list')
            ->set('postcodeFilter', '00100');

        $contracts = $component->viewData('contracts');
        $this->assertCount(2, $contracts);
        $this->assertTrue($contracts->contains('id', 'national-contract'));
        $this->assertTrue($contracts->contains('id', 'helsinki-contract'));

        // Test: Filter by Tampere postcode - should only see national contract
        $component = Livewire::test('contracts-list')
            ->set('postcodeFilter', '33100');

        $contracts = $component->viewData('contracts');
        $this->assertCount(1, $contracts);
        $this->assertTrue($contracts->contains('id', 'national-contract'));
        $this->assertFalse($contracts->contains('id', 'helsinki-contract'));
    }

    /**
     * Test that contracts with many postcodes don't cause excessive memory usage.
     * The component should NOT eager load availabilityPostcodes relationship.
     */
    public function test_contracts_with_many_postcodes_load_efficiently(): void
    {
        // Create 100 postcodes
        for ($i = 0; $i < 100; $i++) {
            \App\Models\Postcode::create([
                'postcode' => str_pad($i, 5, '0', STR_PAD_LEFT),
                'postcode_fi_name' => "Area $i",
                'postcode_fi_name_slug' => "area-$i",
                'postcode_sv_name' => "Område $i",
                'postcode_sv_name_slug' => "omrade-$i",
                'municipal_name_fi' => 'Test',
                'municipal_name_fi_slug' => 'test',
                'municipal_name_sv' => 'Test',
                'municipal_name_sv_slug' => 'test',
            ]);
        }

        // Create a contract available in all 100 postcodes
        ElectricityContract::create([
            'id' => 'multi-postcode-contract',
            'company_name' => 'Test Energia Oy',
            'name' => 'Alueellinen Sähkö',
            'contract_type' => 'Fixed',
            'metering' => 'General',
            'availability_is_national' => false,
        ]);

        PriceComponent::create([
            'id' => 'pc-multi',
            'electricity_contract_id' => 'multi-postcode-contract',
            'price_component_type' => 'General',
            'price_date' => now()->format('Y-m-d'),
            'price' => 6.0,
            'payment_unit' => 'c/kWh',
        ]);

        // Link contract to all 100 postcodes
        $inserts = [];
        for ($i = 0; $i < 100; $i++) {
            $inserts[] = [
                'contract_id' => 'multi-postcode-contract',
                'postcode' => str_pad($i, 5, '0', STR_PAD_LEFT),
            ];
        }
        \Illuminate\Support\Facades\DB::table('contract_postcode')->insert($inserts);

        // The component should load without issues and the contract should NOT have
        // availabilityPostcodes relationship loaded (to save memory)
        $component = Livewire::test('contracts-list');
        $contracts = $component->viewData('contracts');

        $this->assertCount(1, $contracts);
        $contract = $contracts->first();

        // The availabilityPostcodes should NOT be loaded (memory optimization)
        $this->assertFalse($contract->relationLoaded('availabilityPostcodes'));
    }

    /**
     * Test that only the latest price components are used for calculations.
     * Old price components should not affect memory usage or calculations.
     */
    public function test_only_latest_price_components_are_used(): void
    {
        ElectricityContract::create([
            'id' => 'contract-with-history',
            'company_name' => 'Test Energia Oy',
            'name' => 'Historiallinen Sähkö',
            'contract_type' => 'Fixed',
            'metering' => 'General',
            'availability_is_national' => true,
        ]);

        // Create old price components
        PriceComponent::create([
            'id' => 'pc-old-general',
            'electricity_contract_id' => 'contract-with-history',
            'price_component_type' => 'General',
            'price_date' => '2024-01-01',
            'price' => 10.0, // Old, expensive price
            'payment_unit' => 'c/kWh',
        ]);

        PriceComponent::create([
            'id' => 'pc-old-monthly',
            'electricity_contract_id' => 'contract-with-history',
            'price_component_type' => 'Monthly',
            'price_date' => '2024-01-01',
            'price' => 10.0, // Old monthly fee
            'payment_unit' => 'EUR/month',
        ]);

        // Create current price components
        PriceComponent::create([
            'id' => 'pc-new-general',
            'electricity_contract_id' => 'contract-with-history',
            'price_component_type' => 'General',
            'price_date' => '2026-01-01',
            'price' => 5.0, // New, cheaper price
            'payment_unit' => 'c/kWh',
        ]);

        PriceComponent::create([
            'id' => 'pc-new-monthly',
            'electricity_contract_id' => 'contract-with-history',
            'price_component_type' => 'Monthly',
            'price_date' => '2026-01-01',
            'price' => 3.0, // New monthly fee
            'payment_unit' => 'EUR/month',
        ]);

        $component = Livewire::test('contracts-list')
            ->set('consumption', 5000);

        $contracts = $component->viewData('contracts');
        $contract = $contracts->first();

        // Calculation should use NEW prices (5.0 c/kWh, 3.0 EUR/month)
        // Total = (5.0 * 5000 / 100) + (3.0 * 12) = 250 + 36 = 286 EUR/year
        // NOT old prices: (10.0 * 5000 / 100) + (10.0 * 12) = 500 + 120 = 620 EUR/year
        $this->assertArrayHasKey('total_cost', $contract->calculated_cost);
        $totalCost = $contract->calculated_cost['total_cost'];

        // Should be around 286, not 620
        $this->assertGreaterThan(280, $totalCost);
        $this->assertLessThan(300, $totalCost);
    }

    /**
     * Test that inline calculator fields have default values.
     */
    public function test_inline_calculator_has_default_values(): void
    {
        Livewire::test('contracts-list')
            ->assertSet('calcLivingArea', 80)
            ->assertSet('calcNumPeople', 2)
            ->assertSet('calcBuildingType', 'apartment')
            ->assertSet('calcIncludeHeating', false)
            ->assertSet('calcHeatingMethod', 'electricity')
            ->assertSet('calcBuildingRegion', 'south')
            ->assertSet('calcBuildingEnergyEfficiency', '2000');
    }

    /**
     * Test that calculator tab shows building type options.
     */
    public function test_calculator_tab_shows_building_type_options(): void
    {
        Livewire::test('contracts-list')
            ->set('activeTab', 'calculator')
            ->assertSee('Kerrostalo')
            ->assertSee('Rivitalo')
            ->assertSee('Omakotitalo');
    }

    /**
     * Test that changing living area updates consumption when calculator tab is active.
     */
    public function test_changing_living_area_updates_consumption(): void
    {
        // Basic electricity consumption = numPeople * 400 + livingArea * 30
        // With 2 people and 100 m²: 2 * 400 + 100 * 30 = 800 + 3000 = 3800 kWh
        $component = Livewire::test('contracts-list')
            ->set('activeTab', 'calculator')
            ->set('calcNumPeople', 2)
            ->set('calcLivingArea', 100);

        // The consumption should be updated by the calculator
        $consumption = $component->get('consumption');
        $this->assertEquals(3800, $consumption);
    }

    /**
     * Test that changing number of people updates consumption.
     */
    public function test_changing_num_people_updates_consumption(): void
    {
        // Basic electricity consumption = numPeople * 400 + livingArea * 30
        // With 4 people and 80 m²: 4 * 400 + 80 * 30 = 1600 + 2400 = 4000 kWh
        $component = Livewire::test('contracts-list')
            ->set('activeTab', 'calculator')
            ->set('calcLivingArea', 80)
            ->set('calcNumPeople', 4);

        $consumption = $component->get('consumption');
        $this->assertEquals(4000, $consumption);
    }

    /**
     * Test that enabling heating increases consumption significantly.
     */
    public function test_enabling_heating_increases_consumption(): void
    {
        // First get consumption without heating
        $componentWithoutHeating = Livewire::test('contracts-list')
            ->set('activeTab', 'calculator')
            ->set('calcLivingArea', 100)
            ->set('calcNumPeople', 2)
            ->set('calcIncludeHeating', false);

        $consumptionWithoutHeating = $componentWithoutHeating->get('consumption');

        // Now enable heating
        $componentWithHeating = Livewire::test('contracts-list')
            ->set('activeTab', 'calculator')
            ->set('calcLivingArea', 100)
            ->set('calcNumPeople', 2)
            ->set('calcIncludeHeating', true)
            ->set('calcHeatingMethod', 'electricity')
            ->set('calcBuildingRegion', 'south')
            ->set('calcBuildingEnergyEfficiency', '2000');

        $consumptionWithHeating = $componentWithHeating->get('consumption');

        // Heating should significantly increase consumption
        $this->assertGreaterThan($consumptionWithoutHeating, $consumptionWithHeating);
        // For a 100m² house with electric heating, consumption should be well over 10000 kWh
        $this->assertGreaterThan(10000, $consumptionWithHeating);
    }

    /**
     * Test that heating method options are shown when include heating is enabled.
     */
    public function test_heating_options_shown_when_include_heating_enabled(): void
    {
        Livewire::test('contracts-list')
            ->set('activeTab', 'calculator')
            ->set('calcIncludeHeating', true)
            ->assertSee('Suora sähkölämmitys')
            ->assertSee('Ilma-vesilämpöpumppu')
            ->assertSee('Maalämpö');
    }

    /**
     * Test that calculator does not affect consumption when on presets tab.
     */
    public function test_calculator_does_not_affect_consumption_on_presets_tab(): void
    {
        // Set to presets tab and select a preset
        $component = Livewire::test('contracts-list')
            ->set('activeTab', 'presets')
            ->call('selectPreset', 'small_apartment')
            ->assertSet('consumption', 2000);

        // Change calculator values - should not affect consumption
        $component->set('calcLivingArea', 200);
        $component->assertSet('consumption', 2000); // Should still be 2000
    }

    /**
     * Test that selecting a preset clears the preset selection when switching to calculator.
     */
    public function test_calculator_clears_preset_selection(): void
    {
        $component = Livewire::test('contracts-list')
            ->call('selectPreset', 'small_apartment')
            ->assertSet('selectedPreset', 'small_apartment')
            ->set('activeTab', 'calculator')
            ->set('calcLivingArea', 100); // This should trigger recalculation

        // Selected preset should be cleared when using calculator
        $this->assertNull($component->get('selectedPreset'));
    }
}
