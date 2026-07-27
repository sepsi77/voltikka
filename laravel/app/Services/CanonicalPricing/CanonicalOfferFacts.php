<?php

namespace App\Services\CanonicalPricing;

use App\Services\CanonicalPricing\Enums\ComponentType;
use App\Services\CanonicalPricing\Enums\ComponentUnit;
use App\Services\CanonicalPricing\Enums\ContractComparability;
use Carbon\CarbonImmutable;

final class CanonicalOfferFacts
{
    private const MINIMUM_BENEFIT_EUR = 0.005;

    /**
     * Build public offer facts only from one canonical calculated outcome.
     *
     * @param  array<string, mixed>  $calculatedCost
     * @return array{label: string, benefit_eur: float, benefit_text: string, basis_months: int, basis_label: string, description: string}|null
     */
    public static function fromCalculatedCost(array $calculatedCost): ?array
    {
        if (($calculatedCost['pricing_basis'] ?? null) !== 'canonical'
            || ($calculatedCost['includes_discounts'] ?? false) !== true
            || ($calculatedCost['energy_package'] ?? null) !== null) {
            return null;
        }

        $comparability = ContractComparability::tryFrom((string) ($calculatedCost['comparability'] ?? ''));
        if ($comparability === null || ! $comparability->isListed()) {
            return null;
        }

        $termMonths = $calculatedCost['term_months'] ?? null;
        $term = $calculatedCost['contract_term'] ?? null;

        if (is_numeric($termMonths) && (int) $termMonths < 12) {
            if (! is_array($term)
                || ! is_numeric($term['months'] ?? null)
                || ! is_numeric($term['discount_savings_total'] ?? null)) {
                return null;
            }

            $months = (int) $term['months'];
            $benefit = (float) $term['discount_savings_total'];
            $basisLabel = "{$months} kuukauden sopimuskaudella";
        } else {
            if (! is_numeric($calculatedCost['discount_savings_total'] ?? null)) {
                return null;
            }

            $months = 12;
            $benefit = (float) $calculatedCost['discount_savings_total'];
            $basisLabel = '12 kuukauden vertailussa';
        }

        if ($months <= 0 || $benefit <= self::MINIMUM_BENEFIT_EUR) {
            return null;
        }

        $label = self::offerLabel($calculatedCost['offer_terms'] ?? null, $months);
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

    private static function offerLabel(mixed $rawTerms, int $contractBasisMonths): ?string
    {
        if (! is_array($rawTerms) || $rawTerms === [] || ! array_is_list($rawTerms)) {
            return null;
        }

        $terms = [];

        foreach ($rawTerms as $rawTerm) {
            if (! is_array($rawTerm)) {
                return null;
            }

            $components = self::componentPhrases($rawTerm['components'] ?? null);
            $timing = self::timingPhrase($rawTerm, $contractBasisMonths);

            if ($components === null || $timing === null) {
                return null;
            }

            $terms[] = self::joinPhrases($components).' '.$timing;
        }

        return implode('. ', $terms);
    }

    /** @return list<string>|null */
    private static function componentPhrases(mixed $rawComponents): ?array
    {
        if (! is_array($rawComponents) || $rawComponents === [] || ! array_is_list($rawComponents)) {
            return null;
        }

        $phrases = [];
        $seenTypes = [];

        foreach ($rawComponents as $component) {
            if (! is_array($component)
                || ! is_numeric($component['amount'] ?? null)
                || ! is_numeric($component['normal_amount'] ?? null)) {
                return null;
            }

            $type = ComponentType::tryFrom((string) ($component['component_type'] ?? ''));
            $unit = ComponentUnit::tryFrom((string) ($component['unit'] ?? ''));
            $amount = (float) $component['amount'];
            $normalAmount = (float) $component['normal_amount'];

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

    private static function timingPhrase(array $term, int $contractBasisMonths): ?string
    {
        $endKind = $term['end_kind'] ?? null;

        if ($endKind === 'after_months') {
            if (! is_numeric($term['duration_months'] ?? null)
                || ! is_numeric($term['starts_after_months'] ?? null)
                || ! is_numeric($term['ends_after_months'] ?? null)) {
                return null;
            }

            $months = (int) $term['duration_months'];
            $startsAfter = (int) $term['starts_after_months'];
            $endsAfter = (int) $term['ends_after_months'];
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

        $start = self::exactDate($term['starts_on'] ?? null);
        $end = self::exactDate($term['ends_on'] ?? null);

        if ($start === null || $end === null || $end->lessThan($start)) {
            return null;
        }

        if (($term['starts_at_window_start'] ?? null) === true) {
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
