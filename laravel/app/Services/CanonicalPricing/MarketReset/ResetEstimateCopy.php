<?php

namespace App\Services\CanonicalPricing\MarketReset;

use Carbon\CarbonImmutable;

/**
 * Finnish public copy for a market-reset annualised estimate, generated only from the typed
 * `reset_estimate` payload.
 *
 * No string from an interpretation `summary` ever reaches a user. Every sentence here is built
 * from an enum-like cadence value, two numbers, and a date, exactly like the deceptive-pricing
 * label copy.
 */
class ResetEstimateCopy
{
    /**
     * Short caption for the estimated 12-month equivalent shown next to the known
     * current-period price on a contract card. Null when there is no estimate to state.
     *
     * @param  array<string, mixed>|null  $reset
     */
    public static function cardEquivalent(?array $reset): ?string
    {
        $annual = self::price($reset['annual_equivalent_energy_price'] ?? null);

        if ($annual === null) {
            return null;
        }

        return '12 kk arvio '.$annual.' c/kWh';
    }

    /**
     * Tooltip that states the basis behind the card figure.
     *
     * @param  array<string, mixed>|null  $reset
     */
    public static function cardTooltip(?array $reset): ?string
    {
        if ($reset === null) {
            return null;
        }

        $current = self::price($reset['current_period_energy_price'] ?? null);
        $annual = self::price($reset['annual_equivalent_energy_price'] ?? null);

        if ($current === null || $annual === null) {
            return null;
        }

        $parts = [
            'Sopimuksen energianhinta tarkistetaan '.self::cadenceAdverb($reset['cadence'] ?? null).'.',
            $current.' c/kWh on nykyisen hintajakson tiedossa oleva hinta.',
            $annual.' c/kWh on arvio koko 12 kuukauden keskihinnasta, '.self::basisPhrase($reset).'.',
            'Tulevien jaksojen hintoja ei tiedetä, joten arvio ei ole hintalupaus.',
        ];

        return implode(' ', $parts);
    }

    /**
     * The one sentence the contract detail page prints under its receipt rows.
     *
     * It deliberately states ONLY what the surfaces above it do not. The card band
     * already names the reset cadence, the dated receipt rows already separate the
     * known current period from the estimated tail, and the hero price qualifier
     * already states the current price, its end date and the 12-month equivalent.
     * The old `detailNotice()` repeated all three in a boxed notice under the hero;
     * that duplication was removed in the Phase 4 composition pass. What is left
     * here is genuinely new: that future period prices are not known in advance,
     * when the estimated part starts, and which market data it reads.
     *
     * @param  array<string, mixed>|null  $reset
     */
    public static function receiptNote(?array $reset): ?string
    {
        if ($reset === null) {
            return null;
        }

        if (self::price($reset['annual_equivalent_energy_price'] ?? null) === null) {
            return null;
        }

        $tailStarts = self::monthLabel($reset['tail_starts'] ?? null);
        $subject = $tailStarts !== null
            ? 'Arvio '.$tailStarts.' alkaville jaksoille'
            : 'Arvio tuleville jaksoille';

        // "Sähköfutuurit" never appears without the plain-language gloss.
        $basis = match ($reset['basis'] ?? null) {
            'forward_curve_shift' => self::forwardBasisPhrase($reset),
            'spot_seasonal_index' => 'pörssisähkön usean vuoden kausivaihteluun, koska tukkumarkkinan ennakkohintoja ei ollut saatavilla',
            default => 'nykyisen hintajakson hintaan',
        };

        return 'Tulevien hintajaksojen hintoja ei tiedetä etukäteen. '.$subject.' perustuu '.$basis.'.';
    }

    /**
     * @param  array<string, mixed>  $reset
     */
    private static function forwardBasisPhrase(array $reset): string
    {
        $phrase = 'tukkumarkkinan ennakkohintoihin eli sähköfutuureihin';
        $tradeDate = self::dayLabel($reset['curve_trade_date'] ?? null);

        return $tradeDate !== null ? $phrase.' ('.$tradeDate.')' : $phrase;
    }

    /**
     * @param  array<string, mixed>  $reset
     */
    private static function basisPhrase(array $reset): string
    {
        $tradeDate = self::dayLabel($reset['curve_trade_date'] ?? null);

        if (($reset['basis'] ?? null) === 'forward_curve_shift') {
            $phrase = 'joka seuraa sähkön futuurihintojen kausivaihtelua';

            return $tradeDate !== null ? $phrase.' ('.$tradeDate.')' : $phrase;
        }

        if (($reset['basis'] ?? null) === 'spot_seasonal_index') {
            return 'joka seuraa pörssisähkön usean vuoden kausivaihtelua (futuurihintoja ei ollut saatavilla)';
        }

        return 'joka perustuu nykyisen jakson hintaan';
    }

    private static function cadenceAdverb(?string $cadence): string
    {
        return match ($cadence) {
            'monthly' => 'kuukausittain',
            'quarterly' => 'neljännesvuosittain',
            'seasonal' => 'kausittain',
            default => 'jaksoittain',
        };
    }

    private static function price(mixed $value): ?string
    {
        return is_numeric($value) ? number_format((float) $value, 2, ',', ' ') : null;
    }

    private static function monthLabel(mixed $value): ?string
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}$/', $value)) {
            return null;
        }

        $month = CarbonImmutable::createFromFormat('Y-m-d', $value.'-01');

        return $month->format('n').'/'.$month->format('Y');
    }

    private static function dayLabel(mixed $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value)->format('j.n.Y');
        } catch (\Throwable) {
            return null;
        }
    }
}
