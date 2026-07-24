<?php

namespace App\Services\CanonicalPricing\Enums;

/**
 * The comparison-listing verdict for a contract, derived deterministically from its
 * canonical calculation status and phase coverage. Decides list inclusion, sort key,
 * and which non-integrity label the card shows.
 *
 * See app/Services/CanonicalPricing/AGENTS.md for the full policy table.
 */
enum ContractComparability: string
{
    /** Full 12-month window covered with no estimated segments. */
    case ComparableExact = 'comparable_exact';

    /** 12-month window completed via recurring-hold or rolling spot averages; total is an estimate. */
    case ComparableEstimate = 'comparable_estimate';

    /** Fixed-term whose only gap is post-term pricing; ranked by the term price annualized. */
    case TermPriceOnly = 'term_price_only';

    /** Hybrid consumption-effect contract; ranked base-only with a disclosure. */
    case BaseOnlyHybrid = 'base_only_hybrid';

    /** Open-ended promo with an undisclosed later price; hidden from listings. */
    case ExcludedUnknownFuture = 'excluded_unknown_future';

    /** Broken/ambiguous/unsupported structured pricing; hidden from listings. */
    case ExcludedIncomplete = 'excluded_incomplete';

    /**
     * Whether the contract appears in comparison listings and rankings.
     */
    public function isListed(): bool
    {
        return match ($this) {
            self::ComparableExact,
            self::ComparableEstimate,
            self::TermPriceOnly,
            self::BaseOnlyHybrid => true,
            self::ExcludedUnknownFuture,
            self::ExcludedIncomplete => false,
        };
    }

    /**
     * Whether the ranked total is a labelled estimate rather than an exact figure.
     */
    public function isEstimate(): bool
    {
        return match ($this) {
            self::ComparableEstimate,
            self::TermPriceOnly,
            self::BaseOnlyHybrid => true,
            default => false,
        };
    }

    /**
     * Whether an excluded contract still needs a warning on its detail page.
     */
    public function requiresDetailWarning(): bool
    {
        return ! $this->isListed();
    }
}
