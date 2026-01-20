<?php

namespace Tests\Unit;

use App\Models\Company;
use App\Services\CompanyLogoService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CompanyLogoServiceTest extends TestCase
{
    private CompanyLogoService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new CompanyLogoService();
        Storage::fake('public');
    }

    /**
     * Test downloads and stores logo successfully.
     */
    public function test_downloads_and_stores_logo_successfully(): void
    {
        $pngContent = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');

        Http::fake([
            'example.com/logo.png' => Http::response($pngContent, 200, [
                'Content-Type' => 'image/png',
            ]),
        ]);

        $company = new Company([
            'name' => 'Test Company Oy',
            'name_slug' => 'test-company-oy',
            'logo_url' => 'https://example.com/logo.png',
        ]);

        $path = $this->service->downloadAndStore($company);

        $this->assertEquals('logos/test-company-oy.png', $path);
        Storage::disk('public')->assertExists('logos/test-company-oy.png');
    }

    /**
     * Test returns null when company has no logo URL.
     */
    public function test_returns_null_when_no_logo_url(): void
    {
        $company = new Company([
            'name' => 'Test Company',
            'name_slug' => 'test-company',
            'logo_url' => null,
        ]);

        $path = $this->service->downloadAndStore($company);

        $this->assertNull($path);
    }

    /**
     * Test returns null on HTTP failure.
     */
    public function test_returns_null_on_http_failure(): void
    {
        Http::fake([
            'example.com/logo.png' => Http::response('Not Found', 404),
        ]);

        $company = new Company([
            'name' => 'Test Company',
            'name_slug' => 'test-company',
            'logo_url' => 'https://example.com/logo.png',
        ]);

        $path = $this->service->downloadAndStore($company);

        $this->assertNull($path);
        Storage::disk('public')->assertMissing('logos/test-company.png');
    }

    /**
     * Test handles different image content types.
     */
    public function test_handles_jpeg_content_type(): void
    {
        $jpegContent = 'fake jpeg content';

        Http::fake([
            'example.com/logo' => Http::response($jpegContent, 200, [
                'Content-Type' => 'image/jpeg',
            ]),
        ]);

        $company = new Company([
            'name' => 'Test Company',
            'name_slug' => 'test-company',
            'logo_url' => 'https://example.com/logo',
        ]);

        $path = $this->service->downloadAndStore($company);

        $this->assertEquals('logos/test-company.jpg', $path);
        Storage::disk('public')->assertExists('logos/test-company.jpg');
    }

    /**
     * Test handles SVG content type.
     */
    public function test_handles_svg_content_type(): void
    {
        $svgContent = '<svg xmlns="http://www.w3.org/2000/svg"></svg>';

        Http::fake([
            'example.com/logo.svg' => Http::response($svgContent, 200, [
                'Content-Type' => 'image/svg+xml',
            ]),
        ]);

        $company = new Company([
            'name' => 'Test Company',
            'name_slug' => 'test-company',
            'logo_url' => 'https://example.com/logo.svg',
        ]);

        $path = $this->service->downloadAndStore($company);

        $this->assertEquals('logos/test-company.svg', $path);
    }

    /**
     * Test falls back to URL extension when no Content-Type.
     */
    public function test_falls_back_to_url_extension(): void
    {
        Http::fake([
            'example.com/logo.gif' => Http::response('fake gif', 200),
        ]);

        $company = new Company([
            'name' => 'Test Company',
            'name_slug' => 'test-company',
            'logo_url' => 'https://example.com/logo.gif',
        ]);

        $path = $this->service->downloadAndStore($company);

        $this->assertEquals('logos/test-company.gif', $path);
    }

    /**
     * Test defaults to png when extension unknown.
     */
    public function test_defaults_to_png_when_extension_unknown(): void
    {
        Http::fake([
            'example.com/logo' => Http::response('fake image', 200),
        ]);

        $company = new Company([
            'name' => 'Test Company',
            'name_slug' => 'test-company',
            'logo_url' => 'https://example.com/logo',
        ]);

        $path = $this->service->downloadAndStore($company);

        $this->assertEquals('logos/test-company.png', $path);
    }

    /**
     * Test hasLocalLogo returns true when logo exists.
     */
    public function test_has_local_logo_returns_true_when_exists(): void
    {
        Storage::disk('public')->put('logos/test-company.png', 'fake content');

        $company = new Company([
            'name' => 'Test Company',
            'name_slug' => 'test-company',
        ]);

        $this->assertTrue($this->service->hasLocalLogo($company));
    }

    /**
     * Test hasLocalLogo returns false when logo does not exist.
     */
    public function test_has_local_logo_returns_false_when_not_exists(): void
    {
        $company = new Company([
            'name' => 'Test Company',
            'name_slug' => 'test-company',
        ]);

        $this->assertFalse($this->service->hasLocalLogo($company));
    }

    /**
     * Test getLocalPath returns path when logo exists.
     */
    public function test_get_local_path_returns_path_when_exists(): void
    {
        Storage::disk('public')->put('logos/test-company.jpg', 'fake content');

        $company = new Company([
            'name' => 'Test Company',
            'name_slug' => 'test-company',
        ]);

        $path = $this->service->getLocalPath($company);

        $this->assertEquals('logos/test-company.jpg', $path);
    }

    /**
     * Test getLocalPath checks multiple extensions.
     */
    public function test_get_local_path_checks_multiple_extensions(): void
    {
        Storage::disk('public')->put('logos/test-company.webp', 'fake content');

        $company = new Company([
            'name' => 'Test Company',
            'name_slug' => 'test-company',
        ]);

        $path = $this->service->getLocalPath($company);

        $this->assertEquals('logos/test-company.webp', $path);
    }

    /**
     * Test getLocalPath returns null when no logo exists.
     */
    public function test_get_local_path_returns_null_when_not_exists(): void
    {
        $company = new Company([
            'name' => 'Test Company',
            'name_slug' => 'test-company',
        ]);

        $path = $this->service->getLocalPath($company);

        $this->assertNull($path);
    }

    /**
     * Test getPublicUrl returns URL when local logo exists.
     */
    public function test_get_public_url_returns_url_when_exists(): void
    {
        Storage::disk('public')->put('logos/test-company.png', 'fake content');

        $company = new Company([
            'name' => 'Test Company',
            'name_slug' => 'test-company',
        ]);

        $url = $this->service->getPublicUrl($company);

        $this->assertStringContainsString('logos/test-company.png', $url);
    }

    /**
     * Test getPublicUrl returns null when no local logo.
     */
    public function test_get_public_url_returns_null_when_not_exists(): void
    {
        $company = new Company([
            'name' => 'Test Company',
            'name_slug' => 'test-company',
        ]);

        $url = $this->service->getPublicUrl($company);

        $this->assertNull($url);
    }

    /**
     * Test handles connection timeout gracefully.
     */
    public function test_handles_connection_error_gracefully(): void
    {
        Http::fake(function () {
            throw new \Exception('Connection timeout');
        });

        $company = new Company([
            'name' => 'Test Company',
            'name_slug' => 'test-company',
            'logo_url' => 'https://example.com/logo.png',
        ]);

        $path = $this->service->downloadAndStore($company);

        $this->assertNull($path);
    }

    /**
     * Test Finnish characters in slug are handled correctly.
     */
    public function test_handles_finnish_company_name_slug(): void
    {
        Http::fake([
            'example.com/logo.png' => Http::response('fake png', 200, [
                'Content-Type' => 'image/png',
            ]),
        ]);

        $company = new Company([
            'name' => 'Sähköyhtiö Oy',
            'name_slug' => 'sahkoyhti-oy',
            'logo_url' => 'https://example.com/logo.png',
        ]);

        $path = $this->service->downloadAndStore($company);

        $this->assertEquals('logos/sahkoyhti-oy.png', $path);
        Storage::disk('public')->assertExists('logos/sahkoyhti-oy.png');
    }
}
