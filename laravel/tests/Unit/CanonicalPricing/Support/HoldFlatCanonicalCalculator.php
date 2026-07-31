<?php

namespace Tests\Unit\CanonicalPricing\Support;

use App\Services\CanonicalPricing\CanonicalContractPriceCalculator;
use App\Services\CanonicalPricing\MarketReset\DTO\ResetEstimatorSettings;
use App\Services\CanonicalPricing\MarketReset\MarketReferenceCurveProvider;
use App\Services\CanonicalPricing\MarketReset\MarketResetPriceEstimator;
use Carbon\CarbonImmutable;

final class HoldFlatCanonicalCalculator
{
    public static function make(): CanonicalContractPriceCalculator
    {
        return new CanonicalContractPriceCalculator(
            new MarketResetPriceEstimator(
                new DisabledMarketReferenceCurveProvider,
                new ResetEstimatorSettings(enabled: false),
            ),
        );
    }
}

final class DisabledMarketReferenceCurveProvider implements MarketReferenceCurveProvider
{
    public function tradeDate(CarbonImmutable $asOfDate): ?CarbonImmutable
    {
        return null;
    }

    public function referencePrice(CarbonImmutable $asOfDate, CarbonImmutable $anchorMonth, array $kindPreference): ?array
    {
        return null;
    }

    public function forwardPriceForMonth(CarbonImmutable $asOfDate, CarbonImmutable $deliveryMonth): ?array
    {
        return null;
    }

    public function spotSeasonalIndex(): ?array
    {
        return null;
    }

    public function fixedTermMedianEnergyPrice(): ?float
    {
        return null;
    }
}
