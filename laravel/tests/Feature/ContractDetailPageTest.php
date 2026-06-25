<?php

namespace Tests\Feature;

use App\Models\ActiveContract;
use App\Models\Company;
use App\Models\ElectricityContract;
use App\Models\ElectricitySource;
use App\Models\PriceComponent;
use App\Services\Caching\ContractPageCacheVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

class ContractDetailPageTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected ElectricityContract $contract;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        // Create test company
        $this->company = Company::create([
            'name' => 'Test Energia Oy',
            'name_slug' => 'test-energia-oy',
            'company_url' => 'https://testenergia.fi',
            'street_address' => 'Energiakatu 1',
            'postal_code' => '00100',
            'postal_name' => 'Helsinki',
            'logo_url' => 'https://storage.example.com/logos/test-energia.png',
        ]);

        // Create test contract
        $this->contract = ElectricityContract::create([
            'id' => 'contract-detail-test',
            'company_name' => 'Test Energia Oy',
            'name' => 'Perus Sähkö 24kk',
            'name_slug' => 'perus-sahko-24kk',
            'contract_type' => 'Fixed',
            'fixed_time_range' => '24 months',
            'metering' => 'General',
            'short_description' => 'Edullinen kiinteähintainen sähkösopimus.',
            'long_description' => 'Tämä on pidempi kuvaus sopimuksesta. Se sisältää lisätietoja hinnoittelusta ja ehdoista.',
            'product_link' => 'https://testenergia.fi/products/perus-sahko',
            'order_link' => 'https://testenergia.fi/order/perus-sahko',
            'billing_frequency' => ['monthly'],
            'availability_is_national' => true,
            'microproduction_buys' => true,
            'microproduction_default' => 'Ostamme ylituotannon spot-hintaan.',
        ]);

        ActiveContract::create(['id' => $this->contract->id]);

        // Create price components
        PriceComponent::create([
            'id' => 'pc-general-detail',
            'electricity_contract_id' => 'contract-detail-test',
            'price_component_type' => 'General',
            'price_date' => now()->format('Y-m-d'),
            'price' => 5.5,
            'payment_unit' => 'c/kWh',
        ]);

        PriceComponent::create([
            'id' => 'pc-monthly-detail',
            'electricity_contract_id' => 'contract-detail-test',
            'price_component_type' => 'Monthly',
            'price_date' => now()->format('Y-m-d'),
            'price' => 2.95,
            'payment_unit' => 'EUR/month',
        ]);

        // Create electricity source
        ElectricitySource::create([
            'contract_id' => 'contract-detail-test',
            'renewable_total' => 65.0,
            'renewable_wind' => 40.0,
            'renewable_hydro' => 20.0,
            'renewable_solar' => 5.0,
            'nuclear_total' => 30.0,
            'fossil_total' => 5.0,
        ]);
    }

    /**
     * Test that the contract detail page is accessible.
     */
    public function test_contract_detail_page_is_accessible(): void
    {
        $response = $this->get('/sahkosopimus/sopimus/contract-detail-test');

        $response->assertStatus(200);
    }

    /**
     * Test that the contract detail page displays the Livewire component.
     */
    public function test_contract_detail_page_renders_livewire_component(): void
    {
        $response = $this->get('/sahkosopimus/sopimus/contract-detail-test');

        $response->assertStatus(200);
        $response->assertSeeLivewire('contract-detail');
    }

    /**
     * Test that the contract name is displayed.
     */
    public function test_contract_name_is_displayed(): void
    {
        Livewire::test('contract-detail', ['contractId' => 'contract-detail-test'])
            ->assertSee('Perus Sähkö 24kk');
    }

    /**
     * Test that the company name is displayed.
     */
    public function test_company_name_is_displayed(): void
    {
        Livewire::test('contract-detail', ['contractId' => 'contract-detail-test'])
            ->assertSee('Test Energia Oy');
    }

    public function test_company_name_links_to_company_detail_page(): void
    {
        Livewire::test('contract-detail', ['contractId' => 'contract-detail-test'])
            ->assertSeeHtml('href="/sahkosopimus/sahkoyhtiot/test-energia-oy"');
    }

    public function test_duration_and_metering_badges_link_to_comparison_pages(): void
    {
        Livewire::test('contract-detail', ['contractId' => 'contract-detail-test'])
            ->assertSeeHtml('href="/sahkosopimus/maaraaikainen"')
            ->assertSeeHtml('href="/sahkosopimus/yleissahko"');
    }

    public function test_spot_pricing_badge_links_to_spot_comparison_page(): void
    {
        $contract = ElectricityContract::create([
            'id' => 'spot-contract-detail-test',
            'company_name' => 'Test Energia Oy',
            'name' => 'Spot Sähkö',
            'name_slug' => 'spot-sahko',
            'contract_type' => 'OpenEnded',
            'metering' => 'General',
            'pricing_model' => 'Spot',
            'availability_is_national' => true,
        ]);

        ActiveContract::create(['id' => $contract->id]);

        PriceComponent::create([
            'id' => 'pc-spot-margin-detail',
            'electricity_contract_id' => $contract->id,
            'price_component_type' => 'General',
            'price_date' => now()->format('Y-m-d'),
            'price' => 0.45,
            'payment_unit' => 'c/kWh',
        ]);

        Livewire::test('contract-detail', ['contractId' => $contract->id])
            ->assertSeeHtml('href="/sahkosopimus/porssisahko"')
            ->assertDontSeeHtml('href="/sahkosopimus/yleissahko"');
    }

    public function test_hybrid_pricing_badge_links_to_hybrid_comparison_page(): void
    {
        $contract = ElectricityContract::create([
            'id' => 'hybrid-contract-detail-test',
            'company_name' => 'Test Energia Oy',
            'name' => 'Jousto Sähkö',
            'name_slug' => 'jousto-sahko',
            'contract_type' => 'OpenEnded',
            'metering' => 'General',
            'pricing_model' => 'Hybrid',
            'availability_is_national' => true,
        ]);

        ActiveContract::create(['id' => $contract->id]);

        PriceComponent::create([
            'id' => 'pc-hybrid-general-detail',
            'electricity_contract_id' => $contract->id,
            'price_component_type' => 'General',
            'price_date' => now()->format('Y-m-d'),
            'price' => 6.1,
            'payment_unit' => 'c/kWh',
        ]);

        Livewire::test('contract-detail', ['contractId' => $contract->id])
            ->assertSeeHtml('href="/sahkosopimus/joustosahko"')
            ->assertDontSeeHtml('href="/sahkosopimus/yleissahko"');
    }

    /**
     * Test that the company logo is displayed.
     */
    public function test_company_logo_is_displayed(): void
    {
        Livewire::test('contract-detail', ['contractId' => 'contract-detail-test'])
            ->assertSeeHtml('https://storage.example.com/logos/test-energia.png');
    }

    /**
     * Test that the contract type is displayed.
     */
    public function test_contract_type_is_displayed(): void
    {
        Livewire::test('contract-detail', ['contractId' => 'contract-detail-test'])
            ->assertSee('Fixed');
    }

    /**
     * Test that the fixed time range is displayed.
     */
    public function test_fixed_time_range_is_displayed(): void
    {
        Livewire::test('contract-detail', ['contractId' => 'contract-detail-test'])
            ->assertSee('24 months');
    }

    /**
     * Test that the metering type is displayed.
     */
    public function test_metering_type_is_displayed(): void
    {
        Livewire::test('contract-detail', ['contractId' => 'contract-detail-test'])
            ->assertSee('General');
    }

    /**
     * Provider short descriptions should not leak into SEO schema snippets.
     */
    public function test_short_description_is_not_used_as_seo_schema_description(): void
    {
        Livewire::test('contract-detail', ['contractId' => 'contract-detail-test'])
            ->assertDontSee('Edullinen kiinteähintainen sähkösopimus.');
    }

    /**
     * Test that the long description is displayed.
     */
    public function test_long_description_is_displayed(): void
    {
        Livewire::test('contract-detail', ['contractId' => 'contract-detail-test'])
            ->assertSee('Tämä on pidempi kuvaus sopimuksesta.');
    }

    public function test_meta_description_uses_generated_comparison_copy_for_spot_contract(): void
    {
        $contract = ElectricityContract::create([
            'id' => 'spot-meta-contract',
            'company_name' => 'Test Energia Oy',
            'name' => 'Spot+',
            'name_slug' => 'spot-plus',
            'contract_type' => 'OpenEnded',
            'metering' => 'General',
            'pricing_model' => 'Spot',
            'short_description' => 'Palveluntarjoajan markkinointikuvaus, jota ei pidä käyttää metassa.',
            'availability_is_national' => true,
        ]);

        ActiveContract::create(['id' => $contract->id]);

        PriceComponent::create([
            'id' => 'pc-spot-meta-margin',
            'electricity_contract_id' => $contract->id,
            'price_component_type' => 'General',
            'price_date' => now()->format('Y-m-d'),
            'price' => 0.45,
            'payment_unit' => 'c/kWh',
        ]);

        $description = Livewire::test('contract-detail', ['contractId' => $contract->id])
            ->instance()
            ->metaDescription;

        $this->assertStringContainsString('Spot+ on pörssisähkösopimus yhtiöltä Test Energia Oy', $description);
        $this->assertStringContainsString('Voltikan vertailussa se on sijalla', $description);
        $this->assertStringContainsString('5 000 kWh', $description);
        $this->assertStringNotContainsString('Palveluntarjoajan markkinointikuvaus', $description);
    }

    public function test_title_uses_compact_price_rank_and_contract_name(): void
    {
        $title = Livewire::test('contract-detail', ['contractId' => 'contract-detail-test'])
            ->instance()
            ->pageTitle;

        $this->assertSame('Sija 1/1 · 5,50 c/kWh | Perus Sähkö 24kk | Voltikka', $title);
    }

    public function test_meta_description_prefers_meaningful_price_history(): void
    {
        PriceComponent::create([
            'id' => 'pc-general-detail-old',
            'electricity_contract_id' => 'contract-detail-test',
            'price_component_type' => 'General',
            'price_date' => now()->subMonth()->format('Y-m-d'),
            'price' => 7.0,
            'payment_unit' => 'c/kWh',
        ]);

        $component = Livewire::test('contract-detail', ['contractId' => 'contract-detail-test'])->instance();
        $description = $component->metaDescription;

        $this->assertStringContainsString('Perus Sähkö 24kk maksaa nyt 5,50 c/kWh + 2,95 €/kk', $description);
        $this->assertStringContainsString('Energiahinta on laskenut 21 % Voltikan seurannassa', $description);
        $this->assertStringContainsString('sijalla 1 / 1', $description);
        $this->assertSame('Sija 1/1 · 5,50 c/kWh | Perus Sähkö 24kk | Voltikka', $component->pageTitle);
    }

    public function test_meta_description_can_include_annual_cost_and_cheapest_difference(): void
    {
        $this->contract->update([
            'pricing_model' => 'Hybrid',
            'contract_type' => 'FixedTerm',
            'fixed_time_range' => 'Fixed24',
        ]);

        $cheap = ElectricityContract::create([
            'id' => 'cheap-meta-contract',
            'company_name' => 'Test Energia Oy',
            'name' => 'Halpa Sähkö',
            'name_slug' => 'halpa-sahko',
            'contract_type' => 'OpenEnded',
            'metering' => 'General',
            'pricing_model' => 'FixedPrice',
            'availability_is_national' => true,
        ]);

        ActiveContract::create(['id' => $cheap->id]);

        PriceComponent::create([
            'id' => 'pc-cheap-meta-general',
            'electricity_contract_id' => $cheap->id,
            'price_component_type' => 'General',
            'price_date' => now()->format('Y-m-d'),
            'price' => 1.0,
            'payment_unit' => 'c/kWh',
        ]);

        $description = Livewire::test('contract-detail', ['contractId' => 'contract-detail-test'])
            ->instance()
            ->metaDescription;

        $this->assertStringContainsString('Perus Sähkö 24kk on 24 kuukauden hybridisähkösopimus yhtiöltä Test Energia Oy', $description);
        $this->assertStringContainsString('arvioitu hinta on 310 €', $description);
        $this->assertStringContainsString('kalliimpi kuin halvin vaihtoehto', $description);
    }

    public function test_product_schema_uses_generated_meta_description(): void
    {
        $component = Livewire::test('contract-detail', ['contractId' => 'contract-detail-test'])->instance();

        $this->assertSame($component->metaDescription, $component->productSchema['description']);
        $this->assertNotSame($this->contract->short_description, $component->productSchema['description']);
    }

    public function test_spot_title_uses_short_margin_price_phrase(): void
    {
        $contract = ElectricityContract::create([
            'id' => 'spot-title-contract',
            'company_name' => 'Test Energia Oy',
            'name' => 'Spot+',
            'name_slug' => 'spot-plus-title',
            'contract_type' => 'OpenEnded',
            'metering' => 'General',
            'pricing_model' => 'Spot',
            'availability_is_national' => true,
        ]);
        ActiveContract::create(['id' => $contract->id]);
        PriceComponent::create([
            'id' => 'pc-spot-title-margin',
            'electricity_contract_id' => $contract->id,
            'price_component_type' => 'General',
            'price_date' => now()->format('Y-m-d'),
            'price' => 0.49,
            'payment_unit' => 'c/kWh',
        ]);

        $title = Livewire::test('contract-detail', ['contractId' => $contract->id])
            ->instance()
            ->pageTitle;

        $this->assertSame('Sija 1/2 · Marg. 0,49 c/kWh | Spot+ | Voltikka', $title);
    }

    public function test_one_of_cheapest_verdict_requires_top_25_rank(): void
    {
        for ($i = 1; $i <= 25; $i++) {
            $contract = ElectricityContract::create([
                'id' => "cheaper-verdict-{$i}",
                'company_name' => 'Test Energia Oy',
                'name' => "Halvempi {$i}",
                'name_slug' => "halvempi-{$i}",
                'contract_type' => 'OpenEnded',
                'metering' => 'General',
                'pricing_model' => 'FixedPrice',
                'availability_is_national' => true,
            ]);
            ActiveContract::create(['id' => $contract->id]);
            PriceComponent::create([
                'id' => "pc-cheaper-verdict-{$i}",
                'electricity_contract_id' => $contract->id,
                'price_component_type' => 'General',
                'price_date' => now()->format('Y-m-d'),
                'price' => 1.0,
                'payment_unit' => 'c/kWh',
            ]);
        }

        for ($i = 1; $i <= 274; $i++) {
            $contract = ElectricityContract::create([
                'id' => "expensive-verdict-{$i}",
                'company_name' => 'Test Energia Oy',
                'name' => "Kalliimpi {$i}",
                'name_slug' => "kalliimpi-{$i}",
                'contract_type' => 'OpenEnded',
                'metering' => 'General',
                'pricing_model' => 'FixedPrice',
                'availability_is_national' => true,
            ]);
            ActiveContract::create(['id' => $contract->id]);
            PriceComponent::create([
                'id' => "pc-expensive-verdict-{$i}",
                'electricity_contract_id' => $contract->id,
                'price_component_type' => 'General',
                'price_date' => now()->format('Y-m-d'),
                'price' => 10.0,
                'payment_unit' => 'c/kWh',
            ]);
        }

        $component = Livewire::test('contract-detail', ['contractId' => 'contract-detail-test'])
            ->assertDontSee('Yksi halvimmista')
            ->assertSee('Edullinen vaihtoehto — sijalla 26 / 300')
            ->instance();

        $this->assertSame('260 € kalliimpi kuin halvin | Perus Sähkö 24kk | Voltikka', $component->pageTitle);
    }

    /**
     * Test that the energy price is displayed.
     */
    public function test_energy_price_is_displayed(): void
    {
        Livewire::test('contract-detail', ['contractId' => 'contract-detail-test'])
            ->assertSee('5,5'); // Finnish number format
    }

    /**
     * Test that the monthly fee is displayed.
     */
    public function test_monthly_fee_is_displayed(): void
    {
        Livewire::test('contract-detail', ['contractId' => 'contract-detail-test'])
            ->assertSee('2,95'); // Finnish number format
    }

    /**
     * Test that the electricity source breakdown is displayed.
     */
    public function test_electricity_source_breakdown_is_displayed(): void
    {
        Livewire::test('contract-detail', ['contractId' => 'contract-detail-test'])
            ->assertSee('65') // Renewable total
            ->assertSee('30') // Nuclear total
            ->assertSee('5');  // Fossil total (but context might match price too)
    }

    /**
     * Test that the renewable energy breakdown is displayed.
     */
    public function test_renewable_breakdown_is_displayed(): void
    {
        Livewire::test('contract-detail', ['contractId' => 'contract-detail-test'])
            ->assertSee('40') // Wind
            ->assertSee('20'); // Hydro
    }

    /**
     * Test that the product link is displayed.
     */
    public function test_product_link_is_displayed(): void
    {
        Livewire::test('contract-detail', ['contractId' => 'contract-detail-test'])
            ->assertSeeHtml('https://testenergia.fi/products/perus-sahko');
    }

    /**
     * Test that the order link is displayed.
     */
    public function test_order_link_is_displayed(): void
    {
        Livewire::test('contract-detail', ['contractId' => 'contract-detail-test'])
            ->assertSeeHtml('https://testenergia.fi/order/perus-sahko');
    }

    /**
     * Test that the annual cost is calculated and displayed.
     */
    public function test_annual_cost_is_displayed(): void
    {
        // Default 5000 kWh consumption
        // Energy: 5.5 * 5000 / 100 = 275 EUR
        // Monthly: 2.95 * 12 = 35.40 EUR
        // Total: 310.40 EUR
        Livewire::test('contract-detail', ['contractId' => 'contract-detail-test'])
            ->assertSee('310'); // Approximate match
    }

    /**
     * Test that changing consumption updates the cost.
     */
    public function test_changing_consumption_updates_cost(): void
    {
        // 10000 kWh consumption
        // Energy: 5.5 * 10000 / 100 = 550 EUR
        // Monthly: 2.95 * 12 = 35.40 EUR
        // Total: 585.40 EUR
        Livewire::test('contract-detail', ['contractId' => 'contract-detail-test'])
            ->set('consumption', 10000)
            ->assertSee('585'); // Approximate match
    }

    /**
     * Test that consumption presets are available.
     */
    public function test_consumption_presets_are_available(): void
    {
        Livewire::test('contract-detail', ['contractId' => 'contract-detail-test'])
            ->assertSee('2 000 kWh')
            ->assertSee('5 000 kWh')
            ->assertSee('10 000 kWh')
            ->assertSee('18 000 kWh');
    }

    /**
     * Test that microproduction info is displayed when available.
     */
    public function test_microproduction_info_is_displayed(): void
    {
        Livewire::test('contract-detail', ['contractId' => 'contract-detail-test'])
            ->assertSee('Ostamme ylituotannon spot-hintaan.');
    }

    /**
     * Test that company address is displayed.
     */
    public function test_company_address_is_displayed(): void
    {
        Livewire::test('contract-detail', ['contractId' => 'contract-detail-test'])
            ->assertSee('Energiakatu 1')
            ->assertSee('00100')
            ->assertSee('Helsinki');
    }

    /**
     * Test that 404 is returned for non-existent contract.
     */
    public function test_404_for_non_existent_contract(): void
    {
        $response = $this->get('/sahkosopimus/sopimus/non-existent-contract');

        $response->assertStatus(404);
    }

    /**
     * Test that price history is displayed when multiple price dates exist.
     */
    public function test_price_history_is_displayed(): void
    {
        // Add historical price
        PriceComponent::create([
            'id' => 'pc-general-history',
            'electricity_contract_id' => 'contract-detail-test',
            'price_component_type' => 'General',
            'price_date' => now()->subMonth()->format('Y-m-d'),
            'price' => 6.0, // Old higher price
            'payment_unit' => 'c/kWh',
        ]);

        Livewire::test('contract-detail', ['contractId' => 'contract-detail-test'])
            ->assertSee('6,0'); // Historical price should be shown
    }

    /**
     * Test that contract history uses the replacement chain and shows versions newest first.
     */
    public function test_contract_detail_cache_version_hash_is_memoized_per_component(): void
    {
        $this->mock(ContractPageCacheVersion::class, function ($mock) {
            $mock->shouldReceive('hash')->once()->andReturn('stable-version');
        });

        $component = Livewire::test('contract-detail', ['contractId' => 'contract-detail-test'])->instance();

        $lookupKey = new \ReflectionMethod($component, 'contractLookupCacheKey');
        $lookupKey->setAccessible(true);
        $viewKey = new \ReflectionMethod($component, 'contractDetailViewDataCacheKey');
        $viewKey->setAccessible(true);

        $lookupKey->invoke($component);
        $viewKey->invoke($component);
    }

    public function test_contract_detail_reuses_ranking_service_queries_during_render(): void
    {
        for ($i = 1; $i <= 3; $i++) {
            $contract = ElectricityContract::create([
                'id' => "ranking-query-alt-{$i}",
                'company_name' => 'Test Energia Oy',
                'name' => "Ranking Query Alt {$i}",
                'contract_type' => 'OpenEnded',
                'metering' => 'General',
                'pricing_model' => 'FixedPrice',
                'target_group' => 'Household',
                'availability_is_national' => true,
            ]);
            ActiveContract::create(['id' => $contract->id]);
            PriceComponent::create([
                'id' => "pc-ranking-query-alt-{$i}",
                'electricity_contract_id' => $contract->id,
                'price_component_type' => 'General',
                'price_date' => now()->format('Y-m-d'),
                'price' => 1.0 + $i,
                'payment_unit' => 'c/kWh',
            ]);
        }

        DB::enableQueryLog();

        Livewire::test('contract-detail', ['contractId' => 'contract-detail-test'])
            ->assertStatus(200);

        $targetGroupQueries = collect(DB::getQueryLog())
            ->pluck('query')
            ->filter(fn (string $query) => str_contains($query, 'select "target_group", "id"')
                && str_contains($query, 'from "electricity_contracts"'))
            ->count();

        // liveRank, liveTotalContracts and cheaperContracts all need the same
        // eligible target-group list; keep it one query per render.
        $this->assertLessThanOrEqual(1, $targetGroupQueries);
    }

    public function test_contract_detail_history_chain_uses_bounded_bulk_relation_queries(): void
    {
        $previousContract = ElectricityContract::create([
            'id' => 'contract-detail-query-previous',
            'company_name' => 'Test Energia Oy',
            'name' => 'Query Previous',
            'contract_type' => 'Fixed',
            'metering' => 'General',
            'replaced_by_contract_id' => 'contract-detail-test',
            'availability_is_national' => true,
        ]);

        $oldestContract = ElectricityContract::create([
            'id' => 'contract-detail-query-oldest',
            'company_name' => 'Test Energia Oy',
            'name' => 'Query Oldest',
            'contract_type' => 'Fixed',
            'metering' => 'General',
            'replaced_by_contract_id' => $previousContract->id,
            'availability_is_national' => true,
        ]);

        foreach ([$previousContract, $oldestContract] as $index => $historyContract) {
            PriceComponent::create([
                'id' => 'pc-query-history-' . $historyContract->id,
                'electricity_contract_id' => $historyContract->id,
                'price_component_type' => 'General',
                'price_date' => now()->subMonths($index + 1)->format('Y-m-d'),
                'price' => 6.0 + $index,
                'payment_unit' => 'c/kWh',
            ]);
        }

        DB::enableQueryLog();

        Livewire::test('contract-detail', ['contractId' => 'contract-detail-test'])
            ->assertSee('Query Previous')
            ->assertSee('Query Oldest');

        $queries = collect(DB::getQueryLog())->pluck('query');

        $priceComponentQueries = $queries
            ->filter(fn (string $query) => str_contains($query, 'from "price_components"'))
            ->count();
        $activeContractQueries = $queries
            ->filter(fn (string $query) => str_contains($query, 'from "active_contracts"'))
            ->count();

        $this->assertLessThanOrEqual(4, $priceComponentQueries);
        $this->assertLessThanOrEqual(4, $activeContractQueries);
    }

    public function test_inactive_contract_redirect_chain_uses_bounded_bulk_queries(): void
    {
        $replacement = ElectricityContract::create([
            'id' => 'contract-detail-active-replacement',
            'company_name' => 'Test Energia Oy',
            'name' => 'Active Replacement',
            'contract_type' => 'Fixed',
            'metering' => 'General',
            'availability_is_national' => true,
        ]);
        ActiveContract::create(['id' => $replacement->id]);

        $middle = ElectricityContract::create([
            'id' => 'contract-detail-middle-replacement',
            'company_name' => 'Test Energia Oy',
            'name' => 'Middle Replacement',
            'contract_type' => 'Fixed',
            'metering' => 'General',
            'replaced_by_contract_id' => $replacement->id,
            'availability_is_national' => true,
        ]);

        $old = ElectricityContract::create([
            'id' => 'contract-detail-old-replacement',
            'company_name' => 'Test Energia Oy',
            'name' => 'Old Replacement',
            'contract_type' => 'Fixed',
            'metering' => 'General',
            'replaced_by_contract_id' => $middle->id,
            'availability_is_national' => true,
        ]);

        DB::enableQueryLog();

        $this->get('/sahkosopimus/sopimus/' . $old->id)
            ->assertRedirect(route('contract.detail', ['contractId' => $replacement->id]));

        $queries = collect(DB::getQueryLog())->pluck('query');

        $activeContractQueries = $queries
            ->filter(fn (string $query) => str_contains($query, 'from "active_contracts"'))
            ->count();

        $this->assertLessThanOrEqual(2, $activeContractQueries);
    }

    public function test_contract_history_shows_replacement_chain_versions_in_reverse_chronological_order(): void
    {
        $previousContract = ElectricityContract::create([
            'id' => 'contract-detail-previous',
            'company_name' => 'Test Energia Oy',
            'name' => 'Perus Sähkö 12kk',
            'contract_type' => 'Fixed',
            'metering' => 'General',
            'replaced_by_contract_id' => 'contract-detail-test',
            'pricing_has_discounts' => true,
            'availability_is_national' => true,
        ]);

        $oldestContract = ElectricityContract::create([
            'id' => 'contract-detail-oldest',
            'company_name' => 'Test Energia Oy',
            'name' => 'Vanha Perus Sähkö',
            'contract_type' => 'Fixed',
            'metering' => 'General',
            'replaced_by_contract_id' => 'contract-detail-previous',
            'availability_is_national' => true,
        ]);

        PriceComponent::create([
            'id' => 'pc-general-previous',
            'electricity_contract_id' => $previousContract->id,
            'price_component_type' => 'General',
            'price_date' => now()->subMonth()->format('Y-m-d'),
            'price' => 6.2,
            'payment_unit' => 'c/kWh',
            'has_discount' => true,
            'discount_value' => 15,
            'discount_is_percentage' => true,
            'discount_discount_n_first_months' => 3,
        ]);

        PriceComponent::create([
            'id' => 'pc-monthly-previous',
            'electricity_contract_id' => $previousContract->id,
            'price_component_type' => 'Monthly',
            'price_date' => now()->subMonth()->format('Y-m-d'),
            'price' => 3.25,
            'payment_unit' => 'EUR/month',
        ]);

        PriceComponent::create([
            'id' => 'pc-general-oldest',
            'electricity_contract_id' => $oldestContract->id,
            'price_component_type' => 'General',
            'price_date' => now()->subMonths(2)->format('Y-m-d'),
            'price' => 6.9,
            'payment_unit' => 'c/kWh',
        ]);

        Livewire::test('contract-detail', ['contractId' => 'contract-detail-test'])
            ->assertSee('Sopimushistoria')
            ->assertSeeInOrder(['Perus Sähkö 24kk', 'Perus Sähkö 12kk', 'Vanha Perus Sähkö'])
            ->assertSee('6,20')
            ->assertSee('6,90')
            ->assertSee('3 ensimmäistä kuukautta')
            ->assertSee('-15% alennus');
    }

    /**
     * Test that back to list link is present.
     */
    public function test_back_to_list_link_is_present(): void
    {
        Livewire::test('contract-detail', ['contractId' => 'contract-detail-test'])
            ->assertSeeHtml('href="/sahkosopimus"'); // Back link to contracts list
    }

    /**
     * Test time-based metering contract shows day/night prices.
     */
    public function test_time_metering_shows_day_night_prices(): void
    {
        $timeContract = ElectricityContract::create([
            'id' => 'time-metering-contract',
            'company_name' => 'Test Energia Oy',
            'name' => 'Aika Sähkö',
            'contract_type' => 'Fixed',
            'metering' => 'Time',
            'availability_is_national' => true,
        ]);

        PriceComponent::create([
            'id' => 'pc-day-time',
            'electricity_contract_id' => 'time-metering-contract',
            'price_component_type' => 'DayTime',
            'price_date' => now()->format('Y-m-d'),
            'price' => 6.0,
            'payment_unit' => 'c/kWh',
        ]);

        PriceComponent::create([
            'id' => 'pc-night-time',
            'electricity_contract_id' => 'time-metering-contract',
            'price_component_type' => 'NightTime',
            'price_date' => now()->format('Y-m-d'),
            'price' => 4.0,
            'payment_unit' => 'c/kWh',
        ]);

        PriceComponent::create([
            'id' => 'pc-monthly-time',
            'electricity_contract_id' => 'time-metering-contract',
            'price_component_type' => 'Monthly',
            'price_date' => now()->format('Y-m-d'),
            'price' => 3.50,
            'payment_unit' => 'EUR/month',
        ]);

        ActiveContract::create(['id' => 'time-metering-contract']);

        Livewire::test('contract-detail', ['contractId' => 'time-metering-contract'])
            ->assertSee('6,0')  // Day price
            ->assertSee('4,0'); // Night price
    }

    /**
     * Test seasonal metering contract shows seasonal prices.
     */
    public function test_seasonal_metering_shows_seasonal_prices(): void
    {
        $seasonalContract = ElectricityContract::create([
            'id' => 'seasonal-metering-contract',
            'company_name' => 'Test Energia Oy',
            'name' => 'Kausi Sähkö',
            'contract_type' => 'Fixed',
            'metering' => 'Season',
            'availability_is_national' => true,
        ]);

        PriceComponent::create([
            'id' => 'pc-winter-seasonal',
            'electricity_contract_id' => 'seasonal-metering-contract',
            'price_component_type' => 'SeasonalWinterDay',
            'price_date' => now()->format('Y-m-d'),
            'price' => 7.0,
            'payment_unit' => 'c/kWh',
        ]);

        PriceComponent::create([
            'id' => 'pc-other-seasonal',
            'electricity_contract_id' => 'seasonal-metering-contract',
            'price_component_type' => 'SeasonalOther',
            'price_date' => now()->format('Y-m-d'),
            'price' => 4.5,
            'payment_unit' => 'c/kWh',
        ]);

        PriceComponent::create([
            'id' => 'pc-monthly-seasonal',
            'electricity_contract_id' => 'seasonal-metering-contract',
            'price_component_type' => 'Monthly',
            'price_date' => now()->format('Y-m-d'),
            'price' => 4.00,
            'payment_unit' => 'EUR/month',
        ]);

        ActiveContract::create(['id' => 'seasonal-metering-contract']);

        Livewire::test('contract-detail', ['contractId' => 'seasonal-metering-contract'])
            ->assertSee('7,0')  // Winter price
            ->assertSee('4,5'); // Other season price
    }

    /**
     * Test that presets are filtered when contract has minimum consumption limit.
     */
    public function test_presets_filtered_by_minimum_consumption_limit(): void
    {
        // Create contract with min consumption of 8000 kWh
        $this->contract->update([
            'consumption_limitation_min_x_kwh_per_y' => 8000,
        ]);

        // Only "Pieni talo" (10000) and "Suuri talo" (18000) should be shown
        // "Yksiö" (2000) and "Kerrostalo" (5000) should be hidden
        Livewire::test('contract-detail', ['contractId' => 'contract-detail-test'])
            ->assertDontSee('2 000 kWh')
            ->assertDontSee('5 000 kWh')
            ->assertSee('10 000 kWh')
            ->assertSee('18 000 kWh');
    }

    /**
     * Test that presets are filtered when contract has maximum consumption limit.
     */
    public function test_presets_filtered_by_maximum_consumption_limit(): void
    {
        // Create contract with max consumption of 6000 kWh
        $this->contract->update([
            'consumption_limitation_max_x_kwh_per_y' => 6000,
        ]);

        // Only "Yksiö" (2000) and "Kerrostalo" (5000) should be shown
        // "Pieni talo" (10000) and "Suuri talo" (18000) should be hidden
        Livewire::test('contract-detail', ['contractId' => 'contract-detail-test'])
            ->assertSee('2 000 kWh')
            ->assertSee('5 000 kWh')
            ->assertDontSee('10 000 kWh')
            ->assertDontSee('18 000 kWh');
    }

    /**
     * Test that presets are filtered when contract has both min and max limits.
     */
    public function test_presets_filtered_by_both_consumption_limits(): void
    {
        // Create contract with range 4000-12000 kWh
        $this->contract->update([
            'consumption_limitation_min_x_kwh_per_y' => 4000,
            'consumption_limitation_max_x_kwh_per_y' => 12000,
        ]);

        // Only "Kerrostalo" (5000) and "Pieni talo" (10000) should be shown
        // "Yksiö" (2000) is below min, "Suuri talo" (18000) is above max
        Livewire::test('contract-detail', ['contractId' => 'contract-detail-test'])
            ->assertDontSee('2 000 kWh')
            ->assertSee('5 000 kWh')
            ->assertSee('10 000 kWh')
            ->assertDontSee('18 000 kWh');
    }

    /**
     * Test that consumption limits affect the visible preset range.
     */
    public function test_consumption_limits_notice_displayed(): void
    {
        $this->contract->update([
            'consumption_limitation_min_x_kwh_per_y' => 5000,
            'consumption_limitation_max_x_kwh_per_y' => 15000,
        ]);

        Livewire::test('contract-detail', ['contractId' => 'contract-detail-test'])
            ->assertDontSee('2 000 kWh')
            ->assertSee('5 000 kWh')
            ->assertSee('10 000 kWh')
            ->assertDontSee('18 000 kWh');
    }

    /**
     * Test that default consumption is adjusted when out of range.
     */
    public function test_default_consumption_adjusted_to_range(): void
    {
        // Default is 5000, set min to 8000
        $this->contract->update([
            'consumption_limitation_min_x_kwh_per_y' => 8000,
        ]);

        // The default consumption should be adjusted to the minimum
        Livewire::test('contract-detail', ['contractId' => 'contract-detail-test'])
            ->assertSet('consumption', 8000);
    }

    /**
     * Test that setConsumption respects contract limits.
     */
    public function test_set_consumption_respects_limits(): void
    {
        // Set limits
        $this->contract->update([
            'consumption_limitation_min_x_kwh_per_y' => 3000,
            'consumption_limitation_max_x_kwh_per_y' => 10000,
        ]);

        // Try to set consumption below min - should clamp to min
        Livewire::test('contract-detail', ['contractId' => 'contract-detail-test'])
            ->call('setConsumption', 1000)
            ->assertSet('consumption', 3000);

        // Try to set consumption above max - should clamp to max
        Livewire::test('contract-detail', ['contractId' => 'contract-detail-test'])
            ->call('setConsumption', 20000)
            ->assertSet('consumption', 10000);
    }

    /**
     * Listing cards deep-link the visitor's consumption as ?kulutus= so the
     * detail price matches the listing they came from.
     */
    public function test_kulutus_query_param_sets_consumption(): void
    {
        // No param: stays at the 5000 kWh default.
        Livewire::test('contract-detail', ['contractId' => 'contract-detail-test'])
            ->assertSet('consumption', 5000);

        // ?kulutus=10000 is honored on mount.
        Livewire::withQueryParams(['kulutus' => 10000])
            ->test('contract-detail', ['contractId' => 'contract-detail-test'])
            ->assertSet('consumption', 10000);
    }

    /**
     * The ?kulutus= deep links must stay non-indexable: the canonical URL is
     * always the clean, param-free contract URL.
     */
    public function test_kulutus_query_url_keeps_clean_canonical(): void
    {
        $cleanUrl = route('contract.detail', ['contractId' => 'contract-detail-test']);

        $this->get($cleanUrl.'?kulutus=10000')
            ->assertStatus(200)
            ->assertSee('<link rel="canonical" href="'.$cleanUrl.'">', false)
            ->assertDontSee('canonical" href="'.$cleanUrl.'?kulutus', false);
    }
}
