<?php

namespace App\Services\ContractPricing;

use App\Services\CanonicalPricing\DTO\CanonicalPricingOutcome;
use App\Services\CanonicalPricing\Enums\BoundaryKind;
use App\Services\CanonicalPricing\Enums\ComponentType;
use App\Services\CanonicalPricing\Enums\ComponentUnit;
use App\Services\CanonicalPricing\Enums\ContractComparability;
use App\Services\CanonicalPricing\Enums\EstimateMethod;
use App\Services\CanonicalPricing\Enums\PhaseKind;
use App\Services\CanonicalPricing\MarketReset\Enums\ResetEstimateBasis;
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
        private float $discountSaving,
        private bool $includesDiscounts,
        private ?string $pricingBasis,
        private ?ContractComparability $comparability,
        private bool $estimate,
        private ?EstimateMethod $estimateMethod,
        private ?PricingFact $energyPackage,
        private ?PricingFact $contractTerm,
        private ?PricingFact $consumptionEffect,
        private ?PricingFact $resetEstimate,
        private array $phases,
        private array $offerTerms,
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
        $discountSaving = self::finiteNumber($payload['discount_savings_total'], 'calculated_cost.discount_savings_total');
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
        $phases = [];
        $offerTerms = [];

        if ($pricingBasis === 'canonical') {
            foreach ([
                'comparability', 'is_estimate', 'estimate_method', 'term_months',
                'energy_package', 'contract_term', 'phase_breakdown', 'offer_terms',
                'structured_only_total', 'consumption_effect', 'assumptions', 'reset_estimate',
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
                self::validateContractTerm(...),
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
            $phases = self::recordList($payload['phase_breakdown'], 'calculated_cost.phase_breakdown', self::validatePhase(...));
            $offerTerms = self::recordList($payload['offer_terms'], 'calculated_cost.offer_terms', self::validateOfferTerm(...));

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
                    EstimateMethod::HoldCurrentRecurringPrice,
                    EstimateMethod::RecurringForwardCurveShift,
                    EstimateMethod::RecurringSpotSeasonalIndex,
                ];
                if (! $estimate || ! in_array($estimateMethod, $supportedHybridMethods, true)) {
                    throw new InvalidArgumentException('base_only_hybrid requires a Hybrid or recurring-reset estimate method.');
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
            phases: $phases,
            offerTerms: $offerTerms,
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

    public function discountSaving(): float
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
        self::finiteNumber($record['base_total_cost'], $path.'.base_total_cost');
        if (self::finiteNumber($record['discount_savings_total'], $path.'.discount_savings_total') < 0) {
            throw new InvalidArgumentException($path.'.discount_savings_total must not be negative.');
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
