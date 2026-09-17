<?php

namespace App\Services\CanonicalPricing\ForwardPremium;

enum PremiumFamily: string
{
    case SupplierAdjusted = 'supplier_adjusted';
    case MarketReset = 'market_reset';
    case SupplierAdjustedHybridBase = 'supplier_adjusted_hybrid_base';
    case MarketResetHybridBase = 'market_reset_hybrid_base';

    public function isReset(): bool
    {
        return in_array($this, [self::MarketReset, self::MarketResetHybridBase], true);
    }
}
