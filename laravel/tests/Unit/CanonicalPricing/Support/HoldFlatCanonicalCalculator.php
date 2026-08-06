<?php

namespace Tests\Unit\CanonicalPricing\Support;

use App\Services\CanonicalPricing\CanonicalContractPriceCalculator;
use App\Services\CanonicalPricing\DTO\CanonicalContractData;
use App\Services\CanonicalPricing\DTO\ContractContext;
use App\Services\CanonicalPricing\MarketReset\DTO\ResetEstimatorSettings;
use App\Services\CanonicalPricing\MarketReset\MarketReferenceCurveProvider;
use App\Services\CanonicalPricing\MarketReset\MarketResetPriceEstimator;
use App\Services\CanonicalPricing\SupplierAdjusted\DTO\SupplierAdjustedCandidate;
use App\Services\CanonicalPricing\SupplierAdjusted\SupplierAdjustedEligibility;
use App\Services\CanonicalPricing\SupplierAdjusted\SupplierAdjustedPriceEstimator;
use Carbon\CarbonImmutable;

final class HoldFlatCanonicalCalculator
{
    public static function make(): CanonicalContractPriceCalculator
    {
        $provider = new DisabledMarketReferenceCurveProvider;
        $settings = new ResetEstimatorSettings(enabled: false);

        return new CanonicalContractPriceCalculator(
            new MarketResetPriceEstimator($provider, $settings),
            new SupplierAdjustedPriceEstimator($provider, $settings),
            new DisabledSupplierAdjustedEligibility,
        );
    }
}

final class DisabledSupplierAdjustedEligibility extends SupplierAdjustedEligibility
{
    public function candidate(string $contractId, CanonicalContractData $data, ContractContext $context): ?SupplierAdjustedCandidate
    {
        return null;
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
