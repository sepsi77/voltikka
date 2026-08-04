<?php

namespace Tests\Feature;

use App\Livewire\ContractDetail;
use App\Models\ActiveContract;
use App\Models\Company;
use App\Models\ElectricityContract;
use App\Models\ElectricitySource;
use App\Models\PriceComponent;
use App\Models\SpotPriceAverage;
use App\Services\Analytics\ContractOrderClickContextSigner;
use App\Services\Caching\ContractPageCacheVersion;
use App\Services\ContractPriceHistory\ContractHistoryPresenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
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

    public function test_both_seller_ctas_use_the_shared_first_party_path_and_keep_plausible(): void
    {
        $component = Livewire::test('contract-detail', ['contractId' => 'contract-detail-test'])
            ->assertSeeHtml("\$track('Contract Order Clicked', {")
            ->assertSeeHtml('props: {')
            ->assertSeeHtml("contract_id: 'contract-detail-test'")
            ->assertSeeHtml("company: 'Test Energia Oy'")
            ->assertSeeHtml('data-first-party-analytics="contract_order_click"')
            ->assertSeeHtml('data-analytics-placement="hero"')
            ->assertSeeHtml('data-analytics-placement="sticky"');

        $this->assertSame(4, substr_count(
            $component->html(),
            'window.voltikkaAnalytics.trackContractOrderClick',
        ));
        $this->assertSame(2, substr_count($component->html(), '@auxclick='));
        $this->assertSame(2, substr_count(
            $component->html(),
            'href="https://testenergia.fi/order/perus-sahko?utm_source=voltikka.fi&amp;utm_medium=referral&amp;utm_campaign=voltikka_sahkovertailu"',
        ));
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

    public function test_external_only_company_logo_is_not_requested(): void
    {
        Livewire::test('contract-detail', ['contractId' => 'contract-detail-test'])
            ->assertSee('Tes')
            ->assertDontSeeHtml('https://storage.example.com/logos/test-energia.png');
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
        // The Finnish label, not the raw enum. This used to pass on an HTML comment
        // ("<!-- General metering (non-spot) -->") in the hand-rolled price-row block that
        // ContractCardPresenter replaced; the enum value was never shown to a visitor.
        Livewire::test('contract-detail', ['contractId' => 'contract-detail-test'])
            ->assertSee('Yleissähkö');
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
            // Phase 4 dissolved the tiered verdict strip into the hero verdict line.
            ->assertSee('Sija 26')
            ->assertSee('300 sopimuksesta')
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
     * A spot contract's General component is the supplier margin. The history
     * timeline and its trend chart used to call it "Energiahinta", which read as
     * if a 0,60 c/kWh margin were the whole energy price.
     */
    public function test_spot_contract_history_labels_the_general_component_as_margin(): void
    {
        $contract = ElectricityContract::create([
            'id' => 'spot-history-labels',
            'company_name' => 'Test Energia Oy',
            'name' => 'Surffari',
            'name_slug' => 'surffari',
            'contract_type' => 'OpenEnded',
            'metering' => 'General',
            'pricing_model' => 'Spot',
            'availability_is_national' => true,
        ]);

        ActiveContract::create(['id' => $contract->id]);

        PriceComponent::create([
            'id' => 'pc-spot-history-old',
            'electricity_contract_id' => $contract->id,
            'price_component_type' => 'General',
            'price_date' => now()->subMonths(2)->format('Y-m-d'),
            'price' => 0.20,
            'payment_unit' => 'c/kWh',
        ]);
        PriceComponent::create([
            'id' => 'pc-spot-history-new',
            'electricity_contract_id' => $contract->id,
            'price_component_type' => 'General',
            'price_date' => now()->format('Y-m-d'),
            'price' => 0.60,
            'payment_unit' => 'c/kWh',
        ]);

        $labels = $this->contractHistoryPriceLabels($contract->id);

        $this->assertContains('Marginaali', $labels);
        $this->assertNotContains('Energiahinta', $labels);
    }

    public function test_non_spot_contract_history_labels_the_general_component_as_energy_price(): void
    {
        $labels = $this->contractHistoryPriceLabels($this->contract->id);

        $this->assertContains('Energiahinta', $labels);
        $this->assertNotContains('Marginaali', $labels);
    }

    /**
     * `price_component_type` is stored verbatim from the upstream API, so the
     * history must not drop rows whose type is missing from the label map.
     * A `Spot` margin component (Turku Energia Louna Nero) used to vanish.
     */
    public function test_history_shows_component_types_outside_the_known_label_map(): void
    {
        PriceComponent::create([
            'id' => 'pc-spot-type-margin',
            'electricity_contract_id' => $this->contract->id,
            'price_component_type' => 'Spot',
            'price_date' => now()->format('Y-m-d'),
            'price' => 0.49,
            'payment_unit' => 'c/kWh',
        ]);
        PriceComponent::create([
            'id' => 'pc-unknown-upstream-type',
            'electricity_contract_id' => $this->contract->id,
            'price_component_type' => 'SomeNewUpstreamType',
            'price_date' => now()->format('Y-m-d'),
            'price' => 1.25,
            'payment_unit' => 'c/kWh',
        ]);

        $labels = $this->contractHistoryPriceLabels($this->contract->id);

        // A Spot component is a margin whatever the contract's pricing model is.
        $this->assertContains('Marginaali', $labels);
        // An unknown type falls back to its raw name instead of being dropped.
        $this->assertContains('SomeNewUpstreamType', $labels);
    }

    public function test_both_winter_component_spellings_are_labelled_as_winter_price(): void
    {
        $labels = app(ContractHistoryPresenter::class)
            ->present($this->contract)['priceTypeLabels'];

        $this->assertSame('Talvihinta', $labels['SeasonalWinter']);
        $this->assertSame('Talvihinta', $labels['SeasonalWinterDay']);
    }

    /**
     * @return list<string>
     */
    protected function contractHistoryPriceLabels(string $contractId): array
    {
        $history = Livewire::test('contract-detail', ['contractId' => $contractId])
            ->viewData('contractHistory');

        return collect($history)
            ->flatMap(fn (array $entry) => array_column($entry['prices'], 'label'))
            ->unique()
            ->values()
            ->all();
    }

    public function test_inactive_contract_history_shows_not_for_sale_node_with_last_observed_date(): void
    {
        ActiveContract::query()->whereKey($this->contract->id)->delete();
        PriceComponent::query()->where('electricity_contract_id', $this->contract->id)->delete();

        PriceComponent::create([
            'id' => 'pc-inactive-older-positive',
            'electricity_contract_id' => $this->contract->id,
            'price_component_type' => 'General',
            'price_date' => '2026-05-10',
            'price' => 5.5,
            'payment_unit' => 'c/kWh',
        ]);
        PriceComponent::create([
            'id' => 'pc-inactive-newest-zero',
            'electricity_contract_id' => $this->contract->id,
            'price_component_type' => 'General',
            'price_date' => '2026-05-12',
            'price' => 0,
            'payment_unit' => 'c/kWh',
        ]);

        Livewire::test('contract-detail', ['contractId' => $this->contract->id])
            ->assertSee('Sopimus ei ole enää myynnissä')
            // The date carries a machine-readable <time datetime>, so the sentence and
            // the date are asserted separately.
            ->assertSee('Viimeksi havaittu myynnissä')
            ->assertSee('12.5.2026')
            ->assertDontSee('Nykyinen');
    }

    public function test_inactive_contract_without_price_history_still_shows_status_node(): void
    {
        ActiveContract::query()->whereKey($this->contract->id)->delete();
        PriceComponent::query()->where('electricity_contract_id', $this->contract->id)->delete();

        Livewire::test('contract-detail', ['contractId' => $this->contract->id])
            ->assertSee('Näin hinta on kehittynyt')
            ->assertSee('Sopimus ei ole enää myynnissä')
            ->assertSee('Viimeinen havainto myynnissä ei ole tiedossa.');
    }

    public function test_inactive_hybrid_without_canonical_pricing_renders_historical_noindex_page(): void
    {
        config()->set('canonical_pricing.enabled', true);
        app()->forgetScopedInstances();

        ActiveContract::query()->whereKey($this->contract->id)->delete();
        $this->contract->update([
            'pricing_model' => 'Hybrid',
            'canonical_pricing' => null,
            'canonical_source_consistency' => null,
            'canonical_calculation' => null,
        ]);

        $this->get(route('contract.detail', ['contractId' => $this->contract->id]))
            ->assertOk()
            ->assertSeeLivewire('contract-detail')
            ->assertSee('<meta name="robots" content="noindex, follow">', false)
            ->assertSee('Sopimus ei ole enää myynnissä')
            ->assertSee('Kulutusvaikutus')
            ->assertDontSee('Perushinta');
    }

    public function test_active_contract_history_does_not_show_not_for_sale_node(): void
    {
        Livewire::test('contract-detail', ['contractId' => $this->contract->id])
            ->assertDontSee('Sopimus ei ole enää myynnissä')
            ->assertSee('Nykyinen');
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
                'id' => 'pc-query-history-'.$historyContract->id,
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

        // The bound is constant, not proportional to the chain: the history uses
        // one bulk load, and the static per-consumption cost table prices four
        // reference consumptions through the shared listing metric cache, which
        // reads price components once per consumption on a cold cache.
        $this->assertLessThanOrEqual(8, $priceComponentQueries);
        $this->assertLessThanOrEqual(8, $activeContractQueries);
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

        $this->get('/sahkosopimus/sopimus/'.$old->id)
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
            ->assertSee('Näin hinta on kehittynyt')
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
     *
     * The chips are asserted through `data-consumption-preset`, not through the
     * bare "2 000 kWh" substring: the static per-consumption cost table prints
     * every reference consumption, and the consumption-cap warning pill prints
     * the cap itself, so a bare kWh substring no longer identifies a chip.
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
            ->assertDontSeeHtml('data-consumption-preset="2000"')
            ->assertDontSeeHtml('data-consumption-preset="5000"')
            ->assertSeeHtml('data-consumption-preset="10000"')
            ->assertSeeHtml('data-consumption-preset="18000"');
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
            ->assertSeeHtml('data-consumption-preset="2000"')
            ->assertSeeHtml('data-consumption-preset="5000"')
            ->assertDontSeeHtml('data-consumption-preset="10000"')
            ->assertDontSeeHtml('data-consumption-preset="18000"');
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
        // "Yksiö" (2000) is below min, "Suuri talo" (18000) is above max.
        Livewire::test('contract-detail', ['contractId' => 'contract-detail-test'])
            ->assertDontSeeHtml('data-consumption-preset="2000"')
            ->assertSeeHtml('data-consumption-preset="5000"')
            ->assertSeeHtml('data-consumption-preset="10000"')
            ->assertDontSeeHtml('data-consumption-preset="18000"');
    }

    /**
     * A capped contract must say why chips are missing instead of quietly
     * dropping them, and the cost table keeps every reference consumption with
     * the unavailable ones marked.
     */
    public function test_consumption_limits_notice_displayed(): void
    {
        $this->contract->update([
            'consumption_limitation_min_x_kwh_per_y' => 5000,
            'consumption_limitation_max_x_kwh_per_y' => 15000,
        ]);

        Livewire::test('contract-detail', ['contractId' => 'contract-detail-test'])
            ->assertDontSeeHtml('data-consumption-preset="2000"')
            ->assertSeeHtml('data-consumption-preset="5000"')
            ->assertSeeHtml('data-consumption-preset="10000"')
            ->assertDontSeeHtml('data-consumption-preset="18000"')
            ->assertSee('Osa kulutusvaihtoehdoista puuttuu')
            ->assertSee('5 000 kWh ja 15 000 kWh välillä')
            ->assertSee('Ei saatavilla tällä kulutuksella');
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

    /**
     * The rank-1 page has nothing cheaper to compare against. It must state the
     * gap to the runner-up by name instead of an empty comparison state.
     */
    public function test_rank_one_verdict_states_the_gap_to_the_second_cheapest(): void
    {
        $this->createComparisonContract('runner-up-contract', 'Toiseksi Halvin', 6.5);

        Livewire::test('contract-detail', ['contractId' => 'contract-detail-test'])
            ->assertSee('Sija 1')
            ->assertSee('halvempi kuin seuraavaksi halvin (Toiseksi Halvin)')
            ->assertDontSee('Ei vertailutietoa')
            ->assertDontSee('Ei tietoa');
    }

    /**
     * The only comparable contract still gets a true sentence, never "Ei tietoa".
     */
    public function test_rank_one_verdict_without_any_alternative_never_says_no_data(): void
    {
        Livewire::test('contract-detail', ['contractId' => 'contract-detail-test'])
            ->assertSee('Ainoa vertailukelpoinen sopimus tällä kulutuksella')
            ->assertDontSee('Ei vertailutietoa')
            ->assertDontSee('Ei tietoa');
    }

    /**
     * The SEO title and the hero verdict must quote the same comparison size.
     * They used to read different scopes: contracts whose consumption limits
     * exclude the compared consumption counted in the hero but not in the title.
     */
    public function test_title_and_hero_quote_the_same_contract_count(): void
    {
        $this->createComparisonContract('counted-contract', 'Mukana Vertailussa', 6.5);

        $capped = $this->createComparisonContract('capped-contract', 'Rajattu Sopimus', 6.6);
        $capped->update(['consumption_limitation_max_x_kwh_per_y' => 3000]);

        $component = Livewire::test('contract-detail', ['contractId' => 'contract-detail-test'])
            ->assertSee('Sija 1')
            ->assertSee('2 sopimuksesta')
            ->instance();

        $this->assertSame(2, $component->totalContracts);
        $this->assertSame(2, $component->liveTotalContracts);
        $this->assertSame($component->priceRank, $component->liveRank);
        $this->assertStringContainsString('Sija 1/2', $component->pageTitle);
    }

    public function test_spot_contract_shows_the_spot_estimate_qualifier(): void
    {
        SpotPriceAverage::create([
            'region' => 'FI',
            'period_type' => SpotPriceAverage::PERIOD_ROLLING_365D,
            'period_start' => now()->subDays(365),
            'period_end' => now(),
            'avg_price_with_tax' => 6.57,
            'avg_price_without_tax' => 5.24,
            'day_avg_with_tax' => 6.57,
            'night_avg_with_tax' => 5.10,
            'hours_count' => 8760,
        ]);

        $contract = $this->createComparisonContract('spot-qualifier-contract', 'Pörssisähkö Perus', 0.42);
        $contract->update(['pricing_model' => 'Spot']);

        // With the `Arvio` popover present the qualifier states only the mechanism:
        // the popover carries the figures, and split day/night at that. Without a
        // popover the same property still returns the full figure sentence, which
        // `test_spot_qualifier_carries_the_figures_without_a_popover` covers.
        Livewire::test('contract-detail', ['contractId' => $contract->id])
            ->assertSee('Pörssisähkössä maksat sähkön tuntihinnan, joten vuosihinta on arvio.')
            ->assertSee('Vuosihinta perustuu 12 kuukauden toteutuneeseen pörssikeskihintaan')
            ->assertDontSee('joten vuosihinta on arvio: viimeisen 12 kuukauden');
    }

    /**
     * A market-reset contract states the known current price with its end date
     * and says the rest of the year is an estimate from wholesale forward
     * prices, in plain language.
     *
     * That statement is owned by the hero's `Arvio` popover, not by the price
     * qualifier. The two used to render it twice, six lines apart, with the
     * popover's version the richer of the two (it also names the reset cadence),
     * so the qualifier now returns null whenever the popover is there. The null
     * assertion at the end is the point of the split; do not "fix" it by
     * reinstating the sentence in both places.
     */
    public function test_market_reset_contract_shows_the_current_price_and_estimate_qualifier(): void
    {
        $this->travelTo(\Carbon\Carbon::parse('2026-07-25 09:00:00', 'Europe/Helsinki'));

        config([
            'canonical_pricing.enabled' => true,
            'canonical_pricing.reset_forward_shift.enabled' => true,
            'price_forecasting.fixed_term.vat_multiplier' => 1.255,
        ]);

        foreach ([['month', '202607', 19.53], ['month', '202608', 41.64], ['month', '202609', 87.05], ['year', '202601', 60.0], ['year', '202701', 54.12]] as [$type, $maturity, $price]) {
            \App\Models\ElectricityFuturesEodPrice::create([
                'exchange' => 'EEX', 'commodity' => 'POWER', 'pricing' => 'F', 'product' => 'Base', 'area' => 'FI',
                'short_code' => $type === 'month' ? 'FNBM' : 'FNBY',
                'maturity' => $maturity, 'maturity_type' => $type,
                'trade_date' => '2026-07-24', 'settlement_price' => $price,
            ]);
        }

        $contract = $this->createComparisonContract('reset-qualifier-contract', 'Kvartaalisähkö', 8.0);
        $contract->update([
            'pricing_model' => 'FixedPrice',
            'canonical_pricing' => [
                'phases' => [[
                    'label' => 'recurring_period', 'phase_kind' => 'recurring_period',
                    'starts' => ['kind' => 'contract_start', 'value' => null],
                    'ends' => ['kind' => 'none', 'value' => null],
                    'components' => [[
                        'component_type' => 'energy_general', 'amount' => 8.0, 'normal_amount' => null,
                        'unit' => 'cents_per_kwh', 'vat_status' => 'included', 'price_role' => 'current',
                        'source_kind' => 'both', 'evidence' => [],
                    ]],
                    'evidence' => [],
                ]],
                'recurring_schedule' => [
                    'present' => true, 'cadence' => 'quarterly', 'current_period_start' => '2026-07-01',
                    'current_period_end' => '2026-09-30', 'future_price_known' => false, 'description' => null, 'evidence' => [],
                ],
                'consumption_effect' => [
                    'present' => false, 'applies_to' => 'unknown', 'cadence' => 'none',
                    'expected_cents_per_kwh' => null, 'typical_min_cents_per_kwh' => null, 'typical_max_cents_per_kwh' => null,
                    'hard_min_cents_per_kwh' => null, 'hard_max_cents_per_kwh' => null, 'uncapped' => null,
                    'description' => null, 'evidence' => [],
                ],
            ],
            'canonical_calculation' => ['status' => 'estimate_required', 'missing_facts' => [], 'required_assumptions' => []],
            'canonical_source_consistency' => [
                'misleading_first_12_months' => 'not_detected', 'structured_pricing_status' => 'complete',
                'issue_codes' => ['recurring_reset_requires_estimate'],
            ],
        ]);

        $component = Livewire::test('contract-detail', ['contractId' => $contract->id])->instance();
        $estimate = $component->card?->estimate;

        $this->assertNotNull($estimate, 'A market reset must carry the Arvio popover.');
        $this->assertStringContainsString('Nykyinen hinta 8,00 c/kWh on tiedossa 30.9. asti', $estimate->body);
        $this->assertStringContainsString('Loppuvuoden hinnat on arvioitu sähköjohdannaisten markkinahinnoista', $estimate->body);
        $this->assertStringContainsString('koko vuoden keskihinnaksi tulee', $estimate->body);
        $this->assertStringContainsString('Myyjä julkaisee todelliset hinnat neljännesvuosittain', $estimate->body);

        $this->assertNull($component->priceQualifier, 'The qualifier must not repeat what the popover already says.');
    }

    public function test_fully_fixed_contract_states_that_the_price_does_not_change(): void
    {
        $this->contract->update(['pricing_model' => 'FixedPrice', 'contract_type' => 'FixedTerm']);

        Livewire::test('contract-detail', ['contractId' => 'contract-detail-test'])
            ->assertSee('Energian hinta 5,50 c/kWh ei muutu määräaikaisen sopimuksen aikana.');
    }

    public function test_open_ended_fixed_contract_states_that_the_price_does_not_follow_the_market(): void
    {
        $this->contract->update(['pricing_model' => 'FixedPrice', 'contract_type' => 'OpenEnded']);

        Livewire::test('contract-detail', ['contractId' => 'contract-detail-test'])
            ->assertSee('Energian hinta 5,50 c/kWh ei seuraa markkinahintaa, ja myyjän on ilmoitettava hinnanmuutoksesta etukäteen.');
    }

    /**
     * The fallback branch: with no `Arvio` popover, the qualifier is the sole carrier.
     *
     * This fixture is on the legacy pricing path (`canonical_pricing.enabled` off), so
     * `calculated_cost` has no `estimate_method` and `ContractCardCopy::estimate()`
     * returns null. With canonical pricing on, as in production, the same contract gets
     * `hybrid_base_only`, the popover renders the richer version of this sentence, and
     * `priceQualifier` returns null instead. Verified live on Herrfors Vakaa+; the
     * popover-present branch is pinned by
     * `test_market_reset_contract_shows_the_current_price_and_estimate_qualifier`.
     */
    public function test_consumption_effect_contract_states_the_effect_is_not_included(): void
    {
        $this->contract->update(['pricing_model' => 'Hybrid']);

        Livewire::test('contract-detail', ['contractId' => 'contract-detail-test'])
            ->assertSee('Arvio on laskettu sopimuksen kiinteällä perushinnalla 5,50 c/kWh, ja lopullista hintaa nostaa tai laskee kulutusvaikutus, jonka suuruutta myyjä ei julkaise etukäteen.');
    }

    /**
     * The source ships one billing interval per language, so a naive implode printed
     * "1 kk, 1 kk, 1 kk, ".
     */
    public function test_billing_interval_is_printed_once_per_distinct_value(): void
    {
        $this->contract->update([
            'billing_frequency' => ['EN' => '1 kk', 'FI' => '1 kk', 'SV' => '1 kk', 'Default' => null],
        ]);

        $html = Livewire::test('contract-detail', ['contractId' => 'contract-detail-test'])->html();

        $this->assertStringContainsString('Laskutusväli', $html);
        $this->assertStringNotContainsString('1 kk, 1 kk', $html);
    }

    public function test_two_genuinely_different_billing_intervals_are_both_shown(): void
    {
        $this->contract->update([
            'billing_frequency' => ['EN' => '1 kk', 'FI' => '1 kk', 'SV' => '3 kk', 'Default' => null],
        ]);

        Livewire::test('contract-detail', ['contractId' => 'contract-detail-test'])
            ->assertSee('1 kk, 3 kk');
    }

    /**
     * A dead logo URL must leave initials, never a broken image or a blank tile.
     */
    public function test_external_only_company_logo_uses_initials_without_a_remote_request(): void
    {
        $component = Livewire::test('contract-detail', ['contractId' => 'contract-detail-test']);
        $html = $component->html();

        $this->assertStringNotContainsString('https://storage.example.com/logos/test-energia.png', $html);
        $this->assertStringContainsString('Tes', $html);
        $this->assertArrayNotHasKey('logo', $component->instance()->productSchema['brand']);
    }

    public function test_product_schema_and_visible_logo_prefer_optimized_local_logo(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('logos/test-energia.png', 'original content');
        Storage::disk('public')->put('logos/test-energia.webp', 'optimized content');
        $this->company->update(['local_logo_path' => 'logos/test-energia.png']);

        $component = Livewire::test('contract-detail', ['contractId' => 'contract-detail-test']);
        $logoUrl = $component->instance()->productSchema['brand']['logo'];

        $this->assertStringContainsString('logos/test-energia.webp', $logoUrl);
        $this->assertStringContainsString($logoUrl, $component->html());
        $this->assertStringNotContainsString('storage.example.com', $component->html());
    }

    public function test_company_without_a_logo_still_renders_initials(): void
    {
        $this->company->update(['logo_url' => null]);

        Livewire::test('contract-detail', ['contractId' => 'contract-detail-test'])
            ->assertDontSeeHtml('https://storage.example.com/logos/test-energia.png')
            ->assertSee('Tes');
    }

    public function test_shouted_contract_name_is_normalized_in_the_heading_and_the_title(): void
    {
        $this->contract->update(['name' => 'Hehku KIINTEÄ 12 kk - 0€ KUUKAUSIMAKSU ENSIMMÄISET 3 KK!']);

        $component = Livewire::test('contract-detail', ['contractId' => 'contract-detail-test']);

        $component
            ->assertSee('Hehku Kiinteä 12 kk')
            ->assertDontSee('KUUKAUSIMAKSU ENSIMMÄISET');

        $this->assertStringContainsString('Hehku Kiinteä 12 kk', $component->instance()->pageTitle);
        $this->assertStringNotContainsString('KIINTEÄ', $component->instance()->pageTitle);
        $this->assertStringNotContainsString('KIINTEÄ', $component->instance()->ogTitle);
    }

    public function test_seller_description_drops_wrapping_quotes_and_dead_link_callouts(): void
    {
        $this->contract->update([
            'extra_information_fi' => '"Tilaa Perus Sähkö 24kk TÄÄLTÄ. Hinta on kiinteä koko kauden."',
        ]);

        Livewire::test('contract-detail', ['contractId' => 'contract-detail-test'])
            ->assertDontSee('TÄÄLTÄ')
            ->assertSee('Tilaa Perus Sähkö 24kk. Hinta on kiinteä koko kauden.');
    }

    public function test_seller_description_keeps_a_callout_that_is_a_real_link(): void
    {
        $this->contract->update([
            'extra_information_fi' => 'Tilaa sopimus <a href="https://testenergia.fi/tilaa">TÄÄLTÄ</a>.',
        ]);

        Livewire::test('contract-detail', ['contractId' => 'contract-detail-test'])
            ->assertSeeHtml('<a href="https://testenergia.fi/tilaa">TÄÄLTÄ</a>');
    }

    public function test_seller_description_section_is_dropped_when_only_a_callout_remains(): void
    {
        $this->contract->update([
            'extra_information_fi' => '<p><b>KLIKKAA TÄSTÄ</b></p>',
            'long_description' => null,
        ]);

        Livewire::test('contract-detail', ['contractId' => 'contract-detail-test'])
            ->assertDontSee('Sopimuksen kuvaus')
            ->assertDontSee('KLIKKAA');
    }

    /**
     * Active household contract with a General energy price, for ranking and
     * qualifier assertions.
     */
    /**
     * `canonical_pricing` is set on purpose: `PricingCategoryResolver::scopeBucket()`
     * negations rely on three-valued SQL logic, so a NULL `canonical_pricing` row falls
     * out of every bucket. No active production contract is in that state, because a
     * contract stays inactive until an interpretation publishes, so the fixtures have to
     * match that shape or the bucket-scoped counterfactual and same-type alternative
     * silently find nothing. See `app/Services/ContractCard/AGENTS.md` ("Known gap").
     */
    /**
     * The "Kannattaako X?" paragraph in the pay-more direction: it names the tier,
     * the counts, and the money gap, and it offers the way out.
     */
    public function test_verdict_paragraph_states_the_gap_when_the_contract_is_pricier(): void
    {
        // 5 000 kWh: this contract 275 + 35,40 = 310,40 EUR/v, the rival 150 EUR/v.
        $this->createComparisonContract('cheaper-rival-contract', 'Halvempi Kilpailija', 3.0);

        Livewire::test('contract-detail', ['contractId' => 'contract-detail-test'])
            ->assertSee('Kannattaako Perus Sähkö 24kk?')
            ->assertSee('on hinnaltaan vertailun kalliimmassa päässä: 2 vertaillusta sopimuksesta 1 on halvempi 5 000 kWh vuosikulutuksella.')
            ->assertSee('Vertailun halvimpaan sopimukseen verrattuna maksat arviolta 160 € vuodessa enemmän, eli noin 13,37 €/kk.')
            ->assertSee('Katso halvemmat vaihtoehdot');
    }

    /**
     * The cheapest contract states its lead over the runner-up by name and does not
     * offer a "cheaper alternatives" link it cannot honour.
     */
    public function test_verdict_paragraph_states_the_lead_when_the_contract_is_the_cheapest(): void
    {
        // 5 000 kWh: this contract 310,40 EUR/v, the rival 400 EUR/v.
        $this->createComparisonContract('pricier-rival-contract', 'Kalliimpi Kilpailija', 8.0);

        Livewire::test('contract-detail', ['contractId' => 'contract-detail-test'])
            ->assertSee('on 12 kuukauden hinta-arviolla vertailun halvin: 2 vertaillusta sopimuksesta yksikään ei ole edullisempi 5 000 kWh vuosikulutuksella.')
            ->assertSee('Seuraavaksi halvin sopimus (Kalliimpi Kilpailija) maksaa arviolta 90 € vuodessa enemmän.')
            ->assertDontSee('Katso halvemmat vaihtoehdot');
    }

    /**
     * A verdict that claims "valitulla kulutuksella" but never moves reads as a
     * rigged ranking, so the counts, the direction and the gap all follow the chips.
     */
    public function test_verdict_paragraph_reacts_to_the_consumption_selector(): void
    {
        // Low energy price, high monthly fee: cheaper at 18 000 kWh, pricier at 2 000.
        $this->createComparisonContract('verdict-fee-heavy', 'Perusmaksupainotteinen', 3.0, monthlyFee: 15.0);

        $component = Livewire::test('contract-detail', ['contractId' => 'contract-detail-test']);

        // 2 000 kWh: 145 EUR/v against 240 EUR/v, so this contract leads.
        $component->call('setConsumption', 2000)
            ->assertSee('vertailun halvin: 2 vertaillusta sopimuksesta yksikään ei ole edullisempi 2 000 kWh vuosikulutuksella.')
            ->assertSee('Seuraavaksi halvin sopimus (Perusmaksupainotteinen) maksaa arviolta 95 € vuodessa enemmän.');

        // 18 000 kWh: 1 025,40 EUR/v against 720 EUR/v, so the direction flips.
        $component->call('setConsumption', 18000)
            ->assertSee('1 on halvempi 18 000 kWh vuosikulutuksella.')
            ->assertSee('maksat arviolta 305 € vuodessa enemmän, eli noin 25,45 €/kk.')
            ->assertSee('Arvio perustuu sopimuksen hintasijoitukseen, hintatyyppiin ja 12 kuukauden hinta-arvioon 18 000 kWh vuosikulutuksella.');
    }

    /**
     * The FAQPage schema must carry exactly the question/answer pairs the page
     * renders. One list feeds both, so this pins that they cannot drift apart.
     */
    public function test_faq_page_schema_matches_the_rendered_faq_items(): void
    {
        $component = Livewire::test('contract-detail', ['contractId' => 'contract-detail-test']);
        $instance = $component->instance();

        $items = $instance->faqItems;
        $schema = $instance->faqSchema;

        $this->assertNotEmpty($items);
        $this->assertLessThanOrEqual(5, count($items));

        $this->assertSame('https://schema.org', $schema['@context']);
        $this->assertSame('FAQPage', $schema['@type']);
        $this->assertCount(count($items), $schema['mainEntity']);

        foreach ($schema['mainEntity'] as $index => $entity) {
            $this->assertSame('Question', $entity['@type']);
            $this->assertSame('Answer', $entity['acceptedAnswer']['@type']);
            $this->assertSame($items[$index]['question'], $entity['name']);
            $this->assertSame($items[$index]['answer'], $entity['acceptedAnswer']['text']);

            $component->assertSee($entity['name'])
                ->assertSee($entity['acceptedAnswer']['text']);
        }
    }

    /**
     * The pricing-mechanism item owns a stable anchor because the hero's pricing
     * category label links to it.
     */
    public function test_faq_pricing_mechanism_item_carries_the_hero_anchor(): void
    {
        $component = Livewire::test('contract-detail', ['contractId' => 'contract-detail-test'])
            ->assertSeeHtml('<details id="faq-miten"');

        $mechanism = collect($component->instance()->faqItems)->firstWhere('id', 'faq-miten');

        $this->assertNotNull($mechanism);
        $this->assertSame('Miten kiinteä hinta toimii?', $mechanism['question']);
    }

    /**
     * A spot contract answers the volatility question from realized monthly
     * averages, and drops the item entirely when there is no history to quote.
     */
    public function test_spot_faq_answers_the_variation_question_only_with_real_history(): void
    {
        $this->seedSpotAverage();
        $contract = $this->createComparisonContract('spot-faq-contract', 'Pörssisähkö FAQ', 0.42);
        $contract->update(['pricing_model' => 'Spot']);

        Livewire::test('contract-detail', ['contractId' => $contract->id])
            ->assertSee('Miten pörssisähkön hinta muodostuu?')
            ->assertDontSee('Kuinka paljon pörssisähkön hinta vaihtelee?');

        foreach ([3.10, 5.40, 12.80, 7.20] as $index => $average) {
            SpotPriceAverage::create([
                'region' => 'FI',
                'period_type' => SpotPriceAverage::PERIOD_MONTHLY,
                'period_start' => now()->subMonths($index + 1)->startOfMonth(),
                'period_end' => now()->subMonths($index + 1)->endOfMonth(),
                'avg_price_with_tax' => $average,
                'avg_price_without_tax' => $average / 1.255,
                'hours_count' => 720,
            ]);
        }

        Livewire::test('contract-detail', ['contractId' => $contract->id])
            ->assertSee('Kuinka paljon pörssisähkön hinta vaihtelee?')
            ->assertSee('kuukausikeskihinta on vaihdellut välillä 3,10 ja 12,80 c/kWh.');
    }

    /**
     * The terms grid states only what Voltikka actually holds. A row with no data
     * behind it must not appear at all, and the old duplicate billing box is gone.
     */
    public function test_terms_grid_shows_only_rows_whose_data_exists(): void
    {
        $contract = $this->createComparisonContract('terms-grid-contract', 'Ehtosopimus', 6.0);
        $contract->update([
            'billing_frequency' => ['EN' => '1 kk', 'FI' => '1 kk', 'SV' => '1 kk', 'Default' => null],
            'availability_is_national' => true,
            'available_for_existing_users' => null,
        ]);

        Livewire::test('contract-detail', ['contractId' => $contract->id])
            ->assertSee('Sopimusehdot lyhyesti')
            ->assertSee('Sopimuskausi')
            ->assertSee('Toistaiseksi voimassa')
            ->assertSee('Irtisanomisaika')
            ->assertSee('14 vrk')
            ->assertSee('Laskutusväli')
            ->assertSee('1 kk')
            ->assertSee('Saatavuus')
            ->assertSee('Koko Suomi')
            ->assertDontSee('Nykyisille asiakkaille')
            ->assertDontSee('Vuosikulutusrajat')
            ->assertDontSee('Hinta määräajan jälkeen')
            // The old right-column box that these rows replaced.
            ->assertDontSee('Laskutus ja ehdot');
    }

    /**
     * A cap that could bind a household is stated; a cap far above any household
     * is noise and stays out, the same relevance rule the card warning uses.
     */
    public function test_terms_grid_states_only_a_consumption_cap_that_can_bind(): void
    {
        $binding = $this->createComparisonContract('capped-terms-contract', 'Rajattu Ehtosopimus', 6.0);
        $binding->update(['consumption_limitation_max_x_kwh_per_y' => 12000]);

        Livewire::test('contract-detail', ['contractId' => $binding->id])
            ->assertSee('Vuosikulutusrajat')
            ->assertSee('Enintään 12 000 kWh/v');

        $wide = $this->createComparisonContract('wide-cap-terms-contract', 'Väljä Ehtosopimus', 6.0);
        $wide->update(['consumption_limitation_max_x_kwh_per_y' => 200000]);

        Livewire::test('contract-detail', ['contractId' => $wide->id])
            ->assertDontSee('Vuosikulutusrajat');
    }

    protected function createComparisonContract(
        string $id,
        string $name,
        float $generalPrice,
        string $pricingModel = 'FixedPrice',
        ?float $monthlyFee = null,
    ): ElectricityContract {
        $contract = ElectricityContract::create([
            'id' => $id,
            'company_name' => 'Test Energia Oy',
            'name' => $name,
            'name_slug' => \Illuminate\Support\Str::slug($name),
            'contract_type' => 'OpenEnded',
            'metering' => 'General',
            'pricing_model' => $pricingModel,
            'availability_is_national' => true,
            'canonical_pricing' => [
                'recurring_schedule' => ['present' => false],
                'consumption_effect' => ['present' => false],
            ],
        ]);

        ActiveContract::create(['id' => $contract->id]);

        PriceComponent::create([
            'id' => 'pc-general-'.$id,
            'electricity_contract_id' => $contract->id,
            'price_component_type' => 'General',
            'price_date' => now()->format('Y-m-d'),
            'price' => $generalPrice,
            'payment_unit' => 'c/kWh',
        ]);

        if ($monthlyFee !== null) {
            PriceComponent::create([
                'id' => 'pc-monthly-'.$id,
                'electricity_contract_id' => $contract->id,
                'price_component_type' => 'Monthly',
                'price_date' => now()->format('Y-m-d'),
                'price' => $monthlyFee,
                'payment_unit' => 'EUR/month',
            ]);
        }

        return $contract->refresh();
    }

    protected function seedSpotAverage(float $dayAverage = 6.57): void
    {
        SpotPriceAverage::create([
            'region' => 'FI',
            'period_type' => SpotPriceAverage::PERIOD_ROLLING_365D,
            'period_start' => now()->subDays(365),
            'period_end' => now(),
            'avg_price_with_tax' => $dayAverage,
            'avg_price_without_tax' => $dayAverage / 1.255,
            'day_avg_with_tax' => $dayAverage,
            'night_avg_with_tax' => $dayAverage,
            'hours_count' => 8760,
        ]);
    }

    // ---------------------------------------------------------------------
    // Phase 3 unit A: consumption picker, static cost table, counterfactual
    // ---------------------------------------------------------------------

    /**
     * The chips are the page's main interaction, so everything derived from the
     * consumption has to follow them: the hero price, the rank, and the gap to
     * the cheapest alternative. A rank that claims "valitulla kulutuksella" and
     * never moves reads as a rigged ranking.
     */
    public function test_consumption_chips_change_the_price_the_rank_and_the_gap(): void
    {
        // Low energy price, high monthly fee: cheaper than the viewed contract at
        // 18 000 kWh and more expensive at 2 000 kWh, so the rank has to flip.
        $this->createComparisonContract('fee-heavy-contract', 'Perusmaksupainotteinen', 3.0, monthlyFee: 15.0);

        $component = Livewire::test('contract-detail', ['contractId' => 'contract-detail-test']);

        // 2 000 kWh: 2 000 x 5,5 c/kWh + 12 x 2,95 EUR = 145 EUR/v, rank 1.
        $component->call('setConsumption', 2000)
            ->assertSet('consumption', 2000)
            ->assertSet('directConsumption', 2000)
            ->assertSee('145 € vuodessa');
        $this->assertSame(1, $component->instance()->liveRank);

        // 18 000 kWh: 1 025 EUR/v against the fee-heavy contract's 720 EUR/v, rank 2.
        $component->call('setConsumption', 18000)
            ->assertSet('consumption', 18000)
            ->assertSee('1 025 € vuodessa')
            ->assertSee('Perusmaksupainotteinen');

        $this->assertSame(1, $component->instance()->priceRank);
        $this->assertSame(2, $component->instance()->liveRank);
        $this->assertSame(
            305,
            (int) round($component->instance()->cheaperContracts->first()['savings']),
        );

        $signedContext = app(ContractOrderClickContextSigner::class)
            ->verify($component->instance()->contractOrderClickContext);

        $this->assertSame('contract-detail-test', $signedContext->contractId);
        $this->assertSame('Perus Sähkö 24kk', $signedContext->contractName);
        $this->assertSame('Test Energia Oy', $signedContext->companyName);
        $this->assertEqualsWithDelta(1025.4, $signedContext->annualPriceEur, 0.001);
        $this->assertSame(18000, $signedContext->consumptionKwh);
        $this->assertSame(2, $signedContext->priceRank);
        $this->assertSame(2, $signedContext->rankTotal);
        $this->assertSame(18000, $signedContext->rankConsumptionKwh);
    }

    /**
     * The free kWh field must clamp into the supported range, keep a cleared
     * field from zeroing the consumption, and drop the chip selection.
     */
    public function test_free_consumption_input_clamps_and_clears_the_chip_selection(): void
    {
        $component = Livewire::test('contract-detail', ['contractId' => 'contract-detail-test'])
            ->assertSet('directConsumption', 5000);

        $component->set('directConsumption', 500)
            ->assertSet('consumption', ContractDetail::MIN_FREE_CONSUMPTION)
            ->assertSet('directConsumption', ContractDetail::MIN_FREE_CONSUMPTION);

        $component->set('directConsumption', 999999)
            ->assertSet('consumption', ContractDetail::MAX_FREE_CONSUMPTION)
            ->assertSet('directConsumption', ContractDetail::MAX_FREE_CONSUMPTION);

        // A real in-range value applies and no chip stays selected. The assertion
        // is scoped to the consumption chips because the bill module's period
        // preset chips are a second `aria-pressed` control on the page.
        $component->set('directConsumption', 7000)
            ->assertSet('consumption', 7000)
            ->assertSeeHtml('data-consumption-preset="5000"')
            ->assertSee('Sijoitus ja vaihtoehtojen hinnat on laskettu lähimmällä vertailukulutuksella 8 000 kWh/v');

        $signedContext = app(ContractOrderClickContextSigner::class)
            ->verify($component->instance()->contractOrderClickContext);
        $this->assertSame(7000, $signedContext->consumptionKwh);
        $this->assertSame(8000, $signedContext->rankConsumptionKwh);

        $this->assertDoesNotMatchRegularExpression(
            '/data-consumption-preset="\d+"[^>]*aria-pressed="true"/',
            $component->html(),
            'A free consumption value must leave every preset chip unselected.'
        );

        // A cleared field never zeroes the consumption.
        $component->set('directConsumption', '')
            ->assertSet('consumption', 7000)
            ->assertSet('directConsumption', 7000);
    }

    /**
     * The deep link preselects the consumption and mirrors it into the free field.
     */
    public function test_kulutus_query_param_preselects_the_free_field_too(): void
    {
        Livewire::withQueryParams(['kulutus' => 10000])
            ->test('contract-detail', ['contractId' => 'contract-detail-test'])
            ->assertSet('consumption', 10000)
            ->assertSet('directConsumption', 10000);
    }

    /**
     * The per-consumption cost table is SEO content: it has to be in the initial
     * server-rendered HTML with every reference consumption priced, not behind an
     * interaction.
     */
    public function test_static_cost_table_is_in_the_initial_html_with_all_four_rows(): void
    {
        $response = $this->get('/sahkosopimus/sopimus/contract-detail-test');

        $response->assertStatus(200)
            ->assertSee('Arvioitu kustannus eri vuosikulutuksilla')
            // 2 000 / 5 000 / 10 000 / 18 000 kWh x 5,5 c/kWh + 12 x 2,95 EUR
            ->assertSee('145')
            ->assertSee('310')
            ->assertSee('585')
            ->assertSee('1 025')
            ->assertSee('yksiö')
            ->assertSee('kerrostalo')
            ->assertSee('pieni omakotitalo')
            ->assertSee('sähkölämmitteinen talo');
    }

    /**
     * A fixed contract is compared with a typical pörssisähkö contract, and the
     * direction of the sentence has to match the arithmetic.
     */
    public function test_counterfactual_compares_a_fixed_contract_with_a_typical_spot_contract(): void
    {
        $this->seedSpotAverage();
        // 5 000 kWh x (6,57 + 0,42) c/kWh = 349 EUR/v against the viewed 310 EUR/v.
        $this->createComparisonContract('spot-a', 'Pörssi A', 0.42, pricingModel: 'Spot');
        $this->createComparisonContract('spot-b', 'Pörssi B', 0.42, pricingModel: 'Spot');

        Livewire::test('contract-detail', ['contractId' => 'contract-detail-test'])
            ->assertSee('Vertailun vuoksi: tyypillinen pörssisähkösopimus maksaisi samalla 5 000 kWh kulutuksella arviolta 350 € vuodessa')
            ->assertSee('Tämä sopimus on siis arviolta 39 € vuodessa edullisempi.')
            ->assertSeeHtml('href="/sahkosopimus/porssisahko"');
    }

    /**
     * A spot contract is compared with the cheapest fully fixed contract instead,
     * because certainty is what its visitor is deciding about.
     */
    public function test_counterfactual_compares_a_spot_contract_with_the_cheapest_fixed_contract(): void
    {
        $this->seedSpotAverage();
        $spot = $this->createComparisonContract('spot-viewed', 'Pörssi Katsottu', 0.42, pricingModel: 'Spot');
        // 5 000 kWh x 8,00 c/kWh = 400 EUR/v against the spot contract's 349 EUR/v.
        $this->createComparisonContract('fixed-cheapest', 'Kiinteä Halvin', 8.0);

        Livewire::test('contract-detail', ['contractId' => $spot->id])
            ->assertSee('Vertailun vuoksi: halvin kiinteähintainen sopimus maksaisi samalla 5 000 kWh kulutuksella arviolta 400 € vuodessa')
            ->assertSee('Hintavarmuudesta maksaisit siis arviolta 50 € vuodessa.')
            ->assertSeeHtml('href="/sahkosopimus/kiintea-hinta"');
    }

    /**
     * Ranking puts pörssisähkö on top almost everywhere, so the two cheapest
     * alternatives are usually spot. One same-type option must sit beside them,
     * or a visitor who came for a fixed price is offered nothing they would buy.
     */
    public function test_alternatives_include_one_same_type_contract(): void
    {
        $this->seedSpotAverage(2.0);
        $this->createComparisonContract('alt-spot-a', 'Pörssi Halvin', 0.10, pricingModel: 'Spot');
        $this->createComparisonContract('alt-spot-b', 'Pörssi Toinen', 0.20, pricingModel: 'Spot');
        // Cheaper than the viewed contract but more expensive than both spot ones,
        // so it is only shown because it is the same pricing type.
        $this->createComparisonContract('alt-fixed', 'Kiinteä Vaihtoehto', 4.5);

        Livewire::test('contract-detail', ['contractId' => 'contract-detail-test'])
            ->assertSee('Pörssi Halvin')
            ->assertSee('Pörssi Toinen')
            ->assertSee('Kiinteä Vaihtoehto')
            ->assertSee('Samantyyppinen · Kiinteä hinta');
    }
}
