<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\ElectricityContract;
use App\Models\PriceComponent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SeoHousingRoutesTest extends TestCase
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

        // Create a basic test contract
        $this->createContract('c1', 'Test Energia Oy', 'Perussähkö', 5.0, 3.0);
    }

    private function createContract(
        string $id,
        string $companyName,
        string $name,
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

        return $contract;
    }

    // ==================== Route Accessibility Tests ====================

    /**
     * Test that omakotitalo route is accessible.
     */
    public function test_omakotitalo_route_is_accessible(): void
    {
        $response = $this->get('/sahkosopimus/omakotitalo');
        $response->assertStatus(200);
    }

    /**
     * Test that kerrostalo route is accessible.
     */
    public function test_kerrostalo_route_is_accessible(): void
    {
        $response = $this->get('/sahkosopimus/kerrostalo');
        $response->assertStatus(200);
    }

    /**
     * Test that rivitalo route is accessible.
     */
    public function test_rivitalo_route_is_accessible(): void
    {
        $response = $this->get('/sahkosopimus/rivitalo');
        $response->assertStatus(200);
    }

    // ==================== H1 Content Tests ====================

    /**
     * Test that omakotitalo page has unique H1.
     */
    public function test_omakotitalo_page_has_unique_h1(): void
    {
        $response = $this->get('/sahkosopimus/omakotitalo');
        $response->assertSee('Sähkösopimukset omakotitaloon');
    }

    /**
     * Test that kerrostalo page has unique H1.
     */
    public function test_kerrostalo_page_has_unique_h1(): void
    {
        $response = $this->get('/sahkosopimus/kerrostalo');
        $response->assertSee('Sähkösopimukset kerrostaloon');
    }

    /**
     * Test that rivitalo page has unique H1.
     */
    public function test_rivitalo_page_has_unique_h1(): void
    {
        $response = $this->get('/sahkosopimus/rivitalo');
        $response->assertSee('Sähkösopimukset rivitaloon');
    }

    // ==================== SEO Intro Text Tests ====================

    /**
     * Test that omakotitalo page shows appropriate consumption info.
     */
    public function test_omakotitalo_page_shows_consumption_info(): void
    {
        $response = $this->get('/sahkosopimus/omakotitalo');
        $response->assertSee('18 000 kWh');
    }

    /**
     * Test that kerrostalo page shows appropriate consumption info.
     */
    public function test_kerrostalo_page_shows_consumption_info(): void
    {
        $response = $this->get('/sahkosopimus/kerrostalo');
        $response->assertSee('5 000 kWh');
    }

    /**
     * Test that rivitalo page shows appropriate consumption info.
     */
    public function test_rivitalo_page_shows_consumption_info(): void
    {
        $response = $this->get('/sahkosopimus/rivitalo');
        $response->assertSee('10 000 kWh');
    }

    // ==================== Breadcrumb Navigation Tests ====================

    /**
     * Test that omakotitalo page has breadcrumb navigation.
     */
    public function test_omakotitalo_page_has_breadcrumb(): void
    {
        $response = $this->get('/sahkosopimus/omakotitalo');
        $response->assertSee('Etusivu');
        $response->assertSee('Sähkösopimukset');
    }

    /**
     * Test that kerrostalo page has breadcrumb navigation.
     */
    public function test_kerrostalo_page_has_breadcrumb(): void
    {
        $response = $this->get('/sahkosopimus/kerrostalo');
        $response->assertSee('Etusivu');
        $response->assertSee('Sähkösopimukset');
    }

    /**
     * Test that rivitalo page has breadcrumb navigation.
     */
    public function test_rivitalo_page_has_breadcrumb(): void
    {
        $response = $this->get('/sahkosopimus/rivitalo');
        $response->assertSee('Etusivu');
        $response->assertSee('Sähkösopimukset');
    }

    // ==================== Internal Links Tests ====================

    /**
     * Test that omakotitalo page has internal links to other housing types.
     */
    public function test_omakotitalo_page_has_links_to_other_housing_types(): void
    {
        $response = $this->get('/sahkosopimus/omakotitalo');
        $response->assertSee('/sahkosopimus/kerrostalo');
        $response->assertSee('/sahkosopimus/rivitalo');
    }

    /**
     * Test that kerrostalo page has internal links to other housing types.
     */
    public function test_kerrostalo_page_has_links_to_other_housing_types(): void
    {
        $response = $this->get('/sahkosopimus/kerrostalo');
        $response->assertSee('/sahkosopimus/omakotitalo');
        $response->assertSee('/sahkosopimus/rivitalo');
    }

    /**
     * Test that rivitalo page has internal links to other housing types.
     */
    public function test_rivitalo_page_has_links_to_other_housing_types(): void
    {
        $response = $this->get('/sahkosopimus/rivitalo');
        $response->assertSee('/sahkosopimus/omakotitalo');
        $response->assertSee('/sahkosopimus/kerrostalo');
    }

    // ==================== Contracts Display Tests ====================

    /**
     * Test that contracts are displayed on housing type pages.
     */
    public function test_contracts_displayed_on_housing_type_pages(): void
    {
        $response = $this->get('/sahkosopimus/omakotitalo');
        $response->assertSee('Perussähkö');
        $response->assertSee('Test Energia Oy');
    }

    // ==================== Route Naming Tests ====================

    /**
     * Test that omakotitalo route has a name.
     */
    public function test_omakotitalo_route_is_named(): void
    {
        $url = route('seo.housing.omakotitalo');
        $this->assertEquals(url('/sahkosopimus/omakotitalo'), $url);
    }

    /**
     * Test that kerrostalo route has a name.
     */
    public function test_kerrostalo_route_is_named(): void
    {
        $url = route('seo.housing.kerrostalo');
        $this->assertEquals(url('/sahkosopimus/kerrostalo'), $url);
    }

    /**
     * Test that rivitalo route has a name.
     */
    public function test_rivitalo_route_is_named(): void
    {
        $url = route('seo.housing.rivitalo');
        $this->assertEquals(url('/sahkosopimus/rivitalo'), $url);
    }
}
