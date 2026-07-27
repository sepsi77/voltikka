<?php

namespace Tests\Feature;

use App\Models\ActiveContract;
use App\Models\Company;
use App\Models\ElectricityContract;
use App\Models\ElectricitySource;
use App\Models\Municipality;
use App\Models\PriceComponent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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

        // Create test municipality for city tests
        Municipality::create([
            'code' => '091',
            'slug' => 'helsinki',
            'name' => 'Helsinki',
            'name_locative' => 'Helsingissä',
            'name_genitive' => 'Helsingin',
            'region_code' => '01',
            'region_name' => 'Uusimaa',
            'center_latitude' => 60.1699,
            'center_longitude' => 24.9384,
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
        string $targetGroup = 'Household',
        ?string $canonicalStatus = null,
        bool $recurringReset = false,
        ?string $consumptionEffectAppliesTo = null,
    ): ElectricityContract {
        $contract = ElectricityContract::create([
            'id' => $id,
            'company_name' => $companyName,
            'name' => $name,
            'name_slug' => ElectricityContract::generateSlug($name),
            'contract_type' => $contractType,
            'metering' => 'General',
            'pricing_model' => $pricingModel,
            'target_group' => $targetGroup,
            'availability_is_national' => true,
            // Canonical calculation status drives the fully-fixed (kiintea-hinta) filter. Default to
            // a realistic value per model: FixedPrice → exact (fully fixed), Spot → estimate_required,
            // Hybrid → unsupported. A market-reset FixedPrice passes $canonicalStatus/$recurringReset.
            'canonical_calculation' => [
                'status' => $canonicalStatus ?? match ($pricingModel) {
                    'Spot' => 'estimate_required',
                    'Hybrid' => 'unsupported',
                    default => 'exact',
                },
                'missing_facts' => [],
                'required_assumptions' => [],
            ],
            'canonical_pricing' => [
                'phases' => [],
                'recurring_schedule' => [
                    'present' => $recurringReset,
                    'cadence' => $recurringReset ? 'quarterly' : 'none',
                ],
                'consumption_effect' => [
                    'present' => $consumptionEffectAppliesTo !== null,
                    'applies_to' => $consumptionEffectAppliesTo ?? 'unknown',
                ],
            ],
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

        // Mark contract as active so it appears in listings
        ActiveContract::create(['id' => $id]);

        return $contract;
    }

    // ==================== Component Initialization Tests ====================

    public function test_render_does_not_lazy_load_card_relations_per_contract(): void
    {
        for ($i = 1; $i <= 3; $i++) {
            $this->createContract("query-card-{$i}", 'Test Energia Oy', "Query Card {$i}", 5.0 + $i, 3.0, [
                'renewable_total' => 100.0,
                'renewable_wind' => 100.0,
                'fossil_total' => 0.0,
                'nuclear_total' => 0.0,
            ]);
        }

        DB::enableQueryLog();

        Livewire::test('seo-contracts-list')
            ->assertStatus(200);

        $queries = collect(DB::getQueryLog())->pluck('query');

        $priceComponentQueries = $queries
            ->filter(fn (string $query) => str_contains($query, 'from "price_components"'))
            ->count();
        $electricitySourceQueries = $queries
            ->filter(fn (string $query) => str_contains($query, 'from "electricity_sources"'))
            ->count();

        // One bulk latest-price query for calculations and one eager-load query
        // for visible cards. Electricity sources are loaded through fixed bulk
        // queries during list building/rendering, never once per contract.
        $this->assertLessThanOrEqual(2, $priceComponentQueries);
        $this->assertLessThanOrEqual(3, $electricitySourceQueries);
    }

    /**
     * Test that the SEO component exists and can be instantiated.
     */
    public function test_seo_contracts_list_component_exists(): void
    {
        $this->createContract('c1', 'Test Energia Oy', 'Basic Electricity');

        Livewire::test('seo-contracts-list')
            ->assertStatus(200);
    }

    public function test_inline_calculator_updates_on_seo_pages(): void
    {
        $this->createContract('c1', 'Test Energia Oy', 'Basic Electricity');

        Livewire::test('seo-contracts-list', ['pricingType' => 'TimeOfUse'])
            ->set('activeTab', 'calculator')
            ->set('calcLivingArea', 100)
            ->set('calcNumPeople', 3)
            ->assertSet('calcLivingArea', 100)
            ->assertSet('calcNumPeople', 3)
            ->assertSet('selectedPreset', null)
            ->assertStatus(200);
    }

    public function test_inline_calculator_tolerates_blank_seo_page_inputs(): void
    {
        $this->createContract('c1', 'Test Energia Oy', 'Basic Electricity');

        Livewire::test('seo-contracts-list', ['pricingType' => 'TimeOfUse'])
            ->set('activeTab', 'calculator')
            ->set('calcLivingArea', '')
            ->set('calcNumPeople', '')
            ->set('calcBathroomHeatingArea', '')
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

    public function test_explicit_query_consumption_overrides_housing_default(): void
    {
        $this->createContract('c1', 'Test Energia Oy', 'Basic Electricity');

        Livewire::withQueryParams(['consumption' => 7500])
            ->test('seo-contracts-list', ['housingType' => 'omakotitalo'])
            ->assertSet('consumption', 7500)
            ->assertSet('selectedPreset', null)
            ->assertSet('directConsumption', 7500);
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
        $this->assertArrayHasKey('@graph', $seoData['jsonLd']);

        $types = collect($seoData['jsonLd']['@graph'])->pluck('@type')->all();
        $this->assertContains('WebPage', $types);
        $this->assertContains('Service', $types);
        $this->assertContains('ItemList', $types);
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

        $itemList = collect($seoData['jsonLd']['@graph'])
            ->firstWhere('@type', 'ItemList');

        $this->assertNotNull($itemList);
        $this->assertArrayHasKey('itemListElement', $itemList);
        $this->assertCount(2, $itemList['itemListElement']);
    }

    /**
     * Test that JSON-LD list items have correct structure.
     */
    public function test_json_ld_list_items_have_correct_structure(): void
    {
        $this->createContract('c1', 'Test Energia Oy', 'Basic Electricity', 5.0, 3.0);

        $component = Livewire::test('seo-contracts-list');
        $seoData = $component->viewData('seoData');

        $itemList = collect($seoData['jsonLd']['@graph'])
            ->firstWhere('@type', 'ItemList');
        $listItem = $itemList['itemListElement'][0];

        $this->assertEquals('ListItem', $listItem['@type']);
        $this->assertArrayHasKey('position', $listItem);
        $this->assertArrayHasKey('item', $listItem);
        $this->assertEquals('Product', $listItem['item']['@type']);
        $this->assertArrayHasKey('name', $listItem['item']);
        $this->assertEquals('Electricity Contract', $listItem['item']['category']);
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

    // ==================== Fixed Price Pricing Type Tests ====================

    public function test_fixed_price_pricing_type_filters_fixed_price_contracts(): void
    {
        $this->createContract('fixed-1', 'Test Energia Oy', 'Kiinteä Sopimus', 5.0, 3.0, null, 'FixedPrice');
        $this->createContract('spot-1', 'Vihreä Voima Ab', 'Pörssisähkö Sopimus', 0.5, 2.0, null, 'Spot');
        $this->createContract('hybrid-1', 'Test Energia Oy', 'Joustosähkö Sopimus', 4.5, 3.0, null, 'Hybrid');

        $component = Livewire::test('seo-contracts-list', ['pricingType' => 'FixedPrice']);
        $contracts = $component->viewData('contracts');

        $this->assertCount(1, $contracts);
        $this->assertEquals('fixed-1', $contracts->first()->id);
    }

    public function test_fixed_price_page_excludes_market_reset_contracts_classified_as_fixedprice(): void
    {
        // Kvartaalisähkö is pricing_model=FixedPrice in the source enum but resets from the market
        // (recurring schedule + estimate_required). It must NOT appear on the fully-fixed page.
        $this->createContract('truly-fixed', 'Test Energia Oy', 'Kiinteä 12 kk', 5.0, 3.0, null, 'FixedPrice');
        $this->createContract(
            'kvartaali', 'Vihreä Voima Ab', 'Kvartaalisähkö', 7.0, 4.0, null, 'FixedPrice', 'OpenEnded', 'Household',
            canonicalStatus: 'estimate_required', recurringReset: true,
        );

        $contracts = Livewire::test('seo-contracts-list', ['pricingType' => 'FixedPrice'])->viewData('contracts');

        $this->assertCount(1, $contracts);
        $this->assertEquals('truly-fixed', $contracts->first()->id);
    }

    public function test_fixed_price_canonical_url_is_kiintea_hinta(): void
    {
        $this->createContract('fixed-1', 'Test Energia Oy', 'Kiinteä Sopimus', 5.0, 3.0, null, 'FixedPrice');

        $component = Livewire::test('seo-contracts-list', ['pricingType' => 'FixedPrice']);
        $seoData = $component->viewData('seoData');

        $this->assertStringEndsWith('/sahkosopimus/kiintea-hinta', $seoData['canonical']);
    }

    public function test_fixed_price_page_h1_is_kiinteahintaiset_sahkosopimukset(): void
    {
        $this->createContract('fixed-1', 'Test Energia Oy', 'Kiinteä Sopimus', 5.0, 3.0, null, 'FixedPrice');

        Livewire::test('seo-contracts-list', ['pricingType' => 'FixedPrice'])
            ->assertSee('Täysin kiinteähintaiset sähkösopimukset');
    }

    // ==================== Consumption Effect (Kulutusvaikutus) Pricing Type Tests ====================

    public function test_consumption_effect_page_filters_base_contract_effect_contracts(): void
    {
        // A Hybrid with a mandatory base consumption effect is a kulutusvaikutus contract.
        $this->createContract(
            'ce-hybrid', 'Test Energia Oy', 'Joustohinta 12 kk', 5.0, 3.0, null, 'Hybrid', 'OpenEnded', 'Household',
            canonicalStatus: 'unsupported', consumptionEffectAppliesTo: 'base_contract',
        );
        // A plain fixed contract has no consumption effect.
        $this->createContract('ce-fixed', 'Test Energia Oy', 'Kiinteä 12 kk', 5.0, 3.0, null, 'FixedPrice');
        // A Spot contract whose effect only applies to optional fixing must NOT appear here.
        $this->createContract(
            'ce-spot-optional', 'Vihreä Voima Ab', 'Pörssisähkö + fixaus', 0.5, 2.0, null, 'Spot', 'OpenEnded', 'Household',
            canonicalStatus: 'estimate_required', consumptionEffectAppliesTo: 'optional_fixing',
        );

        $contracts = Livewire::test('seo-contracts-list', ['pricingType' => 'ConsumptionEffect'])->viewData('contracts');

        $this->assertCount(1, $contracts);
        $this->assertEquals('ce-hybrid', $contracts->first()->id);
    }

    public function test_consumption_effect_page_seo_metadata(): void
    {
        $this->createContract(
            'ce-hybrid', 'Test Energia Oy', 'Joustohinta 12 kk', 5.0, 3.0, null, 'Hybrid', 'OpenEnded', 'Household',
            canonicalStatus: 'unsupported', consumptionEffectAppliesTo: 'base_contract',
        );

        $seoData = Livewire::test('seo-contracts-list', ['pricingType' => 'ConsumptionEffect'])
            ->assertSee('Kulutusvaikutukselliset sähkösopimukset')
            ->viewData('seoData');

        $this->assertStringContainsString('kulutusvaikutuksellisia', $seoData['title']);
        $this->assertStringEndsWith('/sahkosopimus/kulutusvaikutus', $seoData['canonical']);
    }

    // ==================== Hybrid (Joustosähkö) Pricing Type Tests ====================

    /**
     * Test that the Hybrid pricing type page loads successfully.
     */
    public function test_hybrid_pricing_type_page_loads(): void
    {
        $this->createContract('hybrid-1', 'Test Energia Oy', 'Joustosähkö Sopimus', 5.0, 3.0, null, 'Hybrid');

        Livewire::test('seo-contracts-list', ['pricingType' => 'Hybrid'])
            ->assertStatus(200);
    }

    /**
     * Test that the Hybrid pricing type page filters only Hybrid contracts.
     */
    public function test_hybrid_pricing_type_filters_hybrid_contracts(): void
    {
        $this->createContract('hybrid-1', 'Test Energia Oy', 'Joustosähkö Sopimus', 5.0, 3.0, null, 'Hybrid');
        $this->createContract('fixed-1', 'Vihreä Voima Ab', 'Kiinteä Sopimus', 4.0, 2.0, null, 'FixedPrice', 'FixedTerm');

        $component = Livewire::test('seo-contracts-list', ['pricingType' => 'Hybrid']);
        $contracts = $component->viewData('contracts');

        $this->assertCount(1, $contracts);
        $this->assertEquals('hybrid-1', $contracts->first()->id);
    }

    /**
     * Test that the Hybrid page SEO title contains joustosähkö and hybridisähkö.
     */
    public function test_hybrid_seo_title_contains_jousto_and_hybridi(): void
    {
        $this->createContract('hybrid-1', 'Test Energia Oy', 'Joustosähkö Sopimus', 5.0, 3.0, null, 'Hybrid');

        $component = Livewire::test('seo-contracts-list', ['pricingType' => 'Hybrid']);
        $seoData = $component->viewData('seoData');

        $this->assertStringContainsString('joustosähkö', mb_strtolower($seoData['title']));
        $this->assertStringContainsString('hybridisähkö', mb_strtolower($seoData['title']));
    }

    /**
     * Test that the Hybrid page meta description contains joustosähkö.
     */
    public function test_hybrid_meta_description_contains_joustosahko(): void
    {
        $this->createContract('hybrid-1', 'Test Energia Oy', 'Joustosähkö Sopimus', 5.0, 3.0, null, 'Hybrid');

        $component = Livewire::test('seo-contracts-list', ['pricingType' => 'Hybrid']);
        $seoData = $component->viewData('seoData');

        $this->assertStringContainsString('joustosähkö', mb_strtolower($seoData['description']));
    }

    /**
     * Test that the Hybrid page canonical URL is /sahkosopimus/joustosahko.
     */
    public function test_hybrid_canonical_url_is_joustosahko(): void
    {
        $this->createContract('hybrid-1', 'Test Energia Oy', 'Joustosähkö Sopimus', 5.0, 3.0, null, 'Hybrid');

        $component = Livewire::test('seo-contracts-list', ['pricingType' => 'Hybrid']);
        $seoData = $component->viewData('seoData');

        $this->assertStringEndsWith('/sahkosopimus/joustosahko', $seoData['canonical']);
    }

    /**
     * Test that the Hybrid page H1 heading is Joustosähkösopimukset.
     */
    public function test_hybrid_page_h1_is_joustosahkosopimukset(): void
    {
        $this->createContract('hybrid-1', 'Test Energia Oy', 'Joustosähkö Sopimus', 5.0, 3.0, null, 'Hybrid');

        Livewire::test('seo-contracts-list', ['pricingType' => 'Hybrid'])
            ->assertSee('Joustosähkösopimukset');
    }

    // ==================== Business (Company) Page Tests ====================

    /**
     * Test that the business page loads successfully.
     */
    public function test_business_page_loads_successfully(): void
    {
        $this->createContract('biz-1', 'Test Energia Oy', 'Business Electricity', 5.0, 3.0, null, 'FixedPrice', 'OpenEnded', 'Company');

        Livewire::test('seo-contracts-list', ['targetGroup' => 'Company'])
            ->assertStatus(200);
    }

    /**
     * Test that the business page filters only Company/Both target_group contracts.
     */
    public function test_business_page_filters_company_contracts(): void
    {
        $this->createContract('biz-1', 'Test Energia Oy', 'Business Electricity', 5.0, 3.0, null, 'FixedPrice', 'OpenEnded', 'Company');
        $this->createContract('biz-2', 'Vihreä Voima Ab', 'Both Electricity', 4.0, 2.0, null, 'FixedPrice', 'OpenEnded', 'Both');
        $this->createContract('home-1', 'Test Energia Oy', 'Home Electricity', 3.0, 2.0, null, 'FixedPrice', 'OpenEnded', 'Household');

        $component = Livewire::test('seo-contracts-list', ['targetGroup' => 'Company']);
        $contracts = $component->viewData('contracts');

        // Should include Company and Both, but not Household-only
        $contractIds = $contracts->pluck('id')->toArray();
        $this->assertContains('biz-1', $contractIds);
        $this->assertContains('biz-2', $contractIds);
        $this->assertNotContains('home-1', $contractIds);
    }

    /**
     * Test that the business page sets correct defaults.
     */
    public function test_business_page_sets_correct_defaults(): void
    {
        $this->createContract('biz-1', 'Test Energia Oy', 'Business Electricity', 5.0, 3.0, null, 'FixedPrice', 'OpenEnded', 'Company');

        Livewire::test('seo-contracts-list', ['targetGroup' => 'Company'])
            ->assertSet('consumption', 20000)
            ->assertSet('selectedPreset', 'small_office');
    }

    /**
     * Test that showCalculatorTab is false for business pages.
     */
    public function test_business_page_hides_calculator_tab(): void
    {
        $this->createContract('biz-1', 'Test Energia Oy', 'Business Electricity', 5.0, 3.0, null, 'FixedPrice', 'OpenEnded', 'Company');

        $component = Livewire::test('seo-contracts-list', ['targetGroup' => 'Company']);

        $this->assertFalse($component->viewData('showCalculatorTab'));
    }

    /**
     * Test that business page has business presets.
     */
    public function test_business_page_has_business_presets(): void
    {
        $this->createContract('biz-1', 'Test Energia Oy', 'Business Electricity', 5.0, 3.0, null, 'FixedPrice', 'OpenEnded', 'Company');

        $component = Livewire::test('seo-contracts-list', ['targetGroup' => 'Company']);
        $presets = $component->get('presets');

        $this->assertArrayHasKey('small_office', $presets);
        $this->assertArrayHasKey('restaurant', $presets);
        $this->assertArrayNotHasKey('large_apartment', $presets);
    }

    /**
     * Test that business page SEO title contains "yrityksille".
     */
    public function test_business_page_seo_title_contains_yrityksille(): void
    {
        $this->createContract('biz-1', 'Test Energia Oy', 'Business Electricity', 5.0, 3.0, null, 'FixedPrice', 'OpenEnded', 'Company');

        $component = Livewire::test('seo-contracts-list', ['targetGroup' => 'Company']);
        $seoData = $component->viewData('seoData');

        $this->assertStringContainsString('yrityksille', mb_strtolower($seoData['title']));
    }

    /**
     * Test that business page canonical URL ends with /sahkosopimus/yritykselle.
     */
    public function test_business_page_canonical_url(): void
    {
        $this->createContract('biz-1', 'Test Energia Oy', 'Business Electricity', 5.0, 3.0, null, 'FixedPrice', 'OpenEnded', 'Company');

        $component = Livewire::test('seo-contracts-list', ['targetGroup' => 'Company']);
        $seoData = $component->viewData('seoData');

        $this->assertStringEndsWith('/sahkosopimus/yritykselle', $seoData['canonical']);
    }

    /**
     * Test that business page H1 is "Sähkösopimukset yrityksille".
     */
    public function test_business_page_h1(): void
    {
        $this->createContract('biz-1', 'Test Energia Oy', 'Business Electricity', 5.0, 3.0, null, 'FixedPrice', 'OpenEnded', 'Company');

        Livewire::test('seo-contracts-list', ['targetGroup' => 'Company'])
            ->assertSee('Sähkösopimukset yrityksille');
    }

    /**
     * Test that business page has only 3 pricing models.
     */
    public function test_business_page_has_three_pricing_models(): void
    {
        $this->createContract('biz-1', 'Test Energia Oy', 'Business Electricity', 5.0, 3.0, null, 'FixedPrice', 'OpenEnded', 'Company');

        $component = Livewire::test('seo-contracts-list', ['targetGroup' => 'Company']);
        $pricingModels = $component->get('pricingModels');

        $this->assertCount(3, $pricingModels);
        $this->assertArrayHasKey('FixedPrice', $pricingModels);
        $this->assertArrayHasKey('Spot', $pricingModels);
        $this->assertArrayHasKey('Hybrid', $pricingModels);
    }
}
