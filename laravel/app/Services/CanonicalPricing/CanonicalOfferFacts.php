<?php

namespace App\Services\CanonicalPricing;

use App\Services\CanonicalPricing\Enums\ContractComparability;

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
            $basisLabel = "koko {$months} kk sopimuskausi";
        } else {
            if (! is_numeric($calculatedCost['discount_savings_total'] ?? null)) {
                return null;
            }

            $months = 12;
            $benefit = (float) $calculatedCost['discount_savings_total'];
            $basisLabel = '12 kk vertailujakso';
        }

        if ($months <= 0 || $benefit <= self::MINIMUM_BENEFIT_EUR) {
            return null;
        }

        $benefitText = self::formatEuros($benefit).' / '.$months.' kk';

        return [
            'label' => 'Tarjous sisältyy laskettuun hintaan',
            'benefit_eur' => $benefit,
            'benefit_text' => $benefitText,
            'basis_months' => $months,
            'basis_label' => $basisLabel,
            'description' => 'Tarjous sisältyy laskettuun hintaan. Mitattu etu '.$benefitText.' ('.$basisLabel.').',
        ];
    }

    private static function formatEuros(float $value): string
    {
        $decimals = abs($value - round($value)) < 0.005 ? 0 : 2;

        return number_format($value, $decimals, ',', ' ').' €';
    }
}
