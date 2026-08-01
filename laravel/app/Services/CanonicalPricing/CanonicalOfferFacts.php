<?php

namespace App\Services\CanonicalPricing;

use App\Services\CanonicalPricing\Enums\ComponentType;
use App\Services\CanonicalPricing\Enums\ComponentUnit;
use App\Services\ContractPricing\ContractPricingViewData;
use App\Services\ContractPricing\PricingFact;
use Carbon\CarbonImmutable;

final class CanonicalOfferFacts
{
    private const MINIMUM_BENEFIT_EUR = 0.005;

    /**
     * Build public offer facts only from one canonical calculated outcome.
     *
     * @return array{label: string, benefit_eur: float, benefit_text: string, basis_months: int, basis_label: string, description: string}|null
     */
    public static function fromPricing(ContractPricingViewData $pricing): ?array
    {
        $comparability = $pricing->comparability();
        if ($pricing->pricingBasis() !== 'canonical'
            || ! $pricing->includesDiscounts()
            || $pricing->energyPackage() !== null
            || $comparability === null
            || ! $comparability->isListed()) {
            return null;
        }

        $termMonths = $pricing->termMonths();
        $term = $pricing->contractTerm();

        if ($termMonths !== null && $termMonths < 12) {
            if ($term === null) {
                return null;
            }

            $months = $term->integer('months');
            $benefit = $term->number('discount_savings_total');
            if ($months === null || $benefit === null) {
                return null;
            }
            $basisLabel = "{$months} kuukauden sopimuskaudella";
        } else {
            $months = 12;
            $benefit = $pricing->discountSaving();
            $basisLabel = '12 kuukauden vertailussa';
        }

        if ($months <= 0 || $benefit <= self::MINIMUM_BENEFIT_EUR) {
            return null;
        }

        $label = self::offerLabel($pricing->offerTerms(), $months);
        if ($label === null) {
            return null;
        }

        $benefitText = self::formatEuros($benefit);

        return [
            'label' => $label,
            'benefit_eur' => $benefit,
            'benefit_text' => $benefitText,
            'basis_months' => $months,
            'basis_label' => $basisLabel,
            'description' => $label.'. Säästö '.$benefitText.' '.$basisLabel.'.',
        ];
    }

    /** @param array<string, mixed> $payload */
    public static function fromArray(array $payload): ?array
    {
        return self::fromPricing(ContractPricingViewData::fromArray($payload));
    }

    /** @param list<PricingFact> $rawTerms */
    private static function offerLabel(array $rawTerms, int $contractBasisMonths): ?string
    {
        if ($rawTerms === []) {
            return null;
        }

        $terms = [];

        foreach ($rawTerms as $rawTerm) {
            $components = self::componentPhrases($rawTerm->records('components'));
            $timing = self::timingPhrase($rawTerm, $contractBasisMonths);

            if ($components === null || $timing === null) {
                return null;
            }

            $terms[] = self::joinPhrases($components).' '.$timing;
        }

        return implode('. ', $terms);
    }

