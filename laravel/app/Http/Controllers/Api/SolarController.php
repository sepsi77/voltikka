<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\GeocodeRequest;
use App\Http\Requests\SolarEstimateFormRequest;
use App\Services\DigitransitGeocodingService;
use App\Services\DTO\SolarEstimateRequest;
use App\Services\SolarCalculatorService;
use Illuminate\Http\JsonResponse;

class SolarController extends Controller
{
    public function __construct(
        private readonly DigitransitGeocodingService $geocodingService,
        private readonly SolarCalculatorService $calculatorService,
    ) {
    }

    /**
     * Search for addresses using Digitransit geocoding.
     */
    public function geocode(GeocodeRequest $request): JsonResponse
    {
        $results = $this->geocodingService->search($request->validated('q'));

        return response()->json([
            'data' => array_map(fn ($result) => [
                'label' => $result->label,
                'lat' => $result->lat,
                'lon' => $result->lon,
            ], $results),
        ]);
    }

    /**
     * Estimate solar panel production for a given location.
     */
    public function estimate(SolarEstimateFormRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $estimateRequest = new SolarEstimateRequest(
            lat: (float) $validated['lat'],
            lon: (float) $validated['lon'],
            system_kwp: (float) ($validated['system_kwp'] ?? 5.0),
            roof_tilt_deg: isset($validated['roof_tilt_deg']) ? (int) $validated['roof_tilt_deg'] : null,
            roof_aspect_deg: isset($validated['roof_aspect_deg']) ? (int) $validated['roof_aspect_deg'] : null,
            shading_level: $validated['shading_level'] ?? 'none',
        );

        $result = $this->calculatorService->calculate($estimateRequest);

        return response()->json([
            'data' => $result->toArray(),
        ]);
    }
}
