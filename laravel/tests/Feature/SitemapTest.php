<?php

namespace Tests\Feature;

use App\Models\Postcode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SitemapTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Create some test postcodes for different cities
        Postcode::insert([
            [
                'postcode' => '00100',
                'postcode_fi_name' => 'Helsinki keskusta',
                'postcode_fi_name_slug' => 'helsinki-keskusta',
                'municipal_name_fi' => 'Helsinki',
                'municipal_name_fi_slug' => 'helsinki',
            ],
            [
                'postcode' => '00200',
                'postcode_fi_name' => 'Helsinki Lauttasaari',
                'postcode_fi_name_slug' => 'helsinki-lauttasaari',
                'municipal_name_fi' => 'Helsinki',
                'municipal_name_fi_slug' => 'helsinki',
            ],
            [
                'postcode' => '33100',
                'postcode_fi_name' => 'Tampere keskusta',
                'postcode_fi_name_slug' => 'tampere-keskusta',
                'municipal_name_fi' => 'Tampere',
                'municipal_name_fi_slug' => 'tampere',
            ],
            [
                'postcode' => '02100',
                'postcode_fi_name' => 'Espoo keskusta',
                'postcode_fi_name_slug' => 'espoo-keskusta',
                'municipal_name_fi' => 'Espoo',
                'municipal_name_fi_slug' => 'espoo',
            ],
            [
                'postcode' => '90100',
                'postcode_fi_name' => 'Oulu keskusta',
                'postcode_fi_name_slug' => 'oulu-keskusta',
                'municipal_name_fi' => 'Oulu',
                'municipal_name_fi_slug' => 'oulu',
            ],
            [
                'postcode' => '20100',
                'postcode_fi_name' => 'Turku keskusta',
                'postcode_fi_name_slug' => 'turku-keskusta',
                'municipal_name_fi' => 'Turku',
                'municipal_name_fi_slug' => 'turku',
            ],
        ]);
    }

    /** @test */
    public function sitemap_route_is_accessible(): void
    {
        $response = $this->get('/sitemap.xml');

        $response->assertStatus(200);
    }

    /** @test */
    public function sitemap_returns_xml_content_type(): void
    {
        $response = $this->get('/sitemap.xml');

        $response->assertHeader('Content-Type', 'text/xml; charset=UTF-8');
    }

    /** @test */
    public function sitemap_has_valid_xml_structure(): void
    {
        $response = $this->get('/sitemap.xml');

        $content = $response->getContent();

        // Check XML declaration
        $this->assertStringContainsString('<?xml version="1.0" encoding="UTF-8"?>', $content);

        // Check urlset with proper namespace
        $this->assertStringContainsString('<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"', $content);
        $this->assertStringContainsString('</urlset>', $content);
    }

    /** @test */
    public function sitemap_includes_main_pages(): void
    {
        $response = $this->get('/sitemap.xml');

        $content = $response->getContent();
        $baseUrl = config('app.url');

        // Homepage
        $this->assertStringContainsString("<loc>{$baseUrl}</loc>", $content);

        // Spot price page
        $this->assertStringContainsString("<loc>{$baseUrl}/spot-price</loc>", $content);

        // Locations page
        $this->assertStringContainsString("<loc>{$baseUrl}/sahkosopimus/paikkakunnat</loc>", $content);
    }

    /** @test */
    public function sitemap_includes_housing_type_pages(): void
    {
        $response = $this->get('/sitemap.xml');

        $content = $response->getContent();
        $baseUrl = config('app.url');

        $this->assertStringContainsString("<loc>{$baseUrl}/sahkosopimus/omakotitalo</loc>", $content);
        $this->assertStringContainsString("<loc>{$baseUrl}/sahkosopimus/kerrostalo</loc>", $content);
        $this->assertStringContainsString("<loc>{$baseUrl}/sahkosopimus/rivitalo</loc>", $content);
    }

    /** @test */
    public function sitemap_includes_energy_source_pages(): void
    {
        $response = $this->get('/sitemap.xml');

        $content = $response->getContent();
        $baseUrl = config('app.url');

        $this->assertStringContainsString("<loc>{$baseUrl}/sahkosopimus/tuulisahko</loc>", $content);
        $this->assertStringContainsString("<loc>{$baseUrl}/sahkosopimus/aurinkosahko</loc>", $content);
        $this->assertStringContainsString("<loc>{$baseUrl}/sahkosopimus/vihrea-sahko</loc>", $content);
    }

    /** @test */
    public function sitemap_includes_city_pages(): void
    {
        $response = $this->get('/sitemap.xml');

        $content = $response->getContent();
        $baseUrl = config('app.url');

        // Should include cities from database
        $this->assertStringContainsString("<loc>{$baseUrl}/sahkosopimus/helsinki</loc>", $content);
        $this->assertStringContainsString("<loc>{$baseUrl}/sahkosopimus/tampere</loc>", $content);
        $this->assertStringContainsString("<loc>{$baseUrl}/sahkosopimus/espoo</loc>", $content);
        $this->assertStringContainsString("<loc>{$baseUrl}/sahkosopimus/oulu</loc>", $content);
        $this->assertStringContainsString("<loc>{$baseUrl}/sahkosopimus/turku</loc>", $content);
    }

    /** @test */
    public function sitemap_does_not_duplicate_cities(): void
    {
        $response = $this->get('/sitemap.xml');

        $content = $response->getContent();
        $baseUrl = config('app.url');

        // Helsinki has 2 postcodes but should only appear once in sitemap
        $helsinkiCount = substr_count($content, "<loc>{$baseUrl}/sahkosopimus/helsinki</loc>");
        $this->assertEquals(1, $helsinkiCount);
    }

    /** @test */
    public function sitemap_has_priority_values(): void
    {
        $response = $this->get('/sitemap.xml');

        $content = $response->getContent();

        // Check that priority elements exist
        $this->assertStringContainsString('<priority>', $content);

        // Homepage should have highest priority
        $this->assertMatchesRegularExpression('/<loc>[^<]+<\/loc>\s*<lastmod>[^<]+<\/lastmod>\s*<changefreq>[^<]+<\/changefreq>\s*<priority>1\.0<\/priority>/s', $content);
    }

    /** @test */
    public function sitemap_has_changefreq_values(): void
    {
        $response = $this->get('/sitemap.xml');

        $content = $response->getContent();

        // Check that changefreq elements exist
        $this->assertStringContainsString('<changefreq>', $content);

        // Main pages should update weekly
        $this->assertStringContainsString('<changefreq>weekly</changefreq>', $content);
    }

    /** @test */
    public function sitemap_has_lastmod_values(): void
    {
        $response = $this->get('/sitemap.xml');

        $content = $response->getContent();

        // Check that lastmod elements exist with proper date format
        $this->assertMatchesRegularExpression('/<lastmod>\d{4}-\d{2}-\d{2}<\/lastmod>/', $content);
    }

    /** @test */
    public function sitemap_url_elements_have_required_structure(): void
    {
        $response = $this->get('/sitemap.xml');

        $content = $response->getContent();

        // Each url element should have loc, changefreq, and priority
        $this->assertMatchesRegularExpression(
            '/<url>\s*<loc>[^<]+<\/loc>\s*<lastmod>[^<]+<\/lastmod>\s*<changefreq>[^<]+<\/changefreq>\s*<priority>[^<]+<\/priority>\s*<\/url>/s',
            $content
        );
    }

    /** @test */
    public function sitemap_priorities_are_valid(): void
    {
        $response = $this->get('/sitemap.xml');

        $content = $response->getContent();

        // Extract all priority values
        preg_match_all('/<priority>([0-9.]+)<\/priority>/', $content, $matches);

        foreach ($matches[1] as $priority) {
            $priorityFloat = (float) $priority;
            $this->assertGreaterThanOrEqual(0.0, $priorityFloat);
            $this->assertLessThanOrEqual(1.0, $priorityFloat);
        }
    }

    /** @test */
    public function sitemap_changefreqs_are_valid(): void
    {
        $response = $this->get('/sitemap.xml');

        $content = $response->getContent();

        // Extract all changefreq values
        preg_match_all('/<changefreq>([^<]+)<\/changefreq>/', $content, $matches);

        $validFreqs = ['always', 'hourly', 'daily', 'weekly', 'monthly', 'yearly', 'never'];

        foreach ($matches[1] as $freq) {
            $this->assertContains($freq, $validFreqs);
        }
    }

    /** @test */
    public function sitemap_xml_is_well_formed(): void
    {
        $response = $this->get('/sitemap.xml');

        $content = $response->getContent();

        // Try to parse as XML - should not throw exception
        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($content);
        $errors = libxml_get_errors();
        libxml_clear_errors();

        $this->assertNotFalse($xml, 'Sitemap XML should be well-formed');
        $this->assertEmpty($errors, 'Sitemap XML should have no errors');
    }

    /** @test */
    public function sitemap_artisan_command_exists(): void
    {
        $this->artisan('sitemap:generate')
            ->assertSuccessful();
    }

    /** @test */
    public function sitemap_artisan_command_creates_file(): void
    {
        // Clean up any existing file
        $path = public_path('sitemap.xml');
        if (file_exists($path)) {
            unlink($path);
        }

        $this->artisan('sitemap:generate')
            ->assertSuccessful();

        $this->assertFileExists($path);

        // Clean up
        if (file_exists($path)) {
            unlink($path);
        }
    }

    /** @test */
    public function sitemap_artisan_command_outputs_url_count(): void
    {
        $this->artisan('sitemap:generate')
            ->expectsOutputToContain('URLs')
            ->assertSuccessful();
    }

    /** @test */
    public function sitemap_service_can_get_all_urls(): void
    {
        $service = app(\App\Services\SitemapService::class);

        $urls = $service->getAllUrls();

        $this->assertIsArray($urls);
        $this->assertNotEmpty($urls);

        // Each URL entry should have loc, priority, changefreq
        foreach ($urls as $url) {
            $this->assertArrayHasKey('loc', $url);
            $this->assertArrayHasKey('priority', $url);
            $this->assertArrayHasKey('changefreq', $url);
        }
    }

    /** @test */
    public function sitemap_service_returns_main_page_urls(): void
    {
        $service = app(\App\Services\SitemapService::class);

        $urls = $service->getMainPageUrls();

        $locs = array_column($urls, 'loc');

        $this->assertContains(config('app.url'), $locs);
        $this->assertContains(config('app.url') . '/spot-price', $locs);
        $this->assertContains(config('app.url') . '/sahkosopimus/paikkakunnat', $locs);
    }

    /** @test */
    public function sitemap_service_returns_housing_type_urls(): void
    {
        $service = app(\App\Services\SitemapService::class);

        $urls = $service->getHousingTypeUrls();

        $locs = array_column($urls, 'loc');
        $baseUrl = config('app.url');

        $this->assertContains($baseUrl . '/sahkosopimus/omakotitalo', $locs);
        $this->assertContains($baseUrl . '/sahkosopimus/kerrostalo', $locs);
        $this->assertContains($baseUrl . '/sahkosopimus/rivitalo', $locs);
    }

    /** @test */
    public function sitemap_service_returns_energy_source_urls(): void
    {
        $service = app(\App\Services\SitemapService::class);

        $urls = $service->getEnergySourceUrls();

        $locs = array_column($urls, 'loc');
        $baseUrl = config('app.url');

        $this->assertContains($baseUrl . '/sahkosopimus/tuulisahko', $locs);
        $this->assertContains($baseUrl . '/sahkosopimus/aurinkosahko', $locs);
        $this->assertContains($baseUrl . '/sahkosopimus/vihrea-sahko', $locs);
    }

    /** @test */
    public function sitemap_service_returns_city_urls(): void
    {
        $service = app(\App\Services\SitemapService::class);

        $urls = $service->getCityUrls();

        $locs = array_column($urls, 'loc');
        $baseUrl = config('app.url');

        // Should include cities from database
        $this->assertContains($baseUrl . '/sahkosopimus/helsinki', $locs);
        $this->assertContains($baseUrl . '/sahkosopimus/tampere', $locs);
    }

    /** @test */
    public function sitemap_service_generates_valid_xml(): void
    {
        $service = app(\App\Services\SitemapService::class);

        $xml = $service->generateXml();

        $this->assertStringContainsString('<?xml version="1.0" encoding="UTF-8"?>', $xml);
        $this->assertStringContainsString('<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"', $xml);

        // Verify it's valid XML
        libxml_use_internal_errors(true);
        $parsedXml = simplexml_load_string($xml);
        $errors = libxml_get_errors();
        libxml_clear_errors();

        $this->assertNotFalse($parsedXml);
        $this->assertEmpty($errors);
    }

    /** @test */
    public function sitemap_homepage_has_highest_priority(): void
    {
        $service = app(\App\Services\SitemapService::class);

        $urls = $service->getAllUrls();

        $homepage = collect($urls)->firstWhere('loc', config('app.url'));

        $this->assertNotNull($homepage);
        $this->assertEquals(1.0, $homepage['priority']);
    }

    /** @test */
    public function sitemap_seo_pages_have_appropriate_priority(): void
    {
        $service = app(\App\Services\SitemapService::class);

        $housingUrls = $service->getHousingTypeUrls();
        $energyUrls = $service->getEnergySourceUrls();

        // Housing and energy pages should have high priority (0.8)
        foreach ($housingUrls as $url) {
            $this->assertEquals(0.8, $url['priority']);
        }

        foreach ($energyUrls as $url) {
            $this->assertEquals(0.8, $url['priority']);
        }
    }

    /** @test */
    public function sitemap_city_pages_have_medium_priority(): void
    {
        $service = app(\App\Services\SitemapService::class);

        $cityUrls = $service->getCityUrls();

        // City pages should have medium priority (0.6)
        foreach ($cityUrls as $url) {
            $this->assertEquals(0.6, $url['priority']);
        }
    }

    /** @test */
    public function sitemap_updates_when_new_cities_are_added(): void
    {
        // Get initial sitemap
        $response1 = $this->get('/sitemap.xml');
        $content1 = $response1->getContent();
        $baseUrl = config('app.url');

        // Jyvaskyla should not be in sitemap yet
        $this->assertStringNotContainsString("<loc>{$baseUrl}/sahkosopimus/jyvaskyla</loc>", $content1);

        // Add new city
        Postcode::create([
            'postcode' => '40100',
            'postcode_fi_name' => 'Jyvaskyla keskusta',
            'postcode_fi_name_slug' => 'jyvaskyla-keskusta',
            'municipal_name_fi' => 'Jyvaskyla',
            'municipal_name_fi_slug' => 'jyvaskyla',
        ]);

        // Get updated sitemap
        $response2 = $this->get('/sitemap.xml');
        $content2 = $response2->getContent();

        // New city should now be in sitemap
        $this->assertStringContainsString("<loc>{$baseUrl}/sahkosopimus/jyvaskyla</loc>", $content2);
    }

    /** @test */
    public function sitemap_route_has_cache_headers(): void
    {
        $response = $this->get('/sitemap.xml');

        // Sitemap should be cacheable
        $response->assertHeader('Cache-Control');
    }
}
