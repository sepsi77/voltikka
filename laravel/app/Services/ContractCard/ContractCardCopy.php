<?php

namespace App\Services\ContractCard;

use App\Services\ContractCard\DTO\CardEstimate;
use App\Services\ContractCard\DTO\CardTypeBand;
use App\Services\ContractCard\DTO\PricingCategoryFacts;
use App\Services\ContractCard\Enums\PricingCategory;
use Carbon\CarbonImmutable;

/**
 * Every Finnish sentence on a contract card, generated from typed fields only.
 *
 * The rule this file exists to enforce: no interpretation `summary` string, and no other
 * free text written by a seller or by an LLM, ever reaches a card. Copy is assembled from
 * enum-like values, numbers and dates, exactly as
 * `CanonicalPricing\MarketReset\ResetEstimateCopy` does for the detail page.
 *
 * DESIGN.md forbids em dashes in product copy. Band copy uses `·` as its separator; that
 * separator is rendered by the template, not embedded in these strings.
 */
class ContractCardCopy
{
    /**
     * The type band. Single purpose: it states the pricing category and nothing else.
     *
     * @param bool $hasScheduledPublishedChange True when the contract is fixed but has a
     *        pre-published later price (the integrity payload produced a card label). The
     *        band stays a truthful fixed-category statement; the increase is a footer warning.
     */
    public static function band(
        PricingCategoryFacts $facts,
        ?string $contractType,
        ?string $fixedTimeRange,
        bool $hasScheduledPublishedChange = false,
    ): CardTypeBand {
        if ($facts->category === PricingCategory::Market) {
            // Spot first: when a contract is both hourly and reset-scheduled, the hourly
            // mechanism is the one the customer feels.
            if ($facts->isSpot) {
                return new CardTypeBand(
                    category: PricingCategory::Market,
                    headline: 'Hinta seuraa pörssin tuntihintaa',
                    detail: 'Muuttuu joka tunti',
                    icon: 'wave',
                );
            }

            return new CardTypeBand(
                category: PricingCategory::Market,
                headline: 'Hinta tarkistetaan '.self::cadenceAdverb($facts->cadence),
                detail: $facts->nextReset !== null
                    ? 'Seuraava tarkistus '.self::date($facts->nextReset)
                    : null,
                icon: 'wave',
            );
        }

        if ($facts->category === PricingCategory::ConsumptionEffect) {
            return new CardTypeBand(
                category: PricingCategory::ConsumptionEffect,
                headline: 'Kiinteä hinta + kulutusvaikutus',
                detail: 'Korjaus riippuu kulutusprofiilistasi',
                icon: 'pulse',
            );
        }

        if ($hasScheduledPublishedChange) {
            return new CardTypeBand(
                category: PricingCategory::Fixed,
                headline: 'Kiinteät hinnat',
                detail: 'Julkaistu etukäteen, ei sidottu markkinaan',
                icon: 'lock',
            );
        }

        return new CardTypeBand(
            category: PricingCategory::Fixed,
            headline: 'Energian hinta ei muutu',
            detail: self::durationDetail($contractType, $fixedTimeRange),
            icon: 'lock',
        );
    }

    /**
     * The meta-line duration phrase ("Toistaiseksi voimassa" / "Määräaikainen 12 kk").
     * Returns null for a contract type the source did not classify.
     */
    public static function durationLabel(?string $contractType, ?string $fixedTimeRange): ?string
    {
        if ($contractType === 'OpenEnded') {
            return 'Toistaiseksi voimassa';
        }

        if ($contractType !== 'FixedTerm') {
            return null;
        }

        return match ($fixedTimeRange) {
            'Below6' => 'Määräaikainen alle 6 kk',
            'Fixed6' => 'Määräaikainen 6 kk',
            'Between711' => 'Määräaikainen 7–11 kk',
            'Fixed12' => 'Määräaikainen 12 kk',
            'Between1323' => 'Määräaikainen 13–23 kk',
            'Fixed24' => 'Määräaikainen 24 kk',
            'Over24' => 'Määräaikainen yli 24 kk',
            default => 'Määräaikainen',
        };
    }

    /**
     * The Arvio popover. One of four typed reasons.
     *
     * @param array<string, mixed> $cost The `calculated_cost` payload.
     */
    public static function estimate(array $cost, PricingCategoryFacts $facts): ?CardEstimate
    {
        $method = is_string($cost['estimate_method'] ?? null) ? $cost['estimate_method'] : null;

        // Legacy pricing (CANONICAL_PRICING_ENABLED off) has no estimate_method, but a spot
        // total is still an estimate and must say so.
        if ($method === null || $method === 'none') {
            $method = ($cost['is_spot_contract'] ?? false) === true ? 'rolling_365_spot' : null;
        }

        // These reasons COMPOSE; they are not alternatives, and `estimate_method` reports only
        // one of them. A contract that both resets quarterly and is costed base-only (Vaasan
        // Sähkö Vaikuttaja, Korpela Kvartaali) reports `hybrid_base_only`, because the
        // calculator's unsupported-Hybrid branch is decided before the recurring-reset branch.
        // Keying the copy off that one value claimed the year was priced at a flat current
        // rate while the market-reset estimator had in fact repriced the tail (6,60 -> 9,28
        // c/kWh here, a 134 EUR/yr difference) and the receipt rows said so. Read the price
        // LEVEL from the mechanism, and treat hybrid_base_only as what it is: an exclusion.
        $level = match (true) {
            $facts->isReset => self::resetBody($cost, $facts),
            $method === 'rolling_365_spot' => self::spotBody($cost),
            $method === 'term_price_annualized' => self::termBody($cost),
            $method === 'hybrid_base_only' => self::hybridBody($cost),
            default => null,
        };

        // Appended only when the level sentence has not already said it.
        $exclusion = ($method === 'hybrid_base_only' && $level !== null && $facts->isReset)
            ? 'Arvio ei sisällä kulutusvaikutusta, jonka suuruutta myyjä ei julkaise etukäteen.'
            : null;

        if ($level === null) {
            return null;
        }

        return new CardEstimate(
            heading: 'Miten arvio on laskettu?',
            body: trim($level.($exclusion !== null ? ' '.$exclusion : '')),
        );
    }

