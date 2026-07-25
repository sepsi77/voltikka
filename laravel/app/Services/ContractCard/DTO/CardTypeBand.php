<?php

namespace App\Services\ContractCard\DTO;

use App\Services\ContractCard\Enums\PricingCategory;

/**
 * The card's top strip. Single purpose: it states the pricing category and nothing else.
 *
 * Warnings never appear here, no matter how important. A contract whose price rises keeps
 * a truthful fixed-category band ("Kiinteät hinnat · Julkaistu etukäteen, ei sidottu
 * markkinaan") because both prices are pre-published; the increase is a footer warning.
 */
readonly class CardTypeBand
{
    /**
     * @param string $headline Bold plain-Finnish sentence, e.g. "Hinta tarkistetaan neljännesvuosittain".
     * @param string|null $detail Supporting sentence after the `·` separator. Null when the
     *                            supporting fact is not disclosed (an unknown next reset date).
     * @param string $icon Icon key: lock | wave | pulse.
     */
    public function __construct(
        public PricingCategory $category,
        public string $headline,
        public ?string $detail,
        public string $icon,
    ) {
    }

    public function tint(): string
    {
        return $this->category->tint();
    }
}
