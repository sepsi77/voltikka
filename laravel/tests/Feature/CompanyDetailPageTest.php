<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\ElectricityContract;
use App\Models\ElectricitySource;
use App\Models\PriceComponent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class CompanyDetailPageTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        // Create test company
        $this->company = Company::create([
            'name' => 'Test Energy Oy',
            'name_slug' => 'test-energy-oy',
            'company_url' => 'https://testenergy.fi',
            'logo_url' => 'https://storage.example.com/logos/test-energy.png',
            'street_address' => 'Testikatu 1',
            'postal_code' => '00100',
            'postal_name' => 'Helsinki',
        ]);
    }

    /**
     * Test that the company detail page is accessible.
     */
    public function test_company_detail_page_is_accessible(): void
    {
        // Create a contract for the company
        $this->createContract('test-contract-1', 'Test Sähkö', 4.0, 2.0);

        $response = $this->get('/sahkosopimus/sahkoyhtiot/test-energy-oy');

        $response->assertStatus(200);
    }

    /**
     * Test that 404 is returned for non-existent company.
     */
    public function test_404_for_nonexistent_company(): void
    {
        $response = $this->get('/sahkosopimus/sahkoyhtiot/nonexistent-company');

        $response->assertStatus(404);
    }

    /**
     * Test that the company detail page renders the Livewire component.
     */
    public function test_company_detail_page_renders_livewire_component(): void
    {
        $this->createContract('test-contract-1', 'Test Sähkö', 4.0, 2.0);

        $response = $this->get('/sahkosopimus/sahkoyhtiot/test-energy-oy');

        $response->assertStatus(200);
        $response->assertSeeLivewire('company-detail');
    }

    /**
     * Test company name is displayed.
     */
    public function test_company_name_is_displayed(): void
    {
        $this->createContract('test-contract-1', 'Test Sähkö', 4.0, 2.0);

        Livewire::test('company-detail', ['companySlug' => 'test-energy-oy'])
            ->assertSee('Test Energy Oy');
    }

    /**
     * Test contracts are displayed with calculated costs.
     */
    public function test_contracts_are_displayed_with_calculated_costs(): void
    {
        $this->createContract('test-contract-1', 'Test Sähkö', 4.0, 2.0);

        $component = Livewire::test('company-detail', ['companySlug' => 'test-energy-oy']);
        $contracts = $component->viewData('contracts');

        $this->assertCount(1, $contracts);
        $this->assertEquals('Test Sähkö', $contracts->first()->name);
        $this->assertArrayHasKey('total_cost', $contracts->first()->calculated_cost);
    }

    /**
     * Test contracts are sorted by price.
     */
    public function test_contracts_are_sorted_by_price(): void
    {
        // Create expensive contract first
        $this->createContract('expensive-contract', 'Expensive Sähkö', 10.0, 5.0);
        // Create cheap contract second
        $this->createContract('cheap-contract', 'Cheap Sähkö', 3.0, 1.0);

        $component = Livewire::test('company-detail', ['companySlug' => 'test-energy-oy']);
        $contracts = $component->viewData('contracts');

        // Cheap contract should be first after sorting
        $this->assertEquals('Cheap Sähkö', $contracts->first()->name);
    }

    /**
     * Test consumption preset selection works.
     */
    public function test_consumption_preset_selection_works(): void
    {
        $this->createContract('test-contract-1', 'Test Sähkö', 4.0, 2.0);

        Livewire::test('company-detail', ['companySlug' => 'test-energy-oy'])
            ->assertSet('consumption', 5000) // Default
            ->assertSet('selectedPreset', 'large_apartment')
            ->call('selectPreset', 'small_apartment')
            ->assertSet('consumption', 2000)
            ->assertSet('selectedPreset', 'small_apartment');
    }

    /**
     * Test custom consumption value works.
     */
    public function test_custom_consumption_value_works(): void
    {
        $this->createContract('test-contract-1', 'Test Sähkö', 4.0, 2.0);

        Livewire::test('company-detail', ['companySlug' => 'test-energy-oy'])
            ->call('setConsumption', 15000)
            ->assertSet('consumption', 15000)
            ->assertSet('selectedPreset', null);
    }

    /**
     * Test company stats are calculated correctly.
     */
    public function test_company_stats_are_calculated(): void
    {
        $this->createContract('cheap-contract', 'Cheap Sähkö', 3.0, 1.0);
        $this->createContract('expensive-contract', 'Expensive Sähkö', 8.0, 3.0);

        $component = Livewire::test('company-detail', ['companySlug' => 'test-energy-oy']);
        $stats = $component->viewData('companyStats');

        $this->assertEquals(2, $stats['contract_count']);
        $this->assertNotNull($stats['avg_price']);
        $this->assertNotNull($stats['min_price']);
        $this->assertNotNull($stats['max_price']);
        $this->assertLessThan($stats['max_price'], $stats['min_price']);
    }

    /**
     * Test emission factor is calculated for contracts.
     */
    public function test_emission_factor_is_calculated(): void
    {
        $contractId = 'green-contract';
        $this->createContract($contractId, 'Green Sähkö', 5.0, 2.0);

        // Add electricity source with 100% renewable
        ElectricitySource::create([
            'contract_id' => $contractId,
            'renewable_total' => 100.0,
            'renewable_wind' => 100.0,
            'nuclear_total' => 0.0,
            'fossil_total' => 0.0,
        ]);

        $component = Livewire::test('company-detail', ['companySlug' => 'test-energy-oy']);
        $contracts = $component->viewData('contracts');

        // 100% renewable should have 0 emissions
        $this->assertEquals(0.0, $contracts->first()->emission_factor);
    }

    /**
     * Test high emission contract has positive emission factor.
     */
    public function test_fossil_contract_has_positive_emission_factor(): void
    {
        $contractId = 'fossil-contract';
        $this->createContract($contractId, 'Fossil Sähkö', 4.0, 2.0);

        // Add electricity source with 100% fossil (coal)
        ElectricitySource::create([
            'contract_id' => $contractId,
            'renewable_total' => 0.0,
            'nuclear_total' => 0.0,
            'fossil_total' => 100.0,
            'fossil_coal' => 100.0,
        ]);

        $component = Livewire::test('company-detail', ['companySlug' => 'test-energy-oy']);
        $contracts = $component->viewData('contracts');

        // 100% coal should have high emissions (846 gCO2/kWh)
        $this->assertGreaterThan(800, $contracts->first()->emission_factor);
    }

    /**
     * Test annual emissions are calculated.
     */
    public function test_annual_emissions_are_calculated(): void
    {
        $contractId = 'test-contract';
        $this->createContract($contractId, 'Test Sähkö', 5.0, 2.0);

        // Add electricity source
        ElectricitySource::create([
            'contract_id' => $contractId,
            'renewable_total' => 50.0,
            'renewable_wind' => 50.0,
            'nuclear_total' => 0.0,
            'fossil_total' => 50.0,
        ]);

        $component = Livewire::test('company-detail', ['companySlug' => 'test-energy-oy']);
        $contracts = $component->viewData('contracts');

        // Should have annual_emissions_kg property
        $this->assertIsFloat($contracts->first()->annual_emissions_kg);
    }

    /**
     * Test JSON-LD Organization schema is generated.
     */
    public function test_json_ld_organization_schema_is_generated(): void
    {
        $this->createContract('test-contract', 'Test Sähkö', 5.0, 2.0);

        $component = Livewire::test('company-detail', ['companySlug' => 'test-energy-oy']);
        $jsonLd = $component->viewData('jsonLd');

        $this->assertEquals('https://schema.org', $jsonLd['@context']);
        $this->assertEquals('Organization', $jsonLd['@type']);
        $this->assertEquals('Test Energy Oy', $jsonLd['name']);
        $this->assertEquals('https://testenergy.fi', $jsonLd['url']);
    }

    /**
     * Test JSON-LD includes address when available.
     */
    public function test_json_ld_includes_address(): void
    {
        $this->createContract('test-contract', 'Test Sähkö', 5.0, 2.0);

        $component = Livewire::test('company-detail', ['companySlug' => 'test-energy-oy']);
        $jsonLd = $component->viewData('jsonLd');

        $this->assertArrayHasKey('address', $jsonLd);
        $this->assertEquals('PostalAddress', $jsonLd['address']['@type']);
        $this->assertEquals('Testikatu 1', $jsonLd['address']['streetAddress']);
        $this->assertEquals('00100', $jsonLd['address']['postalCode']);
        $this->assertEquals('Helsinki', $jsonLd['address']['addressLocality']);
    }

    /**
     * Test JSON-LD includes offers for contracts.
     */
    public function test_json_ld_includes_contract_offers(): void
    {
        $this->createContract('test-contract', 'Test Sähkö', 5.0, 2.0);

        $component = Livewire::test('company-detail', ['companySlug' => 'test-energy-oy']);
        $jsonLd = $component->viewData('jsonLd');

        $this->assertArrayHasKey('makesOffer', $jsonLd);
        $this->assertCount(1, $jsonLd['makesOffer']);
        $this->assertEquals('Offer', $jsonLd['makesOffer'][0]['@type']);
        $this->assertEquals('Test Sähkö', $jsonLd['makesOffer'][0]['itemOffered']['name']);
    }

    /**
     * Test canonical URL is correct.
     */
    public function test_canonical_url_is_correct(): void
    {
        $this->createContract('test-contract', 'Test Sähkö', 5.0, 2.0);

        $component = Livewire::test('company-detail', ['companySlug' => 'test-energy-oy']);

        $this->assertStringEndsWith(
            '/sahkosopimus/sahkoyhtiot/test-energy-oy',
            $component->instance()->canonicalUrl
        );
    }

    /**
     * Test meta description is generated.
     */
    public function test_meta_description_is_generated(): void
    {
        $this->createContract('test-contract', 'Test Sähkö', 5.0, 2.0);

        $component = Livewire::test('company-detail', ['companySlug' => 'test-energy-oy']);

        $metaDescription = $component->instance()->metaDescription;

        $this->assertStringContainsString('Test Energy Oy', $metaDescription);
        $this->assertStringContainsString('1 sopimusta', $metaDescription);
    }

    /**
     * Test page title includes company name.
     */
    public function test_page_title_includes_company_name(): void
    {
        $this->createContract('test-contract', 'Test Sähkö', 5.0, 2.0);

        $response = $this->get('/sahkosopimus/sahkoyhtiot/test-energy-oy');

        $response->assertSee('Test Energy Oy', false);
    }

    /**
     * Test consumption is persisted in URL.
     */
    public function test_consumption_is_persisted_in_url(): void
    {
        $this->createContract('test-contract', 'Test Sähkö', 5.0, 2.0);

        $response = $this->get('/sahkosopimus/sahkoyhtiot/test-energy-oy?consumption=8000');

        $response->assertStatus(200);
        // The component should have the consumption from URL
    }

    /**
     * Test company without contracts shows empty state.
     */
    public function test_company_without_contracts_shows_stats(): void
    {
        // Company exists but has no contracts
        $component = Livewire::test('company-detail', ['companySlug' => 'test-energy-oy']);
        $stats = $component->viewData('companyStats');

        $this->assertEquals(0, $stats['contract_count']);
        $this->assertNull($stats['avg_price']);
    }

    /**
     * Test presets are available.
     */
    public function test_presets_are_available(): void
    {
        $this->createContract('test-contract', 'Test Sähkö', 5.0, 2.0);

        $component = Livewire::test('company-detail', ['companySlug' => 'test-energy-oy']);

        $presets = $component->instance()->presets;

        $this->assertArrayHasKey('small_apartment', $presets);
        $this->assertArrayHasKey('large_house_electric', $presets);
        $this->assertEquals(2000, $presets['small_apartment']['consumption']);
        $this->assertEquals(18000, $presets['large_house_electric']['consumption']);
    }

    /**
     * Test changing consumption updates calculated costs.
     */
    public function test_changing_consumption_updates_costs(): void
    {
        $this->createContract('test-contract', 'Test Sähkö', 5.0, 2.0);

        // Get contracts with default 5000 kWh
        $component = Livewire::test('company-detail', ['companySlug' => 'test-energy-oy']);
        $contractsDefault = $component->viewData('contracts');
        $costDefault = $contractsDefault->first()->calculated_cost['total_cost'];

        // Change to 10000 kWh
        $component->call('selectPreset', 'row_house'); // 10000 kWh
        $contractsHigher = $component->viewData('contracts');
        $costHigher = $contractsHigher->first()->calculated_cost['total_cost'];

        // Higher consumption should have higher cost
        $this->assertGreaterThan($costDefault, $costHigher);
    }

    /**
     * Test spot contract stats are tracked.
     */
    public function test_spot_contract_stats(): void
    {
        // Create a spot contract
        $contract = ElectricityContract::create([
            'id' => 'spot-contract',
            'company_name' => $this->company->name,
            'name' => 'Pörssisähkö',
            'contract_type' => 'OpenEnded',
            'pricing_model' => 'Spot',
            'metering' => 'General',
            'availability_is_national' => true,
        ]);

        PriceComponent::create([
            'id' => 'pc-spot-general',
            'electricity_contract_id' => $contract->id,
            'price_component_type' => 'General',
            'price_date' => now()->format('Y-m-d'),
            'price' => 0.35, // Spot margin
            'payment_unit' => 'c/kWh',
        ]);

        PriceComponent::create([
            'id' => 'pc-spot-monthly',
            'electricity_contract_id' => $contract->id,
            'price_component_type' => 'Monthly',
            'price_date' => now()->format('Y-m-d'),
            'price' => 3.0,
            'payment_unit' => 'EUR/month',
        ]);

        // Create a fixed price contract
        $this->createContract('fixed-contract', 'Kiinteä Sähkö', 5.0, 2.0);

        $component = Livewire::test('company-detail', ['companySlug' => 'test-energy-oy']);
        $stats = $component->viewData('companyStats');

        $this->assertEquals(2, $stats['contract_count']);
        $this->assertEquals(1, $stats['spot_contract_count']);
    }

    /**
     * Helper method to create a contract with price components.
     */
    protected function createContract(
        string $id,
        string $name,
        float $generalPrice,
        float $monthlyFee,
        ?string $pricingModel = null
    ): ElectricityContract {
        $contract = ElectricityContract::create([
            'id' => $id,
            'company_name' => $this->company->name,
            'name' => $name,
            'contract_type' => 'OpenEnded',
            'pricing_model' => $pricingModel ?? 'FixedPrice',
            'metering' => 'General',
            'availability_is_national' => true,
        ]);

        PriceComponent::create([
            'id' => 'pc-' . $id . '-general',
            'electricity_contract_id' => $contract->id,
            'price_component_type' => 'General',
            'price_date' => now()->format('Y-m-d'),
            'price' => $generalPrice,
            'payment_unit' => 'c/kWh',
        ]);

        PriceComponent::create([
            'id' => 'pc-' . $id . '-monthly',
            'electricity_contract_id' => $contract->id,
            'price_component_type' => 'Monthly',
            'price_date' => now()->format('Y-m-d'),
            'price' => $monthlyFee,
            'payment_unit' => 'EUR/month',
        ]);

        return $contract;
    }
}
