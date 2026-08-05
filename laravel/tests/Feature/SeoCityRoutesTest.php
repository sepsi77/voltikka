<?php

namespace Tests\Feature;

use App\Livewire\CitySolarEstimate;
use App\Models\ActiveContract;
use App\Models\Company;
use App\Models\ElectricityContract;
use App\Models\Municipality;
use App\Models\Postcode;
use App\Models\PriceComponent;
use App\Services\CitySolarService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
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

        // Create municipalities with proper locative forms
        Municipality::create([
            'code' => '091',
            'slug' => 'helsinki',
            'name' => 'Helsinki',
            'name_locative' => 'Helsingissä',
            'name_genitive' => 'Helsingin',
        ]);

        Municipality::create([
            'code' => '837',
            'slug' => 'tampere',
            'name' => 'Tampere',
            'name_locative' => 'Tampereella',
            'name_genitive' => 'Tampereen',
        ]);

        Municipality::create([
            'code' => '049',
            'slug' => 'espoo',
            'name' => 'Espoo',
            'name_locative' => 'Espoossa',
            'name_genitive' => 'Espoon',
        ]);

        Municipality::create([
            'code' => '564',
            'slug' => 'oulu',
            'name' => 'Oulu',
            'name_locative' => 'Oulussa',
            'name_genitive' => 'Oulun',
        ]);

        Municipality::create([
            'code' => '853',
            'slug' => 'turku',
            'name' => 'Turku',
            'name_locative' => 'Turussa',
            'name_genitive' => 'Turun',
        ]);

        Municipality::create([
            'code' => '179',
            'slug' => 'jyvaskyla',
            'name' => 'Jyväskylä',
            'name_locative' => 'Jyväskylässä',
            'name_genitive' => 'Jyväskylän',
        ]);

        Municipality::create([
            'code' => '743',
            'slug' => 'seinajoki',
            'name' => 'Seinäjoki',
            'name_locative' => 'Seinäjoella',
            'name_genitive' => 'Seinäjoen',
        ]);

        Municipality::create([
            'code' => '698',
            'slug' => 'rovaniemi',
            'name' => 'Rovaniemi',
            'name_locative' => 'Rovaniemellä',
            'name_genitive' => 'Rovaniemen',
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
        if (! $isNational && ! empty($postcodes)) {
            foreach ($postcodes as $postcode) {
                $contract->availabilityPostcodes()->attach($postcode);
            }
        }

        // Mark contract as active so it appears in listings
        ActiveContract::create(['id' => $id]);

        return $contract;
    }

    // ==================== Route Accessibility Tests ====================

    /**
     * Test that Helsinki city route is accessible at new URL.
     */
    public function test_helsinki_route_is_accessible(): void
    {
        $response = $this->get('/sahkosopimus/paikkakunnat/helsinki');
        $response->assertStatus(200);
    }

    public function test_city_page_rejects_missing_municipality_slug(): void
    {
        $municipalitySlugQueries = 0;

        DB::listen(function ($query) use (&$municipalitySlugQueries) {
            $sql = strtolower($query->sql);

            if (str_contains($sql, 'municipalities') && str_contains($sql, 'slug')) {
                $municipalitySlugQueries++;
            }
        });

        $response = $this->get('/sahkosopimus/paikkakunnat/tuntematon-paikkakunta');

        $response->assertStatus(404);
        $this->assertSame(1, $municipalitySlugQueries);
    }

    public function test_city_page_does_not_fetch_solar_estimate_during_initial_page_load(): void
    {
        $this->app->instance(CitySolarService::class, new class
        {
            public function getSolarEstimate(Municipality $municipality, float $systemKwp = 5.0): ?array
            {
                throw new \RuntimeException('Solar estimate should be lazy loaded.');
            }
        });

        $response = $this->get('/sahkosopimus/paikkakunnat/helsinki');

        $response->assertStatus(200);
        $response->assertSee('Aurinkosähkön tuottopotentiaali latautuu');
    }

    public function test_city_solar_estimate_googlebot_request_only_reads_cached_estimate(): void
    {
        $municipality = Municipality::where('slug', 'helsinki')->firstOrFail();
        $municipality->update([
            'center_latitude' => 60.1699,
            'center_longitude' => 24.9384,
        ]);

        $this->app->instance(CitySolarService::class, new class
        {
            public function getCachedSolarEstimate(Municipality $municipality, float $systemKwp = 5.0): ?array
            {
                return null;
            }

            public function getSolarEstimate(Municipality $municipality, float $systemKwp = 5.0): ?array
            {
                throw new \RuntimeException('Crawler requests must not fetch PVGIS.');
            }
        });

        $this->app->instance('request', Request::create(
            '/livewire/update',
            'POST',
            [],
            [],
            [],
            ['HTTP_USER_AGENT' => 'Mozilla/5.0 (Linux; Android 6.0.1) AppleWebKit/537.36 (KHTML, like Gecko; compatible; Googlebot/2.1; +http://www.google.com/bot.html)']
        ));

        $component = new CitySolarEstimate;
        $component->mount($municipality->id, 'Helsingissä', 'helsinki');

        $view = $component->render();

        $this->assertSame('livewire.city-solar-estimate', $view->name());
        $this->assertNull($view->getData()['solarEstimate']);
    }

    /**
     * Test that Tampere city route is accessible at new URL.
     */
    public function test_tampere_route_is_accessible(): void
    {
        $response = $this->get('/sahkosopimus/paikkakunnat/tampere');
        $response->assertStatus(200);
    }

    /**
     * Test that Espoo city route is accessible (even without postcodes in test data).
     */
    public function test_espoo_route_is_accessible(): void
    {
        $response = $this->get('/sahkosopimus/paikkakunnat/espoo');
        $response->assertStatus(200);
    }

    // ==================== 301 Redirect Tests ====================

    /**
     * Test that old Helsinki URL redirects to new URL with 301.
     */
    public function test_old_helsinki_url_redirects_to_new(): void
    {
        $response = $this->get('/sahkosopimus/helsinki');
        $response->assertStatus(301);
        $response->assertRedirect('/sahkosopimus/paikkakunnat/helsinki');
    }

    /**
     * Test that old Tampere URL redirects to new URL with 301.
     */
    public function test_old_tampere_url_redirects_to_new(): void
    {
        $response = $this->get('/sahkosopimus/tampere');
        $response->assertStatus(301);
        $response->assertRedirect('/sahkosopimus/paikkakunnat/tampere');
    }

    /**
     * Test that old city URL pattern redirects for real municipalities.
     */
    public function test_old_city_url_pattern_redirects(): void
    {
        $response = $this->get('/sahkosopimus/oulu');
        $response->assertStatus(301);
        $response->assertRedirect('/sahkosopimus/paikkakunnat/oulu');
    }

    public function test_old_city_url_pattern_rejects_unknown_slugs(): void
    {
        $this->get('/sahkosopimus/tuntematon-paikkakunta')->assertStatus(404);
    }

    // ==================== H1 Content Tests ====================

    /**
     * Test that Helsinki page has unique H1 with locative form.
     */
    public function test_helsinki_page_has_unique_h1(): void
    {
        $response = $this->get('/sahkosopimus/paikkakunnat/helsinki');
        $response->assertSee('Sähkösopimukset Helsingissä');
    }

    /**
     * Test that Tampere page has unique H1 with locative form.
     */
    public function test_tampere_page_has_unique_h1(): void
    {
        $response = $this->get('/sahkosopimus/paikkakunnat/tampere');
        $response->assertSee('Sähkösopimukset Tampereella');
    }

    /**
     * Test that Espoo page has unique H1 with locative form.
     */
    public function test_espoo_page_has_unique_h1(): void
    {
        $response = $this->get('/sahkosopimus/paikkakunnat/espoo');
        $response->assertSee('Sähkösopimukset Espoossa');
    }

    // ==================== SEO Intro Text Tests ====================

    /**
     * Test that city page shows intro text with city name.
     */
    public function test_city_page_shows_intro_text(): void
    {
        $response = $this->get('/sahkosopimus/paikkakunnat/helsinki');
        $response->assertSee('Helsingissä');
    }

    /**
     * Test that city page shows SEO meta description.
     */
    public function test_city_page_has_meta_description(): void
    {
        $response = $this->get('/sahkosopimus/paikkakunnat/helsinki');
        // Meta description should mention the city
        $response->assertSee('Helsinki', false);
    }

    // ==================== Contract Display Tests ====================

    /**
     * Test that national contracts are shown on city pages.
     */
    public function test_national_contracts_shown_on_city_pages(): void
    {
        $response = $this->get('/sahkosopimus/paikkakunnat/helsinki');
        $response->assertSee('Perussähkö');
    }

    /**
     * A city URL alone does not prove the visitor's exact delivery area.
     */
    public function test_city_specific_contracts_require_an_exact_selected_postcode(): void
    {
        $this->get('/sahkosopimus/paikkakunnat/helsinki')
            ->assertDontSee('Helsinki Sähkö');

        Livewire::test('seo-contracts-list', ['location' => 'helsinki'])
            ->call('selectPostcode', '00100')
            ->assertSee('Helsinki Sähkö');
    }

    /**
     * Test that city-specific contracts are NOT shown on other city pages.
     */
    public function test_city_specific_contracts_not_shown_on_other_cities(): void
    {
        $response = $this->get('/sahkosopimus/paikkakunnat/tampere');
        $response->assertDontSee('Helsinki Sähkö');
    }

    /**
     * A different city URL also stays nationwide-only without a postcode.
     */
    public function test_tampere_contracts_are_hidden_without_a_selected_postcode(): void
    {
        $this->get('/sahkosopimus/paikkakunnat/tampere')
            ->assertDontSee('Tampere Sähkö');
    }

    // ==================== Breadcrumb Navigation Tests ====================

    /**
     * Test that city page has breadcrumb navigation.
     */
    public function test_city_page_has_breadcrumb(): void
    {
        $response = $this->get('/sahkosopimus/paikkakunnat/helsinki');
        $response->assertSee('Etusivu');
        $response->assertSee('Sähkösopimukset');
    }

    // ==================== Internal Links Tests ====================

    /**
     * Test that city page has internal links to other page types.
     */
    public function test_city_page_has_internal_links(): void
    {
        $response = $this->get('/sahkosopimus/paikkakunnat/helsinki');
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
        $response = $this->get('/sahkosopimus/paikkakunnat/helsinki');
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
        $this->get('/sahkosopimus/paikkakunnat/helsinki')->assertStatus(200);
        $this->get('/sahkosopimus/paikkakunnat/tampere')->assertStatus(200);
        $this->get('/sahkosopimus/paikkakunnat/oulu')->assertStatus(200);
        $this->get('/sahkosopimus/paikkakunnat/turku')->assertStatus(200);
    }

    /**
     * Test that city route is named and uses new URL pattern.
     */
    public function test_city_route_is_named(): void
    {
        $url = route('seo.city', ['location' => 'helsinki']);
        $this->assertEquals(url('/sahkosopimus/paikkakunnat/helsinki'), $url);
    }

    // ==================== Locative Form Tests ====================

    /**
     * Test that Oulu has correct locative form.
     */
    public function test_oulu_has_correct_locative(): void
    {
        $response = $this->get('/sahkosopimus/paikkakunnat/oulu');
        $response->assertSee('Oulussa');
    }

    /**
     * Test that Turku has correct locative form.
     */
    public function test_turku_has_correct_locative(): void
    {
        $response = $this->get('/sahkosopimus/paikkakunnat/turku');
        $response->assertSee('Turussa');
    }

    /**
     * Test that Jyväskylä has correct locative form.
     */
    public function test_jyvaskyla_has_correct_locative(): void
    {
        $response = $this->get('/sahkosopimus/paikkakunnat/jyvaskyla');
        $response->assertSee('Jyväskylässä');
    }

    /**
     * Test that Seinäjoki has correct locative form (with -lla/-llä ending).
     */
    public function test_seinajoki_has_correct_locative(): void
    {
        $response = $this->get('/sahkosopimus/paikkakunnat/seinajoki');
        $response->assertSee('Seinäjoella');
    }

    /**
     * Test that Rovaniemi has correct locative form (with -lla/-llä ending).
     */
    public function test_rovaniemi_has_correct_locative(): void
    {
        $response = $this->get('/sahkosopimus/paikkakunnat/rovaniemi');
        $response->assertSee('Rovaniemellä');
    }

    // ==================== Unknown City Fallback Tests ====================

    /**
     * Test that unknown city slugs do not create fake location pages.
     */
    public function test_unknown_city_returns_404(): void
    {
        $response = $this->get('/sahkosopimus/paikkakunnat/testcity');
        $response->assertStatus(404);
    }

    // ==================== JSON-LD Tests ====================

    /**
     * Test that city page has JSON-LD structured data.
     */
    public function test_city_page_has_json_ld(): void
    {
        $response = $this->get('/sahkosopimus/paikkakunnat/helsinki');
        $response->assertSee('application/ld+json', false);
    }

    // ==================== Route Conflict Tests ====================

    /**
     * Test that housing routes still work (not overridden by city route or redirect).
     */
    public function test_housing_routes_not_overridden(): void
    {
        $response = $this->get('/sahkosopimus/omakotitalo');
        $response->assertStatus(200);
        $response->assertSee('omakotitaloon');
    }

    /**
     * Test that energy routes still work (not overridden by city route or redirect).
     */
    public function test_energy_routes_not_overridden(): void
    {
        $response = $this->get('/sahkosopimus/tuulisahko');
        $response->assertStatus(200);
        $response->assertSee('Tuulisähkösopimukset');
    }

    /**
     * Test that pricing routes still work (not overridden by city route or redirect).
     */
    public function test_pricing_routes_not_overridden(): void
    {
        $response = $this->get('/sahkosopimus/porssisahko');
        $response->assertStatus(200);
        $response->assertSee('Pörssisähkösopimukset');
    }

    public function test_kiintea_hinta_route_is_pricing_page_not_city_redirect(): void
    {
        $response = $this->get('/sahkosopimus/kiintea-hinta');

        $response->assertStatus(200);
        $response->assertSee('Kiinteähintaiset sähkösopimukset');
        $response->assertDontSee('Kiintea Hintassa');
    }

    public function test_kiintea_hinta_is_not_valid_location_page(): void
    {
        $this->get('/sahkosopimus/paikkakunnat/kiintea-hinta')->assertStatus(404);
    }

    /**
     * Test that cheapest contracts route still works.
     */
    public function test_cheapest_contracts_route_not_overridden(): void
    {
        $response = $this->get('/sahkosopimus/halvin-sahkosopimus');
        $response->assertStatus(200);
    }

    /**
     * Test that company contracts route still works.
     */
    public function test_company_contracts_route_not_overridden(): void
    {
        $response = $this->get('/sahkosopimus/yritykselle');
        $response->assertStatus(200);
    }

    // ==================== City-Specific Info Tests ====================

    /**
     * Test that city page shows the current results count wording.
     */
    public function test_city_page_shows_contracts_count(): void
    {
        $response = $this->get('/sahkosopimus/paikkakunnat/helsinki');

        $response->assertSee('sopimusta');
        $response->assertSee('12 kk arvio sisältää tarjoukset');
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

    // ==================== Locations Browser Tests ====================

    /**
     * Test that locations browser route is accessible.
     */
    public function test_locations_browser_is_accessible(): void
    {
        $response = $this->get('/sahkosopimus/paikkakunnat');
        $response->assertStatus(200);
        $response->assertSee('paikkakunnittain');
    }

    /**
     * Test that locations browser lists municipalities.
     */
    public function test_locations_browser_shows_municipalities(): void
    {
        $response = $this->get('/sahkosopimus/paikkakunnat');
        $response->assertSee('Helsinki');
        $response->assertSee('Tampere');
    }
}
