<?php

namespace App\Services\CanonicalPricing\Enums;

/**
 * Stable reasons why a canonical contract cannot be costed for a bill period.
 */
enum PeriodPricingUnavailableReason: string
{
    case NotComparable = 'not_comparable';
    case NoSpotHistory = 'no_spot_history';
    case NoPricing = 'no_pricing';
}
