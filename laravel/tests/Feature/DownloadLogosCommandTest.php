<?php

namespace Tests\Feature;

use App\Models\Company;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DownloadLogosCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    /**
     * Test command downloads logos for companies without local logos.
     */
    public function test_command_downloads_logos_for_companies(): void
    {
        $pngContent = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');

        Company::create([
            'name' => 'Test Company',
            'name_slug' => 'test-company',
            'logo_url' => 'https://example.com/logo.png',
        ]);

        Http::fake([
            'example.com/logo.png' => Http::response($pngContent, 200, [
                'Content-Type' => 'image/png',
            ]),
        ]);

        $this->artisan('logos:download')
            ->expectsOutput('Downloading logos for 1 companies...')
            ->expectsOutput('Download complete!')
            ->assertExitCode(0);

        $company = Company::where('name', 'Test Company')->first();
        $this->assertEquals('logos/test-company.png', $company->local_logo_path);
        Storage::disk('public')->assertExists('logos/test-company.png');
    }

    /**
     * Test command skips companies without logo_url.
     */
    public function test_command_skips_companies_without_logo_url(): void
    {
        Company::create([
            'name' => 'No Logo Company',
            'name_slug' => 'no-logo-company',
            'logo_url' => null,
        ]);

        $this->artisan('logos:download')
            ->expectsOutput('No company logos to download.')
            ->assertExitCode(0);
    }

    /**
     * Test command skips companies with existing local logos by default.
     */
    public function test_command_skips_companies_with_local_logos(): void
    {
        Storage::disk('public')->put('logos/existing-company.png', 'existing content');

        Company::create([
            'name' => 'Existing Company',
            'name_slug' => 'existing-company',
            'logo_url' => 'https://example.com/logo.png',
            'local_logo_path' => 'logos/existing-company.png',
        ]);

        $this->artisan('logos:download')
            ->expectsOutput('No company logos to download.')
            ->assertExitCode(0);

        // HTTP should not be called
        Http::assertNothingSent();
    }

    /**
     * Test command re-downloads logos with --force flag.
     */
    public function test_command_redownloads_with_force_flag(): void
    {
        $pngContent = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');

        Storage::disk('public')->put('logos/existing-company.png', 'old content');

        Company::create([
            'name' => 'Existing Company',
            'name_slug' => 'existing-company',
            'logo_url' => 'https://example.com/logo.png',
            'local_logo_path' => 'logos/existing-company.png',
        ]);

        Http::fake([
            'example.com/logo.png' => Http::response($pngContent, 200, [
                'Content-Type' => 'image/png',
            ]),
        ]);

        $this->artisan('logos:download', ['--force' => true])
            ->expectsOutput('Downloading logos for 1 companies...')
            ->expectsOutput('Download complete!')
            ->assertExitCode(0);

        // HTTP should have been called
        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'example.com/logo.png');
        });
    }

    /**
     * Test command handles download failures gracefully.
     */
    public function test_command_handles_download_failures(): void
    {
        Company::create([
            'name' => 'Failed Company',
            'name_slug' => 'failed-company',
            'logo_url' => 'https://example.com/missing.png',
        ]);

        Http::fake([
            'example.com/missing.png' => Http::response('Not Found', 404),
        ]);

        $this->artisan('logos:download')
            ->expectsOutput('Downloading logos for 1 companies...')
            ->expectsOutput('Download complete!')
            ->assertExitCode(0);

        $company = Company::where('name', 'Failed Company')->first();
        $this->assertNull($company->local_logo_path);
    }

    /**
     * Test command downloads multiple logos.
     */
    public function test_command_downloads_multiple_logos(): void
    {
        $pngContent = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');

        Company::create([
            'name' => 'Company One',
            'name_slug' => 'company-one',
            'logo_url' => 'https://example.com/one.png',
        ]);

        Company::create([
            'name' => 'Company Two',
            'name_slug' => 'company-two',
            'logo_url' => 'https://example.com/two.png',
        ]);

        Company::create([
            'name' => 'Company Three',
            'name_slug' => 'company-three',
            'logo_url' => 'https://example.com/three.png',
        ]);

        Http::fake([
            'example.com/*' => Http::response($pngContent, 200, [
                'Content-Type' => 'image/png',
            ]),
        ]);

        $this->artisan('logos:download')
            ->expectsOutput('Downloading logos for 3 companies...')
            ->expectsOutput('Download complete!')
            ->assertExitCode(0);

        $this->assertEquals(3, Company::whereNotNull('local_logo_path')->count());
    }

    /**
     * Test command skips companies with empty logo_url.
     */
    public function test_command_skips_companies_with_empty_logo_url(): void
    {
        Company::create([
            'name' => 'Empty Logo Company',
            'name_slug' => 'empty-logo-company',
            'logo_url' => '',
        ]);

        $this->artisan('logos:download')
            ->expectsOutput('No company logos to download.')
            ->assertExitCode(0);
    }
}
