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
    /**
     * @param  float  $absurdityFloorCentsPerKwh  Lower bound on the resulting annual-equivalent energy
     *                                            price. Deliberately an **absolute** bound and never a
     *                                            multiple of the fixed-term retail market — see
     *                                            `MarketResetPriceEstimator::isPlausible()` for why a
     *                                            market-relative band must not come back.
     * @param  float  $absurdityCeilingCentsPerKwh  Upper bound, same reasoning.
     */
    public function __construct(
        public bool $enabled = false,
        public float $beta = 1.0,
        public int $maxCurveAgeDays = 14,
        public bool $seasonalIndexEnabled = true,
        public float $absurdityFloorCentsPerKwh = 0.0,
        public float $absurdityCeilingCentsPerKwh = 60.0,
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
            absurdityFloorCentsPerKwh: $this->absurdityFloorCentsPerKwh,
            absurdityCeilingCentsPerKwh: $this->absurdityCeilingCentsPerKwh,
        );
    }

    public static function fromConfig(): self
    {
        $config = (array) config('canonical_pricing.reset_forward_shift', []);
        $seasonal = (array) ($config['seasonal_index'] ?? []);
        $band = (array) ($config['absurdity_band'] ?? []);

        return new self(
            enabled: (bool) ($config['enabled'] ?? false),
            beta: (float) ($config['beta'] ?? 1.0),
            maxCurveAgeDays: (int) ($config['max_curve_age_days'] ?? 14),
            seasonalIndexEnabled: (bool) ($seasonal['enabled'] ?? true),
            absurdityFloorCentsPerKwh: (float) ($band['floor_cents_per_kwh'] ?? 0.0),
            absurdityCeilingCentsPerKwh: (float) ($band['ceiling_cents_per_kwh'] ?? 60.0),
        );
    }
}
