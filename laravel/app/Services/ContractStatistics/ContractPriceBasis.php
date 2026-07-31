<?php

namespace App\Services\ContractStatistics;

enum ContractPriceBasis: string
{
    case CanonicalCalculation = 'canonical_calculation';
    case ObservedSellerData = 'observed_seller_data';

    public static function forCanonical(bool $useCanonical): self
    {
        return $useCanonical ? self::CanonicalCalculation : self::ObservedSellerData;
    }
}
