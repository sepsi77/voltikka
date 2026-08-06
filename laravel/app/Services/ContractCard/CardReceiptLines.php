<?php

namespace App\Services\ContractCard;

use App\Enums\MeteringType;
use App\Services\CanonicalPricing\DTO\ContractPricingIntegrity;
use App\Services\ContractCard\DTO\CardReceiptLine;
use App\Services\ContractCard\DTO\PricingCategoryFacts;
use App\Services\ContractCard\Enums\PricingCategory;
use App\Services\ContractPricing\ContractPricingViewData;
use App\Services\ContractPricing\PricingFact;
use Carbon\CarbonImmutable;

/**
 * Builds the itemised price rows shown on a contract card.
 *
 * The row set is chosen by pricing mechanism, not by `metering`, because the mechanism is
 * what the rows have to explain. Estimated rows are marked `soft` so the breakdown itself
 * shows which parts of the price are contractual and which are calculated.
 *
 * Hard cap of three rows: the monthly fee is always the last row, so at most two energy
 * rows survive. A longer receipt turns the card back into the dense metric strip this
 * redesign replaced.
 *
 * The contract detail page renders the same rows in `detailed` mode, which raises the cap
 * to five. The page is one contract, not a scannable list, so a dated mechanism change can
 * carry its market baseline and its own monthly-fee pair instead of being truncated.
 */
class CardReceiptLines
{
    private const MAX_LINES = 3;

    /** The detail page shows one contract, so a dated pair may keep its second fee row. */
    private const MAX_DETAIL_LINES = 5;

    /**
     * @param  array<string, float|null>  $rates  Resolved rates: general, day, night, winter, other, fee, margin.
     * @param  array<string, mixed>  $cost  The `calculated_cost` payload.
     * @param  array<string, mixed>|null  $integrity  The `pricing_integrity` payload.
     * @param  bool  $detailed  Detail-page mode: more rows, and a dated monthly-fee pair.
     * @param  bool  $useCanonical  Canonical mode reads phase changes only from calculated output.
     * @return list<CardReceiptLine>
     */
    public function build(
        array $rates,
        ?ContractPricingViewData $pricing,
        ?ContractPricingIntegrity $integrity,
        PricingCategoryFacts $facts,
        ?string $metering,
        bool $detailed = false,
        bool $useCanonical = false,
    ): array {
        $package = $this->packageLines($pricing);
        if ($package !== []) {
            return $package;
        }

        $switch = $this->mechanismSwitchPhases($pricing);

        $lines = $switch !== null
            ? $this->mechanismSwitchLines($switch, $pricing, $detailed)
            : $this->energyLines($rates, $pricing, $integrity, $facts, $metering, $useCanonical);

        $lines = [...$lines, ...$this->feeLines($rates, $switch, $detailed)];

        return array_slice($lines, 0, $detailed ? self::MAX_DETAIL_LINES : self::MAX_LINES);
    }

    /**
     * A monthly included-energy package is one billing mechanism, not an energy promotion.
     * All three current facts come from the typed canonical outcome.
     *
     * @param  array<string, mixed>  $cost
     * @return list<CardReceiptLine>
     */
    private function packageLines(?ContractPricingViewData $pricing): array
    {
        $package = $pricing?->energyPackage();

        if ($package === null) {
            return [];
        }

        return [
            new CardReceiptLine('Kuukausipaketti', $this->amount((float) $package->number('monthly_fee_eur')), '€/kk'),
            new CardReceiptLine('Sisältää', number_format((float) $package->number('included_kwh'), 0, ',', ' '), 'kWh/kk'),
            new CardReceiptLine('Ylittävä kulutus', $this->amount((float) $package->number('excess_rate_cents_per_kwh')), 'c/kWh'),
        ];
    }

