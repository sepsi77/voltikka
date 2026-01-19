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
            ->assertSee('2000 kWh')  // Studio
            ->assertSee('5000 kWh')  // Apartment
            ->assertSee('10000 kWh') // Small house
            ->assertSee('18000 kWh'); // Large house
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
}
