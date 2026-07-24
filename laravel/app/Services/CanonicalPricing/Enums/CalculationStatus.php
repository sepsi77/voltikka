<?php

namespace App\Services\CanonicalPricing\Enums;

/**
 * Canonical calculation feasibility (schema-v3 `calculation.status`).
 */
enum CalculationStatus: string
{
    case Exact = 'exact';
    case EstimateRequired = 'estimate_required';
    case Incomplete = 'incomplete';
    case Unsupported = 'unsupported';
}
