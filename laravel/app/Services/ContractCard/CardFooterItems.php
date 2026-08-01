<?php

namespace App\Services\ContractCard;

use App\Models\ElectricityContract;
use App\Services\CanonicalPricing\DTO\ContractPricingIntegrity;
use App\Services\ContractCard\DTO\CardFooterItem;
use App\Services\ContractCard\DTO\PricingCategoryFacts;
use App\Services\ContractCard\Enums\PricingCategory;
use App\Services\ContractPricing\ContractPricingViewData;

/**
 * Builds the card footer: warnings first, then quiet facts.
 *
 * The footer this replaced was a flat row with no hierarchy, where a price-increase
 * warning sat at the same weight as a marketing pill, and where the percentile callouts
 * ("Edullinen marginaali") only appeared on SEO listing pages because one caller passed
 * the thresholds. Those callouts are gone from cards; `contracts:calculate-percentiles`
 * and its other consumers are unchanged.
 */
class CardFooterItems
{
    /**
     * At most two warnings. A card that shouts four caveats teaches the reader to skip
     * the whole row, which costs more than the third caveat is worth.
     */
    private const MAX_WARNINGS = 2;

    /**
     * A consumption cap only warns when a household could realistically reach it. The
     * largest consumption preset on the site is 18 000 kWh/v, so "Max 80 000 kWh/v" is
     * noise that makes every spot contract look restricted.
     */
    private const CAP_RELEVANCE_THRESHOLD_KWH = 30000;

    /**
     * @return array{warnings: list<CardFooterItem>, facts: list<CardFooterItem>}
     */
    public function build(
        ElectricityContract $contract,
        ?ContractPricingViewData $pricing,
        ?ContractPricingIntegrity $integrity,
        PricingCategoryFacts $facts,
        bool $exceedsConsumptionLimit,
        bool $useCanonical,
    ): array {
        return [
            'warnings' => array_slice($this->warnings($contract, $pricing, $integrity, $facts, $exceedsConsumptionLimit), 0, self::MAX_WARNINGS),
            'facts' => $this->facts($contract, $pricing, $useCanonical),
        ];
    }

    /**
     * Priority order. The first two survive.
     *
     * @return list<CardFooterItem>
     */
    private function warnings(
        ElectricityContract $contract,
        ?ContractPricingViewData $pricing,
        ?ContractPricingIntegrity $integrity,
        PricingCategoryFacts $facts,
        bool $exceedsConsumptionLimit,
    ): array {
        $warnings = [];

        // 1. The price rises during the compared year.
        $label = $integrity?->cardLabel;
        if (is_string($label) && $label !== '') {
            $warnings[] = CardFooterItem::warning($label);
        }

        // 2. The contract cannot serve this household's consumption.
        $cap = $this->consumptionCap($contract, $exceedsConsumptionLimit);
        if ($cap !== null) {
            $warnings[] = CardFooterItem::warning($cap);
        }

        // 3. A fixed term shorter than the compared year, with no published continuation.
        $comparability = $pricing?->comparability()?->value ?? $contract->comparability ?? null;
        $termMonths = $pricing?->termMonths();
        if ($comparability === 'term_price_only' && $termMonths !== null) {
            $warnings[] = CardFooterItem::warning($termMonths.' kk sopimus, jatkohinta ei tiedossa');
        }

        // 4. The total excludes the consumption effect. Redundant when the band already
        //    says the contract is a consumption-effect product, so it is suppressed there.
        if ($comparability === 'base_only_hybrid' && $facts->category !== PricingCategory::ConsumptionEffect) {
            $warnings[] = CardFooterItem::warning('Ei sisällä kulutusvaikutusta');
        }

        return $warnings;
    }

    /**
     * The cap warning text, or null when the cap is out of reach for a household.
     *
     * Exception to the threshold: when the selected consumption actually exceeds the cap
     * the card is greyed out and sorted last, so the reason has to stay visible whatever
     * the cap size.
     */
    private function consumptionCap(ElectricityContract $contract, bool $exceedsConsumptionLimit): ?string
    {
        $max = $contract->consumption_limitation_max_x_kwh_per_y;
        if (! is_numeric($max) || $max <= 0) {
            return null;
        }

        if (! $exceedsConsumptionLimit && $max > self::CAP_RELEVANCE_THRESHOLD_KWH) {
            return null;
        }

        return 'Max '.number_format((float) $max, 0, ',', ' ').' kWh/v';
    }

    /**
     * @return list<CardFooterItem>
     */
    private function facts(ElectricityContract $contract, ?ContractPricingViewData $pricing, bool $useCanonical): array
    {
        $facts = [];

        $discount = $useCanonical
            ? ($pricing?->includesDiscounts() === true ? 'Tarjous' : null)
            : $this->legacyDiscountText($contract);
        if ($discount !== null) {
            $facts[] = CardFooterItem::fact($discount, 'tag');
        }

        $energy = $this->energySourceText($contract);
        if ($energy !== null) {
            $facts[] = CardFooterItem::fact($energy, 'leaf');
        }

        return $facts;
    }

    private function legacyDiscountText(ElectricityContract $contract): ?string
    {
        $hasDiscount = $contract->pricing_has_discounts
            || ($contract->relationLoaded('priceComponents') && $contract->hasActiveDiscounts());

        if (! $hasDiscount) {
            return null;
        }

        $info = $contract->relationLoaded('priceComponents') ? $contract->getActiveDiscountInfo() : null;
        $months = $info['n_first_months'] ?? null;

        return is_numeric($months) && $months > 0 ? $months.' kk tarjous' : 'Tarjous';
    }

    /**
     * The energy source as a data tag with the real figure. This replaces the old "Vihreä"
     * label, which claimed green at 50 % renewable, and it now carries the emissions
     * information that used to live in the card's coloured left stripe.
     */
    private function energySourceText(ElectricityContract $contract): ?string
    {
        if (($contract->emission_factor ?? null) !== null && (float) $contract->emission_factor === 0.0) {
            return 'Päästötön';
        }

        $source = $contract->relationLoaded('electricitySource') ? $contract->electricitySource : null;
        $renewable = $source?->renewable_total;

        if (! is_numeric($renewable) || $renewable <= 0) {
            return null;
        }

        return 'Uusiutuva '.number_format((float) $renewable, 0, ',', ' ').' %';
    }
}
