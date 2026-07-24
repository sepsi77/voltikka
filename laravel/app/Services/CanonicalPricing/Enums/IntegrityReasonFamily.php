<?php

namespace App\Services\CanonicalPricing\Enums;

/**
 * The family of the pricing-integrity finding, used to pick UI copy and severity.
 * Derived from canonical `source_consistency.issue_codes` only when
 * `misleading_first_12_months === 'detected'`.
 */
enum IntegrityReasonFamily: string
{
    /** No public integrity label. */
    case None = 'none';

    /**
     * Structured price understates the first 12 months because a promotion ends and a
     * higher (or missing) later price is disclosed only in the description.
     */
    case Promo = 'promo';

    /**
     * Structured pricing data is internally contradictory or unverifiable
     * (component/model/metering mismatch, insufficient evidence).
     */
    case DataConflict = 'data_conflict';
}
