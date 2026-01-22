<?php

namespace App\Services;

use App\Services\DTO\SolarEstimateRequest;
use App\Services\DTO\SolarEstimateResult;

class SolarCalculatorService
{
    private const KWP_PER_SQM = 0.20;

    public function __construct(
        private PvgisService $pvgisService,
    ) {
    }

    public function calculate(SolarEstimateRequest $request): SolarEstimateResult
    {
        return $this->pvgisService->calculate($request);
    }

    /**
     * Convert roof area in square meters to system capacity in kWp.
     *
     * Uses 0.20 kWp/m² as a typical conversion factor for residential solar panels.
     */
    public function roofAreaToSystemKwp(float $roofAreaSqm): float
    {
        return $roofAreaSqm * self::KWP_PER_SQM;
    }
}
