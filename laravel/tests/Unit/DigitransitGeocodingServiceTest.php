<?php

namespace Tests\Unit;

use App\Services\DigitransitGeocodingService;
use App\Services\DTO\GeocodingResult;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DigitransitGeocodingServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_search_returns_geocoding_results(): void
    {
        Http::fake([
            'api.digitransit.fi/geocoding/v1/autocomplete*' => Http::response([
                'features' => [
                    [
                        'properties' => ['label' => 'Mannerheimintie 1, Helsinki'],
                        'geometry' => ['coordinates' => [24.9384, 60.1695]],
                    ],
                    [
                        'properties' => ['label' => 'Mannerheimintie 2, Helsinki'],
                        'geometry' => ['coordinates' => [24.9390, 60.1700]],
                    ],
                ],
            ], 200),
        ]);

        $service = new DigitransitGeocodingService();
        $results = $service->search('Mannerheimintie Helsinki');

        $this->assertCount(2, $results);
        $this->assertContainsOnlyInstancesOf(GeocodingResult::class, $results);

        $this->assertEquals('Mannerheimintie 1, Helsinki', $results[0]->label);
        $this->assertEquals(60.1695, $results[0]->lat);
        $this->assertEquals(24.9384, $results[0]->lon);
    }

    public function test_search_returns_empty_array_for_no_results(): void
    {
        Http::fake([
            'api.digitransit.fi/geocoding/v1/autocomplete*' => Http::response([
                'features' => [],
            ], 200),
        ]);

        $service = new DigitransitGeocodingService();
        $results = $service->search('asdfghjkl1234');

        $this->assertIsArray($results);
        $this->assertEmpty($results);
    }

    public function test_search_sends_correct_request_parameters(): void
    {
        config(['services.digitransit.api_key' => 'test-api-key-123']);

        Http::fake([
            'api.digitransit.fi/geocoding/v1/autocomplete*' => Http::response([
                'features' => [],
            ], 200),
        ]);

        $service = new DigitransitGeocodingService();
        $service->search('Helsinki');

        Http::assertSent(function (Request $request) {
            return str_contains($request->url(), 'text=Helsinki')
                && str_contains($request->url(), 'layers=address')
                && str_contains($request->url(), 'lang=fi')
                && $request->hasHeader('digitransit-subscription-key', 'test-api-key-123');
        });
    }

    public function test_search_throws_exception_on_api_error(): void
    {
        Http::fake([
            'api.digitransit.fi/geocoding/v1/autocomplete*' => Http::response([
                'error' => 'Internal Server Error',
            ], 500),
        ]);

        $this->expectException(\Illuminate\Http\Client\RequestException::class);

        $service = new DigitransitGeocodingService();
        $service->search('Helsinki');
    }

    public function test_search_caches_results(): void
    {
        Http::fake([
            'api.digitransit.fi/geocoding/v1/autocomplete*' => Http::response([
                'features' => [
                    [
                        'properties' => ['label' => 'Test Address'],
                        'geometry' => ['coordinates' => [25.0, 60.0]],
                    ],
                ],
            ], 200),
        ]);

        $service = new DigitransitGeocodingService();

        // First call
        $results1 = $service->search('Test');
        $this->assertCount(1, $results1);

        // Second call - should use cache
        $results2 = $service->search('Test');
        $this->assertCount(1, $results2);

        // HTTP should only have been called once
        Http::assertSentCount(1);
    }

    public function test_search_uses_different_cache_keys_for_different_queries(): void
    {
        Http::fake([
            'api.digitransit.fi/geocoding/v1/autocomplete*' => Http::response([
                'features' => [
                    [
                        'properties' => ['label' => 'Test Address'],
                        'geometry' => ['coordinates' => [25.0, 60.0]],
                    ],
                ],
            ], 200),
        ]);

        $service = new DigitransitGeocodingService();

        $service->search('Helsinki');
        $service->search('Tampere');

        // HTTP should have been called twice (different queries)
        Http::assertSentCount(2);
    }

    public function test_search_retries_on_server_error(): void
    {
        $attempts = 0;
        Http::fake(function ($request) use (&$attempts) {
            $attempts++;
            if ($attempts < 3) {
                return Http::response(['error' => 'Temporary Error'], 503);
            }
            return Http::response([
                'features' => [
                    [
                        'properties' => ['label' => 'Success after retry'],
                        'geometry' => ['coordinates' => [25.0, 60.0]],
                    ],
                ],
            ], 200);
        });

        $service = new DigitransitGeocodingService();
        $results = $service->search('Retry Test');

        $this->assertCount(1, $results);
        $this->assertEquals('Success after retry', $results[0]->label);
        $this->assertEquals(3, $attempts);
    }
}
