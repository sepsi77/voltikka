<?php

namespace App\Services;

use App\Services\DTO\GeocodingResult;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class DigitransitGeocodingService
{
    private const MAX_RETRIES = 3;
    private const RETRY_DELAY_MS = 500;
    private const CACHE_TTL_DAYS = 7;

    /**
     * Search for addresses using Digitransit Geocoding API.
     *
     * @param string $query Search query (address)
     * @return GeocodingResult[]
     * @throws RequestException
     */
    public function search(string $query): array
    {
        $cacheKey = 'digitransit:geocode:' . md5($query);

        return Cache::remember($cacheKey, now()->addDays(self::CACHE_TTL_DAYS), function () use ($query) {
            return $this->fetchFromApi($query);
        });
    }

    /**
     * Fetch geocoding results from Digitransit API.
     *
     * @param string $query Search query
     * @return GeocodingResult[]
     * @throws RequestException
     */
    private function fetchFromApi(string $query): array
    {
        $baseUrl = config('services.digitransit.base_url', 'https://api.digitransit.fi/geocoding/v1');
        $apiKey = config('services.digitransit.api_key');

        $url = $baseUrl . '/autocomplete';

        $response = Http::retry(self::MAX_RETRIES, self::RETRY_DELAY_MS, function ($exception, $request) {
            return $exception instanceof RequestException
                && ($exception->response?->serverError() || $exception->response === null);
        })
            ->withHeaders([
                'digitransit-subscription-key' => $apiKey,
            ])
            ->get($url, [
                'text' => $query,
                'layers' => 'address',
                'lang' => 'fi',
            ]);

        if ($response->failed()) {
            Log::error('Failed to fetch geocoding results from Digitransit', [
                'status' => $response->status(),
                'body' => $response->body(),
                'query' => $query,
            ]);
            throw new RequestException($response);
        }

        $data = $response->json();
        $features = $data['features'] ?? [];

        return array_map(
            fn(array $feature) => GeocodingResult::fromDigitransitFeature($feature),
            $features
        );
    }
}
