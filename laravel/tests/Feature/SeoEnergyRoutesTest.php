<?php

namespace Tests\Feature;

use App\Models\ActiveContract;
use App\Models\Company;
use App\Models\ElectricityContract;
use App\Models\ElectricitySource;
use App\Models\PriceComponent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SeoEnergyRoutesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Create test company
        Company::create([
            'name' => 'Test Energia Oy',
            'name_slug' => 'test-energia-oy',
            'company_url' => 'https://testenergia.fi',
            'logo_url' => 'https://storage.example.com/logos/test-energia.png',
        ]);

        // Create a wind energy contract
        $this->createContractWithSource(
            'c1',
            'Test Energia Oy',
            'Tuulivoima Sopimus',
            ['renewable_wind' => 80.0, 'renewable_total' => 100.0, 'fossil_total' => 0.0, 'fossil_peat' => 0.0]
        );

        // Create a solar energy contract
        $this->createContractWithSource(
            'c2',
            'Test Energia Oy',
            'Aurinkovoima Sopimus',
            ['renewable_solar' => 60.0, 'renewable_total' => 100.0, 'fossil_total' => 0.0, 'fossil_peat' => 0.0]
        );

        // Create a green energy contract (no wind/solar specific, but 100% renewable, no peat)
        $this->createContractWithSource(
            'c3',
            'Test Energia Oy',
            'Vihreä Sopimus',
            ['renewable_total' => 100.0, 'renewable_hydro' => 100.0, 'fossil_total' => 0.0, 'fossil_peat' => 0.0]
        );

        // Create a non-green contract (has peat)
        $this->createContractWithSource(
            'c4',
            'Test Energia Oy',
            'Tavallinen Sopimus',
            ['renewable_total' => 30.0, 'fossil_total' => 20.0, 'fossil_peat' => 10.0]
        );
    }

    private function createContractWithSource(
        string $id,
        string $companyName,
        string $name,
        array $sourceData,
        float $price = 5.0,
        float $monthlyFee = 3.0,
    ): ElectricityContract {
        $contract = ElectricityContract::create([
            'id' => $id,
            'company_name' => $companyName,
            'name' => $name,
            'name_slug' => ElectricityContract::generateSlug($name),
            'contract_type' => 'OpenEnded',
            'metering' => 'General',
            'pricing_model' => 'FixedPrice',
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

        // Create electricity source
        ElectricitySource::create(array_merge(
            ['contract_id' => $id],
            $sourceData
        ));

        // Mark contract as active so it appears in listings
        ActiveContract::create(['id' => $id]);

        return $contract;
    }

    // ==================== Route Accessibility Tests ====================

    /**
     * Test that tuulisahko route is accessible.
     */
    public function test_tuulisahko_route_is_accessible(): void
    {
        $response = $this->get('/sahkosopimus/tuulisahko');
        $response->assertStatus(200);
    }

    /**
     * Test that aurinkosahko route is accessible.
     */
    public function test_aurinkosahko_route_is_accessible(): void
    {
        $response = $this->get('/sahkosopimus/aurinkosahko');
        $response->assertStatus(200);
    }

    /**
     * Test that vihrea-sahko route is accessible.
     */
    public function test_vihrea_sahko_route_is_accessible(): void
    {
        $response = $this->get('/sahkosopimus/vihrea-sahko');
        $response->assertStatus(200);
    }

    // ==================== H1 Content Tests ====================

    /**
     * Test that tuulisahko page has unique H1.
     */
    public function test_tuulisahko_page_has_unique_h1(): void
    {
        $response = $this->get('/sahkosopimus/tuulisahko');
        $response->assertSee('Tuulisähkösopimukset');
    }

    /**
     * Test that aurinkosahko page has unique H1.
     */
    public function test_aurinkosahko_page_has_unique_h1(): void
    {
        $response = $this->get('/sahkosopimus/aurinkosahko');
        $response->assertSee('Aurinkosähkösopimukset');
    }

    /**
     * Test that vihrea-sahko page has unique H1.
     */
    public function test_vihrea_sahko_page_has_unique_h1(): void
    {
        $response = $this->get('/sahkosopimus/vihrea-sahko');
        $response->assertSee('Vihreä sähkö');
    }

    // ==================== SEO Intro Text Tests ====================

    /**
     * Test that tuulisahko page shows appropriate intro text about wind energy.
     */
    public function test_tuulisahko_page_shows_intro_text(): void
    {
        $response = $this->get('/sahkosopimus/tuulisahko');
        $response->assertSee('tuulivoim');
    }

    /**
     * Test that aurinkosahko page shows appropriate intro text about solar energy.
     */
    public function test_aurinkosahko_page_shows_intro_text(): void
    {
        $response = $this->get('/sahkosopimus/aurinkosahko');
        $response->assertSee('aurinkoenergia');
    }

    /**
     * Test that vihrea-sahko page shows appropriate intro text about green energy.
     */
    public function test_vihrea_sahko_page_shows_intro_text(): void
    {
        $response = $this->get('/sahkosopimus/vihrea-sahko');
        $response->assertSee('uusiutuv');
    }

    // ==================== Contract Filtering Tests ====================

    /**
     * Test that tuulisahko page only shows wind energy contracts.
     */
    public function test_tuulisahko_page_filters_to_wind_contracts(): void
    {
        $response = $this->get('/sahkosopimus/tuulisahko');
        $response->assertSee('Tuulivoima Sopimus');
        $response->assertDontSee('Tavallinen Sopimus');
    }

    /**
     * Test that aurinkosahko page only shows solar energy contracts.
     */
    public function test_aurinkosahko_page_filters_to_solar_contracts(): void
    {
        $response = $this->get('/sahkosopimus/aurinkosahko');
        $response->assertSee('Aurinkovoima Sopimus');
        $response->assertDontSee('Tavallinen Sopimus');
    }

    /**
     * Test that vihrea-sahko page only shows green energy contracts.
     */
    public function test_vihrea_sahko_page_filters_to_green_contracts(): void
    {
        $response = $this->get('/sahkosopimus/vihrea-sahko');
        // Should show contracts with renewable >= 50 and no peat
        $response->assertSee('Tuulivoima Sopimus');
        $response->assertSee('Aurinkovoima Sopimus');
        $response->assertSee('Vihreä Sopimus');
        // Should NOT show contracts with peat
        $response->assertDontSee('Tavallinen Sopimus');
    }

    // ==================== Energy Source Statistics Tests ====================

    /**
     * Test that tuulisahko page shows energy source statistics.
     */
    public function test_tuulisahko_page_shows_energy_statistics(): void
    {
        $response = $this->get('/sahkosopimus/tuulisahko');
        // Should show renewable percentage
        $response->assertSee('Uusiutuva');
    }

    /**
     * Test that aurinkosahko page shows energy source statistics.
     */
    public function test_aurinkosahko_page_shows_energy_statistics(): void
    {
        $response = $this->get('/sahkosopimus/aurinkosahko');
        // Should show renewable percentage
        $response->assertSee('Uusiutuva');
    }

    // ==================== Breadcrumb Navigation Tests ====================

    /**
     * Test that tuulisahko page has breadcrumb navigation.
     */
    public function test_tuulisahko_page_has_breadcrumb(): void
    {
        $response = $this->get('/sahkosopimus/tuulisahko');
        $response->assertSee('Etusivu');
        $response->assertSee('Sähkösopimukset');
    }

    /**
     * Test that aurinkosahko page has breadcrumb navigation.
     */
    public function test_aurinkosahko_page_has_breadcrumb(): void
    {
        $response = $this->get('/sahkosopimus/aurinkosahko');
        $response->assertSee('Etusivu');
        $response->assertSee('Sähkösopimukset');
    }

    /**
     * Test that vihrea-sahko page has breadcrumb navigation.
     */
    public function test_vihrea_sahko_page_has_breadcrumb(): void
    {
        $response = $this->get('/sahkosopimus/vihrea-sahko');
        $response->assertSee('Etusivu');
        $response->assertSee('Sähkösopimukset');
    }

    // ==================== Internal Links Tests ====================

    /**
     * Test that tuulisahko page has internal links to other energy sources.
     */
    public function test_tuulisahko_page_has_links_to_other_energy_sources(): void
    {
        $response = $this->get('/sahkosopimus/tuulisahko');
        $response->assertSee('/sahkosopimus/aurinkosahko');
        $response->assertSee('/sahkosopimus/vihrea-sahko');
    }

    /**
     * Test that aurinkosahko page has internal links to other energy sources.
     */
    public function test_aurinkosahko_page_has_links_to_other_energy_sources(): void
    {
        $response = $this->get('/sahkosopimus/aurinkosahko');
        $response->assertSee('/sahkosopimus/tuulisahko');
        $response->assertSee('/sahkosopimus/vihrea-sahko');
    }

    /**
     * Test that vihrea-sahko page has internal links to other energy sources.
     */
    public function test_vihrea_sahko_page_has_links_to_other_energy_sources(): void
    {
        $response = $this->get('/sahkosopimus/vihrea-sahko');
        $response->assertSee('/sahkosopimus/tuulisahko');
        $response->assertSee('/sahkosopimus/aurinkosahko');
    }

    // ==================== Route Naming Tests ====================

    /**
     * Test that tuulisahko route has a name.
     */
    public function test_tuulisahko_route_is_named(): void
    {
        $url = route('seo.energy.tuulisahko');
        $this->assertEquals(url('/sahkosopimus/tuulisahko'), $url);
    }

    /**
     * Test that aurinkosahko route has a name.
     */
    public function test_aurinkosahko_route_is_named(): void
    {
        $url = route('seo.energy.aurinkosahko');
        $this->assertEquals(url('/sahkosopimus/aurinkosahko'), $url);
    }

    /**
     * Test that vihrea-sahko route has a name.
     */
    public function test_vihrea_sahko_route_is_named(): void
    {
        $url = route('seo.energy.vihrea-sahko');
        $this->assertEquals(url('/sahkosopimus/vihrea-sahko'), $url);
    }

    // ==================== Environmental Info Tests ====================

    /**
     * Test that energy pages show environmental impact information.
     */
    public function test_tuulisahko_page_shows_environmental_info(): void
    {
        $response = $this->get('/sahkosopimus/tuulisahko');
        // Should show info about CO2 emissions or environmental benefits
        $response->assertSee('päästö');
    }

    /**
     * Test that aurinkosahko page shows environmental impact information.
     */
    public function test_aurinkosahko_page_shows_environmental_info(): void
    {
        $response = $this->get('/sahkosopimus/aurinkosahko');
        // Should show info about CO2 emissions or environmental benefits
        $response->assertSee('päästö');
    }

    /**
     * Test that vihrea-sahko page shows environmental impact information.
     */
    public function test_vihrea_sahko_page_shows_environmental_info(): void
    {
        $response = $this->get('/sahkosopimus/vihrea-sahko');
        // Should show info about CO2 emissions or environmental benefits
        $response->assertSee('päästö');
    }
}
