<?php

namespace Tests\Unit;

use App\Services\DigitransitGeocodingService;
use App\Services\DTO\GeocodingResult;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
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

    public function test_search_uses_interactive_request_timeouts(): void
    {
        Http::fake(function (Request $request, array $options) {
            $this->assertSame(2, $options['connect_timeout']);
            $this->assertSame(5, $options['timeout']);

            return Http::response(['features' => []], 200);
        });

        (new DigitransitGeocodingService())->search('Helsinki');

        Http::assertSentCount(1);
    }

    public function test_server_error_is_not_retried_or_cached(): void
    {
        $attempts = 0;
        Http::fake(function () use (&$attempts) {
            $attempts++;

            return Http::response(['error' => 'Temporary Error'], 503);
        });

        $service = new DigitransitGeocodingService();

        for ($search = 1; $search <= 2; $search++) {
            try {
                $service->search('Helsinki');
                $this->fail('Expected a request exception.');
            } catch (RequestException $exception) {
                $this->assertSame(503, $exception->response->status());
            }

            $this->assertSame($search, $attempts);
            $this->assertFalse(Cache::has('digitransit:geocode:' . md5('Helsinki')));
        }
    }

    public function test_connection_timeout_is_not_retried_or_cached(): void
    {
        $attempts = 0;
        Http::fake(function () use (&$attempts) {
            $attempts++;

            throw new ConnectionException('cURL error 28: Connection timed out');
        });

        $service = new DigitransitGeocodingService();

        for ($search = 1; $search <= 2; $search++) {
            try {
                $service->search('Helsinki');
                $this->fail('Expected a connection exception.');
            } catch (ConnectionException $exception) {
                $this->assertStringContainsString('cURL error 28', $exception->getMessage());
            }

            $this->assertSame($search, $attempts);
            $this->assertFalse(Cache::has('digitransit:geocode:' . md5('Helsinki')));
        }
    }
}
