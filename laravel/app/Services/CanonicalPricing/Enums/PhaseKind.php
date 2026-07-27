<?php

namespace App\Services\CanonicalPricing\Enums;

/**
 * Canonical pricing phase kinds (schema-v4 `pricing.phases[].phase_kind`).
 */
enum PhaseKind: string
{
    case CurrentStructured = 'current_structured';
    case Introductory = 'introductory';
    case Normal = 'normal';
    case Future = 'future';
    case RecurringPeriod = 'recurring_period';
    case Continuation = 'continuation';
    case Other = 'other';
}
