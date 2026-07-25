<?php

namespace App\Services\CanonicalPricing\MarketReset\DTO;

/**
 * Tunables for the market-reset tail estimate, read from
 * `config('canonical_pricing.reset_forward_shift')`.
 *
 * Passed in as a value object rather than read through `config()` inside the estimator so
 * the estimator stays a pure, container-free unit under test.
 */
readonly class ResetEstimatorSettings
{
    public function __construct(
        public bool $enabled = false,
        public float $beta = 1.0,
        public int $maxCurveAgeDays = 14,
        public bool $seasonalIndexEnabled = true,
        public float $plausibilityMinMultiple = 0.25,
        public float $plausibilityMaxMultiple = 2.5,
        public float $plausibilityAbsoluteMinCentsPerKwh = 0.0,
        public float $plausibilityAbsoluteMaxCentsPerKwh = 45.0,
    ) {
    }

    /**
     * Force the flag on or off without touching global config. Used by the read-only
     * comparison command so it can cost hold-flat and shifted side by side in one process.
     */
    public function withEnabled(bool $enabled): self
    {
        return new self(
            enabled: $enabled,
            beta: $this->beta,
            maxCurveAgeDays: $this->maxCurveAgeDays,
            seasonalIndexEnabled: $this->seasonalIndexEnabled,
            plausibilityMinMultiple: $this->plausibilityMinMultiple,
            plausibilityMaxMultiple: $this->plausibilityMaxMultiple,
            plausibilityAbsoluteMinCentsPerKwh: $this->plausibilityAbsoluteMinCentsPerKwh,
            plausibilityAbsoluteMaxCentsPerKwh: $this->plausibilityAbsoluteMaxCentsPerKwh,
        );
    }

    public static function fromConfig(): self
    {
        $config = (array) config('canonical_pricing.reset_forward_shift', []);
        $seasonal = (array) ($config['seasonal_index'] ?? []);
        $band = (array) ($config['plausibility'] ?? []);

        return new self(
            enabled: (bool) ($config['enabled'] ?? false),
            beta: (float) ($config['beta'] ?? 1.0),
            maxCurveAgeDays: (int) ($config['max_curve_age_days'] ?? 14),
            seasonalIndexEnabled: (bool) ($seasonal['enabled'] ?? true),
            plausibilityMinMultiple: (float) ($band['min_multiple'] ?? 0.25),
            plausibilityMaxMultiple: (float) ($band['max_multiple'] ?? 2.5),
            plausibilityAbsoluteMinCentsPerKwh: (float) ($band['absolute_min_cents_per_kwh'] ?? 0.0),
            plausibilityAbsoluteMaxCentsPerKwh: (float) ($band['absolute_max_cents_per_kwh'] ?? 45.0),
        );
    }
}
