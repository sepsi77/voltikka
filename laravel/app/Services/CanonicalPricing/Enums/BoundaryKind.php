<?php

namespace App\Services\CanonicalPricing\Enums;

/**
 * Canonical pricing phase boundary kinds (schema-v3 `$defs.boundary.kind`).
 */
enum BoundaryKind: string
{
    case ContractStart = 'contract_start';
    case Date = 'date';
    case AfterMonths = 'after_months';
    case PeriodBoundary = 'period_boundary';
    case None = 'none';
    case Unknown = 'unknown';
}
