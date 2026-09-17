<?php

namespace App\Services\ContractPricing;

use App\Services\CanonicalPricing\DTO\CanonicalPricingOutcome;
use App\Services\CanonicalPricing\Enums\BoundaryKind;
use App\Services\CanonicalPricing\Enums\ComponentType;
use App\Services\CanonicalPricing\Enums\ComponentUnit;
use App\Services\CanonicalPricing\Enums\ContractComparability;
use App\Services\CanonicalPricing\Enums\EnergyPriceRuleKind;
use App\Services\CanonicalPricing\Enums\EstimateMethod;
use App\Services\CanonicalPricing\Enums\PhaseKind;
use App\Services\CanonicalPricing\ForwardPremium\PremiumEstimate;
use App\Services\CanonicalPricing\ForwardPremium\PremiumFamily;
use App\Services\CanonicalPricing\ForwardPremium\PremiumVatBasis;
use App\Services\CanonicalPricing\MarketReset\Enums\ResetEstimateBasis;
use App\Services\CanonicalPricing\SpotForward\Enums\SpotEstimateBasis;
use App\Services\CanonicalPricing\SupplierAdjusted\Enums\PriceEpisodeEvidenceBasis;
use App\Services\CanonicalPricing\SupplierAdjusted\Enums\SupplierAdjustedEstimateBasis;
use App\Services\DTO\ContractPricingResult;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Immutable consumer view of one existing calculated-cost payload.
 */
