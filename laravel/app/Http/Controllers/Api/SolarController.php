<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\GeocodeRequest;
use App\Services\DigitransitGeocodingService;
use Illuminate\Http\JsonResponse;

class SolarController extends Controller
{
    public function __construct(
        private readonly DigitransitGeocodingService $geocodingService,
    ) {
    }

    /**
     * Search for addresses using Digitransit geocoding.
     */
    public function geocode(GeocodeRequest $request): JsonResponse
    {
        $results = $this->geocodingService->search($request->validated('q'));

        return response()->json([
            'data' => array_map(fn($result) => [
                'label' => $result->label,
                'lat' => $result->lat,
                'lon' => $result->lon,
            ], $results),
        ]);
    }
}
