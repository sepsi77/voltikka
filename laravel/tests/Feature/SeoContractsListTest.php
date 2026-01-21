<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\ElectricityContract;
use App\Models\ElectricitySource;
use App\Models\PriceComponent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class SeoContractsListTest extends TestCase
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
            'name' => 'Vihreä Voima Ab',
            'name_slug' => 'vihrea-voima-ab',
            'company_url' => 'https://vihreavoima.fi',
            'logo_url' => 'https://storage.example.com/logos/vihrea.png',
        ]);
    }

    /**
     * Helper method to create a contract with price components and optionally energy source.
     */
    private function createContract(
        string $id,
        string $companyName,
        string $name,
        float $price = 5.0,
        float $monthlyFee = 3.0,
        ?array $energySource = null,
        string $pricingModel = 'FixedPrice',
        string $contractType = 'OpenEnded',
    ): ElectricityContract {
        $contract = ElectricityContract::create([
            'id' => $id,
            'company_name' => $companyName,
            'name' => $name,
            'name_slug' => ElectricityContract::generateSlug($name),
            'contract_type' => $contractType,
            'metering' => 'General',
            'pricing_model' => $pricingModel,
            'target_group' => 'Household',
            'availability_is_national' => true,
        ]);

        PriceComponent::create([
            'id' => "pc-general-{$id}",
            'electricity_contract_id' => $id,
            'price_component_type' => 'General',
            'price_date' => now()->format('Y-m-d'),
            'price' => $price,
            'payment_unit' => 'c/kWh',
        ]);

        PriceComponent::create([
            'id' => "pc-monthly-{$id}",
            'electricity_contract_id' => $id,
            'price_component_type' => 'Monthly',
            'price_date' => now()->format('Y-m-d'),
            'price' => $monthlyFee,
            'payment_unit' => 'EUR/month',
        ]);

        if ($energySource) {
            ElectricitySource::create(array_merge([
                'contract_id' => $id,
            ], $energySource));
        }

        return $contract;
    }

    // ==================== Component Initialization Tests ====================

    /**
     * Test that the SEO component exists and can be instantiated.
     */
    public function test_seo_contracts_list_component_exists(): void
    {
        $this->createContract('c1', 'Test Energia Oy', 'Basic Electricity');

        Livewire::test('seo-contracts-list')
            ->assertStatus(200);
    }

    /**
     * Test that the component accepts filter parameters via mount.
     */
    public function test_component_accepts_filter_parameters_via_mount(): void
    {
        $this->createContract('c1', 'Test Energia Oy', 'Basic Electricity');

        Livewire::test('seo-contracts-list', [
            'housingType' => 'omakotitalo',
            'energySource' => 'tuulisahko',
            'city' => 'helsinki',
        ])
            ->assertSet('housingType', 'omakotitalo')
            ->assertSet('energySource', 'tuulisahko')
            ->assertSet('city', 'helsinki');
    }

    // ==================== Housing Type Filter Tests ====================

    /**
     * Test that housing type 'omakotitalo' sets consumption to 18000 kWh.
     */
    public function test_housing_type_omakotitalo_sets_consumption_18000(): void
    {
        $this->createContract('c1', 'Test Energia Oy', 'Basic Electricity');

        Livewire::test('seo-contracts-list', ['housingType' => 'omakotitalo'])
            ->assertSet('consumption', 18000);
    }

    /**
     * Test that housing type 'kerrostalo' sets consumption to 5000 kWh.
     */
    public function test_housing_type_kerrostalo_sets_consumption_5000(): void
    {
        $this->createContract('c1', 'Test Energia Oy', 'Basic Electricity');

        Livewire::test('seo-contracts-list', ['housingType' => 'kerrostalo'])
            ->assertSet('consumption', 5000);
    }

    /**
     * Test that housing type 'rivitalo' sets consumption to 10000 kWh.
     */
    public function test_housing_type_rivitalo_sets_consumption_10000(): void
    {
        $this->createContract('c1', 'Test Energia Oy', 'Basic Electricity');

        Livewire::test('seo-contracts-list', ['housingType' => 'rivitalo'])
            ->assertSet('consumption', 10000);
    }

    // ==================== Energy Source Filter Tests ====================

    /**
     * Test that energy source 'tuulisahko' filters contracts with wind power.
     */
    public function test_energy_source_tuulisahko_filters_wind_contracts(): void
    {
        // Contract with wind power
        $this->createContract('wind-contract', 'Vihreä Voima Ab', 'Tuuli Sähkö', 5.0, 3.0, [
            'renewable_total' => 100.0,
            'renewable_wind' => 80.0,
            'renewable_hydro' => 20.0,
            'fossil_total' => 0.0,
            'nuclear_total' => 0.0,
        ]);

        // Contract without wind power
        $this->createContract('no-wind-contract', 'Test Energia Oy', 'Perus Sähkö', 4.0, 2.0, [
            'renewable_total' => 50.0,
            'renewable_wind' => 0.0,
            'renewable_hydro' => 50.0,
            'fossil_total' => 50.0,
            'nuclear_total' => 0.0,
        ]);

        $component = Livewire::test('seo-contracts-list', ['energySource' => 'tuulisahko']);
        $contracts = $component->viewData('contracts');

        $this->assertCount(1, $contracts);
        $this->assertEquals('wind-contract', $contracts->first()->id);
    }

    /**
     * Test that energy source 'aurinkosahko' filters contracts with solar power.
     */
    public function test_energy_source_aurinkosahko_filters_solar_contracts(): void
    {
        // Contract with solar power
        $this->createContract('solar-contract', 'Vihreä Voima Ab', 'Aurinko Sähkö', 5.0, 3.0, [
            'renewable_total' => 100.0,
            'renewable_solar' => 60.0,
            'renewable_hydro' => 40.0,
            'fossil_total' => 0.0,
            'nuclear_total' => 0.0,
        ]);

        // Contract without solar power
        $this->createContract('no-solar-contract', 'Test Energia Oy', 'Perus Sähkö', 4.0, 2.0, [
            'renewable_total' => 100.0,
            'renewable_solar' => 0.0,
            'renewable_wind' => 100.0,
            'fossil_total' => 0.0,
            'nuclear_total' => 0.0,
        ]);

        $component = Livewire::test('seo-contracts-list', ['energySource' => 'aurinkosahko']);
        $contracts = $component->viewData('contracts');

        $this->assertCount(1, $contracts);
        $this->assertEquals('solar-contract', $contracts->first()->id);
    }

    /**
     * Test that energy source 'vihrea-sahko' filters green energy contracts.
     */
    public function test_energy_source_vihrea_sahko_filters_green_contracts(): void
    {
        // Green contract (>= 50% renewable, no peat)
        $this->createContract('green-contract', 'Vihreä Voima Ab', 'Vihreä Sähkö', 5.0, 3.0, [
            'renewable_total' => 60.0,
            'fossil_total' => 40.0,
            'fossil_peat' => 0.0,
            'nuclear_total' => 0.0,
        ]);

        // Non-green contract (< 50% renewable)
        $this->createContract('fossil-contract', 'Test Energia Oy', 'Fossiili Sähkö', 4.0, 2.0, [
            'renewable_total' => 30.0,
            'fossil_total' => 70.0,
            'fossil_peat' => 0.0,
            'nuclear_total' => 0.0,
        ]);

        // Contract with peat (not green even if high renewable)
        $this->createContract('peat-contract', 'Test Energia Oy', 'Turve Sähkö', 4.5, 2.5, [
            'renewable_total' => 60.0,
            'fossil_total' => 40.0,
            'fossil_peat' => 20.0,
            'nuclear_total' => 0.0,
        ]);

        $component = Livewire::test('seo-contracts-list', ['energySource' => 'vihrea-sahko']);
        $contracts = $component->viewData('contracts');

        $this->assertCount(1, $contracts);
        $this->assertEquals('green-contract', $contracts->first()->id);
    }

    // ==================== SEO Title Generation Tests ====================

    /**
     * Test that component generates SEO title for housing type.
     */
    public function test_generates_seo_title_for_housing_type(): void
    {
        $this->createContract('c1', 'Test Energia Oy', 'Basic Electricity');

        $component = Livewire::test('seo-contracts-list', ['housingType' => 'omakotitalo']);
        $seoData = $component->viewData('seoData');

        $this->assertArrayHasKey('title', $seoData);
        $this->assertStringContainsString('Omakotitalo', $seoData['title']);
    }

    /**
     * Test that component generates SEO title for energy source.
     */
    public function test_generates_seo_title_for_energy_source(): void
    {
        $this->createContract('c1', 'Test Energia Oy', 'Basic Electricity', 5.0, 3.0, [
            'renewable_total' => 100.0,
            'renewable_wind' => 100.0,
            'fossil_total' => 0.0,
            'nuclear_total' => 0.0,
        ]);

        $component = Livewire::test('seo-contracts-list', ['energySource' => 'tuulisahko']);
        $seoData = $component->viewData('seoData');

        $this->assertArrayHasKey('title', $seoData);
        $this->assertStringContainsString('Tuulisähkö', $seoData['title']);
    }

    /**
     * Test that component generates SEO title for city.
     */
    public function test_generates_seo_title_for_city(): void
    {
        $this->createContract('c1', 'Test Energia Oy', 'Basic Electricity');

        $component = Livewire::test('seo-contracts-list', ['city' => 'helsinki']);
        $seoData = $component->viewData('seoData');

        $this->assertArrayHasKey('title', $seoData);
        // Title contains "Helsingissä" (locative form of Helsinki)
        $this->assertStringContainsString('Helsing', $seoData['title']);
    }

    // ==================== SEO Meta Description Tests ====================

    /**
     * Test that component generates meta description for housing type.
     */
    public function test_generates_meta_description_for_housing_type(): void
    {
        $this->createContract('c1', 'Test Energia Oy', 'Basic Electricity');

        $component = Livewire::test('seo-contracts-list', ['housingType' => 'omakotitalo']);
        $seoData = $component->viewData('seoData');

        $this->assertArrayHasKey('description', $seoData);
        $this->assertNotEmpty($seoData['description']);
        // Meta description should mention housing type
        $this->assertStringContainsString('omakotital', mb_strtolower($seoData['description']));
    }

    /**
     * Test that component generates meta description for energy source.
     */
    public function test_generates_meta_description_for_energy_source(): void
    {
        $this->createContract('c1', 'Test Energia Oy', 'Basic Electricity', 5.0, 3.0, [
            'renewable_total' => 100.0,
            'renewable_wind' => 100.0,
            'fossil_total' => 0.0,
            'nuclear_total' => 0.0,
        ]);

        $component = Livewire::test('seo-contracts-list', ['energySource' => 'tuulisahko']);
        $seoData = $component->viewData('seoData');

        $this->assertArrayHasKey('description', $seoData);
        $this->assertNotEmpty($seoData['description']);
        $this->assertStringContainsString('tuuli', mb_strtolower($seoData['description']));
    }

    // ==================== Canonical URL Tests ====================

    /**
     * Test that component generates canonical URL.
     */
    public function test_generates_canonical_url(): void
    {
        $this->createContract('c1', 'Test Energia Oy', 'Basic Electricity');

        $component = Livewire::test('seo-contracts-list', ['housingType' => 'omakotitalo']);
        $seoData = $component->viewData('seoData');

        $this->assertArrayHasKey('canonical', $seoData);
        $this->assertStringContainsString('omakotitalo', $seoData['canonical']);
    }

    /**
     * Test that canonical URL matches the current filter context.
     */
    public function test_canonical_url_matches_filter_context(): void
    {
        $this->createContract('c1', 'Test Energia Oy', 'Basic Electricity', 5.0, 3.0, [
            'renewable_total' => 100.0,
            'renewable_wind' => 100.0,
            'fossil_total' => 0.0,
            'nuclear_total' => 0.0,
        ]);

        $component = Livewire::test('seo-contracts-list', ['energySource' => 'tuulisahko']);
        $seoData = $component->viewData('seoData');

        $this->assertStringContainsString('tuulisahko', $seoData['canonical']);
    }

    // ==================== JSON-LD Structured Data Tests ====================

    /**
     * Test that component includes JSON-LD structured data.
     */
    public function test_includes_json_ld_structured_data(): void
    {
        $this->createContract('c1', 'Test Energia Oy', 'Basic Electricity');

        $component = Livewire::test('seo-contracts-list', ['housingType' => 'omakotitalo']);
        $seoData = $component->viewData('seoData');

        $this->assertArrayHasKey('jsonLd', $seoData);
        $this->assertIsArray($seoData['jsonLd']);
    }

    /**
     * Test that JSON-LD has correct schema type.
     */
    public function test_json_ld_has_correct_schema_type(): void
    {
        $this->createContract('c1', 'Test Energia Oy', 'Basic Electricity');

        $component = Livewire::test('seo-contracts-list', ['housingType' => 'omakotitalo']);
        $seoData = $component->viewData('seoData');

        $this->assertArrayHasKey('@context', $seoData['jsonLd']);
        $this->assertEquals('https://schema.org', $seoData['jsonLd']['@context']);
        $this->assertArrayHasKey('@type', $seoData['jsonLd']);
    }

    /**
     * Test that JSON-LD includes product list for ItemList type.
     */
    public function test_json_ld_includes_product_list(): void
    {
        $this->createContract('c1', 'Test Energia Oy', 'Basic Electricity');
        $this->createContract('c2', 'Vihreä Voima Ab', 'Green Electricity');

        $component = Livewire::test('seo-contracts-list');
        $seoData = $component->viewData('seoData');

        // Should have ItemList type with itemListElement
        $this->assertEquals('ItemList', $seoData['jsonLd']['@type']);
        $this->assertArrayHasKey('itemListElement', $seoData['jsonLd']);
        $this->assertCount(2, $seoData['jsonLd']['itemListElement']);
    }

    /**
     * Test that JSON-LD list items have correct structure.
     */
    public function test_json_ld_list_items_have_correct_structure(): void
    {
        $this->createContract('c1', 'Test Energia Oy', 'Basic Electricity', 5.0, 3.0);

        $component = Livewire::test('seo-contracts-list');
        $seoData = $component->viewData('seoData');

        $listItem = $seoData['jsonLd']['itemListElement'][0];

        $this->assertEquals('ListItem', $listItem['@type']);
        $this->assertArrayHasKey('position', $listItem);
        $this->assertArrayHasKey('item', $listItem);
        $this->assertEquals('Product', $listItem['item']['@type']);
        $this->assertArrayHasKey('name', $listItem['item']);
        $this->assertArrayHasKey('offers', $listItem['item']);
    }

    // ==================== Reuses Parent Filter Logic Tests ====================

    /**
     * Test that pricing model filter from parent still works.
     */
    public function test_pricing_model_filter_from_parent_works(): void
    {
        $this->createContract('spot-contract', 'Test Energia Oy', 'Spot Electricity', 0.5, 3.0, null, 'Spot');
        $this->createContract('fixed-contract', 'Vihreä Voima Ab', 'Fixed Electricity', 5.0, 3.0, null, 'FixedPrice', 'FixedTerm');

        $component = Livewire::test('seo-contracts-list')
            ->set('pricingModelFilter', 'Spot');

        $contracts = $component->viewData('contracts');

        $this->assertCount(1, $contracts);
        $this->assertEquals('spot-contract', $contracts->first()->id);
    }

    /**
     * Test that renewable filter from parent still works.
     */
    public function test_renewable_filter_from_parent_works(): void
    {
        $this->createContract('green-contract', 'Vihreä Voima Ab', 'Green Electricity', 5.0, 3.0, [
            'renewable_total' => 100.0,
            'fossil_total' => 0.0,
            'nuclear_total' => 0.0,
        ]);

        $this->createContract('fossil-contract', 'Test Energia Oy', 'Fossil Electricity', 4.0, 2.0, [
            'renewable_total' => 20.0,
            'fossil_total' => 80.0,
            'nuclear_total' => 0.0,
        ]);

        $component = Livewire::test('seo-contracts-list')
            ->set('renewableFilter', true);

        $contracts = $component->viewData('contracts');

        $this->assertCount(1, $contracts);
        $this->assertEquals('green-contract', $contracts->first()->id);
    }

    // ==================== Page Heading Tests ====================

    /**
     * Test that page has unique H1 for housing type.
     */
    public function test_page_has_unique_h1_for_housing_type(): void
    {
        $this->createContract('c1', 'Test Energia Oy', 'Basic Electricity');

        Livewire::test('seo-contracts-list', ['housingType' => 'omakotitalo'])
            ->assertSee('Sähkösopimukset omakotitaloon');
    }

    /**
     * Test that page has unique H1 for energy source.
     */
    public function test_page_has_unique_h1_for_energy_source(): void
    {
        $this->createContract('c1', 'Test Energia Oy', 'Basic Electricity', 5.0, 3.0, [
            'renewable_total' => 100.0,
            'renewable_wind' => 100.0,
            'fossil_total' => 0.0,
            'nuclear_total' => 0.0,
        ]);

        Livewire::test('seo-contracts-list', ['energySource' => 'tuulisahko'])
            ->assertSee('Tuulisähkösopimukset');
    }

    /**
     * Test that page has unique H1 for city.
     */
    public function test_page_has_unique_h1_for_city(): void
    {
        $this->createContract('c1', 'Test Energia Oy', 'Basic Electricity');

        Livewire::test('seo-contracts-list', ['city' => 'helsinki'])
            ->assertSee('Sähkösopimukset Helsingissä');
    }

    // ==================== Default Behavior Tests ====================

    /**
     * Test that component works without any filter parameters.
     */
    public function test_component_works_without_filter_parameters(): void
    {
        $this->createContract('c1', 'Test Energia Oy', 'Basic Electricity');

        $component = Livewire::test('seo-contracts-list');
        $contracts = $component->viewData('contracts');

        $this->assertCount(1, $contracts);
    }

    /**
     * Test that default SEO data is generated when no filters are set.
     */
    public function test_default_seo_data_is_generated(): void
    {
        $this->createContract('c1', 'Test Energia Oy', 'Basic Electricity');

        $component = Livewire::test('seo-contracts-list');
        $seoData = $component->viewData('seoData');

        $this->assertArrayHasKey('title', $seoData);
        $this->assertArrayHasKey('description', $seoData);
        $this->assertArrayHasKey('canonical', $seoData);
        $this->assertArrayHasKey('jsonLd', $seoData);
    }

    // ==================== View Rendering Tests ====================

    /**
     * Test that SEO view extends contracts-list view.
     */
    public function test_seo_view_displays_contracts(): void
    {
        $this->createContract('c1', 'Test Energia Oy', 'Test Electricity');

        Livewire::test('seo-contracts-list')
            ->assertSee('Test Electricity')
            ->assertSee('Test Energia Oy');
    }

    /**
     * Test that SEO intro text is displayed for housing type.
     */
    public function test_seo_intro_text_displayed_for_housing_type(): void
    {
        $this->createContract('c1', 'Test Energia Oy', 'Basic Electricity');

        Livewire::test('seo-contracts-list', ['housingType' => 'omakotitalo'])
            ->assertSee('kWh'); // Should mention consumption in intro
    }

    /**
     * Test that meta tags are passed to layout.
     */
    public function test_meta_tags_passed_to_layout(): void
    {
        $this->createContract('c1', 'Test Energia Oy', 'Basic Electricity');

        $component = Livewire::test('seo-contracts-list', ['housingType' => 'omakotitalo']);
        $seoData = $component->viewData('seoData');

        // Check that SEO data is available for the layout
        $this->assertNotEmpty($seoData['title']);
        $this->assertNotEmpty($seoData['description']);
    }
}
