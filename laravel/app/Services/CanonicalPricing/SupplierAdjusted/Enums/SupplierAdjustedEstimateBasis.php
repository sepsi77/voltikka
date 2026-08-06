<?php

namespace App\Services\CanonicalPricing\SupplierAdjusted\Enums;

enum SupplierAdjustedEstimateBasis: string
{
    case ForwardCurveShift = 'forward_curve_shift';
    case SpotSeasonalIndex = 'spot_seasonal_index';
    case HoldFlat = 'hold_flat';

    public function isHigherConfidence(): bool
    {
        return $this === self::ForwardCurveShift;
    }
}
