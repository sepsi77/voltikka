<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\ElectricityContract;
use App\Models\ElectricitySource;
use App\Models\PriceComponent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class CompanyListPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Create test companies
        Company::create([
            'name' => 'Cheap Energy Oy',
            'name_slug' => 'cheap-energy-oy',
            'company_url' => 'https://cheapenergy.fi',
            'logo_url' => 'https://storage.example.com/logos/cheap-energy.png',
        ]);

        Company::create([
            'name' => 'Green Power Ab',
            'name_slug' => 'green-power-ab',
            'company_url' => 'https://greenpower.fi',
            'logo_url' => 'https://storage.example.com/logos/green-power.png',
        ]);

        Company::create([
            'name' => 'Nuclear Plus Oy',
            'name_slug' => 'nuclear-plus-oy',
            'company_url' => 'https://nuclearplus.fi',
            'logo_url' => 'https://storage.example.com/logos/nuclear-plus.png',
        ]);
    }

    /**
     * Test that the company list page is accessible.
     */
    public function test_company_list_page_is_accessible(): void
    {
        $response = $this->get('/sahkosopimus/sahkoyhtiot');

        $response->assertStatus(200);
    }

    /**
     * Test that the company list page renders the Livewire component.
     */
    public function test_company_list_page_renders_livewire_component(): void
    {
        $response = $this->get('/sahkosopimus/sahkoyhtiot');

        $response->assertStatus(200);
        $response->assertSeeLivewire('company-list');
    }

    /**
     * Test that companies with contracts are displayed.
     */
    public function test_companies_with_contracts_are_displayed(): void
    {
        // Create contract for Cheap Energy
        ElectricityContract::create([
            'id' => 'cheap-contract-1',
            'company_name' => 'Cheap Energy Oy',
            'name' => 'Budget Sähkö',
            'contract_type' => 'Fixed',
            'metering' => 'General',
            'availability_is_national' => true,
        ]);

        PriceComponent::create([
            'id' => 'pc-cheap-1',
            'electricity_contract_id' => 'cheap-contract-1',
            'price_component_type' => 'General',
            'price_date' => now()->format('Y-m-d'),
            'price' => 4.0,
            'payment_unit' => 'c/kWh',
        ]);

        PriceComponent::create([
            'id' => 'pc-cheap-monthly-1',
            'electricity_contract_id' => 'cheap-contract-1',
            'price_component_type' => 'Monthly',
            'price_date' => now()->format('Y-m-d'),
            'price' => 2.0,
            'payment_unit' => 'EUR/month',
        ]);

        Livewire::test('company-list')
            ->assertSee('Cheap Energy Oy');
    }

    /**
     * Test that companies without contracts are not displayed.
     */
    public function test_companies_without_contracts_are_not_displayed(): void
    {
        // Green Power has no contracts, should not appear
        Livewire::test('company-list')
            ->assertDontSee('Green Power Ab');
    }

    /**
     * Test that page title is correct.
     */
    public function test_page_has_correct_title(): void
    {
        $response = $this->get('/sahkosopimus/sahkoyhtiot');

        $response->assertStatus(200);
        $response->assertSee('Sähköyhtiöt Suomessa');
    }

    /**
     * Test company rankings are calculated correctly - cheapest.
     */
    public function test_cheapest_company_ranking(): void
    {
        // Cheap Energy - cheaper contract
        ElectricityContract::create([
            'id' => 'cheap-contract-1',
            'company_name' => 'Cheap Energy Oy',
            'name' => 'Budget Sähkö',
            'contract_type' => 'Fixed',
            'metering' => 'General',
            'availability_is_national' => true,
        ]);

        PriceComponent::create([
            'id' => 'pc-cheap-1',
            'electricity_contract_id' => 'cheap-contract-1',
            'price_component_type' => 'General',
            'price_date' => now()->format('Y-m-d'),
            'price' => 4.0,
            'payment_unit' => 'c/kWh',
        ]);

        PriceComponent::create([
            'id' => 'pc-cheap-monthly-1',
            'electricity_contract_id' => 'cheap-contract-1',
            'price_component_type' => 'Monthly',
            'price_date' => now()->format('Y-m-d'),
            'price' => 2.0,
            'payment_unit' => 'EUR/month',
        ]);

        // Green Power - more expensive contract
        ElectricityContract::create([
            'id' => 'green-contract-1',
            'company_name' => 'Green Power Ab',
            'name' => 'Vihreä Sähkö',
            'contract_type' => 'Fixed',
            'metering' => 'General',
            'availability_is_national' => true,
        ]);

        PriceComponent::create([
            'id' => 'pc-green-1',
            'electricity_contract_id' => 'green-contract-1',
            'price_component_type' => 'General',
            'price_date' => now()->format('Y-m-d'),
            'price' => 8.0,
            'payment_unit' => 'c/kWh',
        ]);

        PriceComponent::create([
            'id' => 'pc-green-monthly-1',
            'electricity_contract_id' => 'green-contract-1',
            'price_component_type' => 'Monthly',
            'price_date' => now()->format('Y-m-d'),
            'price' => 5.0,
            'payment_unit' => 'EUR/month',
        ]);

        $component = Livewire::test('company-list');
        $cheapestCompanies = $component->viewData('cheapestCompanies');

        $this->assertCount(2, $cheapestCompanies);
        $this->assertEquals('Cheap Energy Oy', $cheapestCompanies->first()['company']->name);
    }

    /**
     * Test greenest company ranking (100% renewable).
     */
    public function test_greenest_company_ranking(): void
    {
        // Green Power - 100% renewable
        ElectricityContract::create([
            'id' => 'green-contract-1',
            'company_name' => 'Green Power Ab',
            'name' => 'Vihreä Sähkö',
            'contract_type' => 'Fixed',
            'metering' => 'General',
            'availability_is_national' => true,
        ]);

        PriceComponent::create([
            'id' => 'pc-green-1',
            'electricity_contract_id' => 'green-contract-1',
            'price_component_type' => 'General',
            'price_date' => now()->format('Y-m-d'),
            'price' => 6.0,
            'payment_unit' => 'c/kWh',
        ]);

        ElectricitySource::create([
            'contract_id' => 'green-contract-1',
            'renewable_total' => 100.0,
            'renewable_wind' => 60.0,
            'renewable_solar' => 20.0,
            'renewable_hydro' => 20.0,
            'nuclear_total' => 0.0,
            'fossil_total' => 0.0,
        ]);

        // Cheap Energy - 50% renewable
        ElectricityContract::create([
            'id' => 'cheap-contract-1',
            'company_name' => 'Cheap Energy Oy',
            'name' => 'Budget Sähkö',
            'contract_type' => 'Fixed',
            'metering' => 'General',
            'availability_is_national' => true,
        ]);

        PriceComponent::create([
            'id' => 'pc-cheap-1',
            'electricity_contract_id' => 'cheap-contract-1',
            'price_component_type' => 'General',
            'price_date' => now()->format('Y-m-d'),
            'price' => 4.0,
            'payment_unit' => 'c/kWh',
        ]);

        ElectricitySource::create([
            'contract_id' => 'cheap-contract-1',
            'renewable_total' => 50.0,
            'renewable_wind' => 50.0,
            'nuclear_total' => 30.0,
            'fossil_total' => 20.0,
        ]);

        $component = Livewire::test('company-list');
        $greenestCompanies = $component->viewData('greenestCompanies');

        $this->assertCount(2, $greenestCompanies);
        $this->assertEquals('Green Power Ab', $greenestCompanies->first()['company']->name);
    }

    /**
     * Test cleanest emissions company ranking.
     */
    public function test_cleanest_emissions_ranking(): void
    {
        // Nuclear Plus - 100% nuclear (0 emissions)
        ElectricityContract::create([
            'id' => 'nuclear-contract-1',
            'company_name' => 'Nuclear Plus Oy',
            'name' => 'Ydin Sähkö',
            'contract_type' => 'Fixed',
            'metering' => 'General',
            'availability_is_national' => true,
        ]);

        PriceComponent::create([
            'id' => 'pc-nuclear-1',
            'electricity_contract_id' => 'nuclear-contract-1',
            'price_component_type' => 'General',
            'price_date' => now()->format('Y-m-d'),
            'price' => 5.0,
            'payment_unit' => 'c/kWh',
        ]);

        ElectricitySource::create([
            'contract_id' => 'nuclear-contract-1',
            'renewable_total' => 0.0,
            'nuclear_total' => 100.0,
            'fossil_total' => 0.0,
        ]);

        // Cheap Energy - high fossil
        ElectricityContract::create([
            'id' => 'cheap-contract-1',
            'company_name' => 'Cheap Energy Oy',
            'name' => 'Budget Sähkö',
            'contract_type' => 'Fixed',
            'metering' => 'General',
            'availability_is_national' => true,
        ]);

        PriceComponent::create([
            'id' => 'pc-cheap-1',
            'electricity_contract_id' => 'cheap-contract-1',
            'price_component_type' => 'General',
            'price_date' => now()->format('Y-m-d'),
            'price' => 4.0,
            'payment_unit' => 'c/kWh',
        ]);

        ElectricitySource::create([
            'contract_id' => 'cheap-contract-1',
            'renewable_total' => 20.0,
            'nuclear_total' => 0.0,
            'fossil_total' => 80.0,
            'fossil_coal' => 80.0,
        ]);

        $component = Livewire::test('company-list');
        $cleanestCompanies = $component->viewData('cleanestEmissionsCompanies');

        $this->assertCount(2, $cleanestCompanies);
        $this->assertEquals('Nuclear Plus Oy', $cleanestCompanies->first()['company']->name);
    }

    /**
     * Test company metrics calculation.
     */
    public function test_company_metrics_are_calculated(): void
    {
        // Create multiple contracts for one company
        ElectricityContract::create([
            'id' => 'cheap-contract-1',
            'company_name' => 'Cheap Energy Oy',
            'name' => 'Budget Sähkö',
            'contract_type' => 'Fixed',
            'metering' => 'General',
            'availability_is_national' => true,
        ]);

        PriceComponent::create([
            'id' => 'pc-cheap-1',
            'electricity_contract_id' => 'cheap-contract-1',
            'price_component_type' => 'General',
            'price_date' => now()->format('Y-m-d'),
            'price' => 4.0,
            'payment_unit' => 'c/kWh',
        ]);

        PriceComponent::create([
            'id' => 'pc-cheap-monthly-1',
            'electricity_contract_id' => 'cheap-contract-1',
            'price_component_type' => 'Monthly',
            'price_date' => now()->format('Y-m-d'),
            'price' => 2.0,
            'payment_unit' => 'EUR/month',
        ]);

        ElectricityContract::create([
            'id' => 'cheap-contract-2',
            'company_name' => 'Cheap Energy Oy',
            'name' => 'Premium Sähkö',
            'contract_type' => 'Fixed',
            'metering' => 'General',
            'availability_is_national' => true,
        ]);

        PriceComponent::create([
            'id' => 'pc-cheap-2',
            'electricity_contract_id' => 'cheap-contract-2',
            'price_component_type' => 'General',
            'price_date' => now()->format('Y-m-d'),
            'price' => 6.0,
            'payment_unit' => 'c/kWh',
        ]);

        PriceComponent::create([
            'id' => 'pc-cheap-monthly-2',
            'electricity_contract_id' => 'cheap-contract-2',
            'price_component_type' => 'Monthly',
            'price_date' => now()->format('Y-m-d'),
            'price' => 3.0,
            'payment_unit' => 'EUR/month',
        ]);

        $component = Livewire::test('company-list');
        $companies = $component->viewData('companies');

        $cheapEnergy = $companies->firstWhere('company.name', 'Cheap Energy Oy');

        // Should have 2 contracts
        $this->assertEquals(2, $cheapEnergy['contractCount']);
    }

    /**
     * Test search/filter functionality.
     */
    public function test_search_filter_works(): void
    {
        ElectricityContract::create([
            'id' => 'cheap-contract-1',
            'company_name' => 'Cheap Energy Oy',
            'name' => 'Budget Sähkö',
            'contract_type' => 'Fixed',
            'metering' => 'General',
            'availability_is_national' => true,
        ]);

        PriceComponent::create([
            'id' => 'pc-cheap-1',
            'electricity_contract_id' => 'cheap-contract-1',
            'price_component_type' => 'General',
            'price_date' => now()->format('Y-m-d'),
            'price' => 4.0,
            'payment_unit' => 'c/kWh',
        ]);

        ElectricityContract::create([
            'id' => 'green-contract-1',
            'company_name' => 'Green Power Ab',
            'name' => 'Vihreä Sähkö',
            'contract_type' => 'Fixed',
            'metering' => 'General',
            'availability_is_national' => true,
        ]);

        PriceComponent::create([
            'id' => 'pc-green-1',
            'electricity_contract_id' => 'green-contract-1',
            'price_component_type' => 'General',
            'price_date' => now()->format('Y-m-d'),
            'price' => 6.0,
            'payment_unit' => 'c/kWh',
        ]);

        $component = Livewire::test('company-list')
            ->set('search', 'Green');

        $companies = $component->viewData('filteredCompanies');
        $this->assertCount(1, $companies);
        $this->assertEquals('Green Power Ab', $companies->first()['company']->name);
    }

    /**
     * Test most contracts ranking.
     */
    public function test_most_contracts_ranking(): void
    {
        // Create multiple contracts for Cheap Energy
        ElectricityContract::create([
            'id' => 'cheap-contract-1',
            'company_name' => 'Cheap Energy Oy',
            'name' => 'Budget Sähkö 1',
            'contract_type' => 'Fixed',
            'metering' => 'General',
            'availability_is_national' => true,
        ]);

        PriceComponent::create([
            'id' => 'pc-cheap-1',
            'electricity_contract_id' => 'cheap-contract-1',
            'price_component_type' => 'General',
            'price_date' => now()->format('Y-m-d'),
            'price' => 4.0,
            'payment_unit' => 'c/kWh',
        ]);

        ElectricityContract::create([
            'id' => 'cheap-contract-2',
            'company_name' => 'Cheap Energy Oy',
            'name' => 'Budget Sähkö 2',
            'contract_type' => 'Fixed',
            'metering' => 'General',
            'availability_is_national' => true,
        ]);

        PriceComponent::create([
            'id' => 'pc-cheap-2',
            'electricity_contract_id' => 'cheap-contract-2',
            'price_component_type' => 'General',
            'price_date' => now()->format('Y-m-d'),
            'price' => 5.0,
            'payment_unit' => 'c/kWh',
        ]);

        ElectricityContract::create([
            'id' => 'cheap-contract-3',
            'company_name' => 'Cheap Energy Oy',
            'name' => 'Budget Sähkö 3',
            'contract_type' => 'Fixed',
            'metering' => 'General',
            'availability_is_national' => true,
        ]);

        PriceComponent::create([
            'id' => 'pc-cheap-3',
            'electricity_contract_id' => 'cheap-contract-3',
            'price_component_type' => 'General',
            'price_date' => now()->format('Y-m-d'),
            'price' => 6.0,
            'payment_unit' => 'c/kWh',
        ]);

        // Single contract for Green Power
        ElectricityContract::create([
            'id' => 'green-contract-1',
            'company_name' => 'Green Power Ab',
            'name' => 'Vihreä Sähkö',
            'contract_type' => 'Fixed',
            'metering' => 'General',
            'availability_is_national' => true,
        ]);

        PriceComponent::create([
            'id' => 'pc-green-1',
            'electricity_contract_id' => 'green-contract-1',
            'price_component_type' => 'General',
            'price_date' => now()->format('Y-m-d'),
            'price' => 6.0,
            'payment_unit' => 'c/kWh',
        ]);

        $component = Livewire::test('company-list');
        $mostContractsCompanies = $component->viewData('mostContractsCompanies');

        $this->assertEquals('Cheap Energy Oy', $mostContractsCompanies->first()['company']->name);
        $this->assertEquals(3, $mostContractsCompanies->first()['contractCount']);
    }

    /**
     * Test lowest monthly fees ranking.
     */
    public function test_lowest_monthly_fees_ranking(): void
    {
        // Cheap Energy - low monthly fee
        ElectricityContract::create([
            'id' => 'cheap-contract-1',
            'company_name' => 'Cheap Energy Oy',
            'name' => 'Budget Sähkö',
            'contract_type' => 'Fixed',
            'metering' => 'General',
            'availability_is_national' => true,
        ]);

        PriceComponent::create([
            'id' => 'pc-cheap-1',
            'electricity_contract_id' => 'cheap-contract-1',
            'price_component_type' => 'General',
            'price_date' => now()->format('Y-m-d'),
            'price' => 6.0,
            'payment_unit' => 'c/kWh',
        ]);

        PriceComponent::create([
            'id' => 'pc-cheap-monthly-1',
            'electricity_contract_id' => 'cheap-contract-1',
            'price_component_type' => 'Monthly',
            'price_date' => now()->format('Y-m-d'),
            'price' => 1.0,
            'payment_unit' => 'EUR/month',
        ]);

        // Green Power - high monthly fee
        ElectricityContract::create([
            'id' => 'green-contract-1',
            'company_name' => 'Green Power Ab',
            'name' => 'Vihreä Sähkö',
            'contract_type' => 'Fixed',
            'metering' => 'General',
            'availability_is_national' => true,
        ]);

        PriceComponent::create([
            'id' => 'pc-green-1',
            'electricity_contract_id' => 'green-contract-1',
            'price_component_type' => 'General',
            'price_date' => now()->format('Y-m-d'),
            'price' => 4.0,
            'payment_unit' => 'c/kWh',
        ]);

        PriceComponent::create([
            'id' => 'pc-green-monthly-1',
            'electricity_contract_id' => 'green-contract-1',
            'price_component_type' => 'Monthly',
            'price_date' => now()->format('Y-m-d'),
            'price' => 10.0,
            'payment_unit' => 'EUR/month',
        ]);

        $component = Livewire::test('company-list');
        $lowestFeesCompanies = $component->viewData('lowestMonthlyFeesCompanies');

        $this->assertEquals('Cheap Energy Oy', $lowestFeesCompanies->first()['company']->name);
    }

    /**
     * Test 100% renewable ranking.
     */
    public function test_fully_renewable_company_ranking(): void
    {
        // Green Power - 100% renewable
        ElectricityContract::create([
            'id' => 'green-contract-1',
            'company_name' => 'Green Power Ab',
            'name' => 'Vihreä Sähkö',
            'contract_type' => 'Fixed',
            'metering' => 'General',
            'availability_is_national' => true,
        ]);

        PriceComponent::create([
            'id' => 'pc-green-1',
            'electricity_contract_id' => 'green-contract-1',
            'price_component_type' => 'General',
            'price_date' => now()->format('Y-m-d'),
            'price' => 6.0,
            'payment_unit' => 'c/kWh',
        ]);

        ElectricitySource::create([
            'contract_id' => 'green-contract-1',
            'renewable_total' => 100.0,
            'renewable_wind' => 100.0,
            'nuclear_total' => 0.0,
            'fossil_total' => 0.0,
        ]);

        // Cheap Energy - not 100% renewable
        ElectricityContract::create([
            'id' => 'cheap-contract-1',
            'company_name' => 'Cheap Energy Oy',
            'name' => 'Budget Sähkö',
            'contract_type' => 'Fixed',
            'metering' => 'General',
            'availability_is_national' => true,
        ]);

        PriceComponent::create([
            'id' => 'pc-cheap-1',
            'electricity_contract_id' => 'cheap-contract-1',
            'price_component_type' => 'General',
            'price_date' => now()->format('Y-m-d'),
            'price' => 4.0,
            'payment_unit' => 'c/kWh',
        ]);

        ElectricitySource::create([
            'contract_id' => 'cheap-contract-1',
            'renewable_total' => 80.0,
            'renewable_wind' => 80.0,
            'nuclear_total' => 0.0,
            'fossil_total' => 20.0,
        ]);

        $component = Livewire::test('company-list');
        $fullyRenewableCompanies = $component->viewData('fullyRenewableCompanies');

        // Only Green Power should be in this list
        $this->assertCount(1, $fullyRenewableCompanies);
        $this->assertEquals('Green Power Ab', $fullyRenewableCompanies->first()['company']->name);
    }

    /**
     * Test best spot margins ranking.
     */
    public function test_best_spot_margins_ranking(): void
    {
        // Cheap Energy - lower spot margin
        ElectricityContract::create([
            'id' => 'cheap-spot-1',
            'company_name' => 'Cheap Energy Oy',
            'name' => 'Budget Pörssisähkö',
            'contract_type' => 'OpenEnded',
            'pricing_model' => 'Spot',
            'metering' => 'General',
            'availability_is_national' => true,
        ]);

        PriceComponent::create([
            'id' => 'pc-cheap-spot-1',
            'electricity_contract_id' => 'cheap-spot-1',
            'price_component_type' => 'General',
            'price_date' => now()->format('Y-m-d'),
            'price' => 0.30, // Low spot margin
            'payment_unit' => 'c/kWh',
        ]);

        // Green Power - higher spot margin
        ElectricityContract::create([
            'id' => 'green-spot-1',
            'company_name' => 'Green Power Ab',
            'name' => 'Vihreä Pörssisähkö',
            'contract_type' => 'OpenEnded',
            'pricing_model' => 'Spot',
            'metering' => 'General',
            'availability_is_national' => true,
        ]);

        PriceComponent::create([
            'id' => 'pc-green-spot-1',
            'electricity_contract_id' => 'green-spot-1',
            'price_component_type' => 'General',
            'price_date' => now()->format('Y-m-d'),
            'price' => 0.59, // Higher spot margin
            'payment_unit' => 'c/kWh',
        ]);

        $component = Livewire::test('company-list');
        $bestSpotCompanies = $component->viewData('bestSpotMarginsCompanies');

        $this->assertEquals('Cheap Energy Oy', $bestSpotCompanies->first()['company']->name);
    }

    /**
     * Test company count is correct.
     */
    public function test_company_count_is_correct(): void
    {
        // Create contracts for 2 companies
        ElectricityContract::create([
            'id' => 'cheap-contract-1',
            'company_name' => 'Cheap Energy Oy',
            'name' => 'Budget Sähkö',
            'contract_type' => 'Fixed',
            'metering' => 'General',
            'availability_is_national' => true,
        ]);

        PriceComponent::create([
            'id' => 'pc-cheap-1',
            'electricity_contract_id' => 'cheap-contract-1',
            'price_component_type' => 'General',
            'price_date' => now()->format('Y-m-d'),
            'price' => 4.0,
            'payment_unit' => 'c/kWh',
        ]);

        ElectricityContract::create([
            'id' => 'green-contract-1',
            'company_name' => 'Green Power Ab',
            'name' => 'Vihreä Sähkö',
            'contract_type' => 'Fixed',
            'metering' => 'General',
            'availability_is_national' => true,
        ]);

        PriceComponent::create([
            'id' => 'pc-green-1',
            'electricity_contract_id' => 'green-contract-1',
            'price_component_type' => 'General',
            'price_date' => now()->format('Y-m-d'),
            'price' => 6.0,
            'payment_unit' => 'c/kWh',
        ]);

        $component = Livewire::test('company-list');
        $companyCount = $component->viewData('companyCount');

        $this->assertEquals(2, $companyCount);
    }

    /**
     * Test JSON-LD structured data is included.
     */
    public function test_json_ld_structured_data_is_included(): void
    {
        ElectricityContract::create([
            'id' => 'cheap-contract-1',
            'company_name' => 'Cheap Energy Oy',
            'name' => 'Budget Sähkö',
            'contract_type' => 'Fixed',
            'metering' => 'General',
            'availability_is_national' => true,
        ]);

        PriceComponent::create([
            'id' => 'pc-cheap-1',
            'electricity_contract_id' => 'cheap-contract-1',
            'price_component_type' => 'General',
            'price_date' => now()->format('Y-m-d'),
            'price' => 4.0,
            'payment_unit' => 'c/kWh',
        ]);

        $response = $this->get('/sahkosopimus/sahkoyhtiot');

        $response->assertStatus(200);
        $response->assertSee('application/ld+json', false);
        $response->assertSee('@type', false);
        $response->assertSee('ItemList', false);
    }

    /**
     * Test links to company detail pages are correct.
     */
    public function test_links_to_company_detail_pages(): void
    {
        ElectricityContract::create([
            'id' => 'cheap-contract-1',
            'company_name' => 'Cheap Energy Oy',
            'name' => 'Budget Sähkö',
            'contract_type' => 'Fixed',
            'metering' => 'General',
            'availability_is_national' => true,
        ]);

        PriceComponent::create([
            'id' => 'pc-cheap-1',
            'electricity_contract_id' => 'cheap-contract-1',
            'price_component_type' => 'General',
            'price_date' => now()->format('Y-m-d'),
            'price' => 4.0,
            'payment_unit' => 'c/kWh',
        ]);

        Livewire::test('company-list')
            ->assertSeeHtml('href="/sahkosopimus/sahkoyhtiot/cheap-energy-oy"');
    }
}
