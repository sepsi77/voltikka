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
 */
class CardReceiptLines
{
    private const MAX_LINES = 3;

    /**
     * @param array<string, float|null> $rates Resolved rates: general, day, night, winter, other, fee, margin.
     * @param array<string, mixed> $cost The `calculated_cost` payload.
     * @param array<string, mixed>|null $integrity The `pricing_integrity` payload.
     * @return list<CardReceiptLine>
     */
    public function build(
        array $rates,
        array $cost,
        ?array $integrity,
        PricingCategoryFacts $facts,
        ?string $metering,
    ): array {
        $lines = $this->energyLines($rates, $cost, $integrity, $facts, $metering);

        if ($rates['fee'] !== null) {
            $lines[] = new CardReceiptLine('Perusmaksu', $this->amount($rates['fee']), '€/kk');
        }

        return array_slice($lines, 0, self::MAX_LINES);
    }

    /**
     * @param array<string, float|null> $rates
     * @param array<string, mixed> $cost
     * @param array<string, mixed>|null $integrity
     * @return list<CardReceiptLine>
     */
    private function energyLines(
        array $rates,
        array $cost,
        ?array $integrity,
        PricingCategoryFacts $facts,
        ?string $metering,
    ): array {
        // A pre-published later price is the most useful thing the rows can say: both dates
        // and both prices, so the footer warning is backed by the breakdown.
        $scheduled = $this->scheduledChangeLines($integrity);
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
     * @param array<string, mixed>|null $integrity
     * @return list<CardReceiptLine>
     */
    private function scheduledChangeLines(?array $integrity): array
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
     * @param array<string, float|null> $rates
     * @param array<string, mixed> $cost
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
     * @param array<string, float|null> $rates
     * @param array<string, mixed> $cost
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
     * @param array<string, float|null> $rates
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
     * @param array<string, float|null> $rates
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
