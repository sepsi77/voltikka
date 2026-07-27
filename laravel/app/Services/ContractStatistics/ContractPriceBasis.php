<?php

namespace App\Services\ContractStatistics;

enum ContractPriceBasis: string
{
    case CanonicalCalculation = 'canonical_calculation';
    case ObservedSellerData = 'observed_seller_data';

    public static function expectedCurrent(): self
    {
        return self::forCanonical((bool) config('canonical_pricing.enabled', false));
    }

    public static function forCanonical(bool $useCanonical): self
    {
        return $useCanonical ? self::CanonicalCalculation : self::ObservedSellerData;
    }
}
