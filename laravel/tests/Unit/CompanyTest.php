<?php

namespace Tests\Unit;

use App\Models\Company;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CompanyTest extends TestCase
{
    /**
     * Test that Finnish characters are properly converted in slugs.
     */
    public function test_slug_generation_handles_finnish_characters(): void
    {
        // Test basic Finnish characters: ä, ö, å
        $this->assertEquals('helen-oy', Company::generateSlug('Helen Oy'));
        $this->assertEquals('tampereen-sahkolaitos', Company::generateSlug('Tampereen Sähkölaitos'));
        $this->assertEquals('oulun-energia', Company::generateSlug('Oulun Energia'));
        $this->assertEquals('vaasan-sahko', Company::generateSlug('Vaasan Sähkö'));

        // Test with ä, ö, å
        $this->assertEquals('jarvi-suomen-energia', Company::generateSlug('Järvi-Suomen Energia'));
        $this->assertEquals('porvoon-energia', Company::generateSlug('Porvoon Energia'));

        // Test with special characters that should be removed
        $this->assertEquals('example-company-ab', Company::generateSlug('Example Company (AB)'));
    }

    /**
     * Test that the slug generation matches the Python implementation.
     */
    public function test_slug_matches_python_implementation(): void
    {
        // These test cases match the Python name_to_slug function behavior
        $this->assertEquals('helen', Company::generateSlug('Helen'));
        $this->assertEquals('fortum', Company::generateSlug('Fortum'));
        $this->assertEquals('caruna', Company::generateSlug('Caruna'));
    }

    /**
     * Test that underscores are handled correctly.
     */
    public function test_slug_replaces_underscores(): void
    {
        $this->assertEquals('test-company', Company::generateSlug('test_company'));
        $this->assertEquals('my-test-company', Company::generateSlug('my_test_company'));
    }

    /**
     * Test that consecutive spaces/hyphens are collapsed.
     */
    public function test_slug_collapses_consecutive_separators(): void
    {
        $this->assertEquals('test-company', Company::generateSlug('test  company'));
        $this->assertEquals('test-company', Company::generateSlug('test - company'));
    }

    /**
     * Test model configuration.
     */
    public function test_model_has_correct_table_name(): void
    {
        $company = new Company();
        $this->assertEquals('companies', $company->getTable());
    }

    /**
     * Test model has correct primary key settings.
     */
    public function test_model_has_string_primary_key(): void
    {
        $company = new Company();
        $this->assertEquals('name', $company->getKeyName());
        $this->assertEquals('string', $company->getKeyType());
        $this->assertFalse($company->getIncrementing());
    }

    /**
     * Test model does not use timestamps.
     */
    public function test_model_has_no_timestamps(): void
    {
        $company = new Company();
        $this->assertFalse($company->usesTimestamps());
    }

    /**
     * Test getLogoUrl returns local logo path when available.
     */
    public function test_get_logo_url_returns_local_path_when_available(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('logos/test-company.png', 'fake content');

        $company = new Company([
            'name' => 'Test Company',
            'name_slug' => 'test-company',
            'logo_url' => 'https://example.com/logo.png',
            'local_logo_path' => 'logos/test-company.png',
        ]);

        $url = $company->getLogoUrl();

        $this->assertStringContainsString('logos/test-company.png', $url);
        $this->assertStringNotContainsString('example.com', $url);
    }

    /**
     * Test getLogoUrl falls back to external URL when no local logo.
     */
    public function test_get_logo_url_falls_back_to_external_url(): void
    {
        $company = new Company([
            'name' => 'Test Company',
            'name_slug' => 'test-company',
            'logo_url' => 'https://example.com/logo.png',
            'local_logo_path' => null,
        ]);

        $url = $company->getLogoUrl();

        $this->assertEquals('https://example.com/logo.png', $url);
    }

    /**
     * Test getLogoUrl returns null when no logos available.
     */
    public function test_get_logo_url_returns_null_when_no_logos(): void
    {
        $company = new Company([
            'name' => 'Test Company',
            'name_slug' => 'test-company',
            'logo_url' => null,
            'local_logo_path' => null,
        ]);

        $url = $company->getLogoUrl();

        $this->assertNull($url);
    }

    /**
     * Test local_logo_path is in fillable array.
     */
    public function test_local_logo_path_is_fillable(): void
    {
        $company = new Company([
            'name' => 'Test Company',
            'local_logo_path' => 'logos/test.png',
        ]);

        $this->assertEquals('logos/test.png', $company->local_logo_path);
    }
}
