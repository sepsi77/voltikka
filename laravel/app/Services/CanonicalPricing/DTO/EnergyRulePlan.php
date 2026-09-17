<?php

namespace App\Services\CanonicalPricing\DTO;

use App\Enums\MeteringType;
use App\Services\CanonicalPricing\Enums\BoundaryKind;
use App\Services\CanonicalPricing\Enums\ComponentType;
use App\Services\CanonicalPricing\Enums\ComponentUnit;
use App\Services\CanonicalPricing\Enums\EnergyPriceRuleKind as Kind;
use App\Services\CanonicalPricing\Enums\PhaseKind;
use App\Services\CanonicalPricing\Enums\PriceRole;
use App\Services\CanonicalPricing\Support\PhaseTimelineBuilder;
use Carbon\CarbonImmutable;

/** One current underlying tariff. Never infers a new normal reference from a future price. */
final readonly class EnergyRulePlan
{
    public function __construct(
        public array $baseline,
        public array $spans,
        public array $normalSpans,
        public array $boundaries,
        public ?NormalEnergyProjection $projection,
        public array $fixedOnly = [],
        public array $requiredOffers = [],
        public bool $normalAvailable = true,
        public array $ordinarySpans = [],
        public bool $allowFixedContinuation = false,
    ) {}

    public static function hasKnownRules(CanonicalContractData $data): bool
    {
        foreach ($data->phases as $phase) {
            foreach ($phase->components as $component) {
                if ($component->energyRule->kind !== Kind::Unknown) {
                    return true;
                }
            }
        }

        return false;
    }

    public static function build(CanonicalContractData $data, MeteringType $metering, CarbonImmutable $start, CarbonImmutable $end, PhaseTimelineBuilder $timeline, ?NormalEnergyProjection $projection): ?self
    {
        $plan = self::buildPlan($data, $metering, $start, $end, $timeline, $projection);
        if ($plan === null && $projection !== null && self::buildPlan($data, $metering, $start, $end, $timeline, null) !== null) {
            // A supplied projection with different current rates is contradictory input.
            return null;
        }
        $plan ??= self::buildPlan($data, $metering, $start, $end, $timeline, null, actualOnly: true);
        if ($plan?->projection !== null) {
            if (($plan->rates($start)['normal'] ?? null) !== $plan->baseline || ! ($plan->rates($start)['normal_reference_current'] ?? false)) {
                return self::buildPlan($data, $metering, $start, $end, $timeline, null);
            }
            $estimated = false;
            foreach (array_merge([$start], $plan->boundaries) as $date) {
                if ($date->gte($end)) {
                    continue;
                }
                $rates = $plan->rates($date);
                $estimated = $estimated || $rates === null || $rates['actual_estimated'] || $rates['normal_estimated'];
            }
            if (! $estimated) {
                return self::buildPlan($data, $metering, $start, $end, $timeline, null);
            }
        }

        return $plan;
    }

    private static function buildPlan(CanonicalContractData $data, MeteringType $metering, CarbonImmutable $start, CarbonImmutable $end, PhaseTimelineBuilder $timeline, ?NormalEnergyProjection $projection, bool $actualOnly = false): ?self
    {
        $expected = match ($metering) {
            MeteringType::General => ['energy_general'],
            MeteringType::Time => ['energy_day', 'energy_night'],
            MeteringType::Season => ['energy_seasonal_winter', 'energy_seasonal_other'],
        };
        $baseline = $baselineKinds = $spans = $normalSpans = $boundaries = $current = [];
        $ordinarySpans = $fixedValues = $requiredOffers = [];
        $normalAvailable = true;
        foreach ($data->phases as $phase) {
            // Ignore future parents outside the window, but retain independent facts
            // from already-started parents even after their fee phase expires.
            $parent = $phase->components[0] ?? new CanonicalComponent(ComponentType::MonthlyFee, 0, null, ComponentUnit::EurPerMonth, PriceRole::Current);
            $startedRange = self::range($parent, $phase->starts, new PhaseBoundary(BoundaryKind::None, null), $data, $start, $end, $timeline);
            if ($startedRange === null) {
                continue;
            }
            $parentStarted = $startedRange[0]->lte($start);
            if ($phase->package !== null) {
                return null;
            }
            foreach ($phase->billedComponents() as $component) {
                if ($component->type === ComponentType::SpotMargin || $component->isUncostableWhenBilled()) {
                    return null;
                }
                if (! $component->type->isPerKwhEnergy()) {
                    continue;
                }
                $key = $component->type->value;
                if (! in_array($key, $expected, true) || $component->amount < 0
                    || $component->unit !== ComponentUnit::CentsPerKwh) {
                    return null;
                }
                $rule = $component->energyRule;
                if ($rule->kind === Kind::AdjustableTariff
                    && (($component->normalAmount !== null && abs($component->normalAmount - $component->amount) > 0.0001)
                        || $rule->normalBasis?->kind === Kind::FixedPrice)) {
                    // Two different adjustable observations do not prove a discount operator.
                    return null;
                }
                $phaseRange = self::range($component, $phase->starts, $phase->ends, $data, $start, $end, $timeline);
                if ($rule->kind === Kind::Unknown) {
                    if ($phaseRange === null) {
                        continue;
                    }
                    if (! in_array($phase->phaseKind, [PhaseKind::Normal, PhaseKind::Continuation], true)
                        || ($component->normalAmount !== null && abs($component->normalAmount - $component->amount) > 0.0001)) {
                        return null;
                    }
                    $ordinarySpans[$key][] = ['start' => $phaseRange[0], 'end' => $phaseRange[1], 'amount' => $component->amount];
                    $boundaries[] = $phaseRange[0];
                    $boundaries[] = $phaseRange[1];
                    if ($phaseRange[0]->lte($start)) {
                        $current[$key] = true;
                    }

                    continue;
                }
                // The normal tariff has its own bounds. Actual expiry cannot erase it.
                $basis = $rule->normalBasis;
                $normalAmount = $component->normalAmount;
                if ($basis === null && $rule->kind === Kind::AdjustableTariff) {
                    $basis = new EnergyNormalBasis($rule->kind, $rule->starts, $rule->ends);
                    $normalAmount = $component->amount;
                }
                if (! $actualOnly && $basis !== null && $basis->kind !== Kind::Unknown && $normalAmount !== null) {
                    if ($normalAmount < 0) {
                        return null;
                    }
                    $normalRange = self::range($component, $basis->starts, $basis->ends, $data, $start, $end, $timeline);
                    if ($normalRange === null && $parentStarted && $basis->kind === Kind::FixedPrice
                        && $basis->starts?->kind === BoundaryKind::Date && $basis->ends?->kind === BoundaryKind::Date) {
                        $na = CarbonImmutable::parse($basis->starts->value, 'Europe/Helsinki')->startOfDay();
                        $nb = CarbonImmutable::parse($basis->ends->value, 'Europe/Helsinki')->startOfDay()->addDay();
                        if ($na->lt($nb) && $nb->lte($start)) {
                            $normalSpans[$key][] = ['start' => $na, 'end' => $nb, 'kind' => $basis->kind,
                                'amount' => $normalAmount, 'absolute_start' => $na, 'expired' => true];
                        }
                    }
                    if ($normalRange !== null) {
                        [$na, $nb] = $normalRange;
                        $na = $na->max($startedRange[0]);
                        if ($na->gte($nb)) {
                            return null;
                        }
                        foreach ($normalSpans[$key] ?? [] as $prior) {
                            if ($prior['start']->lt($nb) && $prior['end']->gt($na)
                                && abs($prior['amount'] - $normalAmount) > 0.0001
                                && $prior['kind'] === $basis->kind) {
                                return null;
                            }
                        }
                        if ($parentStarted && $na->lte($start) && $nb->gt($start)
                            && (($baselineKinds[$key] ?? null) !== Kind::FixedPrice || $basis->kind === Kind::FixedPrice)) {
                            $baseline[$key] = $normalAmount;
                            $baselineKinds[$key] = $basis->kind;
                            $current[$key] = true;
                        }
                        $normalSpans[$key][] = ['start' => $na, 'end' => $nb, 'kind' => $basis->kind, 'amount' => $normalAmount,
                            'absolute_start' => $basis->starts?->kind === BoundaryKind::Date ? CarbonImmutable::parse($basis->starts->value, 'Europe/Helsinki')->startOfDay() : null];
                        $boundaries[] = $na;
                        $boundaries[] = $nb;
                    }
                }
                $range = self::range($component, $rule->starts, $rule->ends, $data, $start, $end, $timeline);
                $offer = $component->withoutEnergyOffer() !== $component;
                if (! $actualOnly && $component->priceRole === PriceRole::Introductory && $phaseRange !== null
                    && ($rule->kind->isDiscount() || $component->normalAmount === null || $component->normalAmount !== $component->amount)) {
                    if (! $offer && ! ($rule->kind->isDiscount() && $rule->discountValue === 0.0)) {
                        // Missing counterfactual proof does not mean there is no offer.
                        return null;
                    }
                    $requiredOffers[$key][] = $phaseRange;
                    $boundaries[] = $phaseRange[0];
                    $boundaries[] = $phaseRange[1];
                }
                if ($range === null) {
                    if (! $actualOnly && ! $offer && $rule->kind === Kind::FixedPrice
                        && $rule->starts?->kind === BoundaryKind::Date && $rule->ends?->kind === BoundaryKind::Date
                        && ($component->normalAmount === null || $component->normalAmount === $component->amount)) {
                        $announcedStart = CarbonImmutable::parse($rule->starts->value, 'Europe/Helsinki')->startOfDay();
                        $announcedEnd = CarbonImmutable::parse($rule->ends->value, 'Europe/Helsinki')->startOfDay()->addDay();
                        if ($announcedStart->lt($announcedEnd) && $announcedEnd->lte($start) && $parentStarted) {
                            if ($phase->starts->kind === BoundaryKind::Date
                                && CarbonImmutable::parse($phase->starts->value, 'Europe/Helsinki')->startOfDay()->gte($announcedEnd)) {
                                continue;
                            }
                            $ordinarySpans[$key][] = ['start' => $announcedStart, 'end' => $announcedEnd,
                                'amount' => $component->amount, 'fixed' => true, 'expired' => true];
                            if ($phaseRange !== null && $phaseRange[0]->lte($start)
                                && in_array($component->priceRole, [PriceRole::Current, PriceRole::Normal], true)) {
                                $fixedValues[$key][] = $component->amount;
                                $current[$key] = true;
                            }
                        }
                    }

                    continue;
                }
                if ($actualOnly && $rule->kind !== Kind::FixedPrice) {
                    return null;
                }
                if ($actualOnly && ($offer
                    || ($component->normalAmount !== null && abs($component->normalAmount - $component->amount) > 0.0001)
                    || ($component->normalAmount === null && ! in_array($component->priceRole, [PriceRole::Current, PriceRole::Normal, PriceRole::Future], true)))) {
                    $normalAvailable = false;
                }
                if ($rule->kind === Kind::FixedPrice && (! $offer || $actualOnly)) {
                    if (! $actualOnly && $component->normalAmount !== null && abs($component->normalAmount - $component->amount) > 0.0001) {
                        return null;
                    }
                    $fixedValues[$key][] = $component->amount;
                    if (! $offer && ! $actualOnly) {
                        $ordinarySpans[$key][] = ['start' => $range[0]->max($startedRange[0]), 'end' => $range[1], 'amount' => $component->amount, 'fixed' => true];
                    }
                }
                [$a, $b] = $range;
                $a = $a->max($startedRange[0]);
                if ($a->gte($b)) {
                    continue;
                }
                $boundaries[] = $a;
                $boundaries[] = $b;
                foreach ($spans[$key] ?? [] as $prior) {
                    $previous = $prior['component'];
                    if ($rule->kind !== Kind::AdjustableTariff && $previous->energyRule->kind !== Kind::AdjustableTariff
                        && $prior['start']->lt($b) && $prior['end']->gt($a)
                        && ($previous->amount !== $component->amount || $previous->energyRule != $rule)) {
                        return null;
                    }
                }
                if ($rule->kind === Kind::AdjustableTariff && $phaseRange !== null) {
                    $a = $a->max($phaseRange[0]);
                    $b = $b->min($phaseRange[1]);
                }
                $spans[$key][] = ['start' => $a, 'end' => $b, 'component' => $component];
                if ($a->lte($start) && $b->gt($start) && $parentStarted) {
                    $current[$key] = true;
                }
            }
        }
        $fixedOnly = [];
        foreach ($expected as $key) {
            if (! isset($baseline[$key]) && isset($fixedValues[$key]) && empty($requiredOffers[$key])) {
                // A billed fixed map is not an adjustable tariff or a premium anchor.
                $baseline[$key] = $fixedValues[$key][0];
                $fixedOnly[$key] = true;
            }
            if (! isset($baseline[$key], $current[$key])) {
                return null;
            }
            foreach ($ordinarySpans[$key] ?? [] as $ordinarySpan) {
                foreach ($spans[$key] ?? [] as $span) {
                    if ($span['component']->energyRule->kind !== Kind::AdjustableTariff
                        && $ordinarySpan['start']->gte($span['start'])
                        && $span['start']->lt($ordinarySpan['end']) && $span['end']->gt($ordinarySpan['start'])
                        && abs($span['component']->amount - $ordinarySpan['amount']) > 0.0001) {
                        // A current explicit energy change cannot inherit a different lock.
                        return null;
                    }
                }
            }
        }
        ksort($baseline);
        if ($projection !== null) {
            $rates = $projection->currentRates;
            ksort($rates);
            if (array_keys($rates) !== array_keys($baseline)) {
                return null;
            }
            foreach ($baseline as $key => $amount) {
                if (abs($amount - $rates[$key]) > 0.0001) {
                    return null;
                }
            }
            if ($projection->tail() !== null) {
                $boundaries[] = $projection->tail();
            }
        }

        $plan = new self($baseline, $spans, $normalSpans, $boundaries, $projection, $fixedOnly, $requiredOffers, $normalAvailable, $ordinarySpans, ! $actualOnly);
        if ($actualOnly) {
            // Actual-only fallback needs a scoped billed price on every segment.
            // A later ordinary quote can supply its own estimated continuation, but
            // the introductory price cannot fill a gap before that quote applies.
            foreach (array_merge([$start], $boundaries) as $date) {
                if ($date->lt($end) && $plan->rates($date) === null) {
                    return null;
                }
            }
        }

        return $plan;
    }

    private static function range(CanonicalComponent $component, ?PhaseBoundary $starts, ?PhaseBoundary $ends, CanonicalContractData $data, CarbonImmutable $start, CarbonImmutable $end, PhaseTimelineBuilder $timeline): ?array
    {
        if ($starts === null || $ends === null) {
            return null;
        }
        $phase = new PricingPhase('', PhaseKind::CurrentStructured, $starts, $ends, [$component]);
        $covered = array_values(array_filter($timeline->build([$phase], $data->recurringSchedule, $start), fn ($segment) => $segment->isCovered() && $segment->start->lt($end)));

        return $covered === [] ? null : [$covered[0]->start, end($covered)->end->min($end)];
    }

    /** @return array{actual: array, normal: array, actual_estimated: bool, normal_estimated: bool, latest_known_estimate: bool, model_floor_applied: bool, actual_guaranteed: bool, normal_reference_current: bool}|null */
    public function rates(CarbonImmutable $date): ?array
    {
        $actual = $normal = [];
        $actualEstimated = $normalEstimated = $latestKnownEstimate = $modelFloorApplied = false;
        $actualGuaranteed = $normalReferenceCurrent = true;
        foreach ($this->baseline as $key => $base) {
            $ordinary = null;
            $ordinaryRegimeChanged = false;
            foreach ($this->ordinarySpans[$key] ?? [] as $span) {
                $ordinaryRegimeChanged = $ordinaryRegimeChanged || ($span['start']->lte($date) && abs($span['amount'] - $base) > 0.0001);
                if ($this->normalAvailable && ! ($span['fixed'] ?? false) && abs($span['amount'] - $base) < 0.0001) {
                    // Repeated old normal metadata is not a new announced price regime.
                    continue;
                }
                if ($span['start']->lte($date) && ($ordinary === null || $span['start']->gte($ordinary['start']))) {
                    $ordinary = $span;
                }
            }
            $normalSpan = null;
            foreach ($this->normalSpans[$key] ?? [] as $span) {
                $retainExpired = ($normalSpan['expired'] ?? false) && $span['kind'] === Kind::AdjustableTariff
                    && ($span['absolute_start'] === null || $span['absolute_start']->lte($normalSpan['start']));
                $replaceOldQuote = ($span['expired'] ?? false) && ($normalSpan['kind'] ?? null) === Kind::AdjustableTariff
                    && ($normalSpan['absolute_start'] === null || $normalSpan['absolute_start']->lte($span['start']));
                if (! $retainExpired && $span['start']->lte($date) && ($normalSpan === null || $replaceOldQuote || $span['start']->gt($normalSpan['start'])
                    || ($span['start']->eq($normalSpan['start']) && $span['kind'] === Kind::FixedPrice))) {
                    $normalSpan = $span;
                }
            }
            $ordinaryChanged = $ordinary !== null && $ordinaryRegimeChanged
                && ($normalSpan === null || $ordinary['start']->gte($normalSpan['start'])
                    || (($ordinary['expired'] ?? false) && $normalSpan['kind'] === Kind::AdjustableTariff
                        && ($normalSpan['absolute_start'] === null || $normalSpan['absolute_start']->lte($ordinary['start']))));
            $changedRegime = $ordinaryChanged || ($normalSpan !== null && abs($normalSpan['amount'] - $base) > 0.0001);
            $normalReferenceCurrent = $normalReferenceCurrent && ! $changedRegime;
            // Future normal facts are scoped prices, not a current reference or observation.
            $base = $ordinaryChanged ? $ordinary['amount'] : ($normalSpan['amount'] ?? $base);
            $normalFixed = false;
            foreach ($this->normalSpans[$key] ?? [] as $span) {
                if (! $ordinaryChanged && $span['start']->lte($date) && $span['end']->gt($date)
                    && $span['kind'] === Kind::FixedPrice && abs($span['amount'] - $base) < 0.0001) {
                    $normalFixed = true;
                }
            }
            $offset = $normalFixed || $changedRegime ? 0.0 : ($this->projection?->offset($date, $key) ?? 0.0);
            if (! is_finite($offset)) {
                return null;
            }
            $normal[$key] = max(0.0, $base + $offset);
            $estimated = ! $normalFixed && ($this->projection?->tail() === null || $date->gte($this->projection->tail()));
            $selected = null;
            foreach ($this->spans[$key] ?? [] as $span) {
                if ($span['start']->lte($date) && $span['end']->gt($date)
                    && ($selected === null
                        || ($selected['component']->energyRule->kind === Kind::AdjustableTariff && $span['component']->energyRule->kind !== Kind::AdjustableTariff)
                        || ($selected['component']->energyRule->kind === $span['component']->energyRule->kind && $span['start']->gte($selected['start'])))) {
                    $selected = $span;
                }
            }
            $component = $selected['component'] ?? null;
            $rule = $component?->energyRule;
            $actualGuaranteed = $actualGuaranteed && $rule?->kind === Kind::FixedPrice;
            if (isset($this->fixedOnly[$key]) && $rule?->kind !== Kind::FixedPrice && ! $this->allowFixedContinuation) {
                if ($this->normalAvailable || $ordinary === null) {
                    return null;
                }
                $actual[$key] = $ordinary['amount'];
                $actualEstimated = $latestKnownEstimate = true;

                continue;
            }
            if ($changedRegime && ! $normalFixed && ($rule === null || $rule->kind !== Kind::FixedPrice)) {
                $estimated = $latestKnownEstimate = true;
            }
            foreach ($this->requiredOffers[$key] ?? [] as [$a, $b]) {
                if ($a->lte($date) && $b->gt($date) && ($rule === null || $rule->kind === Kind::AdjustableTariff)) {
                    return null;
                }
            }
            $genuine = $component !== null && $component->withoutEnergyOffer() !== $component;
            $actual[$key] = $normal[$key];
            if ($rule?->kind === Kind::FixedPrice) {
                $actual[$key] = $component->amount;
                if (! $genuine || isset($this->fixedOnly[$key])) {
                    $normal[$key] = $actual[$key];
                    $estimated = false;
                }
            } elseif ($rule?->kind->isDiscount()) {
                $value = $rule->kind === Kind::PercentageDiscount
                    ? $normal[$key] * (1 - $rule->discountValue / 100)
                    : $normal[$key] - $rule->discountValue;
                // Source floors are separate from the existing nonnegative model safeguard.
                $unflooredValue = $rule->kind === Kind::PercentageDiscount
                    ? ($base + $offset) * (1 - $rule->discountValue / 100)
                    : $base + $offset - $rule->discountValue;
                $modelFloorApplied = $modelFloorApplied || ($estimated && $rule->floorAmount === null && $unflooredValue < 0);
                $actual[$key] = max(0.0, $rule->floorAmount === null ? $value : max($rule->floorAmount, $value));
                if (! $genuine) {
                    $normal[$key] = $actual[$key];
                }
                $constantFormula = $rule->kind === Kind::PercentageDiscount && $rule->discountValue === 100.0;
                $actualEstimated = $actualEstimated || ($estimated && ! $constantFormula);
            } else {
                $actualEstimated = $actualEstimated || $estimated;
            }
            // Normal projection and direct adjustable rates use the same model safeguard.
            // A fixed actual price can remain exact while its normal comparison is clipped.
            $modelFloorApplied = $modelFloorApplied || ($estimated && $base + $offset < 0);
            $normalEstimated = $this->normalAvailable && ($normalEstimated || $estimated);
        }

        return ['actual' => $actual, 'normal' => $this->normalAvailable ? $normal : [], 'actual_estimated' => $actualEstimated, 'normal_estimated' => $normalEstimated, 'latest_known_estimate' => $latestKnownEstimate, 'model_floor_applied' => $modelFloorApplied, 'actual_guaranteed' => $actualGuaranteed, 'normal_reference_current' => $normalReferenceCurrent];
    }
}
