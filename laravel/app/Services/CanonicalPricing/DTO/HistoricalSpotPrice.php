<?php

namespace App\Services\CanonicalPricing\DTO;

use Carbon\CarbonImmutable;

/** One realized Finnish Spot price, VAT included, for an hourly UTC delivery slot. */
readonly class HistoricalSpotPrice
{
    public function __construct(
        public CarbonImmutable $startsAtUtc,
        public float $centsPerKwhWithTax,
    ) {}
}
