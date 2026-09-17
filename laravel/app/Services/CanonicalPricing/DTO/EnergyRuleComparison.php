<?php

namespace App\Services\CanonicalPricing\DTO;

use App\Services\CanonicalPricing\MarketReset\DTO\ResetEstimate;
use App\Services\CanonicalPricing\SupplierAdjusted\DTO\SupplierAdjustedEstimate;

/** Paired comparison; actual price certainty is independent of normal-price certainty. */
final readonly class EnergyRuleComparison
{
    public const METHOD = 'source_energy_rules_v1';

    /** @param list<float> $signedMonthlyDifferences Annualized on a short-term comparison. */
    public function __construct(
        public array $signedMonthlyDifferences,
        public ?float $netDifference,
        public bool $actualEstimated,
        public bool $normalEstimated,
        public bool $normalHeld,
        public ?NormalEnergyProjection $projection = null,
        public bool $normalAvailable = true,
        public ?float $annualEquivalentEnergyPrice = null,
        public SupplierAdjustedEstimate|ResetEstimate|null $actualProjection = null,
        public ?array $currentNormalRates = null,
        // Billing fee variation on either side of the evaluated real window, not energy evidence.
        public bool $disclosedFeeChanges = false,
    ) {
        if ($annualEquivalentEnergyPrice !== null && ! is_finite($annualEquivalentEnergyPrice)) {
            throw new \InvalidArgumentException('Invalid annual equivalent.');
        }
        if ($currentNormalRates !== null) {
            $buckets = array_keys($currentNormalRates);
            sort($buckets);
            if (! in_array($buckets, [['energy_general'], ['energy_day', 'energy_night'], ['energy_seasonal_other', 'energy_seasonal_winter']], true)) {
                throw new \InvalidArgumentException('Invalid current normal tariff buckets.');
            }
            foreach ($currentNormalRates as $rate) {
                if ((! is_int($rate) && ! is_float($rate)) || ! is_finite((float) $rate) || $rate < 0) {
                    throw new \InvalidArgumentException('Invalid current normal rate.');
                }
            }
            if ($projection !== null && $currentNormalRates != $projection->currentRates) {
                throw new \InvalidArgumentException('Current normal rates disagree with projection.');
            }
        }
        if (! $normalAvailable) {
            if ($signedMonthlyDifferences !== [] || $netDifference !== null || $normalEstimated || $normalHeld || $projection !== null || $currentNormalRates !== null) {
                throw new \InvalidArgumentException('Unavailable normal comparison cannot carry measured benefits.');
            }

            return;
        }
        if (count($signedMonthlyDifferences) !== 12 || ! array_is_list($signedMonthlyDifferences)
            || $netDifference === null || ! is_finite($netDifference) || abs(array_sum($signedMonthlyDifferences) - $netDifference) > 0.000001) {
            throw new \InvalidArgumentException('Invalid paired energy comparison.');
        }
        foreach ($signedMonthlyDifferences as $difference) {
            if (! is_float($difference) || ! is_finite($difference)) {
                throw new \InvalidArgumentException('Invalid signed energy difference.');
            }
        }
    }

    public function toArray(): array
    {
        return [
            'method' => self::METHOD,
            'actual_estimated' => $this->actualEstimated,
            'normal_available' => $this->normalAvailable,
            'normal_estimated' => $this->normalEstimated,
            'normal_held' => $this->normalHeld,
            'signed_monthly_differences' => $this->signedMonthlyDifferences,
            'net_difference' => $this->netDifference,
            'annual_equivalent_energy_price' => $this->annualEquivalentEnergyPrice,
            'current_normal_rates' => $this->currentNormalRates ?? $this->projection?->currentRates,
            'actual_projection' => $this->actualProjection === null ? null : [
                'kind' => $this->actualProjection instanceof ResetEstimate ? 'reset' : 'supplier_adjusted',
                'estimate' => $this->actualProjection->toArray(),
            ],
            'projection' => $this->projection === null ? null : [
                'kind' => $this->projection->estimate instanceof ResetEstimate ? 'reset' : 'supplier_adjusted',
                'estimate' => $this->projection->estimate instanceof SupplierAdjustedEstimate
                    ? array_replace($this->projection->estimate->toArray(), [
                        'monthly_fee_assumption' => $this->disclosedFeeChanges ? 'disclosed_phases' : 'held_flat',
                    ])
                    : $this->projection->estimate->toArray(),
            ],
        ];
    }
}
