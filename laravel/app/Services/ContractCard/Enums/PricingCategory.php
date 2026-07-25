<?php

namespace App\Services\ContractCard\Enums;

/**
 * The three pricing categories a consumer actually decides between.
 *
 * These are NOT `pricing_model` (Spot / FixedPrice / Hybrid) and NOT `metering`
 * (General / Time / Season). Metering says when consumption is measured; it never
 * says whether the price moves. Before this enum the card showed one mixed label
 * ("Pörssisähkö" / "Hybridisähkö" / "Aikasähkö" / "Kausisähkö" / "Kiinteähintainen
 * sähkö"), which hid the category the user is choosing on.
 *
 * See app/Services/ContractCard/AGENTS.md for the derivation rules and their reasons.
 */
enum PricingCategory: string
{
    /** The energy price does not change during the contract. */
    case Fixed = 'fixed';

    /**
     * The price follows the market: Spot, kvartaalisähkö, markkinahintasähkö, and any
     * other product where the seller resets the price each period.
     */
    case Market = 'market';

    /** Otherwise fixed, plus an adjustment that depends on the consumption profile. */
    case ConsumptionEffect = 'consumption_effect';

    /**
     * Short Finnish label. Used for filters, aria labels and analytics, not for the card
     * band, which states the mechanism as a full sentence instead.
     */
    public function label(): string
    {
        return match ($this) {
            self::Fixed => 'Kiinteä hinta',
            self::Market => 'Markkinahinta',
            self::ConsumptionEffect => 'Kulutusvaikutus',
        };
    }

    /**
     * The card type-band tint key. Fixed is deliberately grey: certainty is the default
     * state and colour marks deviation from it.
     */
    public function tint(): string
    {
        return match ($this) {
            self::Fixed => 'fixed',
            self::Market => 'market',
            self::ConsumptionEffect => 'usage',
        };
    }
}
