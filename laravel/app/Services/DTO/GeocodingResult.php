<?php

namespace App\Services\DTO;

class GeocodingResult
{
    public function __construct(
        public readonly string $label,
        public readonly float $lat,
        public readonly float $lon,
    ) {
    }

    /**
     * Create a GeocodingResult from a Digitransit GeoJSON feature.
     *
     * Digitransit returns coordinates in [lon, lat] order (GeoJSON standard).
     */
    public static function fromDigitransitFeature(array $feature): self
    {
        $coordinates = $feature['geometry']['coordinates'] ?? [0, 0];
        $properties = $feature['properties'] ?? [];

        return new self(
            label: $properties['label'] ?? '',
            lat: (float) ($coordinates[1] ?? 0),
            lon: (float) ($coordinates[0] ?? 0),
        );
    }
}
