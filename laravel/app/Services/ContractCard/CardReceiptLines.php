<?php

namespace App\Services\ContractCard;

use App\Services\ContractCard\DTO\CardReceiptLine;
use App\Services\ContractCard\DTO\PricingCategoryFacts;
use App\Services\ContractCard\Enums\PricingCategory;
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
        array $cost,
        ?array $integrity,
        PricingCategoryFacts $facts,
        ?string $metering,
        bool $detailed = false,
        bool $useCanonical = false,
    ): array {
        $package = $this->packageLines($cost);
        if ($package !== []) {
            return $package;
        }

        $switch = $this->mechanismSwitchPhases($cost);

        $lines = $switch !== null
            ? $this->mechanismSwitchLines($switch, $cost, $detailed)
            : $this->energyLines($rates, $cost, $integrity, $facts, $metering, $useCanonical);

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
    private function packageLines(array $cost): array
    {
        $package = is_array($cost['energy_package'] ?? null) ? $cost['energy_package'] : null;

        if ($package === null
            || ! is_numeric($package['monthly_fee_eur'] ?? null)
            || ! is_numeric($package['included_kwh'] ?? null)
            || ! is_numeric($package['excess_rate_cents_per_kwh'] ?? null)
            || ($package['allowance_cadence'] ?? null) !== 'monthly') {
            return [];
        }

        return [
            new CardReceiptLine('Kuukausipaketti', $this->amount((float) $package['monthly_fee_eur']), '€/kk'),
            new CardReceiptLine('Sisältää', number_format((float) $package['included_kwh'], 0, ',', ' '), 'kWh/kk'),
            new CardReceiptLine('Ylittävä kulutus', $this->amount((float) $package['excess_rate_cents_per_kwh']), 'c/kWh'),
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
    private function mechanismSwitchPhases(array $cost): ?array
    {
        $phases = is_array($cost['phase_breakdown'] ?? null) ? array_values($cost['phase_breakdown']) : [];

        for ($i = 0; $i < count($phases) - 1; $i++) {
            $first = $phases[$i];
            $second = $phases[$i + 1];

            if (! is_array($first) || ! is_array($second)) {
                continue;
            }

            if (($first['uses_spot'] ?? null) === ($second['uses_spot'] ?? null)) {
                continue;
            }

            if ($this->mechanismRate($first) === null || $this->mechanismRate($second) === null) {
                continue;
            }

            if (! is_string($first['window_end'] ?? null) || ! is_string($second['window_start'] ?? null)) {
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
    private function mechanismSwitchLines(array $switch, array $cost, bool $detailed): array
    {
        [$first, $second] = $switch;

        $until = $this->date($first['window_end']);
        $from = $this->date($second['window_start']);

        if ($until === null || $from === null) {
            return [];
        }

        $lines = [$this->mechanismLine($first, ContractCardCopy::dayMonth($until).' asti')];

        // The margin alone does not state the price, so on the detail page the market
        // baseline the total was built on sits between the two dated rows. The card has no
        // room for it; its Arvio popover carries the same figure.
        $baseline = $cost['spot_price_day_avg'] ?? null;
        if ($detailed && is_numeric($baseline) && (($first['uses_spot'] ?? false) || ($second['uses_spot'] ?? false))) {
            $lines[] = new CardReceiptLine('Pörssin keskihinta 12 kk', $this->amount((float) $baseline), 'c/kWh', soft: true);
        }

        $lines[] = $this->mechanismLine($second, ContractCardCopy::dayMonth($from).' alkaen');

        return $lines;
    }

    /**
     * @param  array<string, mixed>  $phase
     */
    private function mechanismLine(array $phase, string $when): CardReceiptLine
    {
        $label = ($phase['uses_spot'] ?? false) ? 'Marginaali ' : 'Energia ';

        return new CardReceiptLine($label.$when, $this->amount($this->mechanismRate($phase)), 'c/kWh');
    }

    /**
     * The per-kWh figure that phase is priced on: its margin when it follows the market,
     * otherwise its flat energy rate.
     *
     * @param  array<string, mixed>  $phase
     */
    private function mechanismRate(array $phase): ?float
    {
        $value = ($phase['uses_spot'] ?? false) ? ($phase['spot_margin_cents'] ?? null) : ($phase['energy_cents'] ?? null);

        return is_numeric($value) ? (float) $value : null;
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
            $before = $first['monthly_fee'] ?? null;
            $after = $second['monthly_fee'] ?? null;
            $until = $this->date($first['window_end']);
            $from = $this->date($second['window_start']);

            if (is_numeric($before) && is_numeric($after) && abs((float) $before - (float) $after) > 0.005
                && $until !== null && $from !== null) {
                return [
                    new CardReceiptLine('Perusmaksu '.ContractCardCopy::dayMonth($until).' asti', $this->amount((float) $before), '€/kk'),
                    new CardReceiptLine('Perusmaksu '.ContractCardCopy::dayMonth($from).' alkaen', $this->amount((float) $after), '€/kk'),
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
        array $cost,
        ?array $integrity,
        PricingCategoryFacts $facts,
        ?string $metering,
        bool $useCanonical,
    ): array {
        // A pre-published later price is the most useful thing the rows can say: both dates
        // and both prices, so the footer warning is backed by the breakdown. Canonical mode
        // reads the calculator's resolved phase record. The integrity payload stays only in
        // the explicit legacy branch.
        $scheduled = $useCanonical
            ? $this->canonicalScheduledChangeLines($cost)
            : $this->legacyScheduledChangeLines($integrity);
        if ($scheduled !== []) {
            return $scheduled;
        }

        if ($facts->isReset) {
            return $this->resetLines($rates, $cost, $facts, $metering);
        }

        if ($facts->isSpot) {
            return $this->spotLines($rates, $cost);
        }

        if ($facts->category === PricingCategory::ConsumptionEffect) {
            return [
                new CardReceiptLine('Perushinta', $this->amount($this->baseRate($rates, $metering)), 'c/kWh'),
                new CardReceiptLine('Kulutusvaikutus', '± käyttöajan mukaan', null, soft: true),
            ];
        }

        return $this->meteringLines($rates, $metering);
    }

    /**
     * @param  array<string, mixed>|null  $integrity
     * @return list<CardReceiptLine>
     */
    private function legacyScheduledChangeLines(?array $integrity): array
    {
        $promo = $integrity['promo_rate_cents'] ?? null;
        $normal = $integrity['normal_rate_cents'] ?? null;
        $changeDate = $integrity['change_date'] ?? null;

        if (! is_numeric($promo) || ! is_numeric($normal) || ! is_string($changeDate)) {
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
                $this->amount((float) $promo),
                'c/kWh',
            ),
            new CardReceiptLine(
                'Energia '.ContractCardCopy::dayMonth($change).' alkaen',
                $this->amount((float) $normal),
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
    private function canonicalScheduledChangeLines(array $cost): array
    {
        $phases = is_array($cost['phase_breakdown'] ?? null)
            ? array_values($cost['phase_breakdown'])
            : [];

        for ($index = 0; $index < count($phases) - 1; $index++) {
            $first = $phases[$index];
            $second = $phases[$index + 1];

            if (! is_array($first) || ! is_array($second)
                || ($first['uses_spot'] ?? null) !== ($second['uses_spot'] ?? null)) {
                continue;
            }

            $firstRate = $this->mechanismRate($first);
            $secondRate = $this->mechanismRate($second);
            $until = $this->date($first['window_end'] ?? null);
            $from = $this->date($second['window_start'] ?? null);

            if ($firstRate === null || $secondRate === null || $until === null || $from === null
                || abs($firstRate - $secondRate) < 0.0001) {
                continue;
            }

            $label = ($first['uses_spot'] ?? false) ? 'Marginaali ' : 'Energia ';

            return [
                new CardReceiptLine($label.ContractCardCopy::dayMonth($until).' asti', $this->amount($firstRate), 'c/kWh'),
                new CardReceiptLine($label.ContractCardCopy::dayMonth($from).' alkaen', $this->amount($secondRate), 'c/kWh'),
            ];
        }

        return [];
    }

    /**
     * @param  array<string, float|null>  $rates
     * @param  array<string, mixed>  $cost
     * @return list<CardReceiptLine>
     */
    private function resetLines(array $rates, array $cost, PricingCategoryFacts $facts, ?string $metering): array
    {
        $reset = is_array($cost['reset_estimate'] ?? null) ? $cost['reset_estimate'] : [];

        $current = is_numeric($reset['current_period_energy_price'] ?? null)
            ? (float) $reset['current_period_energy_price']
            : $this->baseRate($rates, $metering);

        $until = $facts->nextReset?->subDay();
        $label = $until !== null
            ? 'Energia nyt, '.ContractCardCopy::dayMonth($until).' asti'
            : 'Energia nyt';

        $lines = [new CardReceiptLine($label, $this->amount($current), 'c/kWh')];

        // Only present when RESET_FORWARD_SHIFT_ENABLED produced a forward-shifted tail.
        // With the flag off the tail holds flat, and there is no second figure to show.
        if (is_numeric($reset['annual_equivalent_energy_price'] ?? null)) {
            $lines[] = new CardReceiptLine(
                'Loppuvuosi, arvio',
                $this->amount((float) $reset['annual_equivalent_energy_price']),
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
    private function spotLines(array $rates, array $cost): array
    {
        $lines = [];

        // The day average is the exact figure the total is built on: for General metering
        // (every active spot contract) the calculator prices the whole bucket at
        // `spot_price_day_avg + margin`. The night average appears in the Arvio popover.
        $baseline = $cost['spot_price_day_avg'] ?? null;
        if (is_numeric($baseline)) {
            $lines[] = new CardReceiptLine(
                'Pörssin keskihinta 12 kk',
                $this->amount((float) $baseline),
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
        if ($metering === 'Time' && $rates['day'] !== null && $rates['night'] !== null) {
            return [
                new CardReceiptLine('Päivä', $this->amount($rates['day']), 'c/kWh'),
                new CardReceiptLine('Yö', $this->amount($rates['night']), 'c/kWh'),
            ];
        }

        if ($metering === 'Season' && $rates['winter'] !== null && $rates['other'] !== null) {
            return [
                new CardReceiptLine('Talvi', $this->amount($rates['winter']), 'c/kWh'),
                new CardReceiptLine('Muu aika', $this->amount($rates['other']), 'c/kWh'),
            ];
        }

        $rate = $this->baseRate($rates, $metering);
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
    private function baseRate(array $rates, ?string $metering): ?float
    {
        return match ($metering) {
            'Time' => $rates['day'] ?? $rates['general'],
            'Season' => $rates['winter'] ?? $rates['general'],
            default => $rates['general'] ?? $rates['day'] ?? $rates['winter'],
        };
    }

    private function amount(?float $value): string
    {
        return number_format($value ?? 0.0, 2, ',', ' ');
    }
}
