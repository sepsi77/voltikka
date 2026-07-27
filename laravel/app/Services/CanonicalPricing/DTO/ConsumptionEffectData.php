<?php

namespace App\Services\CanonicalPricing\DTO;

/**
 * Canonical Hybrid consumption-effect disclosure (schema-v4 `pricing.consumption_effect`).
 * Used for base-only Hybrid disclosure copy; not costed into the base total.
 */
readonly class ConsumptionEffectData
{
    public function __construct(
        public bool $present,
        public string $appliesTo,
        public string $cadence,
        public ?float $expectedCentsPerKwh,
        public ?float $typicalMinCentsPerKwh,
        public ?float $typicalMaxCentsPerKwh,
        public ?float $hardMinCentsPerKwh,
        public ?float $hardMaxCentsPerKwh,
        public ?bool $uncapped,
    ) {}

    public function hasDisclosedBounds(): bool
    {
        return $this->expectedCentsPerKwh !== null
            || $this->typicalMinCentsPerKwh !== null
            || $this->typicalMaxCentsPerKwh !== null
            || $this->hardMinCentsPerKwh !== null
            || $this->hardMaxCentsPerKwh !== null;
    }

    /**
     * @return array<string, float|bool|null>
     */
    public function toArray(): array
    {
        return [
            'present' => $this->present,
            'applies_to' => $this->appliesTo,
            'expected_cents_per_kwh' => $this->expectedCentsPerKwh,
            'typical_min_cents_per_kwh' => $this->typicalMinCentsPerKwh,
            'typical_max_cents_per_kwh' => $this->typicalMaxCentsPerKwh,
            'hard_min_cents_per_kwh' => $this->hardMinCentsPerKwh,
            'hard_max_cents_per_kwh' => $this->hardMaxCentsPerKwh,
            'uncapped' => $this->uncapped,
        ];
    }
}
