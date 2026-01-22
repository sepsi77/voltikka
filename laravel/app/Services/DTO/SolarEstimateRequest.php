<?php

namespace App\Services\DTO;

class SolarEstimateRequest
{
    public function __construct(
        public readonly float $lat,
        public readonly float $lon,
        public readonly float $system_kwp = 5.0,
        public readonly ?int $roof_tilt_deg = null,
        public readonly ?int $roof_aspect_deg = null,
        public readonly string $shading_level = 'none',
    ) {
    }
}
