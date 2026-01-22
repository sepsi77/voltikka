<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class SolarGeocodeApiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        RateLimiter::clear('solar-geocode');
    }

    public function test_geocode_returns_addresses(): void
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

        $response = $this->getJson('/api/solar/geocode?q=Mannerheimintie');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'data' => [
                '*' => ['label', 'lat', 'lon'],
            ],
        ]);
        $response->assertJsonCount(2, 'data');
        $response->assertJsonFragment(['label' => 'Mannerheimintie 1, Helsinki']);
        $response->assertJsonFragment(['lat' => 60.1695]);
        $response->assertJsonFragment(['lon' => 24.9384]);
    }

    public function test_geocode_returns_empty_array_for_no_results(): void
    {
        Http::fake([
            'api.digitransit.fi/geocoding/v1/autocomplete*' => Http::response([
                'features' => [],
            ], 200),
        ]);

        $response = $this->getJson('/api/solar/geocode?q=asdfghjkl1234');

        $response->assertStatus(200);
        $response->assertJsonCount(0, 'data');
    }

    public function test_geocode_returns_422_when_q_is_missing(): void
    {
        $response = $this->getJson('/api/solar/geocode');

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['q']);
    }

    public function test_geocode_returns_422_when_q_is_too_short(): void
    {
        $response = $this->getJson('/api/solar/geocode?q=a');

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['q']);
    }

    public function test_geocode_returns_422_when_q_is_too_long(): void
    {
        $longQuery = str_repeat('a', 201);
        $response = $this->getJson('/api/solar/geocode?q=' . $longQuery);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['q']);
    }

    public function test_geocode_accepts_valid_query_at_min_length(): void
    {
        Http::fake([
            'api.digitransit.fi/geocoding/v1/autocomplete*' => Http::response([
                'features' => [],
            ], 200),
        ]);

        $response = $this->getJson('/api/solar/geocode?q=ab');

        $response->assertStatus(200);
    }

    public function test_geocode_accepts_valid_query_at_max_length(): void
    {
        Http::fake([
            'api.digitransit.fi/geocoding/v1/autocomplete*' => Http::response([
                'features' => [],
            ], 200),
        ]);

        $query = str_repeat('a', 200);
        $response = $this->getJson('/api/solar/geocode?q=' . $query);

        $response->assertStatus(200);
    }

    public function test_geocode_rate_limiting(): void
    {
        Http::fake([
            'api.digitransit.fi/geocoding/v1/autocomplete*' => Http::response([
                'features' => [],
            ], 200),
        ]);

        // Make 60 requests (the limit)
        for ($i = 0; $i < 60; $i++) {
            $response = $this->getJson('/api/solar/geocode?q=test' . $i);
            $response->assertStatus(200);
        }

        // The 61st request should be rate limited
        $response = $this->getJson('/api/solar/geocode?q=test61');
        $response->assertStatus(429);
    }
}
