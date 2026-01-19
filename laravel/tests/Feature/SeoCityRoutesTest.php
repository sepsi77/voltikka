<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\ElectricityContract;
use App\Models\Postcode;
use App\Models\PriceComponent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SeoCityRoutesTest extends TestCase
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

        Company::create([
            'name' => 'Helsinki Voima Oy',
            'name_slug' => 'helsinki-voima-oy',
            'company_url' => 'https://helsinkivoima.fi',
            'logo_url' => 'https://storage.example.com/logos/helsinki-voima.png',
        ]);

        // Create postcodes for Helsinki
        Postcode::create([
            'postcode' => '00100',
            'postcode_fi_name' => 'Helsinki keskusta',
            'postcode_fi_name_slug' => 'helsinki-keskusta',
            'municipal_code' => '091',
            'municipal_name_fi' => 'Helsinki',
            'municipal_name_fi_slug' => 'helsinki',
        ]);

        Postcode::create([
            'postcode' => '00200',
            'postcode_fi_name' => 'Helsinki Kruununhaka',
            'postcode_fi_name_slug' => 'helsinki-kruununhaka',
            'municipal_code' => '091',
            'municipal_name_fi' => 'Helsinki',
            'municipal_name_fi_slug' => 'helsinki',
        ]);

        // Create postcodes for Tampere
        Postcode::create([
            'postcode' => '33100',
            'postcode_fi_name' => 'Tampere keskusta',
            'postcode_fi_name_slug' => 'tampere-keskusta',
            'municipal_code' => '837',
            'municipal_name_fi' => 'Tampere',
            'municipal_name_fi_slug' => 'tampere',
        ]);

        // Create national contract (available everywhere)
        $this->createContract('c1', 'Test Energia Oy', 'Perussähkö', 5.0, 3.0, true);

        // Create Helsinki-only contract
        $this->createContract('c2', 'Helsinki Voima Oy', 'Helsinki Sähkö', 4.5, 2.5, false, ['00100', '00200']);

        // Create Tampere-only contract
        $this->createContract('c3', 'Test Energia Oy', 'Tampere Sähkö', 4.8, 2.8, false, ['33100']);
    }

    private function createContract(
        string $id,
        string $companyName,
        string $name,
        float $price = 5.0,
        float $monthlyFee = 3.0,
        bool $isNational = true,
        array $postcodes = [],
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
            'availability_is_national' => $isNational,
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

        // Attach postcodes if not national
        if (!$isNational && !empty($postcodes)) {
            foreach ($postcodes as $postcode) {
                $contract->availabilityPostcodes()->attach($postcode);
            }
        }

        return $contract;
    }

    // ==================== Route Accessibility Tests ====================

    /**
     * Test that Helsinki city route is accessible.
     */
    public function test_helsinki_route_is_accessible(): void
    {
        $response = $this->get('/sahkosopimus/helsinki');
        $response->assertStatus(200);
    }

    /**
     * Test that Tampere city route is accessible.
     */
    public function test_tampere_route_is_accessible(): void
    {
        $response = $this->get('/sahkosopimus/tampere');
        $response->assertStatus(200);
    }

    /**
     * Test that Espoo city route is accessible (even without postcodes in test data).
     */
    public function test_espoo_route_is_accessible(): void
    {
        $response = $this->get('/sahkosopimus/espoo');
        $response->assertStatus(200);
    }

    // ==================== H1 Content Tests ====================

    /**
     * Test that Helsinki page has unique H1 with locative form.
     */
    public function test_helsinki_page_has_unique_h1(): void
    {
        $response = $this->get('/sahkosopimus/helsinki');
        $response->assertSee('Sähkösopimukset Helsingissä');
    }

    /**
     * Test that Tampere page has unique H1 with locative form.
     */
    public function test_tampere_page_has_unique_h1(): void
    {
        $response = $this->get('/sahkosopimus/tampere');
        $response->assertSee('Sähkösopimukset Tampereella');
    }

    /**
     * Test that Espoo page has unique H1 with locative form.
     */
    public function test_espoo_page_has_unique_h1(): void
    {
        $response = $this->get('/sahkosopimus/espoo');
        $response->assertSee('Sähkösopimukset Espoossa');
    }

    // ==================== SEO Intro Text Tests ====================

    /**
     * Test that city page shows intro text with city name.
     */
    public function test_city_page_shows_intro_text(): void
    {
        $response = $this->get('/sahkosopimus/helsinki');
        $response->assertSee('Helsingissä');
    }

    /**
     * Test that city page shows SEO meta description.
     */
    public function test_city_page_has_meta_description(): void
    {
        $response = $this->get('/sahkosopimus/helsinki');
        // Meta description should mention the city
        $response->assertSee('Helsinki', false);
    }

    // ==================== Contract Display Tests ====================

    /**
     * Test that national contracts are shown on city pages.
     */
    public function test_national_contracts_shown_on_city_pages(): void
    {
        $response = $this->get('/sahkosopimus/helsinki');
        $response->assertSee('Perussähkö');
    }

    /**
     * Test that city-specific contracts are shown on the correct city page.
     */
    public function test_city_specific_contracts_shown_on_correct_city(): void
    {
        $response = $this->get('/sahkosopimus/helsinki');
        $response->assertSee('Helsinki Sähkö');
    }

    /**
     * Test that city-specific contracts are NOT shown on other city pages.
     */
    public function test_city_specific_contracts_not_shown_on_other_cities(): void
    {
        $response = $this->get('/sahkosopimus/tampere');
        $response->assertDontSee('Helsinki Sähkö');
    }

    /**
     * Test that Tampere-specific contracts are shown on Tampere page.
     */
    public function test_tampere_contracts_shown_on_tampere_page(): void
    {
        $response = $this->get('/sahkosopimus/tampere');
        $response->assertSee('Tampere Sähkö');
    }

    // ==================== Breadcrumb Navigation Tests ====================

    /**
     * Test that city page has breadcrumb navigation.
     */
    public function test_city_page_has_breadcrumb(): void
    {
        $response = $this->get('/sahkosopimus/helsinki');
        $response->assertSee('Etusivu');
        $response->assertSee('Sähkösopimukset');
    }

    // ==================== Internal Links Tests ====================

    /**
     * Test that city page has internal links to other page types.
     */
    public function test_city_page_has_internal_links(): void
    {
        $response = $this->get('/sahkosopimus/helsinki');
        // Should have links to housing types
        $response->assertSee('/sahkosopimus/omakotitalo');
        // Should have links to energy sources
        $response->assertSee('/sahkosopimus/tuulisahko');
    }

    // ==================== City Statistics Tests ====================

    /**
     * Test that city page shows provider count.
     */
    public function test_city_page_shows_provider_count(): void
    {
        $response = $this->get('/sahkosopimus/helsinki');
        // Should show number of providers available in Helsinki
        $response->assertSee('sopimusta');
    }

    // ==================== Route Naming Tests ====================

    /**
     * Test that city routes use a wildcard pattern.
     */
    public function test_city_route_uses_wildcard(): void
    {
        // Test that routes work for various cities
        $this->get('/sahkosopimus/helsinki')->assertStatus(200);
        $this->get('/sahkosopimus/tampere')->assertStatus(200);
        $this->get('/sahkosopimus/oulu')->assertStatus(200);
        $this->get('/sahkosopimus/turku')->assertStatus(200);
    }

    /**
     * Test that city route is named.
     */
    public function test_city_route_is_named(): void
    {
        $url = route('seo.city', ['city' => 'helsinki']);
        $this->assertEquals(url('/sahkosopimus/helsinki'), $url);
    }

    // ==================== Locative Form Tests ====================

    /**
     * Test that Oulu has correct locative form.
     */
    public function test_oulu_has_correct_locative(): void
    {
        $response = $this->get('/sahkosopimus/oulu');
        $response->assertSee('Oulussa');
    }

    /**
     * Test that Turku has correct locative form.
     */
    public function test_turku_has_correct_locative(): void
    {
        $response = $this->get('/sahkosopimus/turku');
        $response->assertSee('Turussa');
    }

    /**
     * Test that Jyväskylä has correct locative form.
     */
    public function test_jyvaskyla_has_correct_locative(): void
    {
        $response = $this->get('/sahkosopimus/jyvaskyla');
        $response->assertSee('Jyväskylässä');
    }

    /**
     * Test that Seinäjoki has correct locative form (with -lla/-llä ending).
     */
    public function test_seinajoki_has_correct_locative(): void
    {
        $response = $this->get('/sahkosopimus/seinajoki');
        $response->assertSee('Seinäjoella');
    }

    /**
     * Test that Rovaniemi has correct locative form (with -lla/-llä ending).
     */
    public function test_rovaniemi_has_correct_locative(): void
    {
        $response = $this->get('/sahkosopimus/rovaniemi');
        $response->assertSee('Rovaniemellä');
    }

    // ==================== Unknown City Fallback Tests ====================

    /**
     * Test that unknown city uses generic locative form.
     */
    public function test_unknown_city_uses_generic_locative(): void
    {
        $response = $this->get('/sahkosopimus/testcity');
        $response->assertStatus(200);
        // Should use generic -ssa ending
        $response->assertSee('Testcityssa');
    }

    // ==================== JSON-LD Tests ====================

    /**
     * Test that city page has JSON-LD structured data.
     */
    public function test_city_page_has_json_ld(): void
    {
        $response = $this->get('/sahkosopimus/helsinki');
        $response->assertSee('application/ld+json', false);
    }

    // ==================== Route Conflict Tests ====================

    /**
     * Test that housing routes still work (not overridden by city route).
     */
    public function test_housing_routes_not_overridden(): void
    {
        $response = $this->get('/sahkosopimus/omakotitalo');
        $response->assertStatus(200);
        $response->assertSee('omakotitaloon');
    }

    /**
     * Test that energy routes still work (not overridden by city route).
     */
    public function test_energy_routes_not_overridden(): void
    {
        $response = $this->get('/sahkosopimus/tuulisahko');
        $response->assertStatus(200);
        $response->assertSee('Tuulisähkösopimukset');
    }

    // ==================== City-Specific Info Tests ====================

    /**
     * Test that city page shows contracts count.
     */
    public function test_city_page_shows_contracts_count(): void
    {
        $response = $this->get('/sahkosopimus/helsinki');
        // Should show count of contracts found
        $response->assertSee('löytyi');
    }

    // ==================== Top Cities List Tests ====================

    /**
     * Test that system can get list of top Finnish cities.
     */
    public function test_can_get_top_cities_list(): void
    {
        // Create some postcodes with municipalities
        Postcode::create([
            'postcode' => '02100',
            'postcode_fi_name' => 'Espoo keskusta',
            'postcode_fi_name_slug' => 'espoo-keskusta',
            'municipal_code' => '049',
            'municipal_name_fi' => 'Espoo',
            'municipal_name_fi_slug' => 'espoo',
        ]);

        // Get unique municipalities from postcodes
        $municipalities = Postcode::distinct()
            ->whereNotNull('municipal_name_fi')
            ->pluck('municipal_name_fi');

        $this->assertContains('Helsinki', $municipalities);
        $this->assertContains('Tampere', $municipalities);
        $this->assertContains('Espoo', $municipalities);
    }
}