final readonly class ContractPricingViewData
{
    private const RATE_KEYS = [
        'monthly_fixed_fee',
        'spot_price_margin',
        'general_kwh_price',
        'nighttime_kwh_price',
        'daytime_kwh_price',
        'seasonal_winter_day_kwh_price',
        'seasonal_other_kwh_price',
        'spot_price_day_avg',
        'spot_price_night_avg',
    ];

    private function __construct(
        private array $payload,
        private ?float $total,
        private ?float $averageMonthlyCost,
        private array $monthlyCosts,
        private array $rates,
        private bool $spot,
        private ?float $discountSaving,
        private bool $includesDiscounts,
        private ?string $pricingBasis,
        private ?ContractComparability $comparability,
        private bool $estimate,
        private ?EstimateMethod $estimateMethod,
        private ?PricingFact $energyPackage,
        private ?PricingFact $contractTerm,
        private ?PricingFact $consumptionEffect,
        private ?PricingFact $resetEstimate,
        private ?PricingFact $supplierAdjustedEstimate,
        private ?PricingFact $spotEstimate,
        private array $phases,
        private array $offerTerms,
        private ?PricingFact $energyRuleComparison,
    ) {}

    public static function fromCanonicalOutcome(CanonicalPricingOutcome $outcome): self
    {
        return self::fromArray($outcome->toCalculatedCostArray());
    }

    public static function fromLegacyResult(ContractPricingResult $result): self
    {
        return self::fromArray($result->toArray());
    }

    /** @param array<string, mixed> $payload */
    public static function fromArray(array $payload): self
    {
        foreach ([
            'total_cost', 'avg_monthly_cost', 'monthly_costs', ...self::RATE_KEYS,
            'is_spot_contract', 'base_total_cost', 'base_avg_monthly_cost',
            'base_monthly_costs', 'discount_savings_total',
            'monthly_discount_savings', 'includes_discounts',
        ] as $key) {
            self::requireKey($payload, $key, 'calculated_cost');
        }

        $total = self::nullableFiniteNumber($payload['total_cost'], 'calculated_cost.total_cost');
        $average = self::nullableFiniteNumber($payload['avg_monthly_cost'], 'calculated_cost.avg_monthly_cost');
        $monthlyCosts = self::finiteNumberList($payload['monthly_costs'], 'calculated_cost.monthly_costs');
        self::nullableFiniteNumber($payload['base_total_cost'], 'calculated_cost.base_total_cost');
        self::nullableFiniteNumber($payload['base_avg_monthly_cost'], 'calculated_cost.base_avg_monthly_cost');
        if ($payload['base_monthly_costs'] !== null) {
            self::finiteNumberList($payload['base_monthly_costs'], 'calculated_cost.base_monthly_costs');
        }
        $discountSaving = ($payload['energy_rule_comparison']['normal_available'] ?? true) === false
            ? self::nullableFiniteNumber($payload['discount_savings_total'], 'calculated_cost.discount_savings_total')
            : self::finiteNumber($payload['discount_savings_total'], 'calculated_cost.discount_savings_total');
        self::finiteNumberList($payload['monthly_discount_savings'], 'calculated_cost.monthly_discount_savings');
        $spot = self::boolean($payload['is_spot_contract'], 'calculated_cost.is_spot_contract');
        $includesDiscounts = self::boolean($payload['includes_discounts'], 'calculated_cost.includes_discounts');

        $rates = [];
        foreach (self::RATE_KEYS as $key) {
            $rates[$key] = self::nullableFiniteNumber($payload[$key], 'calculated_cost.'.$key);
        }

        $pricingBasis = null;
        if (array_key_exists('pricing_basis', $payload)) {
            $pricingBasis = self::nullableNonEmptyString($payload['pricing_basis'], 'calculated_cost.pricing_basis');
            if ($pricingBasis !== null && ! in_array($pricingBasis, ['canonical', 'legacy'], true)) {
                throw new InvalidArgumentException('calculated_cost.pricing_basis is not supported.');
            }
        }

        $comparability = null;
        $estimate = false;
        $estimateMethod = null;
        $energyPackage = null;
        $contractTerm = null;
        $consumptionEffect = null;
        $resetEstimate = null;
        $supplierAdjustedEstimate = null;
        $spotEstimate = null;
        $phases = [];
        $offerTerms = [];
        $energyRuleComparison = null;

        if ($pricingBasis === 'canonical') {
            foreach ([
                'comparability', 'is_estimate', 'estimate_method', 'term_months',
                'energy_package', 'contract_term', 'phase_breakdown', 'offer_terms',
                'structured_only_total', 'consumption_effect', 'assumptions', 'reset_estimate',
                'supplier_adjusted_estimate', 'spot_estimate',
            ] as $key) {
                self::requireKey($payload, $key, 'canonical calculated_cost');
            }

            $comparabilityValue = self::nonEmptyString($payload['comparability'], 'calculated_cost.comparability');
            $comparability = ContractComparability::tryFrom($comparabilityValue)
                ?? throw new InvalidArgumentException('calculated_cost.comparability is not supported.');
            $estimate = self::boolean($payload['is_estimate'], 'calculated_cost.is_estimate');
            $methodValue = self::nonEmptyString($payload['estimate_method'], 'calculated_cost.estimate_method');
            $estimateMethod = EstimateMethod::tryFrom($methodValue)
                ?? throw new InvalidArgumentException('calculated_cost.estimate_method is not supported.');

            self::nullablePositiveInteger($payload['term_months'], 'calculated_cost.term_months');
            self::nullableFiniteNumber($payload['structured_only_total'], 'calculated_cost.structured_only_total');
            self::stringList($payload['assumptions'], 'calculated_cost.assumptions');

            $energyPackage = self::optionalRecord(
                $payload['energy_package'],
                'calculated_cost.energy_package',
                self::validatePackage(...),
            );
            $contractTerm = self::optionalRecord(
                $payload['contract_term'],
                'calculated_cost.contract_term',
                function (array $record, string $path) use ($payload): void {
                    self::validateContractTerm($record, $path);
                    if (($payload['energy_rule_comparison'] ?? null) === null
                        && ($record['base_total_cost'] === null || $record['discount_savings_total'] === null || $record['discount_savings_total'] < 0)) {
                        throw new InvalidArgumentException($path.' requires nonnegative legacy savings and complete normal facts.');
                    }
                },
            );
            $consumptionEffect = self::optionalRecord(
                $payload['consumption_effect'],
                'calculated_cost.consumption_effect',
                self::validateConsumptionEffect(...),
            );
            $resetEstimate = self::optionalRecord(
                $payload['reset_estimate'],
                'calculated_cost.reset_estimate',
                self::validateResetEstimate(...),
            );
            $supplierAdjustedEstimate = self::optionalRecord(
                $payload['supplier_adjusted_estimate'],
                'calculated_cost.supplier_adjusted_estimate',
                self::validateSupplierAdjustedEstimate(...),
            );
            $spotEstimate = self::optionalRecord(
                $payload['spot_estimate'],
                'calculated_cost.spot_estimate',
                self::validateSpotEstimate(...),
            );
            $phases = self::recordList($payload['phase_breakdown'], 'calculated_cost.phase_breakdown', self::validatePhase(...));
            $offerTerms = self::recordList($payload['offer_terms'], 'calculated_cost.offer_terms', self::validateOfferTerm(...));

            $energyRuleComparison = self::optionalRecord($payload['energy_rule_comparison'] ?? null, 'calculated_cost.energy_rule_comparison', self::validateEnergyRuleComparison(...));
            if ($energyRuleComparison !== null) {
                self::validateEnergyRuleTotals($payload, $energyRuleComparison);
            } elseif ($estimateMethod === EstimateMethod::SourceEnergyRules) {
                throw new InvalidArgumentException('Source energy estimates require paired provenance.');
            }

            if ($comparability === ContractComparability::TermPriceOnly) {
                if ($contractTerm === null) {
                    throw new InvalidArgumentException('term_price_only requires calculated_cost.contract_term.');
                }
                $months = $contractTerm->integer('months');
                if ($months === null || $months <= 0) {
                    throw new InvalidArgumentException('term_price_only requires positive contract term months.');
                }
            }

            if ($comparability === ContractComparability::BaseOnlyHybrid) {
                $supportedHybridMethods = [
                    EstimateMethod::HybridBaseOnly,
                    EstimateMethod::ForwardCurveSpot,
                    EstimateMethod::Rolling365Spot,
                    EstimateMethod::SourceEnergyRules,
                    EstimateMethod::SupplierAdjustedForwardCurveShift,
                    EstimateMethod::SupplierAdjustedForwardPremium,
                    EstimateMethod::SupplierAdjustedSpotSeasonalIndex,
                    EstimateMethod::HoldCurrentSupplierPrice,
                    EstimateMethod::RecurringForwardPremium,
                    EstimateMethod::HoldCurrentRecurringPrice,
                    EstimateMethod::RecurringForwardCurveShift,
                    EstimateMethod::RecurringSpotSeasonalIndex,
                ];
                if (! $estimate || ! in_array($estimateMethod, $supportedHybridMethods, true)) {
                    throw new InvalidArgumentException('base_only_hybrid requires a supported Hybrid estimate method.');
                }
                if (in_array($estimateMethod, [EstimateMethod::ForwardCurveSpot, EstimateMethod::Rolling365Spot], true)) {
                    self::validateHybridSpotTimeline($payload, $estimateMethod);
                }
                if ($consumptionEffect !== null && $consumptionEffect->boolean('present') !== true) {
                    throw new InvalidArgumentException('A base-only Hybrid consumption-effect record must be present when supplied.');
                }
            }

            if (! $comparability->isListed()) {
                if ($total !== null || $average !== null) {
                    throw new InvalidArgumentException('Excluded canonical pricing must not expose a total.');
                }
                foreach ($rates as $rate) {
                    if ($rate !== null) {
                        throw new InvalidArgumentException('Excluded canonical pricing must not expose public rates.');
                    }
                }
                if ($energyPackage !== null || $offerTerms !== []) {
                    throw new InvalidArgumentException('Excluded canonical pricing must not expose package or offer facts.');
                }
            }
        }

        return new self(
            payload: $payload,
            total: $total,
            averageMonthlyCost: $average,
            monthlyCosts: $monthlyCosts,
            rates: $rates,
            spot: $spot,
            discountSaving: $discountSaving,
            includesDiscounts: $includesDiscounts,
            pricingBasis: $pricingBasis,
            comparability: $comparability,
            estimate: $estimate,
            estimateMethod: $estimateMethod,
            energyPackage: $energyPackage,
            contractTerm: $contractTerm,
            consumptionEffect: $consumptionEffect,
            resetEstimate: $resetEstimate,
            supplierAdjustedEstimate: $supplierAdjustedEstimate,
            spotEstimate: $spotEstimate,
            phases: $phases,
            offerTerms: $offerTerms,
            energyRuleComparison: $energyRuleComparison,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->payload;
    }

    public function total(): ?float
    {
        return $this->total;
    }

    public function averageMonthlyCost(): ?float
    {
        return $this->averageMonthlyCost;
    }

    /** @return list<float> */
    public function monthlyCosts(): array
    {
        return $this->monthlyCosts;
    }

    public function baseTotal(): ?float
    {
        return $this->payload['base_total_cost'] === null ? null : (float) $this->payload['base_total_cost'];
    }

    public function baseAverageMonthlyCost(): ?float
    {
        return $this->payload['base_avg_monthly_cost'] === null ? null : (float) $this->payload['base_avg_monthly_cost'];
    }

    /** @return list<float>|null */
    public function baseMonthlyCosts(): ?array
    {
        return $this->payload['base_monthly_costs'] === null
            ? null
            : array_map(static fn (int|float $value): float => (float) $value, $this->payload['base_monthly_costs']);
    }

    /** @return list<float> */
    public function monthlyDiscountSavings(): array
    {
        return array_map(static fn (int|float $value): float => (float) $value, $this->payload['monthly_discount_savings']);
    }

    public function monthlyFixedFee(): ?float
    {
        return $this->rates['monthly_fixed_fee'];
    }

    public function spotPriceMargin(): ?float
    {
        return $this->rates['spot_price_margin'];
    }

    public function generalKwhPrice(): ?float
    {
        return $this->rates['general_kwh_price'];
    }

    public function nighttimeKwhPrice(): ?float
    {
        return $this->rates['nighttime_kwh_price'];
    }

    public function daytimeKwhPrice(): ?float
    {
        return $this->rates['daytime_kwh_price'];
    }

    public function seasonalWinterDayKwhPrice(): ?float
    {
        return $this->rates['seasonal_winter_day_kwh_price'];
    }

    public function seasonalOtherKwhPrice(): ?float
    {
        return $this->rates['seasonal_other_kwh_price'];
    }

    public function spotPriceDayAverage(): ?float
    {
        return $this->rates['spot_price_day_avg'];
    }

    public function spotPriceNightAverage(): ?float
    {
        return $this->rates['spot_price_night_avg'];
    }

    public function isSpotContract(): bool
    {
        return $this->spot;
    }

    public function discountSaving(): ?float
    {
        return $this->discountSaving;
    }

    public function includesDiscounts(): bool
    {
        return $this->includesDiscounts;
    }

    public function pricingBasis(): ?string
    {
        return $this->pricingBasis;
    }

    public function comparability(): ?ContractComparability
    {
        return $this->comparability;
    }

    public function isEstimate(): bool
    {
        return $this->estimate;
    }

    public function estimateMethod(): ?EstimateMethod
    {
        return $this->estimateMethod;
    }

    public function termMonths(): ?int
    {
        $months = $this->payload['term_months'] ?? null;

        return is_int($months) ? $months : null;
    }

    public function structuredOnlyTotal(): ?float
    {
        $total = $this->payload['structured_only_total'] ?? null;

        return is_int($total) || is_float($total) ? (float) $total : null;
    }

    /** @return list<string> */
    public function assumptions(): array
    {
        $assumptions = $this->payload['assumptions'] ?? [];

        return is_array($assumptions) ? $assumptions : [];
    }

    public function energyPackage(): ?PricingFact
    {
        return $this->energyPackage;
    }

    public function contractTerm(): ?PricingFact
    {
        return $this->contractTerm;
    }

    public function consumptionEffect(): ?PricingFact
    {
        return $this->consumptionEffect;
    }

    public function resetEstimate(): ?PricingFact
    {
        return $this->resetEstimate;
    }

    public function supplierAdjustedEstimate(): ?PricingFact
    {
        return $this->supplierAdjustedEstimate;
    }

    public function spotEstimate(): ?PricingFact
    {
        return $this->spotEstimate;
    }

    /** @return list<PricingFact> */
    public function phases(): array
    {
        return $this->phases;
    }

    /** @return list<PricingFact> */
    public function offerTerms(): array
    {
        return $this->offerTerms;
    }

    public function energyRuleComparison(): ?PricingFact
    {
        return $this->energyRuleComparison;
    }

    public function benefitIsEstimate(): bool
    {
        if ($this->energyRuleComparison === null
            || (! $this->energyRuleComparison->boolean('actual_estimated') && ! $this->energyRuleComparison->boolean('normal_estimated'))) {
            return false;
        }
        // Shared energy forecasts cancel for fee-only offers. Only a genuine energy
        // offer exposes its benefit to that uncertainty, including model floors.
        foreach ($this->offerTerms as $term) {
            foreach ($term->records('components') ?? [] as $component) {
                if (ComponentType::tryFrom($component->string('component_type') ?? '')?->isPerKwhEnergy()) {
                    return true;
                }
            }
        }

        return false;
    }

    private static function validateEnergyRuleComparison(array $record, string $path): void
    {
        $keys = ['method', 'actual_estimated', 'normal_available', 'normal_estimated', 'normal_held', 'signed_monthly_differences', 'net_difference', 'annual_equivalent_energy_price', 'current_normal_rates', 'projection', 'actual_projection'];
        if (count($record) !== count($keys) || array_diff($keys, array_keys($record)) !== [] || $record['method'] !== 'source_energy_rules_v1') {
            throw new InvalidArgumentException($path.' has invalid method or fields.');
        }
        foreach (['actual_estimated', 'normal_available', 'normal_estimated', 'normal_held'] as $key) {
            self::boolean($record[$key], $path.'.'.$key);
        }
        self::nullableFiniteNumber($record['annual_equivalent_energy_price'], $path.'.annual_equivalent_energy_price');
        $monthly = self::finiteNumberList($record['signed_monthly_differences'], $path.'.signed_monthly_differences');
        $net = self::nullableFiniteNumber($record['net_difference'], $path.'.net_difference');
        if ($record['actual_projection'] !== null) {
            if (! $record['actual_estimated']) {
                throw new InvalidArgumentException($path.' asserts an actual projection for an exact price.');
            }
            self::validateEnergyProjection($record['actual_projection'], $path.'.actual_projection');
        }
        if (! $record['normal_available']) {
            if ($monthly !== [] || $net !== null || $record['normal_estimated'] || $record['normal_held'] || $record['projection'] !== null || $record['current_normal_rates'] !== null) {
                throw new InvalidArgumentException($path.' asserts unavailable normal facts.');
            }

            return;
        }
        if (count($monthly) !== 12 || $net === null || abs(array_sum($monthly) - $net) > 0.00001 || ($record['normal_held'] && ! $record['normal_estimated'])) {
            throw new InvalidArgumentException($path.' has inconsistent signed differences.');
        }
        if ($record['projection'] !== null) {
            self::validateEnergyProjection($record['projection'], $path.'.projection');
        }
        $rates = $record['current_normal_rates'];
        if ($rates === null && $record['projection'] === null) {
            return;
        }
        if (! is_array($rates) || $rates === []) {
            throw new InvalidArgumentException($path.' lacks normal rates.');
        }
        $buckets = array_keys($rates);
        sort($buckets);
        if (! in_array($buckets, [['energy_general'], ['energy_day', 'energy_night'], ['energy_seasonal_other', 'energy_seasonal_winter']], true)) {
            throw new InvalidArgumentException($path.' has incomplete normal tariff buckets.');
        }
        if ($record['normal_held'] && $record['projection'] !== null && $record['projection']['estimate']['basis'] !== 'hold_flat') {
            throw new InvalidArgumentException($path.' has inconsistent normal hold provenance.');
        }
        foreach ($rates as $bucket => $rate) {
            if (! is_string($bucket) || ! (ComponentType::tryFrom($bucket)?->isPerKwhEnergy() ?? false) || self::finiteNumber($rate, $path.'.current_normal_rates') < 0) {
                throw new InvalidArgumentException($path.' has invalid normal rates.');
            }
        }
        if ($record['projection'] !== null) {
            $projection = $record['projection'];
            $current = $projection['estimate'][$projection['kind'] === 'reset' ? 'current_period_energy_price' : 'current_energy_price'];
            $representative = count($rates) === 1 ? reset($rates) : null;
            if ($projection['kind'] === 'supplier_adjusted') {
                $representative ??= isset($rates['energy_day'])
                    ? ($rates['energy_day'] * 15 + $rates['energy_night'] * 9) / 24
                    : ($rates['energy_seasonal_winter'] * 5 + $rates['energy_seasonal_other'] * 7) / 12;
            }
            if (($representative !== null && abs($representative - $current) > 0.0001)
                || ($representative === null && ($current < min($rates) - 0.0001 || $current > max($rates) + 0.0001))) {
                throw new InvalidArgumentException($path.' current normal rates disagree with projection.');
            }
        }
    }

    private static function validateEnergyProjection(mixed $projection, string $path): void
    {
        if (! is_array($projection) || count($projection) !== 2 || ! in_array($projection['kind'] ?? null, ['reset', 'supplier_adjusted'], true) || ! is_array($projection['estimate'] ?? null)) {
            throw new InvalidArgumentException($path.' has invalid projection.');
        }
        if ($projection['kind'] === 'reset') {
            if (! in_array($projection['estimate']['cadence'] ?? null, ['monthly', 'quarterly', 'seasonal', 'other'], true)) {
                throw new InvalidArgumentException($path.' has invalid reset cadence.');
            }
            self::validateResetEstimate($projection['estimate'], $path.'.estimate');
        } else {
            self::validateSupplierAdjustedEstimate($projection['estimate'], $path.'.estimate');
        }
    }

    private static function validateEnergyRuleTotals(array $payload, PricingFact $comparison): void
    {
        $normal = $comparison->boolean('normal_available');
        $net = $comparison->number('net_difference');
        $monthly = $comparison->toArray()['signed_monthly_differences'];
        if ($comparison->boolean('actual_estimated') !== ($payload['estimate_method'] === EstimateMethod::SourceEnergyRules->value)
            || ($comparison->boolean('actual_estimated') && ! $payload['is_estimate'])) {
            throw new InvalidArgumentException('Energy-rule actual certainty disagrees with method.');
        }
        if ($payload['total_cost'] === null || count($payload['monthly_costs']) !== 12
            || abs(array_sum($payload['monthly_costs']) - $payload['total_cost']) > 0.00001
            || abs($payload['avg_monthly_cost'] * 12 - $payload['total_cost']) > 0.00001) {
            throw new InvalidArgumentException('Energy-rule actual totals do not reconcile.');
        }
        if ($normal) {
            if ($payload['base_total_cost'] === null || ! is_array($payload['base_monthly_costs']) || count($payload['base_monthly_costs']) !== 12
                || abs($payload['base_total_cost'] - $payload['total_cost'] - $net) > 0.00001
                || abs($payload['base_avg_monthly_cost'] * 12 - $payload['base_total_cost']) > 0.00001
                || $payload['discount_savings_total'] === null || abs($payload['discount_savings_total'] - $net) > 0.00001) {
                throw new InvalidArgumentException('Energy-rule normal totals do not reconcile.');
            }
            foreach ($monthly as $index => $difference) {
                if (abs($payload['base_monthly_costs'][$index] - $payload['monthly_costs'][$index] - $difference) > 0.00001) {
                    throw new InvalidArgumentException('Energy-rule monthly differences do not reconcile.');
                }
            }
        } elseif ($payload['base_total_cost'] !== null || $payload['base_avg_monthly_cost'] !== null || ! in_array($payload['base_monthly_costs'], [null, []], true) || $payload['discount_savings_total'] !== null || $payload['offer_terms'] !== []) {
            throw new InvalidArgumentException('Unavailable normal comparison carries benefit facts.');
        }
        if ($payload['monthly_discount_savings'] != $monthly || $payload['includes_discounts'] !== ($normal && $net > 0 && $payload['offer_terms'] !== [])) {
            throw new InvalidArgumentException('Energy-rule benefit qualification is inconsistent.');
        }
        $term = $payload['contract_term'];
        if ($term !== null) {
            $factor = 12 / $term['months'];
            if ($payload['term_months'] !== $term['months'] || abs($term['total_cost'] * $factor - $payload['total_cost']) > 0.00001
                || ($normal && ($term['base_total_cost'] === null || $term['discount_savings_total'] === null || abs($term['base_total_cost'] * $factor - $payload['base_total_cost']) > 0.00001 || abs($term['discount_savings_total'] * $factor - $net) > 0.00001))
                || (! $normal && ($term['base_total_cost'] !== null || $term['discount_savings_total'] !== null))) {
                throw new InvalidArgumentException('Energy-rule real-term totals do not reconcile.');
            }
        }
    }

    private static function validatePackage(array $record, string $path): void
    {
        foreach (['monthly_fee_eur', 'included_kwh', 'allowance_cadence', 'excess_rate_cents_per_kwh'] as $key) {
            self::requireKey($record, $key, $path);
        }
        if (self::finiteNumber($record['monthly_fee_eur'], $path.'.monthly_fee_eur') < 0
            || self::finiteNumber($record['included_kwh'], $path.'.included_kwh') <= 0
            || self::finiteNumber($record['excess_rate_cents_per_kwh'], $path.'.excess_rate_cents_per_kwh') <= 0) {
            throw new InvalidArgumentException($path.' contains invalid package amounts.');
        }
        if (self::nonEmptyString($record['allowance_cadence'], $path.'.allowance_cadence') !== 'monthly') {
            throw new InvalidArgumentException($path.'.allowance_cadence is not supported.');
        }
    }

    private static function validateContractTerm(array $record, string $path): void
    {
        foreach (['months', 'total_cost', 'base_total_cost', 'discount_savings_total'] as $key) {
            self::requireKey($record, $key, $path);
        }
        self::positiveInteger($record['months'], $path.'.months');
        self::finiteNumber($record['total_cost'], $path.'.total_cost');
        self::nullableFiniteNumber($record['base_total_cost'], $path.'.base_total_cost');
        self::nullableFiniteNumber($record['discount_savings_total'], $path.'.discount_savings_total');
        if (($record['base_total_cost'] === null) !== ($record['discount_savings_total'] === null)) {
            throw new InvalidArgumentException($path.' has incomplete normal facts.');
        }
    }

    private static function validateConsumptionEffect(array $record, string $path): void
    {
        foreach (['present', 'applies_to', 'expected_cents_per_kwh', 'typical_min_cents_per_kwh', 'typical_max_cents_per_kwh', 'hard_min_cents_per_kwh', 'hard_max_cents_per_kwh', 'uncapped'] as $key) {
            self::requireKey($record, $key, $path);
        }
        self::boolean($record['present'], $path.'.present');
        self::nonEmptyString($record['applies_to'], $path.'.applies_to');
        foreach (['expected_cents_per_kwh', 'typical_min_cents_per_kwh', 'typical_max_cents_per_kwh', 'hard_min_cents_per_kwh', 'hard_max_cents_per_kwh'] as $key) {
            self::nullableFiniteNumber($record[$key], $path.'.'.$key);
        }
        if ($record['uncapped'] !== null) {
            self::boolean($record['uncapped'], $path.'.uncapped');
        }
    }

    private static function validateResetEstimate(array $record, string $path): void
    {
        foreach (['basis', 'beta', 'cadence', 'current_period_energy_price', 'annual_equivalent_energy_price', 'reference_kind', 'reference_price', 'curve_trade_date', 'reference_trade_date', 'anchor_period', 'tail_starts', 'higher_confidence', 'flags'] as $key) {
            self::requireKey($record, $key, $path);
        }
        $basis = self::nonEmptyString($record['basis'], $path.'.basis');
        if ($basis === ResetEstimateBasis::ForwardPremium->value || array_key_exists('premium', $record)) {
            self::validateForwardPremium($record, $path, 'recurring_forward_premium_v1');
        }
        if (ResetEstimateBasis::tryFrom($basis) === null) {
            throw new InvalidArgumentException($path.'.basis is not supported.');
        }
        if (self::finiteNumber($record['beta'], $path.'.beta') < 0) {
            throw new InvalidArgumentException($path.'.beta must not be negative.');
        }
        self::nonEmptyString($record['cadence'], $path.'.cadence');
        self::finiteNumber($record['current_period_energy_price'], $path.'.current_period_energy_price');
        foreach (['annual_equivalent_energy_price', 'reference_price'] as $key) {
            self::nullableFiniteNumber($record[$key], $path.'.'.$key);
        }
        foreach (['reference_kind', 'anchor_period', 'tail_starts'] as $key) {
            self::nullableNonEmptyString($record[$key], $path.'.'.$key);
        }
        foreach (['curve_trade_date', 'reference_trade_date'] as $key) {
            $date = self::nullableNonEmptyString($record[$key], $path.'.'.$key);
            if ($date !== null) {
                self::date($date, $path.'.'.$key);
            }
        }
        self::boolean($record['higher_confidence'], $path.'.higher_confidence');
        self::stringList($record['flags'], $path.'.flags');
    }

    private static function validateSpotEstimate(array $record, string $path): void
    {
        foreach (['basis', 'shape', 'current_curve_trade_date', 'future_curve_trade_date', 'months', 'annual_equivalent_base_price', 'annual_equivalent_day_price', 'annual_equivalent_night_price', 'confidence', 'higher_confidence', 'flags'] as $key) {
            self::requireKey($record, $key, $path);
        }
        $basis = SpotEstimateBasis::tryFrom(self::nonEmptyString($record['basis'], $path.'.basis'));
        if ($basis === null) {
            throw new InvalidArgumentException($path.'.basis is not supported.');
        }
        if (! is_array($record['shape'])) {
            throw new InvalidArgumentException($path.'.shape must be an array.');
        }
        foreach (['overall_price', 'day_price', 'night_price', 'day_offset', 'night_offset', 'period_start', 'period_end'] as $key) {
            self::requireKey($record['shape'], $key, $path.'.shape');
        }
        foreach (['overall_price', 'day_price', 'night_price', 'day_offset', 'night_offset'] as $key) {
            self::nullableFiniteNumber($record['shape'][$key], $path.'.shape.'.$key);
        }
        foreach (['period_start', 'period_end'] as $key) {
            $date = self::nullableNonEmptyString($record['shape'][$key], $path.'.shape.'.$key);
            if ($date !== null) {
                self::date($date, $path.'.shape.'.$key);
            }
        }
        foreach (['current_curve_trade_date', 'future_curve_trade_date'] as $key) {
            $date = self::nullableNonEmptyString($record[$key], $path.'.'.$key);
            if ($date !== null) {
                self::date($date, $path.'.'.$key);
            }
        }
        self::recordList($record['months'], $path.'.months', function (array $month, string $monthPath): void {
            foreach (['month', 'base_price', 'day_price', 'night_price', 'source_kind', 'trade_date'] as $key) {
                self::requireKey($month, $key, $monthPath);
            }
            $monthKey = self::nonEmptyString($month['month'], $monthPath.'.month');
            if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $monthKey)) {
                throw new InvalidArgumentException($monthPath.'.month must be a calendar month.');
            }
            foreach (['base_price', 'day_price', 'night_price'] as $key) {
                self::finiteNumber($month[$key], $monthPath.'.'.$key);
            }
            $sourceKind = self::nonEmptyString($month['source_kind'], $monthPath.'.source_kind');
            if (! in_array($sourceKind, ['month', 'quarter', 'year'], true)) {
                throw new InvalidArgumentException($monthPath.'.source_kind is not supported.');
            }
            self::date(self::nonEmptyString($month['trade_date'], $monthPath.'.trade_date'), $monthPath.'.trade_date');
        });
        foreach (['annual_equivalent_base_price', 'annual_equivalent_day_price', 'annual_equivalent_night_price'] as $key) {
            self::nullableFiniteNumber($record[$key], $path.'.'.$key);
        }
        $confidence = self::nonEmptyString($record['confidence'], $path.'.confidence');
        if (! in_array($confidence, ['higher', 'lower', 'fallback'], true)) {
            throw new InvalidArgumentException($path.'.confidence is not supported.');
        }
        $higherConfidence = self::boolean($record['higher_confidence'], $path.'.higher_confidence');
        self::stringList($record['flags'], $path.'.flags');

        if ($basis === SpotEstimateBasis::ForwardCurve) {
            if ($record['current_curve_trade_date'] === null || $record['future_curve_trade_date'] === null
                || $record['months'] === [] || ! in_array($confidence, ['higher', 'lower'], true)
                || $higherConfidence !== ($confidence === 'higher')
                || ($confidence === 'higher' && ($record['shape']['period_start'] === null || $record['shape']['period_end'] === null))) {
                throw new InvalidArgumentException($path.' has incoherent forward-curve evidence.');
            }
        } elseif ($record['months'] !== [] || $confidence !== 'fallback' || $higherConfidence
            || $record['current_curve_trade_date'] !== null || $record['future_curve_trade_date'] !== null) {
            throw new InvalidArgumentException($path.' has incoherent rolling fallback evidence.');
        }
    }

    private static function validateSupplierAdjustedEstimate(array $record, string $path): void
    {
        foreach (['basis', 'beta', 'current_energy_price', 'monthly_fee', 'annual_equivalent_energy_price', 'reference_kind', 'reference_price', 'curve_trade_date', 'reference_trade_date', 'price_episode_started_at', 'price_episode_evidence_basis', 'tail_starts', 'monthly_fee_assumption', 'higher_confidence', 'flags'] as $key) {
            self::requireKey($record, $key, $path);
        }
        if (($record['basis'] ?? null) === SupplierAdjustedEstimateBasis::ForwardPremium->value || array_key_exists('premium', $record)) {
            self::validateForwardPremium($record, $path, 'supplier_adjusted_forward_premium_v1');
        }
        self::validateSupplierAdjustedFields($record, $path);
    }

    private static function validateForwardPremium(array $record, string $path, string $policy): void
    {
        self::requireKey($record, 'premium', $path);
        $premium = $record['premium'];
        if (! is_array($premium) || ! in_array($premium['source'] ?? null, ['own_lineage', 'same_company', 'market'], true)
            || ($record['current_policy'] ?? null) !== $policy
            || ! in_array($premium['confidence'] ?? null, ['higher', 'lower'], true)) {
            throw new InvalidArgumentException($path.'.premium is not supported.');
        }
        if (count($premium) !== count(PremiumEstimate::PUBLIC_KEYS)
            || array_diff(PremiumEstimate::PUBLIC_KEYS, array_keys($premium)) !== []) {
            throw new InvalidArgumentException($path.'.premium must contain only public fields.');
        }
        self::stringList($premium['flags'], $path.'.premium.flags');
        if (array_diff($premium['flags'], PremiumEstimate::PUBLIC_FLAGS) !== []) {
            throw new InvalidArgumentException($path.'.premium.flags is not supported.');
        }
        if (! is_array($premium['references']) || ! array_is_list($premium['references']) || $premium['references'] === []) {
            throw new InvalidArgumentException($path.'.premium.references must be a non-empty list.');
        }
        foreach ($premium['references'] as $reference) {
            if (! is_array($reference) || count($reference) !== count(PremiumEstimate::PUBLIC_REFERENCE_KEYS)
                || array_diff(PremiumEstimate::PUBLIC_REFERENCE_KEYS, array_keys($reference)) !== []
                || ! is_bool($reference['reference_period_proxy'])) {
                throw new InvalidArgumentException($path.'.premium.references must contain public reference facts.');
            }
            foreach (['pricing_date', 'delivery_start', 'delivery_end'] as $key) {
                if ($key === 'pricing_date' && $reference[$key] === null) {
                    continue;
                }
                self::date(self::nonEmptyString($reference[$key], $path.'.premium.references.'.$key), $path.'.premium.references.'.$key);
            }
            $provenances = [];
            foreach (PremiumFamily::cases() as $family) {
                foreach (PremiumVatBasis::cases() as $vat) {
                    $provenances[] = PremiumEstimate::publicProvenance($family, $reference['reference_period_proxy'], $vat);
                }
            }
            if (! in_array($reference['provenance'], $provenances, true)) {
                throw new InvalidArgumentException($path.'.premium.references.provenance is not supported.');
            }
        }
        foreach (['lineage_count', 'company_count', 'observation_count', 'independent_variant_count'] as $key) {
            if (! is_int($premium[$key] ?? null) || $premium[$key] < 1) {
                throw new InvalidArgumentException($path.'.premium.'.$key.' must be positive.');
            }
        }
        if (! is_array($premium['premiums_by_bucket'] ?? null) || $premium['premiums_by_bucket'] === []) {
            throw new InvalidArgumentException($path.'.premium requires energy buckets.');
        }
        foreach ($premium['premiums_by_bucket'] as $bucket => $rate) {
            if (! (ComponentType::tryFrom($bucket)?->isPerKwhEnergy() ?? false)) {
                throw new InvalidArgumentException($path.'.premium contains a non-energy bucket.');
            }
            self::finiteNumber($rate, $path.'.premium.premiums_by_bucket.'.$bucket);
        }
        self::date(self::nonEmptyString($record['curve_trade_date'], $path.'.curve_trade_date'), $path.'.curve_trade_date');
        foreach (['evidence_from', 'evidence_through'] as $key) {
            self::date(self::nonEmptyString($premium[$key] ?? null, $path.'.premium.'.$key), $path.'.premium.'.$key);
        }
        self::stringList($premium['reference_trade_dates'] ?? null, $path.'.premium.reference_trade_dates');
        if ($premium['reference_trade_dates'] === []) {
            throw new InvalidArgumentException($path.'.premium requires a reference trade date.');
        }
        foreach ($premium['reference_trade_dates'] as $date) {
            self::date($date, $path.'.premium.reference_trade_dates');
        }
    }

    private static function validateSupplierAdjustedFields(array $record, string $path): void
    {
        if (SupplierAdjustedEstimateBasis::tryFrom(self::nonEmptyString($record['basis'], $path.'.basis')) === null) {
            throw new InvalidArgumentException($path.'.basis is not supported.');
        }
        if (self::finiteNumber($record['beta'], $path.'.beta') < 0
            || self::finiteNumber($record['current_energy_price'], $path.'.current_energy_price') < 0
            || self::finiteNumber($record['monthly_fee'], $path.'.monthly_fee') < 0) {
            throw new InvalidArgumentException($path.' contains a negative price or coefficient.');
        }
        foreach (['annual_equivalent_energy_price', 'reference_price'] as $key) {
            self::nullableFiniteNumber($record[$key], $path.'.'.$key);
        }
        self::nullableNonEmptyString($record['reference_kind'], $path.'.reference_kind');
        foreach (['curve_trade_date', 'reference_trade_date', 'price_episode_started_at'] as $key) {
            $date = self::nullableNonEmptyString($record[$key], $path.'.'.$key);
            if ($date !== null) {
                self::date($date, $path.'.'.$key);
            }
        }
        if (PriceEpisodeEvidenceBasis::tryFrom(self::nonEmptyString($record['price_episode_evidence_basis'], $path.'.price_episode_evidence_basis')) === null) {
            throw new InvalidArgumentException($path.'.price_episode_evidence_basis is not supported.');
        }
        self::nullableNonEmptyString($record['tail_starts'], $path.'.tail_starts');
        if (! in_array(self::nonEmptyString($record['monthly_fee_assumption'], $path.'.monthly_fee_assumption'), ['held_flat', 'disclosed_phases'], true)) {
            throw new InvalidArgumentException($path.'.monthly_fee_assumption is not supported.');
        }
        self::boolean($record['higher_confidence'], $path.'.higher_confidence');
        self::stringList($record['flags'], $path.'.flags');
    }

    private static function validateHybridSpotTimeline(array $payload, EstimateMethod $method): void
    {
        $basis = $method === EstimateMethod::ForwardCurveSpot
            ? SpotEstimateBasis::ForwardCurve : SpotEstimateBasis::Rolling365Fallback;
        if (($payload['spot_estimate']['basis'] ?? null) !== $basis->value) {
            throw new InvalidArgumentException('Hybrid Spot pricing requires matching Spot estimate provenance.');
        }
        $phases = $payload['phase_breakdown'];
        usort($phases, static fn (array $a, array $b) => strcmp($a['window_start'], $b['window_start']));
        $lastEnd = null;
        $hasBase = false;
        $hasSpot = false;
        foreach ($phases as $phase) {
            // Resolved window ends are inclusive. A Spot display rate must not hide a fixed base.
            if (($lastEnd !== null && $phase['window_start'] <= $lastEnd)
                || $phase['energy_package'] !== null
                || ($phase['uses_spot'] && ($phase['energy_cents'] !== null || $phase['spot_margin_cents'] === null))
                || (! $phase['uses_spot'] && $phase['spot_margin_cents'] !== null)) {
                throw new InvalidArgumentException('Hybrid Spot pricing requires distinct non-conflicting phase usage.');
            }
            $lastEnd = $phase['window_end'];
            $hasSpot = $hasSpot || $phase['uses_spot'];
            $hasBase = $hasBase || (! $phase['uses_spot'] && $phase['energy_cents'] !== null);
        }
        if (! $hasBase || ! $hasSpot) {
            throw new InvalidArgumentException('Hybrid Spot pricing requires both base and Spot phases.');
        }
    }

    private static function validatePhase(array $record, string $path): void
    {
        foreach (['label', 'phase_kind', 'starts', 'ends', 'ends_value', 'window_start', 'window_end', 'uses_spot', 'energy_cents', 'spot_margin_cents', 'monthly_fee', 'energy_package'] as $key) {
            self::requireKey($record, $key, $path);
        }
        if (! is_string($record['label'])) {
            throw new InvalidArgumentException($path.'.label must be a string.');
        }
        if (PhaseKind::tryFrom(self::nonEmptyString($record['phase_kind'], $path.'.phase_kind')) === null
            || BoundaryKind::tryFrom(self::nonEmptyString($record['starts'], $path.'.starts')) === null
            || BoundaryKind::tryFrom(self::nonEmptyString($record['ends'], $path.'.ends')) === null) {
            throw new InvalidArgumentException($path.' contains an unsupported phase or boundary kind.');
        }
        if ($record['ends_value'] !== null && ! is_int($record['ends_value']) && ! is_string($record['ends_value'])) {
            throw new InvalidArgumentException($path.'.ends_value has an invalid type.');
        }
        $start = self::date(self::nonEmptyString($record['window_start'], $path.'.window_start'), $path.'.window_start');
        $end = self::date(self::nonEmptyString($record['window_end'], $path.'.window_end'), $path.'.window_end');
        if ($end < $start) {
            throw new InvalidArgumentException($path.' has a window end before its start.');
        }
        self::boolean($record['uses_spot'], $path.'.uses_spot');
        if (array_key_exists('energy_price_guaranteed', $record)) {
            self::boolean($record['energy_price_guaranteed'], $path.'.energy_price_guaranteed');
        }
        foreach (['energy_cents', 'spot_margin_cents', 'monthly_fee'] as $key) {
            self::nullableFiniteNumber($record[$key], $path.'.'.$key);
        }
        if ($record['energy_package'] !== null) {
            if (! is_array($record['energy_package'])) {
                throw new InvalidArgumentException($path.'.energy_package must be an array or null.');
            }
            self::validatePackage($record['energy_package'], $path.'.energy_package');
        }
    }

    private static function validateOfferTerm(array $record, string $path): void
    {
        foreach (['end_kind', 'starts_on', 'ends_on', 'duration_months', 'starts_after_months', 'ends_after_months', 'starts_at_window_start', 'components'] as $key) {
            self::requireKey($record, $key, $path);
        }
        if (BoundaryKind::tryFrom(self::nonEmptyString($record['end_kind'], $path.'.end_kind')) === null) {
            throw new InvalidArgumentException($path.'.end_kind is not supported.');
        }
        $start = self::date(self::nonEmptyString($record['starts_on'], $path.'.starts_on'), $path.'.starts_on');
        $end = self::date(self::nonEmptyString($record['ends_on'], $path.'.ends_on'), $path.'.ends_on');
        if ($end < $start) {
            throw new InvalidArgumentException($path.' ends before it starts.');
        }
        self::nullablePositiveInteger($record['duration_months'], $path.'.duration_months');
        foreach (['starts_after_months', 'ends_after_months'] as $key) {
            if ($record[$key] !== null && (! is_int($record[$key]) || $record[$key] < 0)) {
                throw new InvalidArgumentException($path.'.'.$key.' must be a non-negative integer or null.');
            }
        }
        self::boolean($record['starts_at_window_start'], $path.'.starts_at_window_start');
        if (! is_array($record['components']) || $record['components'] === [] || ! array_is_list($record['components'])) {
            throw new InvalidArgumentException($path.'.components must be a non-empty list.');
        }
        foreach ($record['components'] as $index => $component) {
            if (! is_array($component)) {
                throw new InvalidArgumentException($path.'.components.'.$index.' must be an array.');
            }
            foreach (['component_type', 'unit', 'amount', 'normal_amount'] as $key) {
                self::requireKey($component, $key, $path.'.components.'.$index);
            }
            if (ComponentType::tryFrom(self::nonEmptyString($component['component_type'], $path.'.components.'.$index.'.component_type')) === null
                || ComponentUnit::tryFrom(self::nonEmptyString($component['unit'], $path.'.components.'.$index.'.unit')) === null) {
                throw new InvalidArgumentException($path.'.components.'.$index.' contains an unsupported type or unit.');
            }
            self::finiteNumber($component['amount'], $path.'.components.'.$index.'.amount');
            self::finiteNumber($component['normal_amount'], $path.'.components.'.$index.'.normal_amount');
            if (array_key_exists('rule_kind', $component)) {
                $kind = is_string($component['rule_kind']) ? EnergyPriceRuleKind::tryFrom($component['rule_kind']) : null;
                if ($kind === null || (! $kind->isDiscount() && $kind !== EnergyPriceRuleKind::FixedPrice)
                    || ! ComponentType::from($component['component_type'])->isPerKwhEnergy()
                    || $component['unit'] !== ComponentUnit::CentsPerKwh->value
                    || $component['amount'] < 0 || $component['normal_amount'] < 0) {
                    throw new InvalidArgumentException($path.' has unsupported offer rule.');
                }
                foreach (['discount_value', 'floor_amount'] as $key) {
                    self::requireKey($component, $key, $path);
                    self::nullableFiniteNumber($component[$key], $path.'.'.$key);
                }
                if ($kind->isDiscount()) {
                    if ($component['discount_value'] === null || $component['discount_value'] <= 0 || ($kind->value === 'percentage_discount' && $component['discount_value'] > 100) || ($component['floor_amount'] !== null && $component['floor_amount'] < 0)) {
                        throw new InvalidArgumentException($path.' has invalid offer operands.');
                    }
                } elseif ($component['discount_value'] !== null || $component['floor_amount'] !== null || $component['normal_amount'] <= $component['amount']) {
                    throw new InvalidArgumentException($path.' has invalid fixed offer facts.');
                }
            }
        }
    }

    private static function optionalRecord(mixed $value, string $path, callable $validator): ?PricingFact
    {
        if ($value === null) {
            return null;
        }
        if (! is_array($value)) {
            throw new InvalidArgumentException($path.' must be an array or null.');
        }
        $validator($value, $path);

        return new PricingFact($value);
    }

    /** @return list<PricingFact> */
    private static function recordList(mixed $value, string $path, callable $validator): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            throw new InvalidArgumentException($path.' must be a list.');
        }
        $records = [];
        foreach ($value as $index => $record) {
            if (! is_array($record)) {
                throw new InvalidArgumentException($path.'.'.$index.' must be an array.');
            }
            $validator($record, $path.'.'.$index);
            $records[] = new PricingFact($record);
        }

        return $records;
    }

    private static function requireKey(array $payload, string $key, string $path): void
    {
        if (! array_key_exists($key, $payload)) {
            throw new InvalidArgumentException($path.' is missing required key '.$key.'.');
        }
    }

    private static function finiteNumber(mixed $value, string $path): float
    {
        if ((! is_int($value) && ! is_float($value)) || ! is_finite((float) $value)) {
            throw new InvalidArgumentException($path.' must be a finite number.');
        }

        return (float) $value;
    }

    private static function nullableFiniteNumber(mixed $value, string $path): ?float
    {
        return $value === null ? null : self::finiteNumber($value, $path);
    }

    /** @return list<float> */
    private static function finiteNumberList(mixed $value, string $path): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            throw new InvalidArgumentException($path.' must be a list.');
        }

        return array_map(fn (mixed $item, int $index): float => self::finiteNumber($item, $path.'.'.$index), $value, array_keys($value));
    }

    private static function boolean(mixed $value, string $path): bool
    {
        if (! is_bool($value)) {
            throw new InvalidArgumentException($path.' must be a boolean.');
        }

        return $value;
    }

    private static function nonEmptyString(mixed $value, string $path): string
    {
        if (! is_string($value) || trim($value) === '') {
            throw new InvalidArgumentException($path.' must be a non-empty string.');
        }

        return $value;
    }

    private static function nullableNonEmptyString(mixed $value, string $path): ?string
    {
        return $value === null ? null : self::nonEmptyString($value, $path);
    }

    private static function positiveInteger(mixed $value, string $path): int
    {
        if (! is_int($value) || $value <= 0) {
            throw new InvalidArgumentException($path.' must be a positive integer.');
        }

        return $value;
    }

    private static function nullablePositiveInteger(mixed $value, string $path): ?int
    {
        return $value === null ? null : self::positiveInteger($value, $path);
    }

    private static function stringList(mixed $value, string $path): void
    {
        if (! is_array($value) || ! array_is_list($value)) {
            throw new InvalidArgumentException($path.' must be a list.');
        }
        foreach ($value as $item) {
            self::nonEmptyString($item, $path.' item');
        }
    }

    private static function date(string $value, string $path): DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw new InvalidArgumentException($path.' must be an ISO date.');
        }

        return $date;
    }
}
