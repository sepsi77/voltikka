<?php

namespace App\Services\ContractCard\Enums;

use App\Services\ContractCard\DTO\PricingCategoryFacts;

/**
 * The four buckets the visible pricing-type filter offers.
 *
 * These are the three `PricingCategory` cases with `Market` split into spot and non-spot
 * resets, because "the price follows the hourly exchange" and "the seller republishes a
 * price every quarter" are different amounts of risk for the customer even though the card
 * band groups them under one Markkinahinta category.
 *
 * The four buckets partition the contract set: every contract is in exactly one, and
 * `Spot` + `MarketReset` together equal `PricingCategory::Market`. That is what makes
 * multi-select include semantics well defined and the per-bucket counts add up.
 *
 * The values are user-visible: they are the `?hintatyyppi=` query values, so they are
 * Finnish, and `porssisahko`/`kulutusvaikutus` match the existing SEO slugs.
 */
enum PricingBucket: string
{
    /** Pörssisähkö: the price follows the hourly exchange price. */
    case Spot = 'porssisahko';

    /** Jaksoittain vaihtuva hinta: the seller resets the price monthly, quarterly, seasonally, or on another stated cadence. */
    case MarketReset = 'paivittyva';

    /** Otherwise fixed, plus a mandatory adjustment that depends on when the customer uses power. */
    case ConsumptionEffect = 'kulutusvaikutus';

    /** The energy price does not change during the contract. */
    case Fixed = 'kiintea';

    /**
     * The bucket a resolved contract belongs to.
     *
     * Spot wins inside the market category: a spot contract that also carries a reset
     * schedule (for example a fixing option priced quarterly) is still Pörssisähkö, because
     * the hourly exchange price is what the customer actually pays.
     */
    public static function fromFacts(PricingCategoryFacts $facts): self
    {
        return match ($facts->category) {
            PricingCategory::Market => $facts->isSpot ? self::Spot : self::MarketReset,
            PricingCategory::ConsumptionEffect => self::ConsumptionEffect,
            PricingCategory::Fixed => self::Fixed,
        };
    }

    /**
     * The card category this bucket belongs to. Use it for the band tint, so the filter pill
     * and the cards it lists carry the same colour.
     */
    public function category(): PricingCategory
    {
        return match ($this) {
            self::Spot, self::MarketReset => PricingCategory::Market,
            self::ConsumptionEffect => PricingCategory::ConsumptionEffect,
            self::Fixed => PricingCategory::Fixed,
        };
    }
}
