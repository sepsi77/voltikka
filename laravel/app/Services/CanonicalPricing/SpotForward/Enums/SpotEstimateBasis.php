<?php

namespace App\Services\CanonicalPricing\SpotForward\Enums;

enum SpotEstimateBasis: string
{
    case ForwardCurve = 'forward_curve';
    case Rolling365Fallback = 'rolling_365_fallback';

    public function isForward(): bool
    {
        return $this === self::ForwardCurve;
    }
}
