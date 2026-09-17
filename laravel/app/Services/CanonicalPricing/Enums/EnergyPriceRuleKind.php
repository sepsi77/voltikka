<?php

namespace App\Services\CanonicalPricing\Enums;

enum EnergyPriceRuleKind: string
{
    case Unknown = 'unknown';
    case FixedPrice = 'fixed_price';
    case AdjustableTariff = 'adjustable_tariff';
    case AbsoluteDiscount = 'absolute_discount';
    case PercentageDiscount = 'percentage_discount';

    public function isDiscount(): bool
    {
        return $this === self::AbsoluteDiscount || $this === self::PercentageDiscount;
    }
}
