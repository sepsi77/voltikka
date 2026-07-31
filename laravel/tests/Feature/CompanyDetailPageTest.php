<?php

namespace Tests\Feature;

use App\Models\ActiveContract;
use App\Models\Company;
use App\Models\ContractSourceObservation;
use App\Models\ContractSourceSnapshot;
use App\Models\ElectricityContract;
use App\Models\ElectricitySource;
use App\Models\PriceComponent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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

    public function test_company_detail_reuses_contracts_for_stats_schema_and_view(): void
    {
        $this->createContract('query-company-contract-1', 'Query Sähkö 1', 4.0, 2.0);
        $this->createContract('query-company-contract-2', 'Query Sähkö 2', 5.0, 2.5);

        DB::enableQueryLog();

        Livewire::test('company-detail', ['companySlug' => 'test-energy-oy'])
            ->assertStatus(200);

        $electricityContractQueries = collect(DB::getQueryLog())
            ->pluck('query')
            ->filter(fn (string $query) => str_contains($query, 'from "electricity_contracts"')
                && str_contains($query, '"company_name" = ?'))
            ->count();

        $this->assertSame(1, $electricityContractQueries);
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
            ->assertSet('selectedPreset', 'small_apartment')
            ->assertSet('directConsumption', null);
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
            ->assertSet('directConsumption', 15000)
            ->assertSet('selectedPreset', null);
    }

    public function test_direct_consumption_input_matches_the_main_listing_selector(): void
    {
        $this->createContract('test-contract-1', 'Test Sähkö', 4.0, 2.0);

        Livewire::test('company-detail', ['companySlug' => 'test-energy-oy'])
            ->assertSee('Vuosikulutus')
            ->assertSee('Tiedän kulutukseni')
            ->assertSee('En tiedä – arvioi laskurilla')
            ->set('directConsumption', 7200)
            ->assertSet('consumption', 7200)
            ->assertSet('selectedPreset', null);
    }

    public function test_custom_query_consumption_activates_the_direct_input(): void
    {
        $this->createContract('test-contract-1', 'Test Sähkö', 4.0, 2.0);

        Livewire::withQueryParams(['consumption' => 7200])
            ->test('company-detail', ['companySlug' => 'test-energy-oy'])
            ->assertSet('consumption', 7200)
            ->assertSet('directConsumption', 7200)
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
        $schemas = $component->viewData('schemas');
        $jsonLd = collect($schemas)->firstWhere('@type', 'Organization');

        $this->assertNotNull($jsonLd);
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
        $schemas = $component->viewData('schemas');
        $jsonLd = collect($schemas)->firstWhere('@type', 'Organization');

        $this->assertNotNull($jsonLd);
        $this->assertArrayHasKey('address', $jsonLd);
        $this->assertEquals('PostalAddress', $jsonLd['address']['@type']);
        $this->assertEquals('Testikatu 1', $jsonLd['address']['streetAddress']);
        $this->assertEquals('00100', $jsonLd['address']['postalCode']);
        $this->assertEquals('Helsinki', $jsonLd['address']['addressLocality']);
    }

    /**
     * Test JSON-LD includes contract products in an item list.
     */
    public function test_json_ld_includes_contract_products(): void
    {
        $this->createContract('test-contract', 'Test Sähkö', 5.0, 2.0);

        $component = Livewire::test('company-detail', ['companySlug' => 'test-energy-oy']);
        $schemas = $component->viewData('schemas');
        $jsonLd = collect($schemas)->firstWhere('@type', 'ItemList');

        $this->assertNotNull($jsonLd);
        $this->assertArrayHasKey('itemListElement', $jsonLd);
        $this->assertCount(1, $jsonLd['itemListElement']);
        $this->assertEquals('Product', $jsonLd['itemListElement'][0]['item']['@type']);
        $this->assertEquals('Test Sähkö', $jsonLd['itemListElement'][0]['item']['name']);
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

        $this->assertSame(
            'Test Energy Oy: vertaa yhtä kotitalouksille sopivaa sähkösopimusta. Katso hinnat, tarjoukset, markkinavertailu ja pörssisähkön kulut.',
            $metaDescription,
        );
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
    public function test_company_without_contracts_shows_honest_empty_copy_and_metadata(): void
    {
        $component = Livewire::test('company-detail', ['companySlug' => 'test-energy-oy']);
        $stats = $component->viewData('companyStats');

        $this->assertEquals(0, $stats['contract_count']);
        $this->assertNull($stats['avg_price']);
        $this->assertSame(
            'Test Energy Oy: kotitalouksille sopivia sähkösopimuksia ei ole nyt vertailussa. Katso sopimustilanne, tarjoukset, markkinavertailu ja pörssisähkön kulut.',
            $component->instance()->metaDescription,
        );
        $component
            ->assertSee('Voltikan vertailussa ei ole tällä hetkellä yhtiön kotitalouksille sopivia sähkösopimuksia.')
            ->assertDontSee('Tällä sivulla näet yhtiön sähkösopimukset, hinnat, tarjoukset ja pörssisähkön myyjäkohtaiset kulut.')
            ->assertDontSee('Hinnat alkavat')
            ->assertDontSee('eurosta vuodessa');
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

    public function test_household_and_business_contracts_are_partitioned_without_losing_both_or_legacy_null(): void
    {
        $household = $this->createContract('household', 'Kotitaloussopimus', 5.0, 2.0, targetGroup: 'Household');
        $both = $this->createContract('both', 'Molemmille', 0.4, 3.0, 'Spot', targetGroup: 'Both');
        $legacy = $this->createContract('legacy', 'Vanha kohderyhmä', 6.0, 2.0, targetGroup: null);
        $business = $this->createContract('business', 'Yrityssopimus', 0.2, 1.0, 'Spot', targetGroup: 'Company');

        PriceComponent::query()->where('electricity_contract_id', $household->id)->where('price_component_type', 'Monthly')->update([
            'has_discount' => true,
            'discount_value' => 1.0,
            'discount_is_percentage' => false,
        ]);
        PriceComponent::query()->where('electricity_contract_id', $business->id)->where('price_component_type', 'Monthly')->update([
            'has_discount' => true,
            'discount_value' => 1.0,
            'discount_is_percentage' => false,
        ]);

        $component = Livewire::test('company-detail', ['companySlug' => 'test-energy-oy']);
        $householdContracts = $component->viewData('contracts');
        $businessContracts = $component->viewData('businessContracts');

        $this->assertEqualsCanonicalizing(
            [$household->id, $both->id, $legacy->id],
            $householdContracts->pluck('id')->all(),
        );
        $this->assertEqualsCanonicalizing(
            [$both->id, $business->id],
            $businessContracts->pluck('id')->all(),
        );
        $this->assertSame(3, $component->viewData('companyStats')['contract_count']);
        $this->assertSame(['household'], $component->viewData('promotionContracts')->pluck('id')->all());
        $this->assertSame(['both'], $component->viewData('spotContracts')->pluck('id')->all());

        $html = $component->html();
        $this->assertLessThan(strpos($html, 'Test Energy Oy sähkösopimukset yrityksille'), strpos($html, 'Test Energy Oy sähkösopimukset'));
        $this->assertLessThan(strpos($html, 'Takaisin sähköyhtiöihin'), strpos($html, 'Test Energy Oy sähkösopimukset yrityksille'));
        $this->assertStringContainsString('3 kotitalouksille sopivaa sopimusta saatavilla', strip_tags($html));
        $this->assertStringContainsString('2 yrityksille sopivaa sopimusta saatavilla', strip_tags($html));
    }

    public function test_title_and_h1_lead_with_the_company_price_intent_without_rank(): void
    {
        $this->createContract('title-contract', 'Test Sähkö', 5.0, 2.0);

        $component = Livewire::test('company-detail', ['companySlug' => 'test-energy-oy']);

        $this->assertSame('Test Energy Oy: sähkön hinta verrattuna markkinaan | Voltikka', $component->instance()->pageTitle);
        $this->assertSame('Test Energy Oy: sähkön hinta ja sähkösopimukset', $component->instance()->h1);
        $component
            ->assertSee('Test Energy Oy: sähkön hinta ja sähkösopimukset')
            ->assertDontSee('#1 halvin')
            ->assertDontSee('Kaikki 1 sopimusta vertailussa');

        $response = $this->get('/sahkosopimus/sahkoyhtiot/test-energy-oy');
        $response
            ->assertSee('<title>Test Energy Oy: sähkön hinta verrattuna markkinaan | Voltikka</title>', false)
            ->assertSee('<h1', false)
            ->assertSee('Test Energy Oy: sähkön hinta ja sähkösopimukset');
    }

    public function test_hero_copy_uses_complete_sentences_and_selected_consumption(): void
    {
        $this->createContract('fixed-one', 'Kiinteä Sähkö', 5.0, 2.0);
        $this->createContract('spot-one', 'Pörssi Sähkö', 0.45, 3.90, 'Spot');

        $component = Livewire::test('company-detail', ['companySlug' => 'test-energy-oy'])
            ->call('setConsumption', 7200);
        $description = $component->instance()->heroDescription;

        $this->assertStringStartsWith('Voltikka vertaa 2 kotitalouksille sopivaa sähkösopimusta.', $description);
        $this->assertSame(
            'Test Energy Oy: vertaa 2 kotitalouksille sopivaa sähkösopimusta. Katso hinnat, tarjoukset, markkinavertailu ja pörssisähkön kulut.',
            $component->instance()->metaDescription,
        );
        $minimum = $component->viewData('companyStats')['min_price'];
        $this->assertStringContainsString(
            'Hinnat alkavat '.number_format($minimum, 0, ',', ' ').' eurosta vuodessa 7 200 kWh:n kulutuksella.',
            $description,
        );
        $this->assertStringContainsString('Mukana on 1 pörssisähkösopimus.', $description);
        $this->assertStringNotContainsString('. hinnat', $description);
        $component->assertSee('Tällä sivulla näet yhtiön sähkösopimukset, hinnat, tarjoukset ja pörssisähkön myyjäkohtaiset kulut.');
    }

    public function test_summary_and_consumption_control_use_the_approved_heading_semantics(): void
    {
        $this->createContract('spot-one', 'Pörssi Sähkö', 0.45, 3.90, 'Spot');
        $html = Livewire::test('company-detail', ['companySlug' => 'test-energy-oy'])->html();

        $this->assertMatchesRegularExpression('/<h2[^>]*>\s*Test Energy Oy: hinnat lyhyesti\s*<\/h2>/', $html);
        $this->assertStringContainsString('1 pörssisähkösopimus', strip_tags($html));
        $this->assertDoesNotMatchRegularExpression('/<h3[^>]*>\s*Vuosikulutus\s*<\/h3>/', $html);
        $this->assertMatchesRegularExpression('/<p[^>]*>Vuosikulutus<\/p>/', $html);
    }

    public function test_update_date_prefers_the_latest_active_source_observation_and_updates_webpage_schema(): void
    {
        $older = $this->createContract('older-snapshot', 'Vanhempi', 5.0, 2.0);
        $newer = $this->createContract('newer-snapshot', 'Uudempi', 6.0, 2.0, targetGroup: 'Company');

        foreach ([[$older, '2026-08-01 09:00:00', 'a'], [$newer, '2026-08-04 15:30:00', 'b']] as [$contract, $observedAt, $fingerprint]) {
            $snapshot = ContractSourceSnapshot::create([
                'contract_id' => $contract->id,
                'source_fingerprint' => str_repeat($fingerprint, 64),
                'source_payload' => [],
                'first_observed_at' => $observedAt,
                'last_observed_at' => $observedAt,
            ]);
            $observation = ContractSourceObservation::create([
                'contract_id' => $contract->id,
                'source_snapshot_id' => $snapshot->id,
                'first_observed_at' => $observedAt,
                'last_observed_at' => $observedAt,
            ]);
            $contract->update(['current_source_observation_id' => $observation->id]);
        }

        $component = Livewire::test('company-detail', ['companySlug' => 'test-energy-oy']);
        $webPage = collect($component->viewData('schemas'))->firstWhere('@type', 'WebPage');

        $component->assertSee('Päivitetty 4.8.2026');
        $this->assertStringStartsWith('2026-08-04T15:30:00', $webPage['dateModified']);
    }

    public function test_update_date_falls_back_to_the_latest_active_price_date(): void
    {
        $contract = $this->createContract('legacy-date', 'Vanha sopimus', 5.0, 2.0);
        PriceComponent::query()->where('electricity_contract_id', $contract->id)->update(['price_date' => '2026-07-31']);

        Livewire::test('company-detail', ['companySlug' => 'test-energy-oy'])
            ->assertSee('Päivitetty 31.7.2026');
    }

    public function test_update_date_compares_episode_and_mixed_legacy_contract_dates(): void
    {
        $episodeContract = $this->createContract('episode-date', 'Jakso', 5.0, 2.0);
        $legacyContract = $this->createContract('mixed-legacy-date', 'Vanha', 6.0, 2.0);
        $snapshot = ContractSourceSnapshot::create([
            'contract_id' => $episodeContract->id,
            'source_fingerprint' => str_repeat('a', 64),
            'source_payload' => [],
            'first_observed_at' => '2026-08-01 09:00:00',
            'last_observed_at' => '2026-08-01 09:00:00',
        ]);
        $observation = ContractSourceObservation::create([
            'contract_id' => $episodeContract->id,
            'source_snapshot_id' => $snapshot->id,
            'first_observed_at' => '2026-08-01 09:00:00',
            'last_observed_at' => '2026-08-01 09:00:00',
        ]);
        $episodeContract->update(['current_source_observation_id' => $observation->id]);
        PriceComponent::query()
            ->where('electricity_contract_id', $legacyContract->id)
            ->update(['price_date' => '2026-08-05']);
        PriceComponent::query()
            ->where('electricity_contract_id', $episodeContract->id)
            ->update(['price_date' => '2026-08-09']);

        Livewire::test('company-detail', ['companySlug' => 'test-energy-oy'])
            ->assertSee('Päivitetty 5.8.2026');
    }

    public function test_organization_area_served_requires_an_explicit_national_contract(): void
    {
        $contract = $this->createContract('national', 'Valtakunnallinen', 5.0, 2.0, targetGroup: 'Household');

        $component = Livewire::test('company-detail', ['companySlug' => 'test-energy-oy']);
        $organization = collect($component->viewData('schemas'))->firstWhere('@type', 'Organization');
        $this->assertSame('Finland', $organization['areaServed']['name']);

        $contract->update(['availability_is_national' => false]);

        $component = Livewire::test('company-detail', ['companySlug' => 'test-energy-oy']);
        $organization = collect($component->viewData('schemas'))->firstWhere('@type', 'Organization');
        $this->assertArrayNotHasKey('areaServed', $organization);
        $component->assertDontSee('Missä Test Energy Oy myy sähköä?');
    }

    public function test_business_section_is_hidden_when_no_business_contract_exists(): void
    {
        $this->createContract('household-only', 'Kotitaloussopimus', 5.0, 2.0, targetGroup: 'Household');

        Livewire::test('company-detail', ['companySlug' => 'test-energy-oy'])
            ->assertDontSee('Test Energy Oy sähkösopimukset yrityksille');
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

        ActiveContract::create(['id' => $contract->id]);

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
        ?string $pricingModel = null,
        ?string $targetGroup = null,
        bool $isNational = true,
    ): ElectricityContract {
        $contract = ElectricityContract::create([
            'id' => $id,
            'company_name' => $this->company->name,
            'name' => $name,
            'contract_type' => 'OpenEnded',
            'pricing_model' => $pricingModel ?? 'FixedPrice',
            'metering' => 'General',
            'target_group' => $targetGroup,
            'availability_is_national' => $isNational,
        ]);

        PriceComponent::create([
            'id' => 'pc-'.$id.'-general',
            'electricity_contract_id' => $contract->id,
            'price_component_type' => 'General',
            'price_date' => now()->format('Y-m-d'),
            'price' => $generalPrice,
            'payment_unit' => 'c/kWh',
        ]);

        PriceComponent::create([
            'id' => 'pc-'.$id.'-monthly',
            'electricity_contract_id' => $contract->id,
            'price_component_type' => 'Monthly',
            'price_date' => now()->format('Y-m-d'),
            'price' => $monthlyFee,
            'payment_unit' => 'EUR/month',
        ]);

        ActiveContract::create(['id' => $contract->id]);

        return $contract;
    }
}
