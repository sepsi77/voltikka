<?php

namespace App\Services;

use App\Models\Municipality;
use App\Services\DTO\SolarEstimateRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class CitySolarService
{
    private const DEFAULT_SYSTEM_KWP = 5.0;
    private const CACHE_TTL_SECONDS = 365 * 24 * 60 * 60; // 1 year (solar potential doesn't change)

    public function __construct(
        private PvgisService $pvgisService,
    ) {}

    /**
     * Get solar potential estimate for a municipality.
     *
     * @param Municipality $municipality
     * @param float $systemKwp System size in kWp (default 5kWp)
     * @return array{annual_kwh: int, system_kwp: float}|null
     */
    public function getSolarEstimate(Municipality $municipality, float $systemKwp = self::DEFAULT_SYSTEM_KWP): ?array
    {
        if (!$municipality->hasCoordinates()) {
            return null;
        }

        $cacheKey = $this->getCacheKey($municipality, $systemKwp);

        return Cache::remember($cacheKey, self::CACHE_TTL_SECONDS, function () use ($municipality, $systemKwp) {
            return $this->fetchEstimate($municipality, $systemKwp);
        });
    }

    /**
     * Fetch solar estimate from PVGIS API.
     */
    private function fetchEstimate(Municipality $municipality, float $systemKwp): ?array
    {
        try {
            $request = new SolarEstimateRequest(
                lat: $municipality->center_latitude,
                lon: $municipality->center_longitude,
                system_kwp: $systemKwp,
                shading_level: 'none',
            );

            $result = $this->pvgisService->calculate($request);

            return [
                'annual_kwh' => (int) round($result->annual_kwh),
                'system_kwp' => $systemKwp,
            ];
        } catch (\Exception $e) {
            Log::warning("Failed to fetch solar estimate for municipality {$municipality->name}", [
                'municipality_id' => $municipality->id,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Get cache key for municipality solar estimate.
     */
    private function getCacheKey(Municipality $municipality, float $systemKwp): string
    {
        return "city_solar:{$municipality->id}:{$systemKwp}";
    }
}