    /** @return list<string>|null */
    private static function componentPhrases(?array $rawComponents): ?array
    {
        if ($rawComponents === null || $rawComponents === []) {
            return null;
        }

        $phrases = [];
        $seenTypes = [];

        foreach ($rawComponents as $component) {
            $type = ComponentType::tryFrom($component->string('component_type') ?? '');
            $unit = ComponentUnit::tryFrom($component->string('unit') ?? '');
            $amount = $component->number('amount');
            $normalAmount = $component->number('normal_amount');

            if ($amount === null || $normalAmount === null) {
                return null;
            }

            if ($type === null
                || $unit === null
                || ! is_finite($amount)
                || ! is_finite($normalAmount)
                || $normalAmount <= $amount
                || isset($seenTypes[$type->value])) {
                return null;
            }

            $seenTypes[$type->value] = true;

            $name = match ($type) {
                ComponentType::MonthlyFee => $unit === ComponentUnit::EurPerMonth ? 'Perusmaksu' : null,
                ComponentType::EnergyGeneral => $unit === ComponentUnit::CentsPerKwh ? 'Energiahinta' : null,
                ComponentType::EnergyDay => $unit === ComponentUnit::CentsPerKwh ? 'Päivähinta' : null,
                ComponentType::EnergyNight => $unit === ComponentUnit::CentsPerKwh ? 'Yöhinta' : null,
                ComponentType::EnergySeasonalWinter => $unit === ComponentUnit::CentsPerKwh ? 'Talvipäivän hinta' : null,
                ComponentType::EnergySeasonalOther => $unit === ComponentUnit::CentsPerKwh ? 'Muun ajan hinta' : null,
                ComponentType::SpotMargin => $unit === ComponentUnit::CentsPerKwh ? 'Marginaali' : null,
                default => null,
            };

            if ($name === null) {
                return null;
            }

            $price = $unit === ComponentUnit::EurPerMonth
                ? self::formatMonthlyPrice($amount).' €/kk'
                : number_format($amount, 2, ',', ' ').' c/kWh';

            $phrases[] = $name.' '.$price;
        }

        return $phrases;
    }

    private static function timingPhrase(PricingFact $term, int $contractBasisMonths): ?string
    {
        $endKind = $term->string('end_kind');

        if ($endKind === 'after_months') {
            $months = $term->integer('duration_months');
            $startsAfter = $term->integer('starts_after_months');
            $endsAfter = $term->integer('ends_after_months');
            if ($months === null || $startsAfter === null || $endsAfter === null) {
                return null;
            }
            if ($months <= 0 || $startsAfter < 0 || $endsAfter <= $startsAfter || $endsAfter - $startsAfter !== $months) {
                return null;
            }

            if ($startsAfter === 0) {
                if ($contractBasisMonths < 12 && $months === $contractBasisMonths) {
                    return "koko {$months} kk sopimuskauden";
                }

                return $months === 1
                    ? 'ensimmäisen kuukauden'
                    : "ensimmäiset {$months} kk";
            }

            $firstMonth = $startsAfter + 1;

            return $firstMonth === $endsAfter
                ? "{$firstMonth}. kuukauden"
                : "kuukaudet {$firstMonth}–{$endsAfter}";
        }

        if ($endKind !== 'date') {
            return null;
        }

        $start = self::exactDate($term->string('starts_on'));
        $end = self::exactDate($term->string('ends_on'));

        if ($start === null || $end === null || $end->lessThan($start)) {
            return null;
        }

        if ($term->boolean('starts_at_window_start') === true) {
            return $end->format('j.n.Y').' asti';
        }

        return 'aikavälillä '.$start->format('j.n.Y').'–'.$end->format('j.n.Y');
    }

    /** @param list<string> $phrases */
    private static function joinPhrases(array $phrases): string
    {
        if (count($phrases) === 1) {
            return $phrases[0];
        }

        $last = self::lowerFirst((string) array_pop($phrases));
        $phrases = array_map(static fn (string $phrase): string => self::lowerFirst($phrase), $phrases);
        $first = array_shift($phrases);

        return ucfirst((string) $first)
            .($phrases === [] ? ' ja ' : ', '.implode(', ', $phrases).' ja ')
            .$last;
    }

    private static function exactDate(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }

        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $value, 'Europe/Helsinki');
        } catch (\Throwable) {
            return null;
        }

        return $date !== false && $date->toDateString() === $value ? $date : null;
    }

    private static function lowerFirst(string $value): string
    {
        return mb_strtolower(mb_substr($value, 0, 1)).mb_substr($value, 1);
    }

    private static function formatMonthlyPrice(float $value): string
    {
        $decimals = abs($value - round($value)) < 0.005 ? 0 : 2;

        return number_format($value, $decimals, ',', ' ');
    }

    private static function formatEuros(float $value): string
    {
        $decimals = abs($value - round($value)) < 0.005 ? 0 : 2;

        return number_format($value, $decimals, ',', ' ').' €';
    }
}
