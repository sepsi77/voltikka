<?php

namespace Tests\Feature;

use App\Models\ActiveContract;
use App\Models\Company;
use App\Models\ElectricityContract;
use App\Models\ElectricitySource;
use App\Models\PriceComponent;
use App\Services\CompanyListCacheService;
use App\Services\ContractListCacheService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
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
     * Helper method to mark a contract as active.
     */
    private function markAsActive(string $contractId): void
    {
        ActiveContract::create(['id' => $contractId]);
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
        $this->markAsActive('cheap-contract-1');

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
     *
     * The year must come from the clock, never a literal, and the brand suffix must
     * stay off: the old 77-character title was truncated by Google.
     */
    public function test_page_has_correct_title(): void
    {
        $this->seedCheapContract();

        $response = $this->get('/sahkosopimus/sahkoyhtiot');

        $response->assertStatus(200);
        $response->assertSee(
            '<title>Sähköyhtiöt Suomessa '.now()->year.' – 1 yhtiötä ja kaikki 1 sopimusta</title>',
            false,
        );
        $response->assertDontSee('sopimusta | Voltikka</title>', false);
    }

    /**
     * Test that the meta description leads with the completeness claim.
     */
    public function test_page_has_seo_meta_description(): void
    {
        $this->seedCheapContract();

        $response = $this->get('/sahkosopimus/sahkoyhtiot');

        $response->assertStatus(200);
        $response->assertSee('Vertaa kaikkien 1 sähköyhtiön hinnat samalla 5 000 kWh kulutuksella', false);
        $response->assertSee('Emme rajaa vertailua kumppaneihin.', false);
    }

    /**
     * Test that the FAQ renders and is published as FAQPage schema.
     */
    public function test_page_has_faq_block_and_faq_schema(): void
    {
        $this->seedCheapContract();

        $response = $this->get('/sahkosopimus/sahkoyhtiot');

        $response->assertStatus(200);
        $response->assertSee('FAQPage', false);
        $response->assertSee('Kuinka monta sähköyhtiötä Suomessa on?', false);
        $response->assertSee('Mitä eroa on sähköyhtiöllä ja energiayhtiöllä?', false);
        $response->assertSee('Mitä eroa on sähkön myynnillä ja sähkön siirrolla?', false);
        $response->assertSee('Kannattaako valita pieni sähköyhtiö?', false);
    }

    /**
     * Test that the FAQ does not make quality claims Voltikka cannot substantiate.
     *
     * Voltikka holds no customer-satisfaction or review data, so the page must not
     * answer "which company is best/most reliable". Do not add such an answer without
     * a real data source behind it.
     */
    public function test_faq_makes_no_unsupported_quality_claims(): void
    {
        $this->seedCheapContract();

        $response = $this->get('/sahkosopimus/sahkoyhtiot');

        $response->assertDontSee('luotettavin sähköyhtiö', false);
        $response->assertDontSee('paras sähköyhtiö on', false);
    }

    /**
     * Test that the cheapest FAQ answer is derived from live data.
     */
    public function test_cheapest_faq_answer_quotes_the_calculated_company(): void
    {
        $this->seedCheapContract();

        $faq = Livewire::test('company-list')->instance()->faqItems;
        $cheapest = collect($faq)->firstWhere('question', 'Mikä sähköyhtiö on halvin?');

        $this->assertNotNull($cheapest);
        $this->assertStringContainsString('Cheap Energy Oy', $cheapest['answer']);
        $this->assertStringContainsString('5 000 kWh', $cheapest['answer']);
        // The figure is energy only; the exclusion must stay explicit.
        $this->assertStringContainsString('eikä siihen kuulu sähkön siirtoa', $cheapest['answer']);
    }

    /**
     * Test that the small-company section only lists classified, still-listed companies.
     */
    public function test_small_companies_section_lists_classified_companies_only(): void
    {
        // 'Parikkalan Valo Oy' is classified `local`, 'Hehku Energia Oy' is
        // `challenger`, and 'Cheap Energy Oy' is not classified at all.
        foreach ([['Parikkalan Valo Oy', 'parikkalan-valo-oy'], ['Hehku Energia Oy', 'hehku-energia-oy']] as [$name, $slug]) {
            Company::create(['name' => $name, 'name_slug' => $slug]);
            $this->seedCheapContract($name, 'contract-'.$slug, 'pc-'.$slug);
        }
        $this->seedCheapContract();

        $component = Livewire::test('company-list');

        $component->assertSee('Pienet ja paikalliset sähköyhtiöt')
            ->assertSeeHtml('href="/sahkosopimus/sahkoyhtiot/parikkalan-valo-oy"')
            ->assertSeeHtml('href="/sahkosopimus/sahkoyhtiot/hehku-energia-oy"');

        $small = $component->instance()->smallCompanies->pluck('company.name')->all();
        $this->assertContains('Parikkalan Valo Oy', $small);
        $this->assertContains('Hehku Energia Oy', $small);
        $this->assertNotContains('Cheap Energy Oy', $small);
    }

    /**
     * Seed one active, priced contract so the page has a company to rank.
     */
    private function seedCheapContract(
        string $companyName = 'Cheap Energy Oy',
        string $contractId = 'cheap-contract-1',
        string $priceComponentId = 'pc-cheap-1',
    ): void {
        ElectricityContract::create([
            'id' => $contractId,
            'company_name' => $companyName,
            'name' => 'Budget Sähkö',
            'contract_type' => 'Fixed',
            'metering' => 'General',
            'availability_is_national' => true,
        ]);
        $this->markAsActive($contractId);

        PriceComponent::create([
            'id' => $priceComponentId,
            'electricity_contract_id' => $contractId,
            'price_component_type' => 'General',
            'price_date' => now()->format('Y-m-d'),
            'price' => 4.0,
            'payment_unit' => 'c/kWh',
        ]);
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
        $this->markAsActive('cheap-contract-1');

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
        $this->markAsActive('green-contract-1');

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
        $this->markAsActive('green-contract-1');

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
        $this->markAsActive('cheap-contract-1');

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
        $this->markAsActive('nuclear-contract-1');

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
        $this->markAsActive('cheap-contract-1');

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
        $this->markAsActive('cheap-contract-1');

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
        $this->markAsActive('cheap-contract-2');

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
        $this->markAsActive('cheap-contract-1');

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
        $this->markAsActive('green-contract-1');

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
        $this->markAsActive('cheap-contract-1');

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
        $this->markAsActive('cheap-contract-2');

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
        $this->markAsActive('cheap-contract-3');

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
        $this->markAsActive('green-contract-1');

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
        $this->markAsActive('cheap-contract-1');

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
        $this->markAsActive('green-contract-1');

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
        $this->markAsActive('green-contract-1');

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
        $this->markAsActive('cheap-contract-1');

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
        $this->markAsActive('cheap-spot-1');

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
        $this->markAsActive('green-spot-1');

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
        $this->markAsActive('cheap-contract-1');

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
        $this->markAsActive('green-contract-1');

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
        $this->markAsActive('cheap-contract-1');

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
        $this->markAsActive('cheap-contract-1');

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

    public function test_canonical_company_prices_ignore_conflicting_relational_rows_and_include_canonical_only_contracts(): void
    {
        config(['canonical_pricing.enabled' => true]);

        $this->createCanonicalContract('cheap-canonical', 'Cheap Energy Oy', 10.0, 1.0);
        $this->createCanonicalContract('green-canonical', 'Green Power Ab', 5.0, 20.0);
        $this->createCanonicalContract('nuclear-canonical-only', 'Nuclear Plus Oy', 7.0);

        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = strtolower($query->sql);
        });

        $component = Livewire::test('company-list');
        $companies = $component->viewData('companies')->keyBy('company.name');
        $cheapest = $component->viewData('cheapestCompanies');

        $this->assertCount(3, $companies);
        $this->assertEqualsWithDelta(500.0, $companies['Cheap Energy Oy']['avgPrice'], 0.01);
        $this->assertEqualsWithDelta(500.0, $companies['Cheap Energy Oy']['lowestPrice'], 0.01);
        $this->assertEqualsWithDelta(250.0, $companies['Green Power Ab']['lowestPrice'], 0.01);
        $this->assertEqualsWithDelta(350.0, $companies['Nuclear Plus Oy']['lowestPrice'], 0.01);
        $this->assertSame('Green Power Ab', $cheapest->first()['company']->name);
        $this->assertEmpty(array_filter(
            $queries,
            fn (string $sql): bool => str_contains($sql, 'price_components'),
        ));
        $component->assertSee('250 EUR')->assertSee('350 EUR')->assertSee('500 EUR');
    }

    public function test_canonical_excluded_and_missing_outcomes_do_not_become_zero_counts_or_rankings(): void
    {
        config(['canonical_pricing.enabled' => true]);

        $this->createCanonicalContract('listed-canonical', 'Cheap Energy Oy', 8.0);
        $this->createCanonicalContract('missing-canonical', 'Cheap Energy Oy', null, 0.0);
        $this->createCanonicalContract('excluded-canonical', 'Green Power Ab', null, 0.0);
        $this->createCanonicalContract('incomplete-canonical', 'Nuclear Plus Oy', null, 0.0, true);

        $component = Livewire::test('company-list');
        $companies = $component->viewData('companies');
        $cheapest = $component->viewData('cheapestCompanies');

        $this->assertCount(1, $companies);
        $this->assertSame(1, $component->viewData('companyCount'));
        $this->assertSame(1, $component->viewData('contractCount'));
        $this->assertCount(1, $cheapest);
        $this->assertSame(1, $companies->first()['contractCount']);
        $this->assertSame('Cheap Energy Oy', $cheapest->first()['company']->name);
        $component
            ->assertDontSee('Green Power Ab')
            ->assertDontSee('Nuclear Plus Oy')
            ->assertDontSeeHtml('>0 EUR<');
    }

    public function test_company_list_keeps_relational_pricing_when_canonical_feature_is_off(): void
    {
        config(['canonical_pricing.enabled' => false]);

        $this->createCanonicalContract('cheap-legacy', 'Cheap Energy Oy', 20.0, 2.0);
        $this->createCanonicalContract('green-legacy', 'Green Power Ab', 1.0, 8.0);

        $component = Livewire::test('company-list');
        $companies = $component->viewData('companies')->keyBy('company.name');
        $cheapest = $component->viewData('cheapestCompanies');

        $this->assertEqualsWithDelta(100.0, $companies['Cheap Energy Oy']['lowestPrice'], 0.01);
        $this->assertEqualsWithDelta(400.0, $companies['Green Power Ab']['lowestPrice'], 0.01);
        $this->assertSame('Cheap Energy Oy', $cheapest->first()['company']->name);
    }

    public function test_company_cache_key_has_schema_and_separates_both_pricing_flags(): void
    {
        Cache::flush();

        foreach ([[false, false], [true, false], [true, true]] as [$canonical, $reset]) {
            config([
                'canonical_pricing.enabled' => $canonical,
                'canonical_pricing.reset_forward_shift.enabled' => $reset,
            ]);
            app(CompanyListCacheService::class)->getCachedCompanies(5000);
        }

        app(ContractListCacheService::class)->bumpVersion();
        app(CompanyListCacheService::class)->getCachedCompanies(5000);

        $keys = $this->cacheKeysMatching('company_list:');

        $this->assertContains('company_list:v1:s2:lv1:c0r0:5000', $keys);
        $this->assertContains('company_list:v1:s2:lv1:c1r0:5000', $keys);
        $this->assertContains('company_list:v1:s2:lv1:c1r1:5000', $keys);
        $this->assertContains('company_list:v1:s2:lv2:c1r1:5000', $keys);
    }

    private function createCanonicalContract(
        string $id,
        string $company,
        ?float $canonicalRate,
        ?float $relationalRate = null,
        bool $emptyPhase = false,
    ): void {
        $components = $canonicalRate !== null && ! $emptyPhase ? [[
            'component_type' => 'energy_general',
            'amount' => $canonicalRate,
            'normal_amount' => null,
            'unit' => 'cents_per_kwh',
            'vat_status' => 'included',
            'price_role' => 'current',
            'source_kind' => 'both',
            'evidence' => [],
        ]] : [];

        ElectricityContract::create([
            'id' => $id,
            'company_name' => $company,
            'name' => $id,
            'contract_type' => 'OpenEnded',
            'pricing_model' => 'FixedPrice',
            'metering' => 'General',
            'target_group' => 'Household',
            'availability_is_national' => true,
            'canonical_pricing' => $canonicalRate === null && ! $emptyPhase ? null : [
                'phases' => [[
                    'label' => 'current',
                    'phase_kind' => 'current_structured',
                    'starts' => ['kind' => 'contract_start', 'value' => null],
                    'ends' => ['kind' => 'none', 'value' => null],
                    'components' => $components,
                    'evidence' => [],
                ]],
                'recurring_schedule' => [
                    'present' => false,
                    'cadence' => 'none',
                    'current_period_start' => null,
                    'current_period_end' => null,
                    'future_price_known' => null,
                    'description' => null,
                    'evidence' => [],
                ],
                'consumption_effect' => [
                    'present' => false,
                    'applies_to' => 'unknown',
                    'cadence' => 'none',
                    'expected_cents_per_kwh' => null,
                    'typical_min_cents_per_kwh' => null,
                    'typical_max_cents_per_kwh' => null,
                    'hard_min_cents_per_kwh' => null,
                    'hard_max_cents_per_kwh' => null,
                    'uncapped' => null,
                    'description' => null,
                    'evidence' => [],
                ],
            ],
            'canonical_calculation' => [
                'status' => 'exact',
                'missing_facts' => [],
                'required_assumptions' => [],
            ],
            'canonical_source_consistency' => [
                'misleading_first_12_months' => 'not_detected',
                'structured_pricing_status' => 'complete',
                'issue_codes' => [],
            ],
        ]);
        $this->markAsActive($id);

        if ($relationalRate !== null) {
            PriceComponent::create([
                'id' => 'pc-'.$id,
                'electricity_contract_id' => $id,
                'price_component_type' => 'General',
                'price_date' => now()->format('Y-m-d'),
                'price' => $relationalRate,
                'payment_unit' => 'c/kWh',
            ]);
        }
    }

    /** @return list<string> */
    private function cacheKeysMatching(string $needle): array
    {
        $store = Cache::store()->getStore();
        $storage = (new \ReflectionClass($store))->getProperty('storage');
        $storage->setAccessible(true);

        return collect(array_keys((array) $storage->getValue($store)))
            ->map(fn ($key) => (string) $key)
            ->filter(fn (string $key) => str_contains($key, $needle))
            ->values()
            ->all();
    }
}
