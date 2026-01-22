<?php

namespace App\Services;

use App\Services\DTO\SolarEstimateRequest;
use App\Services\DTO\SolarEstimateResult;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class PvgisService
{
    private const BASE_URL = 'https://re.jrc.ec.europa.eu/api/v5_3/PVcalc';
    private const BASE_LOSS_PERCENT = 14;
    private const CACHE_TTL_SECONDS = 30 * 24 * 60 * 60; // 30 days

    private const SHADING_LOSS = [
        'none' => 0,
        'some' => 5,
        'heavy' => 12,
    ];

    public function calculate(SolarEstimateRequest $request): SolarEstimateResult
    {
        $cacheKey = $this->getCacheKey($request);

        $cached = Cache::remember($cacheKey, self::CACHE_TTL_SECONDS, function () use ($request) {
            return $this->fetchFromApi($request);
        });

        return new SolarEstimateResult(
            annual_kwh: $cached['annual_kwh'],
            monthly_kwh: $cached['monthly_kwh'],
            assumptions: $cached['assumptions'],
        );
    }

    private function fetchFromApi(SolarEstimateRequest $request): array
    {
        $losses = self::BASE_LOSS_PERCENT + (self::SHADING_LOSS[$request->shading_level] ?? 0);
        $useOptimalAngles = $request->roof_tilt_deg === null && $request->roof_aspect_deg === null;

        $params = [
            'lat' => $request->lat,
            'lon' => $request->lon,
            'peakpower' => $request->system_kwp,
            'loss' => $losses,
            'outputformat' => 'json',
        ];

        if ($useOptimalAngles) {
            $params['optimalangles'] = 1;
        } else {
            if ($request->roof_tilt_deg !== null) {
                $params['angle'] = $request->roof_tilt_deg;
            }
            if ($request->roof_aspect_deg !== null) {
                $params['aspect'] = $request->roof_aspect_deg;
            }
        }

        $response = Http::get(self::BASE_URL, $params);

        if (! $response->successful()) {
            throw new \RuntimeException('PVGIS API request failed: '.$response->body());
        }

        $data = $response->json();

        $monthlyKwh = [];
        foreach ($data['outputs']['monthly']['fixed'] as $month) {
            $monthlyKwh[] = $month['E_m'];
        }

        return [
            'annual_kwh' => $data['outputs']['totals']['fixed']['E_y'],
            'monthly_kwh' => $monthlyKwh,
            'assumptions' => [
                'system_kwp' => $request->system_kwp,
                'losses_percent' => $losses,
                'shading_level' => $request->shading_level,
                'optimal_angles' => $useOptimalAngles,
                'roof_tilt_deg' => $request->roof_tilt_deg,
                'roof_aspect_deg' => $request->roof_aspect_deg,
            ],
        ];
    }

    private function getCacheKey(SolarEstimateRequest $request): string
    {
        $key = sprintf(
            '%s:%s:%s:%s:%s:%s',
            $request->lat,
            $request->lon,
            $request->system_kwp,
            $request->roof_tilt_deg ?? 'opt',
            $request->roof_aspect_deg ?? 'opt',
            $request->shading_level,
        );

        return 'pvgis:'.md5($key);
    }
}
