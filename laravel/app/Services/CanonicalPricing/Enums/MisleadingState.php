<?php

namespace App\Services\CanonicalPricing\Enums;

/**
 * Canonical deceptive-pricing signal (schema-v3 `source_consistency.misleading_first_12_months`).
 * Only `Detected` may drive a public integrity label.
 */
enum MisleadingState: string
{
    case Detected = 'detected';
    case NotDetected = 'not_detected';
    case NotAssessable = 'not_assessable';
    case Uncertain = 'uncertain';
}