    /**
     * @param array<string, mixed> $cost
     */
    private static function spotBody(array $cost): string
    {
        $day = self::price($cost['spot_price_day_avg'] ?? null);
        $night = self::price($cost['spot_price_night_avg'] ?? null);
        $margin = self::price($cost['spot_price_margin'] ?? null);

        $body = 'Vuosihinta perustuu 12 kuukauden toteutuneeseen pörssikeskihintaan';
        if ($day !== null && $night !== null) {
            $body .= ' (päivä '.$day.' c, yö '.$night.' c, sis. alv)';
        }
        $body .= $margin !== null
            ? ' ja sopimuksen marginaaliin '.$margin.' c/kWh.'
            : '.';

        return $body.' Toteutunut hinta vaihtelee tunneittain, joten vuosihinta ei ole hintalupaus.';
    }

    /**
     * The market-reset explanation.
     *
     * The tail basis is read from `reset_estimate.basis`, NOT from `estimate_method`. The two
     * disagree whenever another branch of the calculator claimed the method first (a Hybrid
     * reset reports `hybrid_base_only` while still carrying a forward-curve payload), and the
     * payload is the record of what actually happened to the numbers. A missing payload means
     * the tail held flat, which is what `RESET_FORWARD_SHIFT_ENABLED=false` produces.
     *
     * @param array<string, mixed> $cost
     */
    private static function resetBody(array $cost, PricingCategoryFacts $facts): string
    {
        $reset = is_array($cost['reset_estimate'] ?? null) ? $cost['reset_estimate'] : [];
        $current = self::price($reset['current_period_energy_price'] ?? $cost['general_kwh_price'] ?? null);
        $until = $facts->nextReset?->subDay();

        $body = $current !== null
            ? 'Nykyinen hinta '.$current.' c/kWh on tiedossa'
            : 'Nykyisen jakson hinta on tiedossa';
        $body .= $until !== null ? ' '.self::dayMonth($until).' asti.' : '.';

        $annual = self::price($reset['annual_equivalent_energy_price'] ?? null);
        $body .= match ($reset['basis'] ?? null) {
            'forward_curve_shift' => ' Loppuvuoden hinnat on arvioitu sähköjohdannaisten markkinahinnoista'
                .($annual !== null ? ', jolloin koko vuoden keskihinnaksi tulee '.$annual.' c/kWh.' : '.'),
            'spot_seasonal_index' => ' Loppuvuoden hinnat on arvioitu pörssisähkön usean vuoden kausivaihtelusta, koska johdannaishintoja ei ollut saatavilla'
                .($annual !== null ? ', jolloin koko vuoden keskihinnaksi tulee '.$annual.' c/kWh.' : '.'),
            default => ' Loppuvuoden hintoja ei tiedetä, joten arvio olettaa nykyisen hinnan jatkuvan.',
        };

        return $body.' Myyjä julkaisee todelliset hinnat '.self::cadenceAdverb($facts->cadence).'.';
    }

    /**
     * @param array<string, mixed> $cost
     */
    private static function termBody(array $cost): string
    {
        $months = is_numeric($cost['term_months'] ?? null) ? (int) $cost['term_months'] : null;

        $body = $months !== null
            ? 'Sopimus on kiinteä '.$months.' kuukautta.'
            : 'Sopimus on kiinteä alle vuoden.';

        return $body.' Vuosihinta on laskettu olettaen, että sama hinta jatkuu koko vuoden.'
            .' Myyjä ei ole ilmoittanut hintaa jakson jälkeen.';
    }

    /**
     * @param array<string, mixed> $cost
     */
    private static function hybridBody(array $cost): string
    {
        $base = self::price($cost['general_kwh_price'] ?? $cost['daytime_kwh_price'] ?? $cost['seasonal_winter_day_kwh_price'] ?? null);

        $body = $base !== null
            ? 'Vuosihinta on laskettu kiinteällä perushinnalla '.$base.' c/kWh.'
            : 'Vuosihinta on laskettu pelkällä sopimuksen perushinnalla.';

        return $body.' Lopullista hintaa nostaa tai laskee kulutusvaikutus, joka riippuu siitä, mihin aikaan käytät sähköä.'
            .' Myyjä ei julkaise vaikutuksen suuruutta etukäteen.';
    }

    public static function cadenceAdverb(?string $cadence): string
    {
        return match ($cadence) {
            'monthly' => 'kuukausittain',
            'quarterly' => 'neljännesvuosittain',
            'seasonal' => 'kausittain',
            default => 'jaksoittain',
        };
    }

    private static function durationDetail(?string $contractType, ?string $fixedTimeRange): ?string
    {
        if ($contractType === 'OpenEnded') {
            return 'Voimassa toistaiseksi';
        }

        return self::durationLabel($contractType, $fixedTimeRange);
    }

    public static function date(CarbonImmutable $date): string
    {
        return $date->format('j.n.Y');
    }

    /** Day and month without the year, for a boundary inside the coming 12 months. */
    public static function dayMonth(CarbonImmutable $date): string
    {
        return $date->format('j.n.');
    }

    private static function price(mixed $value): ?string
    {
        return is_numeric($value) ? number_format((float) $value, 2, ',', ' ') : null;
    }
}