    /**
     * The two phases of a disclosed mid-window switch between the two per-kWh mechanisms
     * (a flat energy price then a spot margin, or the reverse), or null.
     *
     * Deliberately narrow. A rate change inside one mechanism is already covered by the
     * scheduled-change rows and by the market-reset rows; this is only the case where the
     * price stops meaning the same thing. Cheap Markkinahintasähkö is the live example:
     * 6,99 c/kWh flat for one month, then Nord Pool's monthly average + 1,29 c/kWh. The
     * detail page used to print the flat intro price as "Marginaali 6,99" a few hundred
     * pixels above the seller's own text saying the margin is 1,29.
     *
     * @param  array<string, mixed>  $cost
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}|null
     */
    private function mechanismSwitchPhases(?ContractPricingViewData $pricing): ?array
    {
        $phases = $pricing?->phases() ?? [];

        for ($i = 0; $i < count($phases) - 1; $i++) {
            $first = $phases[$i];
            $second = $phases[$i + 1];

            if ($first->boolean('uses_spot') === $second->boolean('uses_spot')) {
                continue;
            }

            if ($this->mechanismRate($first) === null || $this->mechanismRate($second) === null) {
                continue;
            }

            if ($first->string('window_end') === null || $second->string('window_start') === null) {
                continue;
            }

            return [$first, $second];
        }

        return null;
    }

    /**
     * @param  array{0: array<string, mixed>, 1: array<string, mixed>}  $switch
     * @param  array<string, mixed>  $cost
     * @return list<CardReceiptLine>
     */
    private function mechanismSwitchLines(array $switch, ?ContractPricingViewData $pricing, bool $detailed): array
    {
        [$first, $second] = $switch;

        $until = $this->date($first->string('window_end'));
        $from = $this->date($second->string('window_start'));

        if ($until === null || $from === null) {
            return [];
        }

        $lines = [$this->mechanismLine($first, ContractCardCopy::dayMonth($until).' asti')];

        // The margin alone does not state the price, so on the detail page the market
        // baseline the total was built on sits between the two dated rows. The card has no
        // room for it; its Arvio popover carries the same figure.
        $baseline = $pricing?->spotPriceDayAverage();
        if ($detailed && $baseline !== null && ($first->boolean('uses_spot') || $second->boolean('uses_spot'))) {
            $lines[] = new CardReceiptLine('Pörssin keskihinta 12 kk', $this->amount($baseline), 'c/kWh', soft: true);
        }

        $lines[] = $this->mechanismLine($second, ContractCardCopy::dayMonth($from).' alkaen');

        return $lines;
    }

    /**
     * @param  array<string, mixed>  $phase
     */
    private function mechanismLine(PricingFact $phase, string $when): CardReceiptLine
    {
        $label = $phase->boolean('uses_spot') ? 'Marginaali ' : 'Energia ';

        return new CardReceiptLine($label.$when, $this->amount((float) $this->mechanismRate($phase)), 'c/kWh');
    }

    /**
     * The per-kWh figure that phase is priced on: its margin when it follows the market,
     * otherwise its flat energy rate.
     *
     * @param  array<string, mixed>  $phase
     */
    private function mechanismRate(PricingFact $phase): ?float
    {
        return $phase->boolean('uses_spot')
            ? $phase->number('spot_margin_cents')
            : $phase->number('energy_cents');
    }

    /**
     * The monthly fee, as a dated pair when a mechanism switch also changes it and there is
     * room to say so.
     *
     * @param  array<string, float|null>  $rates
     * @param  array{0: array<string, mixed>, 1: array<string, mixed>}|null  $switch
     * @return list<CardReceiptLine>
     */
    private function feeLines(array $rates, ?array $switch, bool $detailed): array
    {
        if ($detailed && $switch !== null) {
            [$first, $second] = $switch;
            $before = $first->number('monthly_fee');
            $after = $second->number('monthly_fee');
            $until = $this->date($first->string('window_end'));
            $from = $this->date($second->string('window_start'));

            if ($before !== null && $after !== null && abs($before - $after) > 0.005
                && $until !== null && $from !== null) {
                return [
                    new CardReceiptLine('Perusmaksu '.ContractCardCopy::dayMonth($until).' asti', $this->amount($before), '€/kk'),
                    new CardReceiptLine('Perusmaksu '.ContractCardCopy::dayMonth($from).' alkaen', $this->amount($after), '€/kk'),
                ];
            }
        }

        if ($rates['fee'] === null) {
            return [];
        }

        return [new CardReceiptLine('Perusmaksu', $this->amount($rates['fee']), '€/kk')];
    }

