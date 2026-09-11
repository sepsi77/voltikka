<?php

namespace App\Services\ContractCard;

use App\Enums\ContractType;
use App\Services\CanonicalPricing\SupplierAdjusted\SupplierAdjustedEstimateCopy;
use App\Services\ContractCard\DTO\CardEstimate;
use App\Services\ContractCard\DTO\CardTypeBand;
use App\Services\ContractCard\DTO\PricingCategoryFacts;
use App\Services\ContractCard\Enums\PricingCategory;
use App\Services\ContractPricing\ContractPricingViewData;
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
     * @param  bool  $hasScheduledPublishedChange  True when the contract is fixed but has a
     *                                             pre-published later price (the integrity payload produced a card label). The
     *                                             band stays a truthful fixed-category statement; the increase is a footer warning.
     */
    public static function band(
        PricingCategoryFacts $facts,
        ?string $contractType,
        ?string $fixedTimeRange,
        bool $hasScheduledPublishedChange = false,
        bool $hasSupplierAdjustedEstimate = false,
        bool $hasEstimatedUnknownPrices = false,
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
                headline: $hasEstimatedUnknownPrices ? 'Perushinta + kulutusvaikutus' : 'Kiinteä hinta + kulutusvaikutus',
                detail: $hasEstimatedUnknownPrices
                    ? 'Tuntemattomien jaksojen perushinnat on arvioitu'
                    : 'Vaikutus riippuu siitä, mihin aikaan käytät sähköä',
                icon: 'pulse',
            );
        }

        if ($hasEstimatedUnknownPrices) {
            return new CardTypeBand(
                category: PricingCategory::Fixed,
                headline: 'Nykyinen energianhinta on tiedossa',
                detail: 'Tuntemattomien jaksojen hinnat on arvioitu',
                icon: 'lock',
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

        if ($hasSupplierAdjustedEstimate) {
            return new CardTypeBand(
                category: PricingCategory::Fixed,
                headline: 'Nykyinen energianhinta on kiinteä',
                detail: 'Myyjä voi muuttaa hintaa ilmoittamalla siitä',
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
        $type = ContractType::fromSource($contractType);

        if ($type === ContractType::OpenEnded) {
            return 'Toistaiseksi voimassa';
        }

        if ($type !== ContractType::FixedTerm) {
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
     * The Arvio popover. Typed price, term, and exclusion reasons compose.
     */
    public static function estimate(?ContractPricingViewData $pricing, PricingCategoryFacts $facts): ?CardEstimate
    {
        $method = $pricing?->estimateMethod()?->value;

        // Legacy pricing (CANONICAL_PRICING_ENABLED off) has no estimate_method, but a spot
        // total is still an estimate and must say so.
        if ($method === null || $method === 'none') {
            $method = $pricing?->isSpotContract() === true ? 'rolling_365_spot' : null;
        }

        // These reasons COMPOSE; they are not alternatives, and `estimate_method` reports only
        // one of them. A contract that both resets quarterly and is costed base-only (Vaasan
        // Sähkö Vaikuttaja, Korpela Kvartaali) reports `hybrid_base_only`, because the
        // calculator's unsupported-Hybrid branch is decided before the recurring-reset branch.
        // Keying the copy off that one value claimed the year was priced at a flat current
        // rate while the market-reset estimator had in fact repriced the tail (6,60 -> 9,28
        // c/kWh here, a 134 EUR/yr difference) and the receipt rows said so. Read the price
        // LEVEL from the mechanism, and treat hybrid_base_only as what it is: an exclusion.
        // Supplier-adjusted is also a price-level reason. Its separate payload wins before the
        // ordinary fixed fallback because all three supplier methods are estimates.
        $level = match (true) {
            $pricing?->supplierAdjustedEstimate() !== null => SupplierAdjustedEstimateCopy::popoverBody($pricing->supplierAdjustedEstimate()),
            $facts->isReset => self::resetBody($pricing, $facts),
            $method === 'forward_curve_spot' => self::forwardSpotBody($pricing),
            $method === 'rolling_365_spot' => self::spotBody($pricing),
            $method === 'term_price_annualized' => self::termBody($pricing),
            $method === 'hybrid_base_only' => self::hybridBody(),
            $method === 'hold_last_known_price' => self::heldPriceBody(),
            default => null,
        };

        if ($level === null) {
            return null;
        }

        $assumptions = $pricing?->assumptions() ?? [];
        if ($method !== 'term_price_annualized' && in_array('term_price_annualized', $assumptions, true)) {
            $level .= ' '.self::termBody($pricing);
        }

        // Append the exclusion only when the price-level sentence has not already said it.
        if (($method === 'hybrid_base_only' || in_array('excludes_consumption_effect', $assumptions, true))
            && ($method !== 'hybrid_base_only' || $facts->isReset || $pricing?->supplierAdjustedEstimate() !== null)) {
            $level .= ' Arvio ei sisällä kulutusvaikutusta, jonka suuruutta myyjä ei julkaise etukäteen.';
        }

        return new CardEstimate(
            heading: 'Miten arvio on laskettu?',
            body: $level,
        );
    }

    private static function forwardSpotBody(?ContractPricingViewData $pricing): string
    {
        $day = self::price($pricing?->spotPriceDayAverage());
        $night = self::price($pricing?->spotPriceNightAverage());
        $margin = self::price($pricing?->spotPriceMargin());

        $vatLabel = ($pricing?->toArray()['vat_basis'] ?? null) === 'excluded' ? 'ilman alv:tä' : 'sis. alv';
        $body = 'Vuosihinta perustuu seuraavan 12 kuukauden Suomen tukkumarkkinan ennakkohintoihin eli sähköfutuureihin. ';
        $body .= $pricing?->spotEstimate()?->string('confidence') === 'lower'
            ? 'Hintahistoriaa ei ole riittävästi päivä- ja yöeron arviointiin, joten päivälle ja yölle oletetaan sama pörssihinta'
            : 'Viimeisen 365 päivän toteutuneiden päivä- ja yöhintojen ero säilytetään arviossa';
        if ($day !== null && $night !== null) {
            $body .= ' (päivä '.$day.' c, yö '.$night.' c, '.$vatLabel.')';
        }
        $body .= $margin !== null
            ? ', ja hintaan lisätään sopimuksen marginaali '.$margin.' c/kWh.'
            : '.';

        return $body.' Toteutunut hinta vaihtelee tunneittain, joten vuosihinta ei ole hintalupaus.';
    }

    private static function spotBody(?ContractPricingViewData $pricing): string
    {
        $day = self::price($pricing?->spotPriceDayAverage());
        $night = self::price($pricing?->spotPriceNightAverage());
        $margin = self::price($pricing?->spotPriceMargin());

        $vatLabel = ($pricing?->toArray()['vat_basis'] ?? null) === 'excluded' ? 'ilman alv:tä' : 'sis. alv';
        $body = 'Vuosihinta perustuu 12 kuukauden toteutuneeseen pörssikeskihintaan';
        if ($day !== null && $night !== null) {
            $body .= ' (päivä '.$day.' c, yö '.$night.' c, '.$vatLabel.')';
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
     * @param  array<string, mixed>  $cost
     */
    private static function resetBody(?ContractPricingViewData $pricing, PricingCategoryFacts $facts): string
    {
        $reset = $pricing?->resetEstimate();
        $current = self::price($reset?->number('current_period_energy_price') ?? $pricing?->generalKwhPrice());
        $until = $facts->nextReset?->subDay();

        $body = $current !== null
            ? 'Nykyinen hinta '.$current.' c/kWh on tiedossa'
            : 'Nykyisen jakson hinta on tiedossa';
        $body .= $until !== null ? ' '.self::dayMonth($until).' asti.' : '.';

        $annual = self::price($reset?->number('annual_equivalent_energy_price'));
        $body .= match ($reset?->string('basis')) {
            'forward_curve_shift' => ' Tulevien jaksojen hinnat on arvioitu sähköjohdannaisten markkinahinnoista'
                .($annual !== null ? ', jolloin seuraavien 12 kuukauden keskihinnaksi tulee '.$annual.' c/kWh.' : '.'),
            'spot_seasonal_index' => ' Tulevien jaksojen hinnat on arvioitu pörssisähkön usean vuoden kausivaihtelusta, koska johdannaishintoja ei ollut saatavilla'
                .($annual !== null ? ', jolloin seuraavien 12 kuukauden keskihinnaksi tulee '.$annual.' c/kWh.' : '.'),
            default => ' Tulevien jaksojen hintoja ei tiedetä, joten 12 kuukauden arvio olettaa nykyisen hinnan jatkuvan.',
        };

        return $body.' Arvio sisältää nykyisen tunnetun hintajakson ja sen jälkeiset arvioidut jaksot.'
            .' Myyjä julkaisee todelliset hinnat '.self::cadenceAdverb($facts->cadence).'.';
    }

    private static function termBody(?ContractPricingViewData $pricing): string
    {
        $months = $pricing?->termMonths();

        $months ??= $pricing?->contractTerm()?->integer('months');
        $body = $months !== null
            ? 'Sopimus on määräaikainen '.$months.' kuukautta. Vertailun vuosihinta saadaan kertomalla sopimuskauden laskettu kustannus luvulla 12 / '.$months.'.'
            : 'Sopimus on määräaikainen ja alle vuoden mittainen. Vertailun vuosihinta saadaan kertomalla sopimuskauden laskettu kustannus luvulla 12 ja jakamalla se sopimuskuukausien määrällä.';

        return $body.' Sopimuskauden kustannus sisältää tiedossa olevat hinnat ja mahdolliset arviot tuntemattomille osille.'
            .' Vuosihinta ei ole tarjous sopimuskauden jälkeiselle ajalle.';
    }

    private static function hybridBody(): string
    {
        return 'Arviossa käytetään sopimuksen tiedossa olevia perushintoja. Mahdolliset tuntemattomat osat arvioidaan viimeisimmällä soveltuvalla perushinnalla tai ilmoitetulla normaalihinnalla.'
            .' Arvio ei sisällä kulutusvaikutusta. Se voi nostaa tai laskea lopullista hintaa sen mukaan, mihin aikaan käytät sähköä.'
            .' Myyjä ei julkaise vaikutuksen suuruutta etukäteen.';
    }

    private static function heldPriceBody(): string
    {
        return 'Arviossa käytetään tiedossa olevia hintoja niiden voimassaoloajalta. Tuntemattomille osille oletetaan viimeisin soveltuva hinta tai myyjän ilmoittama normaalihinta.'
            .' Todellinen hinta voi muuttua. Vuosihinta ei ole hintalupaus.';
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
        if (ContractType::fromSource($contractType) === ContractType::OpenEnded) {
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