    private function date(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value)) {
            return null;
        }

        try {
            return CarbonImmutable::parse($value, 'Europe/Helsinki')->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param  array<string, float|null>  $rates
     * @param  array<string, mixed>  $cost
     * @param  array<string, mixed>|null  $integrity
     * @return list<CardReceiptLine>
     */
    private function energyLines(
        array $rates,
        ?ContractPricingViewData $pricing,
        ?ContractPricingIntegrity $integrity,
        PricingCategoryFacts $facts,
        ?string $metering,
        bool $useCanonical,
    ): array {
        // A pre-published later price is the most useful thing the rows can say: both dates
        // and both prices, so the footer warning is backed by the breakdown. Canonical mode
        // reads the calculator's resolved phase record. The integrity payload stays only in
        // the explicit legacy branch.
        $scheduled = $useCanonical
            ? $this->canonicalScheduledChangeLines($pricing)
            : $this->legacyScheduledChangeLines($integrity);
        if ($scheduled !== []) {
            return $scheduled;
        }

        if ($pricing?->supplierAdjustedEstimate() !== null) {
            return $this->supplierAdjustedLines($pricing);
        }

        if ($facts->isReset) {
            return $this->resetLines($rates, $pricing, $facts, $metering);
        }

        if ($facts->isSpot) {
            return $this->spotLines($rates, $pricing);
        }

        if ($facts->category === PricingCategory::ConsumptionEffect) {
            $baseRate = $this->baseRate($rates, $metering);
            $lines = [];

            if ($baseRate !== null) {
                $lines[] = new CardReceiptLine('Perushinta', $this->amount($baseRate), 'c/kWh');
            }

            $lines[] = new CardReceiptLine('Kulutusvaikutus', '± käyttöajan mukaan', null, soft: true);

            return $lines;
        }

        return $this->meteringLines($rates, $metering);
    }

    /**
     * @param  array<string, mixed>|null  $integrity
     * @return list<CardReceiptLine>
     */
    private function legacyScheduledChangeLines(?ContractPricingIntegrity $integrity): array
    {
        $promo = $integrity?->promoRateCents;
        $normal = $integrity?->normalRateCents;
        $changeDate = $integrity?->changeDate;

        if ($promo === null || $normal === null || $changeDate === null) {
            return [];
        }

        try {
            $change = CarbonImmutable::parse($changeDate, 'Europe/Helsinki')->startOfDay();
        } catch (\Throwable) {
            return [];
        }

        return [
            new CardReceiptLine(
                'Energia '.ContractCardCopy::dayMonth($change->subDay()).' asti',
                $this->amount($promo),
                'c/kWh',
            ),
            new CardReceiptLine(
                'Energia '.ContractCardCopy::dayMonth($change).' alkaen',
                $this->amount($normal),
                'c/kWh',
            ),
        ];
    }

    /**
     * A canonical rate change from the calculator's resolved phase timeline.
     *
     * @param  array<string, mixed>  $cost
     * @return list<CardReceiptLine>
     */
    private function canonicalScheduledChangeLines(?ContractPricingViewData $pricing): array
    {
        $phases = $pricing?->phases() ?? [];

        for ($index = 0; $index < count($phases) - 1; $index++) {
            $first = $phases[$index];
            $second = $phases[$index + 1];

            if ($first->boolean('uses_spot') !== $second->boolean('uses_spot')) {
                continue;
            }

            $firstRate = $this->mechanismRate($first);
            $secondRate = $this->mechanismRate($second);
            $until = $this->date($first->string('window_end'));
            $from = $this->date($second->string('window_start'));

            if ($firstRate === null || $secondRate === null || $until === null || $from === null
                || abs($firstRate - $secondRate) < 0.0001) {
                continue;
            }

            $label = $first->boolean('uses_spot') ? 'Marginaali ' : 'Energia ';

            return [
                new CardReceiptLine($label.ContractCardCopy::dayMonth($until).' asti', $this->amount($firstRate), 'c/kWh'),
                new CardReceiptLine($label.ContractCardCopy::dayMonth($from).' alkaen', $this->amount($secondRate), 'c/kWh'),
            ];
        }

        return [];
    }

    /** @return list<CardReceiptLine> */
    private function supplierAdjustedLines(ContractPricingViewData $pricing): array
    {
        $estimate = $pricing->supplierAdjustedEstimate();
        $current = $estimate?->number('current_energy_price');
        $annual = $estimate?->number('annual_equivalent_energy_price');

        if ($current === null) {
            return [];
        }

        $lines = [new CardReceiptLine('Energia nyt', $this->amount($current), 'c/kWh')];

        if ($annual !== null) {
            $lines[] = new CardReceiptLine(
                '12 kk keskihinta, arvio',
                $this->amount($annual),
                'c/kWh',
                soft: true,
            );
        }

        return $lines;
    }

    /**
     * @param  array<string, float|null>  $rates
     * @param  array<string, mixed>  $cost
     * @return list<CardReceiptLine>
     */
    private function resetLines(array $rates, ?ContractPricingViewData $pricing, PricingCategoryFacts $facts, ?string $metering): array
    {
        $reset = $pricing?->resetEstimate();

        $current = $reset?->number('current_period_energy_price')
            ?? $this->baseRate($rates, $metering);

        if ($current === null) {
            return [];
        }

        $until = $facts->nextReset?->subDay();
        $label = $until !== null
            ? 'Energia nyt, '.ContractCardCopy::dayMonth($until).' asti'
            : 'Energia nyt';

        $lines = [new CardReceiptLine($label, $this->amount($current), 'c/kWh')];

        // Only present when RESET_FORWARD_SHIFT_ENABLED produced a forward-shifted tail.
        // With the flag off the tail holds flat, and there is no second figure to show.
        if ($reset?->number('annual_equivalent_energy_price') !== null) {
            $lines[] = new CardReceiptLine(
                'Loppuvuosi, arvio',
                $this->amount((float) $reset->number('annual_equivalent_energy_price')),
                'c/kWh',
                soft: true,
            );
        }

        return $lines;
    }

    /**
     * @param  array<string, float|null>  $rates
     * @param  array<string, mixed>  $cost
     * @return list<CardReceiptLine>
     */
    private function spotLines(array $rates, ?ContractPricingViewData $pricing): array
    {
        $lines = [];

        // The day average is the exact figure the total is built on: for General metering
        // (every active spot contract) the calculator prices the whole bucket at
        // `spot_price_day_avg + margin`. The night average appears in the Arvio popover.
        $baseline = $pricing?->spotPriceDayAverage();
        if ($baseline !== null) {
            $lines[] = new CardReceiptLine(
                'Pörssin keskihinta 12 kk',
                $this->amount($baseline),
                'c/kWh',
                soft: true,
            );
        }

        if ($rates['margin'] !== null) {
            $lines[] = new CardReceiptLine('Marginaali', $this->amount($rates['margin']), 'c/kWh');
        } elseif ($lines === [] && $rates['general'] !== null) {
            // A market-price product costed as spot without a separate margin component.
            $lines[] = new CardReceiptLine('Energia', $this->amount($rates['general']), 'c/kWh');
        }

        return $lines;
    }

    /**
     * @param  array<string, float|null>  $rates
     * @return list<CardReceiptLine>
     */
    private function meteringLines(array $rates, ?string $metering): array
    {
        $meteringType = MeteringType::fromSource($metering);

        if ($meteringType === MeteringType::Time && $rates['day'] !== null && $rates['night'] !== null) {
            return [
                new CardReceiptLine('Päivä', $this->amount($rates['day']), 'c/kWh'),
                new CardReceiptLine('Yö', $this->amount($rates['night']), 'c/kWh'),
            ];
        }

        if ($meteringType === MeteringType::Season && $rates['winter'] !== null && $rates['other'] !== null) {
            return [
                new CardReceiptLine('Talvi', $this->amount($rates['winter']), 'c/kWh'),
                new CardReceiptLine('Muu aika', $this->amount($rates['other']), 'c/kWh'),
            ];
        }

        $rate = $this->baseRate($rates, $meteringType);
        if ($rate === null) {
            return [];
        }

        return [new CardReceiptLine('Energia', $this->amount($rate), 'c/kWh')];
    }

    /**
     * The single headline rate for a contract whose rows do not split by time or season.
     *
     * @param  array<string, float|null>  $rates
     */
    private function baseRate(array $rates, MeteringType|string|null $metering): ?float
    {
        $meteringType = is_string($metering) ? MeteringType::fromSource($metering) : $metering;

        return match ($meteringType) {
            MeteringType::Time => $rates['day'] ?? $rates['general'],
            MeteringType::Season => $rates['winter'] ?? $rates['general'],
            default => $rates['general'] ?? $rates['day'] ?? $rates['winter'],
        };
    }

    private function amount(float $value): string
    {
        return number_format($value, 2, ',', ' ');
    }
}
