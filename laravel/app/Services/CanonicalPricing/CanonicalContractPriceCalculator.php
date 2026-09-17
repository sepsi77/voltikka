<?php

namespace App\Services\CanonicalPricing;

use App\Enums\ContractType;
use App\Enums\MeteringType;
use App\Enums\PricingModel;
use App\Services\CanonicalPricing\DTO\CanonicalComponent;
use App\Services\CanonicalPricing\DTO\CanonicalContractData;
use App\Services\CanonicalPricing\DTO\CanonicalPeriodPricingOutcome;
use App\Services\CanonicalPricing\DTO\CanonicalPeriodPricingRequest;
use App\Services\CanonicalPricing\DTO\CanonicalPricingOutcome;
use App\Services\CanonicalPricing\DTO\ContractContext;
use App\Services\CanonicalPricing\DTO\EnergyRuleComparison;
use App\Services\CanonicalPricing\DTO\EnergyRulePlan;
use App\Services\CanonicalPricing\DTO\IncludedEnergyPackageData;
use App\Services\CanonicalPricing\DTO\NormalEnergyProjection;
use App\Services\CanonicalPricing\DTO\OfferComponentData;
use App\Services\CanonicalPricing\DTO\OfferTermData;
use App\Services\CanonicalPricing\DTO\PhaseBoundary;
use App\Services\CanonicalPricing\DTO\PricingPhase;
use App\Services\CanonicalPricing\DTO\SpotAssumptions;
use App\Services\CanonicalPricing\DTO\WindowSegment;
use App\Services\CanonicalPricing\Enums\BoundaryKind;
use App\Services\CanonicalPricing\Enums\CalculationStatus;
use App\Services\CanonicalPricing\Enums\ComparisonPolicy;
use App\Services\CanonicalPricing\Enums\ComponentType;
use App\Services\CanonicalPricing\Enums\ComponentUnit;
use App\Services\CanonicalPricing\Enums\ContractComparability;
use App\Services\CanonicalPricing\Enums\EstimateMethod;
use App\Services\CanonicalPricing\Enums\PeriodPricingUnavailableReason;
use App\Services\CanonicalPricing\Enums\PhaseKind;
use App\Services\CanonicalPricing\Enums\PriceRole;
use App\Services\CanonicalPricing\ForwardPremium\PremiumEstimate;
use App\Services\CanonicalPricing\MarketReset\DTO\ResetEstimate;
use App\Services\CanonicalPricing\MarketReset\DTO\ResetEstimateRequest;
use App\Services\CanonicalPricing\MarketReset\DTO\ResetPremiumCandidate;
use App\Services\CanonicalPricing\MarketReset\Enums\ResetEstimateBasis;
use App\Services\CanonicalPricing\MarketReset\MarketResetPriceEstimator;
use App\Services\CanonicalPricing\SpotForward\DTO\SpotEstimate;
use App\Services\CanonicalPricing\SpotForward\Enums\SpotEstimateBasis;
use App\Services\CanonicalPricing\SupplierAdjusted\DTO\PriceEpisodeAnchor;
use App\Services\CanonicalPricing\SupplierAdjusted\DTO\SupplierAdjustedCandidate;
use App\Services\CanonicalPricing\SupplierAdjusted\DTO\SupplierAdjustedEstimate;
use App\Services\CanonicalPricing\SupplierAdjusted\DTO\SupplierAdjustedEstimateRequest;
use App\Services\CanonicalPricing\SupplierAdjusted\Enums\SupplierAdjustedEstimateBasis;
use App\Services\CanonicalPricing\SupplierAdjusted\SupplierAdjustedEligibility;
use App\Services\CanonicalPricing\SupplierAdjusted\SupplierAdjustedPriceEstimator;
use App\Services\CanonicalPricing\Support\EnergyRuleOfferTerms;
use App\Services\CanonicalPricing\Support\MonthlyUsageProfileBuilder;
use App\Services\CanonicalPricing\Support\PhaseTimelineBuilder;
use App\Services\DTO\EnergyUsage;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Costs a contract's canonical pricing phases across the next 12 months from a signup date
 * and assigns a deterministic comparison verdict (ContractComparability).
 *
 * Reuses MonthlyUsageProfileBuilder for the usage distribution so the numbers stay
 * identical to the legacy calculator for constant-price contracts, then applies each
 * phase's rates to the day-fraction of usage falling inside it. Fails closed: any
 * uncovered window that cannot be honestly estimated excludes the contract.
 *
 * See app/Services/CanonicalPricing/AGENTS.md for the algorithm and policy table.
 */
class CanonicalContractPriceCalculator
{
    /**
     * On a Spot contract the energy price is always spot base + margin. A standalone per-kWh
     * energy rate at or below this ceiling (c/kWh) is treated as a misclassified spot margin
     * rather than an all-in fixed price: no supplier sells all-in energy this cheaply, while
     * spot margins are essentially always well under it. A rate above the ceiling (e.g. a
     * market-price product at ~7 c/kWh) is left as a genuine fixed energy price.
     */
    private const SPOT_MARGIN_CEILING_CENTS = 2.0;

    public function __construct(
        private readonly MarketResetPriceEstimator $resetEstimator,
        private readonly SupplierAdjustedPriceEstimator $supplierAdjustedEstimator,
        private readonly SupplierAdjustedEligibility $supplierAdjustedEligibility = new SupplierAdjustedEligibility,
        private readonly PhaseTimelineBuilder $timelineBuilder = new PhaseTimelineBuilder,
        private readonly MonthlyUsageProfileBuilder $usageProfileBuilder = new MonthlyUsageProfileBuilder,
        private readonly float $vatMultiplier = 1.255,
    ) {}

    public function resetForwardShiftEnabled(): bool
    {
        return $this->resetEstimator->enabled();
    }

    public function supplierAdjustedCandidate(
        string $contractId,
        CanonicalContractData $data,
        ContractContext $context,
        ?CarbonInterface $comparisonDate = null,
        ComparisonPolicy $policy = ComparisonPolicy::Current,
    ): ?SupplierAdjustedCandidate {
        $data = $data->withVatBasis($context->includesVat(), $this->vatMultiplier);
        if ($policy === ComparisonPolicy::Historical) {
            return $this->supplierAdjustedEligibility->candidate($contractId, $data, $context);
        }

        $baseHybrid = SupplierAdjustedEligibility::isBaseHybrid($data, $context);
        if ($baseHybrid) {
            $data = $this->withoutZeroBaseEffectPlaceholders($data);
        }
        if (ContractType::fromSource($context->contractType) !== ContractType::OpenEnded
            || (! $baseHybrid && PricingModel::fromSource($context->pricingModel) !== PricingModel::FixedPrice)
            || ($data->calculationStatus !== CalculationStatus::Exact && ! ($baseHybrid && $data->calculationStatus === CalculationStatus::Unsupported))
            || $data->structuredPricingStatus !== 'complete'
            || $data->recurringSchedule->present || ($data->consumptionEffect->present && ! $baseHybrid)) {
            return null;
        }
        $start = CarbonImmutable::parse(($comparisonDate ?? CarbonImmutable::now('Europe/Helsinki'))->toDateString(), 'Europe/Helsinki')->startOfDay();
        if ((new PromotionTermsAssessment)->isIncomplete($data, $start, $start->addMonthsNoOverflow(12))) {
            return null;
        }
        $metering = MeteringType::fromSource($context->metering);
        if ($metering === null) {
            return null;
        }
        $spot = new SpotAssumptions(null, null);
        $candidate = null;
        // Check even shadowed phases: an unknown or different energy disclosure is not
        // proof of an ordinary unchanged tariff. Billing keeps every original phase.
        foreach ($data->phases as $phase) {
            if (! $phase->hasKnownPricing() || $phase->package !== null
                || ! in_array($phase->phaseKind, [PhaseKind::CurrentStructured, PhaseKind::Introductory, PhaseKind::Normal, PhaseKind::Continuation, PhaseKind::Future], true)
                || $phase->ends->kind === BoundaryKind::Unknown) {
                return null;
            }
            $applicable = $this->candidateApplicablePhases($data, $phase, $start);
            if ($applicable === null) {
                return null;
            }
            $components = [];
            foreach ($phase->components as $component) {
                if (! $component->isBilled() || $component->priceRole === PriceRole::Unknown) {
                    return null;
                }
            }
            foreach ($this->effectiveBilledComponents($phase, $applicable) as $component) {
                if ($component->type !== ComponentType::MonthlyFee
                    && $component->normalAmount !== null && $component->normalAmount !== $component->amount) {
                    return null;
                }
                if ($component->normalAmount !== null && (! is_finite($component->normalAmount) || $component->normalAmount < 0)) {
                    return null;
                }
                $components[] = new CanonicalComponent($component->type, $component->amount, null, $component->unit, PriceRole::Current, $component->vatStatus, $component->energyRule);
            }
            $ordinary = new PricingPhase('', PhaseKind::CurrentStructured,
                new PhaseBoundary(BoundaryKind::ContractStart, null), new PhaseBoundary(BoundaryKind::None, null), $components);
            $next = $this->supplierAdjustedEligibility->candidate($contractId, $data->withComparisonEvidence(phases: [$ordinary]), $context, currentBaseHybrid: $baseHybrid);
            $actual = $this->resolvePhaseRates($phase, $applicable, $metering, $spot);
            $normal = $this->resolvePhaseRates($phase, $applicable, $metering, $spot, normalPrice: true);
            if ($next === null || $actual === null || $normal === null || $actual['buckets'] !== $normal['buckets']
                || ($candidate !== null && ! $candidate->hasSameEnergySignature($next))) {
                return null;
            }
            $candidate ??= $next;
        }
        $segments = $this->timelineBuilder->build($data->phases, $data->recurringSchedule, $start);
        if ($candidate === null || $this->hasUncovered($segments)) {
            return null;
        }
        $currentIndex = $this->phaseIndexAt($segments, $start);
        $current = $this->resolvePhaseRates($data->phases[$currentIndex], $data->phases, $metering, $spot);

        return new SupplierAdjustedCandidate($contractId, $candidate->currentEnergyPriceCentsPerKwh, $current['monthly_fee'],
            energyRates: $candidate->energyRates, metering: $candidate->metering,
            includesVat: $candidate->includesVat, pricingMechanism: $candidate->pricingMechanism);
    }

    /** A projection target is broader than an ordinary monthly-reference donor. */
    public function normalEnergyTargetData(CanonicalContractData $data, ContractContext $context, CarbonImmutable $start): ?CanonicalContractData
    {
        if (! EnergyRulePlan::hasKnownRules($data) || $context->isSpot()) {
            return null;
        }
        $data = $data->withVatBasis($context->includesVat(), $this->vatMultiplier);
        if (SupplierAdjustedEligibility::isBaseHybrid($data, $context)) {
            $data = $this->withoutZeroBaseEffectPlaceholders($data);
        }
        $metering = MeteringType::fromSource($context->metering);
        if ($metering === null) {
            return null;
        }
        $months = $context->isFixedTerm() ? $context->fixedTermMonths() : null;
        $end = $start->addMonthsNoOverflow($months !== null && $months > 0 && $months < 12 ? $months : 12);
        $plan = EnergyRulePlan::build($data, $metering, $start, $end, $this->timelineBuilder, null);
        if ($plan === null || ! $plan->normalAvailable || ($plan->rates($start)['normal'] ?? null) !== $plan->baseline
            || ! ($plan->rates($start)['normal_reference_current'] ?? false)) {
            return null;
        }
        $needsProjection = false;
        foreach (array_merge([$start], $plan->boundaries) as $date) {
            if ($date->gte($end)) {
                continue;
            }
            $rates = $plan->rates($date);
            $needsProjection = $needsProjection || $rates === null || $rates['actual_estimated'] || $rates['normal_estimated'];
        }
        if (! $needsProjection) {
            return null;
        }
        $segments = $this->timelineBuilder->build($data->phases, $data->recurringSchedule, $start);
        $index = $this->phaseIndexAt($segments, $start) ?? $this->applicableKnownPhaseIndex($data, $start, $start);
        $current = $index === null ? null : $this->resolvePhaseRates($data->phases[$index], $data->phases, $metering, new SpotAssumptions(null, null));
        if ($current === null || $current['uses_spot']) {
            return null;
        }
        $components = [];
        foreach ($plan->baseline as $key => $rate) {
            $components[] = new CanonicalComponent(ComponentType::from($key), $rate, null, ComponentUnit::CentsPerKwh, PriceRole::Current);
        }
        // This copy is estimator input only. Original fee phases remain in costWindow.
        $components[] = new CanonicalComponent(ComponentType::MonthlyFee, $current['monthly_fee'], null, ComponentUnit::EurPerMonth, PriceRole::Current);

        return $data->withComparisonEvidence(phases: [new PricingPhase('', PhaseKind::CurrentStructured,
            new PhaseBoundary(BoundaryKind::ContractStart, null), new PhaseBoundary(BoundaryKind::None, null), $components)]);
    }

    public function normalEnergyCandidate(string $id, CanonicalContractData $data, ContractContext $context, CarbonImmutable $start): ?SupplierAdjustedCandidate
    {
        $target = $this->normalEnergyTargetData($data, $context, $start);
        if ($target === null || $data->recurringSchedule->present) {
            return null;
        }
        // A finite contract term is not a guarantee for its independent normal tariff.
        $targetContext = $context->isFixedTerm()
            ? new ContractContext($context->pricingModel, ContractType::OpenEnded->value, $context->metering, null, $context->targetGroup)
            : $context;
        if ($target->calculationStatus === CalculationStatus::Incomplete && $this->onlyFuturePricingUnknown($target)) {
            // The complete normal map was proved above. Unknown future prices do not
            // make this estimator-only current quote incomplete.
            $target = new CanonicalContractData($target->phases, $target->recurringSchedule, $target->consumptionEffect,
                CalculationStatus::EstimateRequired, $target->missingFacts, $target->misleadingState,
                $target->structuredPricingStatus, $target->issueCodes, $target->sourceCampaignEnergyRates);
        }
        $candidate = (new SupplierAdjustedEligibility(currentNormalEvidence: true))->candidate($id, $target, $targetContext, currentBaseHybrid: true);
        if ($candidate === null) {
            return null;
        }

        return new SupplierAdjustedCandidate($id, $candidate->currentEnergyPriceCentsPerKwh, $candidate->monthlyFeeEur,
            $candidate->energyRates, $candidate->metering, $candidate->includesVat, $candidate->pricingMechanism, normalTariffEvidence: true);
    }

    public function resetPremiumCandidate(
        string $contractId,
        CanonicalContractData $data,
        ContractContext $context,
        CarbonImmutable $start,
    ): ?ResetPremiumCandidate {
        $baseHybrid = SupplierAdjustedEligibility::isBaseHybrid($data, $context);
        if ($baseHybrid) {
            $data = $this->withoutZeroBaseEffectPlaceholders($data);
        }
        if (! $this->resetEstimator->enabled() || ! $data->recurringSchedule->isActiveReset()
            || ! in_array(ContractType::fromSource($context->contractType), [ContractType::OpenEnded, ContractType::FixedTerm], true)
            || (! $baseHybrid && PricingModel::fromSource($context->pricingModel) !== PricingModel::FixedPrice)
            || (! $baseHybrid && ($data->consumptionEffect->present || $data->calculationStatus === CalculationStatus::Unsupported))
            || $data->structuredPricingStatus !== 'complete'
            || ($data->calculationStatus === CalculationStatus::Incomplete && ! $this->onlyFuturePricingUnknown($data))) {
            return null;
        }
        $metering = MeteringType::fromSource($context->metering);
        if ($metering === null) {
            return null;
        }
        $data = $data->withVatBasis($context->includesVat(), $this->vatMultiplier);
        $months = $context->isFixedTerm() ? $context->fixedTermMonths() : null;
        $end = $start->addMonthsNoOverflow($months !== null && $months > 0 && $months < 12 ? $months : 12);
        if ((new PromotionTermsAssessment)->isIncomplete($data, $start, $end)) {
            return null;
        }
        $spot = new SpotAssumptions(null, null);
        $segments = $this->timelineBuilder->build($data->phases, $data->recurringSchedule, $start);
        $tail = $this->resetTailStart($data, $segments, $start, $metering, $spot, false);
        $segments = $this->segmentsUntil($this->splitSegmentsAt($segments, $tail), $end);
        $energy = null;
        $tailKeys = [];
        foreach ($segments as $segment) {
            if ($segment->start->gte($tail)) {
                $tailKeys[$segment->start->format('Y-m')] = true;
            } elseif (! $segment->isCovered()) {
                return null;
            }
        }
        foreach ($data->phases as $phase) {
            if (! $phase->hasKnownPricing()) {
                continue;
            }
            if ($phase->package !== null) {
                return null;
            }
            foreach ($phase->components as $component) {
                if (! $component->isBilled() || $component->priceRole === PriceRole::Unknown) {
                    return null;
                }
            }
            $applicable = $this->candidateApplicablePhases($data, $phase, $start);
            if ($applicable === null) {
                return null;
            }
            $seen = [];
            foreach ($this->effectiveBilledComponents($phase, $applicable) as $component) {
                if ($component->type === ComponentType::MonthlyFee) {
                    if ($baseHybrid && ($component->unit !== ComponentUnit::EurPerMonth
                        || $component->amount === null || ! is_finite($component->amount) || $component->amount < 0
                        || ($component->normalAmount !== null && (! is_finite($component->normalAmount) || $component->normalAmount < 0)))) {
                        return null;
                    }

                    continue;
                }
                if (! $component->type->isPerKwhEnergy() || $component->unit !== ComponentUnit::CentsPerKwh
                    || $component->amount === null || ! is_finite($component->amount)
                    || ($component->normalAmount !== null && $component->normalAmount !== $component->amount)
                    || isset($seen[$component->type->value])) {
                    return null;
                }
                $seen[$component->type->value] = $component->amount;
            }
            $rates = $this->resolvePhaseRates($phase, $applicable, $metering, $spot);
            $normal = $this->resolvePhaseRates($phase, $applicable, $metering, $spot, normalPrice: true);
            if ($rates === null || $normal === null || $rates['uses_spot']
                || $rates['buckets'] !== $normal['buckets'] || ($energy !== null && $energy !== $rates['buckets'])) {
                return null;
            }
            $resolvedNames = array_map(SupplierAdjustedEstimate::energyBucket(...), array_keys($rates['buckets']));
            sort($resolvedNames);
            $declaredNames = array_keys($seen);
            sort($declaredNames);
            if ($declaredNames !== $resolvedNames) {
                return null;
            }
            $energy = $rates['buckets'];
        }
        if ($energy === null || $tailKeys === []) {
            return null;
        }
        $named = [];
        foreach ($energy as $bucket => $rate) {
            $named[SupplierAdjustedEstimate::energyBucket($bucket)] = $rate;
        }
        ksort($named);

        return new ResetPremiumCandidate($contractId, $named, $metering->value, $context->includesVat(),
            $data->recurringSchedule->cadence, $this->resetPeriodStart($data, $tail),
            $tail->subDay()->startOfMonth(), $tail, array_keys($tailKeys),
            pricingMechanism: $baseHybrid ? PricingModel::Hybrid->value : $context->pricingModel);
    }

    /**
     * Candidate proof only: use already-applicable phases, with one disclosed fee-intro exception.
     * This does not change the billing resolver's inheritance rules.
     *
     * @return array<int, PricingPhase>|null
     */
    private function candidateApplicablePhases(CanonicalContractData $data, PricingPhase $phase, CarbonImmutable $start): ?array
    {
        $phaseSegments = $this->timelineBuilder->build([$phase], $data->recurringSchedule, $start);
        $covered = array_values(array_filter($phaseSegments, static fn (WindowSegment $segment) => $segment->isCovered()));
        if ($covered === [] && $phase->ends->kind === BoundaryKind::Date) {
            $past = $this->parseScheduleDate($phase->ends->value);
            if ($past !== null && $past->lessThan($start)
                && in_array($phase->starts->kind, [BoundaryKind::Date, BoundaryKind::ContractStart, BoundaryKind::None, BoundaryKind::Unknown], true)) {
                // An inclusive past end supplies a candidate-only coverage check, not a
                // repricing date. Callers still compare this phase's complete energy map.
                $start = $past;
                $phaseSegments = $this->timelineBuilder->build([$phase], $data->recurringSchedule, $start);
                $covered = array_values(array_filter($phaseSegments, static fn (WindowSegment $segment) => $segment->isCovered()));
            }
        }
        if ($covered === []) {
            return null;
        }
        $applicable = [];
        $this->applicableKnownPhaseIndex($data, $start, $covered[0]->start, $applicable);
        // A typed fee-only introduction may inherit its adjacent typed Normal baseline.
        // A genuinely Future energy phase cannot prove a missing current rate or bucket.
        if ($phase->phaseKind === PhaseKind::Introductory
            && count(array_filter($phase->components, static fn (CanonicalComponent $component) => $component->type !== ComponentType::MonthlyFee)) === 0) {
            foreach ($data->phases as $index => $baseline) {
                if ($baseline->phaseKind !== PhaseKind::Normal || isset($applicable[$index])) {
                    continue;
                }
                $baselineSegments = $this->timelineBuilder->build([$baseline], $data->recurringSchedule, $start);
                foreach ($baselineSegments as $segment) {
                    if ($segment->isCovered()) {
                        if ($segment->start->equalTo($covered[array_key_last($covered)]->end)) {
                            $applicable[$index] = $baseline;
                        }
                        break;
                    }
                }
            }
        }

        return $applicable;
    }

    public function usesSpotPricing(
        CanonicalContractData $data,
        ContractContext $context,
        ?CarbonInterface $startDate = null,
        ComparisonPolicy $policy = ComparisonPolicy::Current,
    ): bool {
        if ($context->isSpot()) {
            return true;
        }

        $start = CarbonImmutable::parse(($startDate ?? CarbonImmutable::now('Europe/Helsinki'))->toDateString(), 'Europe/Helsinki')->startOfDay();
        $months = $context->isFixedTerm() ? $context->fixedTermMonths() : null;
        foreach ($data->phases as $phase) {
            if ($policy === ComparisonPolicy::Current && $months !== null && $months > 0 && $months < 12
                && ! (new PromotionTermsAssessment)->intersects($phase, $start, $start->addMonthsNoOverflow($months), $data)) {
                continue;
            }
            foreach ($phase->billedComponents() as $component) {
                if ($component->type === ComponentType::SpotMargin) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Resolve the seller-set direct General rate in effect at signup. This boundary uses
     * the normal phase timeline and inheritance rules, but it does not calculate a bill.
     */
    public function directGeneralRate(
        CanonicalContractData $data,
        ContractContext $context,
        CarbonInterface|string $startDate,
    ): ?float {
        $data = $data->withVatBasis($context->includesVat(), $this->vatMultiplier);
        if ($context->isSpot() || MeteringType::fromSource($context->metering) !== MeteringType::General) {
            return null;
        }

        $windowStart = CarbonImmutable::parse(
            $startDate instanceof CarbonInterface ? $startDate->toDateString() : $startDate,
            'Europe/Helsinki',
        )->startOfDay();
        $segments = $this->timelineBuilder->build($data->phases, $data->recurringSchedule, $windowStart);
        $phaseIndex = $this->resolveCurrentPhaseIndex($segments, $data, $windowStart);
        $phase = $phaseIndex !== null ? ($data->phases[$phaseIndex] ?? null) : null;
        if ($phase === null || $phase->package !== null) {
            return null;
        }

        $rates = $this->resolvePhaseRates(
            $phase,
            $data->phases,
            MeteringType::General,
            new SpotAssumptions(null, null),
            isSpot: false,
        );
        $rate = $rates !== null && ! $rates['uses_spot'] && $rates['package'] === null
            ? $rates['display']['general']
            : null;

        return $rate !== null && is_finite($rate) && $rate > 0.0 ? $rate : null;
    }

    public function calculate(
        CanonicalContractData $data,
        ContractContext $context,
        EnergyUsage $usage,
        SpotAssumptions $spot,
        ?CarbonInterface $startDate = null,
        ?PriceEpisodeAnchor $priceEpisodeAnchor = null,
        ?SpotEstimate $spotEstimate = null,
        ComparisonPolicy $policy = ComparisonPolicy::Current,
        ?PremiumEstimate $premium = null,
        ?PremiumEstimate $resetPremium = null,
        ?NormalEnergyProjection $normalEnergyProjection = null,
    ): CanonicalPricingOutcome {
        $windowStart = CarbonImmutable::parse(($startDate ?? CarbonImmutable::now('Europe/Helsinki'))->toDateString(), 'Europe/Helsinki')->startOfDay();

        if ($policy === ComparisonPolicy::Current) {
            if ($context->isFixedTerm() && in_array($context->fixedTimeRange, ['Below6', 'Between711'], true)) {
                return $this->excluded(ContractComparability::ExcludedIncomplete, $context, $data, ['unknown_short_fixed_term_duration']);
            }
            $months = $context->isFixedTerm() ? $context->fixedTermMonths() : null;
            $horizon = $windowStart->addMonthsNoOverflow($months !== null && $months > 0 && $months < 12 ? $months : 12);
            $promotion = new PromotionTermsAssessment;
            if ($promotion->isIncomplete($data, $windowStart, $horizon)) {
                return $this->excluded(ContractComparability::ExcludedIncomplete, $context, $data, [PromotionTermsAssessment::INSUFFICIENT]);
            }
            // Post-term phases cannot supply inherited rates, normal-price savings, or confidence.
            if ($horizon->lessThan($windowStart->addMonthsNoOverflow(12))) {
                $data = $data->withComparisonEvidence(phases: array_values(array_filter(
                    $data->phases,
                    fn (PricingPhase $phase) => $promotion->intersects($phase, $windowStart, $horizon, $data),
                )));
            }
        }

        $data = $data->withVatBasis($context->includesVat(), $this->vatMultiplier);
        $spot = $spot->withVatBasis($context->includesVat(), $this->vatMultiplier);
        $spotEstimate = $spotEstimate?->withVatBasis($context->includesVat(), $this->vatMultiplier);

        // Resolve the billed Spot mechanism from the supplied forward estimate even
        // when no historical shape exists. Its original provenance stays in spotEstimate.
        if (! $spot->isAvailable() && $spotEstimate?->basis === SpotEstimateBasis::ForwardCurve) {
            $spot = new SpotAssumptions(
                $spotEstimate->wholesaleForBucket($windowStart->format('Y-m'), 'DayTime'),
                $spotEstimate->wholesaleForBucket($windowStart->format('Y-m'), 'NightTime'),
            );
        }

        if ($data->structuredPricingStatus === 'conflicting') {
            return $this->excluded(ContractComparability::ExcludedIncomplete, $context, $data);
        }

        if (($data->calculationStatus === CalculationStatus::Unsupported
                || ($policy === ComparisonPolicy::Current && $data->calculationStatus !== CalculationStatus::Incomplete
                    && SupplierAdjustedEligibility::isBaseHybrid($data, $context)))
            && $data->consumptionEffect->present
            && $data->consumptionEffect->appliesTo === 'base_contract') {
            $data = $this->withoutZeroBaseEffectPlaceholders($data);
        }

        $metering = $this->deriveMetering($data->phases, $context->metering);
        $profile = $this->usageProfileBuilder->build($metering, $usage, isSpotContract: false);

        $segments = $this->timelineBuilder->build($data->phases, $data->recurringSchedule, $windowStart);
        if ($data->recurringSchedule->isActiveReset()) {
            $tailStart = $this->resetTailStart($data, $segments, $windowStart, $metering, $spot, $context->isSpot());
            $segments = $this->splitSegmentsAt($segments, $tailStart);
        }
        $currentPhaseIndex = $this->phaseIndexAt($segments, $windowStart)
            ?? $this->applicableKnownPhaseIndex($data, $windowStart, $windowStart);
        if ($policy === ComparisonPolicy::Current) {
            foreach ($this->segmentsUntil($segments, $horizon) as $segment) {
                $phaseIndex = $segment->phaseIndex ?? $this->applicableKnownPhaseIndex($data, $windowStart, $segment->start);
                if ($phaseIndex !== null && $this->hasAmbiguousEnergyMechanisms($data->phases[$phaseIndex], $data->phases)) {
                    return $this->excluded(ContractComparability::ExcludedIncomplete, $context, $data, ['ambiguous_energy_mechanisms']);
                }
            }
        }
        $hasUncovered = $this->hasUncovered($segments);
        $fullyCovered = ! $hasUncovered;

        // Current services authorize this parser mode with exact batched source proof.
        // Historical never selects these semantics.
        if ($policy === ComparisonPolicy::Current && EnergyRulePlan::hasKnownRules($data)) {
            $months = $context->isFixedTerm() ? $context->fixedTermMonths() : null;
            $termMonths = $months !== null && $months > 0 && $months < 12 ? $months : null;
            $end = $windowStart->addMonthsNoOverflow($termMonths ?? 12);
            $hybrid = SupplierAdjustedEligibility::isBaseHybrid($data, $context);
            $supported = ! $context->isSpot()
                && ($data->calculationStatus !== CalculationStatus::Unsupported || $hybrid)
                && ($data->calculationStatus !== CalculationStatus::Incomplete || $this->onlyFuturePricingUnknown($data))
                && (! $data->consumptionEffect->present || $hybrid);
            if ($supported && $normalEnergyProjection === null) {
                $target = $this->normalEnergyTargetData($data, $context, $windowStart);
                if ($target !== null) {
                    $targetSegments = $this->timelineBuilder->build($target->phases, $target->recurringSchedule, $windowStart);
                    $targetSegments = $this->segmentsUntil($targetSegments, $end);
                    if ($target->recurringSchedule->isActiveReset()) {
                        $tail = $this->resetTailStart($target, $targetSegments, $windowStart, $metering, $spot, false);
                        $targetSegments = $this->splitSegmentsAt($targetSegments, $tail);
                        $estimate = $this->resolveResetEstimate($target, $context, $metering, $profile, $spot, $windowStart, $targetSegments, 0, false, $policy, $resetPremium, allowZeroAnchor: true);
                        $rates = [];
                        foreach ($target->phases[0]->components as $component) {
                            if ($component->type->isPerKwhEnergy()) {
                                $rates[$component->type->value] = $component->amount;
                            }
                        }
                    } else {
                        $candidate = $this->normalEnergyCandidate('', $data, $context, $windowStart);
                        $estimate = $candidate === null ? null : $this->resolveSupplierAdjustedEstimate($candidate, $profile, $windowStart, $targetSegments,
                            $priceEpisodeAnchor ?? PriceEpisodeAnchor::missing(), $context, $premium, true);
                        $rates = $candidate?->normalizedEnergyRates() ?? [];
                    }
                    if ($estimate !== null) {
                        $normalEnergyProjection = new NormalEnergyProjection($rates, $estimate);
                    }
                }
            }
            $plan = $supported ? EnergyRulePlan::build($data, $metering, $windowStart, $end, $this->timelineBuilder, $normalEnergyProjection) : null;
            if ($plan?->projection?->estimate instanceof ResetEstimate) {
                $buckets = [];
                foreach ($profile as $monthBuckets) {
                    foreach ($monthBuckets as $bucket => $kwh) {
                        $key = SupplierAdjustedEstimate::energyBucket($bucket);
                        if (array_key_exists($key, $plan->baseline)) {
                            $buckets[$bucket] = $plan->baseline[$key];
                        }
                    }
                }
                $reference = $this->weightedEnergyPrice(['buckets' => $buckets], $profile);
                if ($reference !== null && abs($reference - $normalEnergyProjection->estimate->currentPeriodEnergyPriceCentsPerKwh) > 0.0001) {
                    $plan = null;
                }
            }
            if ($plan === null || $currentPhaseIndex === null) {
                // Unsupported known guarantees cannot fall through to legacy offsets.
                return $this->excluded(ContractComparability::ExcludedIncomplete, $context, $data, ['unsupported_energy_rule_plan']);
            }
            foreach ($plan->boundaries as $boundary) {
                $segments = $this->splitSegmentsAt($segments, $boundary);
            }
            $segments = $this->segmentsUntil($segments, $end);

            return $this->costWindow($data, $context, $metering, $profile, $spot, $windowStart,
                $segments, $currentPhaseIndex,
                $hybrid ? ContractComparability::BaseOnlyHybrid : ($termMonths === null ? ContractComparability::ComparableExact : ContractComparability::TermPriceOnly),
                true, defaultEstimateMethod: $hybrid ? EstimateMethod::HybridBaseOnly : ($termMonths === null ? EstimateMethod::None : EstimateMethod::TermPriceAnnualized),
                annualizationFactor: $termMonths === null ? 1.0 : 12 / $termMonths,
                termMonths: $termMonths, energyRulePlan: $plan, policy: $policy);
        }

        // 1. Hybrid / unsupported: cost every disclosed base-price phase when the full
        //    comparison window is covered. The unknown consumption effect stays excluded.
        if ($data->calculationStatus === CalculationStatus::Unsupported
            || ($policy === ComparisonPolicy::Current && $data->calculationStatus !== CalculationStatus::Incomplete
                && SupplierAdjustedEligibility::isBaseHybrid($data, $context))) {
            // A short Hybrid still has a real finite contract term. Cost only that term,
            // preserve its unannualized offer benefit, and annualize the same base-only
            // result for comparison. Handling Unsupported first must not erase Fixed6.
            if ($this->isFixedTermTermOnly($context, $segments, $windowStart)) {
                $termMonths = $context->fixedTermMonths();
                $termSegments = $this->segmentsUntil($segments, $windowStart->addMonthsNoOverflow($termMonths));
                $termHasUncovered = $this->hasUncovered($termSegments);
                $reset = $this->resolveResetEstimate(
                    $data,
                    $context,
                    $metering,
                    $profile,
                    $spot,
                    $windowStart,
                    $termSegments,
                    $currentPhaseIndex,
                    heldForward: false,
                    policy: $policy,
                    premium: $resetPremium,
                );

                return $this->costWindow(
                    $data,
                    $context,
                    $metering,
                    $profile,
                    $spot,
                    $windowStart,
                    $termSegments,
                    $currentPhaseIndex,
                    ContractComparability::BaseOnlyHybrid,
                    $termHasUncovered,
                    $reset,
                    EstimateMethod::HybridBaseOnly,
                    12 / $termMonths,
                    $termMonths,
                    spotEstimate: $spotEstimate,
                    policy: $policy,
                );
            }

            $supplierCandidate = $policy === ComparisonPolicy::Current
                ? $this->supplierAdjustedCandidate('', $data, $context, $windowStart) : null;
            $supplierAdjusted = $supplierCandidate !== null
                ? $this->resolveSupplierAdjustedEstimate($supplierCandidate, $profile, $windowStart, $segments,
                    $priceEpisodeAnchor ?? PriceEpisodeAnchor::missing(), $context, $premium, true)
                : null;
            $reset = $this->resolveResetEstimate(
                $data,
                $context,
                $metering,
                $profile,
                $spot,
                $windowStart,
                $segments,
                $currentPhaseIndex,
                heldForward: false,
                policy: $policy,
                premium: $resetPremium,
            );

            return $this->costWindow(
                $data,
                $context,
                $metering,
                $profile,
                $spot,
                $windowStart,
                $segments,
                $currentPhaseIndex,
                ContractComparability::BaseOnlyHybrid,
                ! $fullyCovered,
                $reset,
                EstimateMethod::HybridBaseOnly,
                supplierAdjusted: $supplierAdjusted,
                spotEstimate: $spotEstimate,
                policy: $policy,
            );
        }

        // 2. Cost the real short term, then annualize once. Historical replay retains
        //    the former coverage-dependent policy.
        if (($policy === ComparisonPolicy::Current || ! $fullyCovered) && $this->isFixedTermTermOnly($context, $segments, $windowStart)) {
            $termMonths = $context->fixedTermMonths();

            return $this->costWindow(
                $data,
                $context,
                $metering,
                $profile,
                $spot,
                $windowStart,
                $this->segmentsUntil($segments, $windowStart->addMonthsNoOverflow($termMonths)),
                $currentPhaseIndex,
                ContractComparability::TermPriceOnly,
                $this->hasUncovered($this->segmentsUntil($segments, $windowStart->addMonthsNoOverflow($termMonths))),
                $policy === ComparisonPolicy::Current ? $this->resolveResetEstimate(
                    $data, $context, $metering, $profile, $spot, $windowStart,
                    $this->segmentsUntil($segments, $windowStart->addMonthsNoOverflow($termMonths)),
                    $currentPhaseIndex, heldForward: false, policy: $policy, premium: $resetPremium,
                ) : null,
                EstimateMethod::TermPriceAnnualized,
                12 / $termMonths,
                $termMonths,
                spotEstimate: $spotEstimate,
                policy: $policy,
            );
        }

        // 3. Genuinely broken structured pricing — with two documented exceptions that are fully
        //    costable despite the LLM marking them incomplete:
        //    (a) a Spot contract with a disclosed margin (price = spot market + margin + fee); some are
        //        marked incomplete only because the description phrases the margin as a "toimitusmaksu";
        //    (b) a fully-covered contract whose only gap is a duplicate/ambiguous monthly fee — we
        //        resolve it conservatively to the higher fee.
        if ($data->calculationStatus === CalculationStatus::Incomplete
            && ! $this->onlyFuturePricingUnknown($data)
            && ! $this->isCostableSpot($data, $context, $currentPhaseIndex, $metering, $spot)
            && ! $this->isResolvableDuplicateFee($data, $currentPhaseIndex, $fullyCovered, $metering, $spot, $context->isSpot())) {
            return $this->excluded(ContractComparability::ExcludedIncomplete, $context, $data);
        }

        // Unknown future prices are an estimate, not a zero-cost period. The costing
        // pass must still identify an applicable billed price for each missing slice.
        $estimateFill = ! $fullyCovered;

        $supplierCandidate = $this->supplierAdjustedCandidate('', $data, $context, $windowStart, $policy);
        $supplierAdjusted = $supplierCandidate !== null
            ? $this->resolveSupplierAdjustedEstimate(
                $supplierCandidate,
                $profile,
                $windowStart,
                $segments,
                $priceEpisodeAnchor ?? PriceEpisodeAnchor::missing(),
                $context,
                $policy === ComparisonPolicy::Current ? $premium : null,
                $policy === ComparisonPolicy::Current,
            )
            : null;
        $currentResetTail = $policy === ComparisonPolicy::Current && $this->resetEstimator->enabled()
            && ! $context->isSpot() && isset($tailStart) && $tailStart->lt($windowStart->addMonthsNoOverflow(12));
        $comparability = $supplierAdjusted !== null || $estimateFill || $currentResetTail
            ? ContractComparability::ComparableEstimate
            : ($data->calculationStatus === CalculationStatus::Exact
                ? ContractComparability::ComparableExact
                : ContractComparability::ComparableEstimate);

        return $this->costWindow(
            $data,
            $context,
            $metering,
            $profile,
            $spot,
            $windowStart,
            $segments,
            $currentPhaseIndex,
            $comparability,
            $estimateFill,
            $this->resolveResetEstimate($data, $context, $metering, $profile, $spot, $windowStart, $segments, $currentPhaseIndex, heldForward: false, policy: $policy, premium: $resetPremium),
            defaultEstimateMethod: $currentResetTail ? EstimateMethod::HoldCurrentRecurringPrice : EstimateMethod::None,
            supplierAdjusted: $supplierAdjusted,
            spotEstimate: $spotEstimate,
            policy: $policy,
        );
    }

    /**
     * Cost one exact counterfactual bill period from canonical phases.
     *
     * Relative phase boundaries are anchored on the requested period start because the
     * contract is treated as an offer accepted on that date. Absolute disclosed dates
     * keep their calendar meaning. Realized Spot observations replace rolling averages
     * only for the period pass; annual comparability still comes from calculate().
     */
    public function calculatePeriod(
        CanonicalContractData $data,
        ContractContext $context,
        CanonicalPeriodPricingRequest $request,
        SpotAssumptions $annualSpot,
        CanonicalPricingOutcome $annualOutcome,
    ): CanonicalPeriodPricingOutcome {
        $windowStart = $request->startDate->setTimezone('Europe/Helsinki')->startOfDay();
        $periodEnd = $request->endDate->setTimezone('Europe/Helsinki')->startOfDay()->addDay();

        if ($periodEnd->lessThanOrEqualTo($windowStart) || $periodEnd->greaterThan($windowStart->addMonthsNoOverflow(12))) {
            return $this->unavailablePeriod($annualOutcome->comparability, PeriodPricingUnavailableReason::NoPricing);
        }

        if ((new PromotionTermsAssessment)->isIncomplete($data, $windowStart, $periodEnd)
            || in_array(PromotionTermsAssessment::INSUFFICIENT, $annualOutcome->assumptions, true)) {
            return $this->unavailablePeriod(ContractComparability::ExcludedIncomplete, PeriodPricingUnavailableReason::InsufficientPromotionTerms);
        }

        $data = $data->withVatBasis($context->includesVat(), $this->vatMultiplier);
        $annualSpot = $annualSpot->withVatBasis($context->includesVat(), $this->vatMultiplier);
        $metering = $this->deriveMetering($data->phases, $context->metering);
        $annualSegments = $this->timelineBuilder->build($data->phases, $data->recurringSchedule, $windowStart);
        $periodRulePlan = null;
        if (EnergyRulePlan::hasKnownRules($data)) {
            if ($annualOutcome->comparability === ContractComparability::BaseOnlyHybrid) {
                $data = $this->withoutZeroBaseEffectPlaceholders($data);
            }
            $periodRulePlan = EnergyRulePlan::build($data, $metering, $windowStart, $periodEnd, $this->timelineBuilder, null);
            if ($periodRulePlan === null) {
                return $this->unavailablePeriod(ContractComparability::ExcludedIncomplete, PeriodPricingUnavailableReason::NoPricing);
            }
            foreach ($periodRulePlan->boundaries as $boundary) {
                $annualSegments = $this->splitSegmentsAt($annualSegments, $boundary);
            }
        }
        $normalAvailable = $periodRulePlan?->normalAvailable ?? true;
        $periodLatestKnown = false;
        $periodSegments = $this->segmentsUntil($annualSegments, $periodEnd);
        $lastCoveredPhaseIndex = $this->lastCoveredPhaseIndex($annualSegments);
        $canFill = $data->recurringSchedule->isActiveReset()
            || $context->isSpot()
            || $annualOutcome->comparability === ContractComparability::BaseOnlyHybrid;

        $periodSpot = new SpotAssumptions(0.0, 0.0);
        $resolved = [];
        $usesSpot = false;

        foreach ($periodSegments as $segment) {
            $phaseIndex = $segment->phaseIndex;
            if ($phaseIndex === null && $canFill) {
                $phaseIndex = $this->applicableKnownPhaseIndex($data, $windowStart, $segment->start);
            }

            if ($phaseIndex === null) {
                continue;
            }

            if ($this->hasAmbiguousEnergyMechanisms($data->phases[$phaseIndex], $data->phases)) {
                return $this->unavailablePeriod(ContractComparability::ExcludedIncomplete, PeriodPricingUnavailableReason::NoPricing);
            }

            $rates = $this->resolvePhaseRates($data->phases[$phaseIndex], $data->phases, $metering, $periodSpot, $context->isSpot());
            if ($rates === null) {
                continue;
            }

            if ($periodRulePlan !== null) {
                $paired = $periodRulePlan->rates($segment->start);
                if ($paired === null) {
                    return $this->unavailablePeriod(ContractComparability::ExcludedIncomplete, PeriodPricingUnavailableReason::NoPricing);
                }
                $rates = $this->withEnergyRuleRates($rates, $paired['actual']);
                $periodLatestKnown = $periodLatestKnown || $paired['latest_known_estimate'];
            }
            $usesSpot = $usesSpot || $rates['uses_spot'];
            $resolved[] = ['segment' => $segment, 'phase_index' => $phaseIndex, 'rates' => $rates];
        }

        $spotMap = [];
        foreach ($request->historicalSpotPrices as $price) {
            // Inclusive-only observations can use the configured rate only in its current period.
            // Older delivery requires explicit hourly ex-VAT evidence, not today's tax rate.
            $priceValue = $context->includesVat() ? $price->centsPerKwhWithTax
                : ($price->centsPerKwhWithoutTax ?? ($price->startsAtUtc->setTimezone('Europe/Helsinki')->toDateString() >= '2024-09-01'
                    ? $price->centsPerKwhWithTax / $this->vatMultiplier : null));
            if ($usesSpot && $priceValue === null) {
                return $this->unavailablePeriod($annualOutcome->comparability, PeriodPricingUnavailableReason::NoPricing, true);
            }
            if ($priceValue !== null) {
                $spotMap[$price->startsAtUtc->utc()->getTimestamp()] = $priceValue;
            }
        }

        $hasComponentDiscount = $this->hasNormalPriceDiscount($data);
        // Consumption-free eligibility only: factual periods never receive a forecast.
        $unchangedEnergy = $this->supplierAdjustedCandidate('', $data, $context, $windowStart) !== null;
        $hasPackage = $this->hasEnergyPackage($data);
        $normalFallbackRates = null;

        if (! $hasComponentDiscount && ! $hasPackage && $lastCoveredPhaseIndex !== null) {
            $normalFallbackRates = $this->resolvePhaseRates(
                $data->phases[$lastCoveredPhaseIndex],
                $data->phases,
                $metering,
                $periodSpot,
                $context->isSpot(),
            );
        }

        foreach ($resolved as $index => $item) {
            $phaseIndex = $item['phase_index'];
            $resolved[$index]['normal_rates'] = match (true) {
                $unchangedEnergy => $this->unchangedEnergyNormalRates($data, $context, $metering, $periodSpot, $item['segment'], $annualSegments),
                $hasComponentDiscount => $this->resolvePhaseRates(
                    $data->phases[$phaseIndex],
                    $data->phases,
                    $metering,
                    $periodSpot,
                    $context->isSpot(),
                    normalPrice: true,
                ),
                $hasPackage => $item['rates'],
                default => $normalFallbackRates ?? $item['rates'],
            };
            if ($periodRulePlan !== null) {
                $normalRates = $resolved[$index]['normal_rates'];
                if ($normalAvailable && $normalRates !== null) {
                    $normalRates = $this->withEnergyRuleRates($normalRates, $periodRulePlan->rates($item['segment']->start)['normal']);
                    $normalRates['monthly_fee'] = $this->energyRuleNormalFee($data, $phaseIndex, $data->phases, $annualSegments, $item['segment'], $item['rates']['monthly_fee'], false);
                }
                $resolved[$index]['normal_rates'] = $normalAvailable ? $normalRates : null;
            }
        }

        $completedSpot = $this->completeRequiredSpotHistory($resolved, $spotMap);
        $spotMap = $completedSpot['map'];

        if (! $completedSpot['available']) {
            return $this->unavailablePeriod(
                $annualOutcome->comparability,
                PeriodPricingUnavailableReason::NoSpotHistory,
                usesSpot: true,
            );
        }

        if (! $annualOutcome->isListed()) {
            return $this->unavailablePeriod($annualOutcome->comparability, PeriodPricingUnavailableReason::NotComparable, $usesSpot);
        }

        if (count($resolved) !== count($periodSegments)) {
            return $this->unavailablePeriod($annualOutcome->comparability, PeriodPricingUnavailableReason::NoPricing, $usesSpot);
        }

        // Exact bill periods never receive the annual market projection.
        $reset = null;

        $periodHours = max(1, $windowStart->utc()->diffInHours($periodEnd->utc()));
        $hourlyKwh = $request->periodKwh / $periodHours;
        $actualTotal = 0.0;
        $normalTotal = 0.0;
        $actualFlatApplied = [];
        $normalFlatApplied = [];
        $breakdown = [];
        $spotMargins = [];
        $firstRates = null;

        foreach ($resolved as $item) {
            /** @var WindowSegment $segment */
            $segment = $item['segment'];
            $phaseIndex = $item['phase_index'];
            $rates = $item['rates'];
            $firstRates ??= $rates;

            $actualTotal += $this->costPeriodSegment(
                $segment,
                $metering,
                $rates,
                $hourlyKwh,
                $spotMap,
                $actualFlatApplied,
                $phaseIndex,
                $reset,
            );

            $normalRates = $item['normal_rates'];

            if ($normalAvailable) {
                if ($normalRates === null) {
                    return $this->unavailablePeriod(
                        $annualOutcome->comparability,
                        PeriodPricingUnavailableReason::NoPricing,
                        $usesSpot,
                    );
                }

                $normalTotal += $this->costPeriodSegment(
                    $segment,
                    $metering,
                    $normalRates,
                    $hourlyKwh,
                    $spotMap,
                    $normalFlatApplied,
                    $hasComponentDiscount ? $phaseIndex : ($lastCoveredPhaseIndex ?? $phaseIndex),
                    $reset,
                );
            }

            if ($rates['spot_margin'] !== null && ! in_array((float) $rates['spot_margin'], $spotMargins, true)) {
                $spotMargins[] = (float) $rates['spot_margin'];
            }

            $breakdown[] = [
                'window_start' => $segment->start->format('Y-m-d'),
                'window_end' => $segment->end->subDay()->format('Y-m-d'),
                'uses_spot' => $rates['uses_spot'],
                'spot_margin_cents' => $rates['spot_margin'],
                'monthly_fee' => $rates['monthly_fee'],
                'energy_cents' => $rates['display']['general']
                    ?? $rates['display']['day']
                    ?? $rates['display']['seasonal_winter']
                    ?? null,
                'energy_package' => ($rates['package'] ?? null)?->toArray(),
                ...($periodRulePlan !== null ? ['energy_price_guaranteed' => $periodRulePlan->rates($segment->start)['actual_guaranteed']
                    && count(array_unique($rates['buckets'], SORT_REGULAR)) === 1
                    && $annualOutcome->comparability !== ContractComparability::BaseOnlyHybrid] : []),
            ];
        }

        $saving = $normalAvailable ? max(0.0, $normalTotal - $actualTotal) : 0.0;
        $factualAnnualAssumptions = array_values(array_filter(
            $annualOutcome->assumptions,
            static fn (string $assumption): bool => ! str_starts_with($assumption, 'supplier_adjusted_')
                && ! str_starts_with($assumption, 'spot_forward_curve_')
                && ! str_starts_with($assumption, 'reset_tail_shifted_')
                && ! str_starts_with($assumption, 'energy_rule_')
                && $assumption !== 'unknown_periods_use_latest_applicable_price_or_disclosed_normal',
        ));
        $assumptions = array_values(array_unique(array_merge($factualAnnualAssumptions, [
            'contract_offer_available_at_period_start',
            'relative_phases_anchor_at_period_start',
            'absolute_phase_dates_preserved',
            'period_consumption_flat_by_actual_hour',
            'monthly_fee_prorated_by_days_over_30',
        ], $periodRulePlan !== null ? [
            'energy_rule_period_uses_published_rates_without_forecast',
            $normalAvailable ? 'energy_rule_normal_known' : 'energy_rule_normal_unavailable',
            ...($periodLatestKnown ? ['energy_rule_latest_known_price_continuation'] : []),
        ] : [], $usesSpot ? ['actual_hourly_spot_prices'] : [], $completedSpot['filled'] ? [
            'missing_spot_hours_filled_with_observed_average',
        ] : [], $hasPackage ? [
            'package_allowance_resets_each_calendar_month',
            'partial_package_fee_and_allowance_prorated_by_calendar_month_fraction',
        ] : [])));

        return new CanonicalPeriodPricingOutcome(
            periodTotal: $actualTotal,
            normalPeriodTotal: $normalAvailable ? $normalTotal : null,
            measuredDiscountSavings: $saving,
            comparability: $annualOutcome->comparability,
            unavailableReason: null,
            usesSpot: $usesSpot,
            monthlyFixedFee: $firstRates['monthly_fee'] ?? null,
            generalKwhPrice: $firstRates['display']['general'] ?? null,
            daytimeKwhPrice: $firstRates['display']['day'] ?? null,
            nighttimeKwhPrice: $firstRates['display']['night'] ?? null,
            seasonalWinterDayKwhPrice: $firstRates['display']['seasonal_winter'] ?? null,
            seasonalOtherKwhPrice: $firstRates['display']['seasonal_other'] ?? null,
            spotMargins: $spotMargins,
            phaseBreakdown: $breakdown,
            assumptions: $assumptions,
        );
    }

    private function withEnergyRuleRates(array $rates, array $energyRates): array
    {
        foreach ($rates['buckets'] as $bucket => $amount) {
            $rates['buckets'][$bucket] = $energyRates[SupplierAdjustedEstimate::energyBucket($bucket)];
        }
        foreach (['energy_general' => 'general', 'energy_day' => 'day', 'energy_night' => 'night', 'energy_seasonal_winter' => 'seasonal_winter', 'energy_seasonal_other' => 'seasonal_other'] as $key => $displayKey) {
            if (array_key_exists($key, $energyRates)) {
                $rates['display'][$displayKey] = $energyRates[$key];
            }
        }

        return $rates;
    }

    /** Exclude ConsumptionEffect disclosures and proven zero Other effect placeholders from the annual base-only calculation copy. */
    private function withoutZeroBaseEffectPlaceholders(CanonicalContractData $data): CanonicalContractData
    {
        return new CanonicalContractData(
            phases: array_map(static fn (PricingPhase $phase) => new PricingPhase(
                label: $phase->label,
                phaseKind: $phase->phaseKind,
                starts: $phase->starts,
                ends: $phase->ends,
                components: array_values(array_filter($phase->components, static fn (CanonicalComponent $component) => ! (
                    $component->type === ComponentType::ConsumptionEffect || ($component->type === ComponentType::Other
                    && $component->unit === ComponentUnit::CentsPerKwh
                    && $component->amount === 0.0
                    && ($component->normalAmount === null || $component->normalAmount === 0.0))
                ))),
                package: $phase->package,
            ), $data->phases),
            recurringSchedule: $data->recurringSchedule,
            consumptionEffect: $data->consumptionEffect,
            calculationStatus: $data->calculationStatus,
            missingFacts: $data->missingFacts,
            misleadingState: $data->misleadingState,
            structuredPricingStatus: $data->structuredPricingStatus,
            issueCodes: $data->issueCodes,
            sourceCampaignEnergyRates: $data->sourceCampaignEnergyRates,
        );
    }

    /**
     * Cost every window segment at its governing phase's rates; optionally hold the current
     * phase forward across uncovered slices.
     *
     * @param  array<int, array<string, float>>  $profile
     * @param  list<WindowSegment>  $segments
     */
    private function costWindow(
        CanonicalContractData $data,
        ContractContext $context,
        MeteringType $metering,
        array $profile,
        SpotAssumptions $spot,
        CarbonImmutable $windowStart,
        array $segments,
        ?int $currentPhaseIndex,
        ContractComparability $comparability,
        bool $estimateFill,
        ?ResetEstimate $reset = null,
        EstimateMethod $defaultEstimateMethod = EstimateMethod::None,
        float $annualizationFactor = 1.0,
        ?int $termMonths = null,
        ?SupplierAdjustedEstimate $supplierAdjusted = null,
        ?SpotEstimate $spotEstimate = null,
        ?EnergyRulePlan $energyRulePlan = null,
        ComparisonPolicy $policy = ComparisonPolicy::Historical,
    ): CanonicalPricingOutcome {
        $usesSpot = false;
        $shiftFloorApplied = false;
        $actualRuleEstimated = $normalRuleEstimated = $latestKnownRuleEstimate = $modelFloorApplied = false;
        $normalAvailable = $energyRulePlan?->normalAvailable ?? true;
        $monthly = array_fill(0, 12, 0.0);
        $normalMonthly = $normalAvailable ? array_fill(0, 12, 0.0) : [];
        $flatApplied = [];
        $normalFlatApplied = [];
        // Keep actual and normal fee timelines separate: unequal constant fees are still held flat.
        $actualBillingFees = $normalBillingFees = [];
        $spans = [];
        $hasComponentDiscount = $this->hasNormalPriceDiscount($data);

        $energyTotal = 0.0;
        $costedKwh = 0.0;

        foreach ($segments as $segment) {
            $phaseIndex = $segment->phaseIndex;
            $ratePhases = $data->phases;
            $estimated = $phaseIndex === null;
            if ($estimated) {
                $phaseIndex = $estimateFill
                    ? $this->applicableKnownPhaseIndex($data, $windowStart, $segment->start, $ratePhases)
                    : null;
                if ($phaseIndex === null) {
                    return $this->excluded(ContractComparability::ExcludedUnknownFuture, $context, $data);
                }
            }

            // A disclosed normal amount ends the discount even when its continuation
            // phase is missing. Without it, holding the billed price is only an assumption.
            $rates = $this->resolvePhaseRates($data->phases[$phaseIndex], $ratePhases, $metering, $spot, $context->isSpot(), normalPrice: $estimated);
            if ($rates === null) {
                // A spot phase without spot averages cannot be costed; fail closed.
                return $this->excluded(ContractComparability::ExcludedIncomplete, $context, $data);
            }
            $usesSpot = $usesSpot || $rates['uses_spot'];
            $disclosedRates = $rates;
            $paired = $energyRulePlan?->rates($segment->start);
            if ($energyRulePlan !== null) {
                if ($paired === null) {
                    return $this->excluded(ContractComparability::ExcludedIncomplete, $context, $data);
                }
                foreach ($rates['buckets'] as $bucket => $amount) {
                    $rates['buckets'][$bucket] = $paired['actual'][SupplierAdjustedEstimate::energyBucket($bucket)];
                }
                $actualRuleEstimated = $actualRuleEstimated || $paired['actual_estimated'];
                $normalRuleEstimated = $normalRuleEstimated || $paired['normal_estimated'];
                $latestKnownRuleEstimate = $latestKnownRuleEstimate || $paired['latest_known_estimate'];
                $modelFloorApplied = $modelFloorApplied || $paired['model_floor_applied'];
            }

            // Offer terms contain only disclosed coverage, never the estimated continuation.
            if (! $estimated && ! ($paired['latest_known_estimate'] ?? false)) {
                $known = $spans[$phaseIndex] ?? null;
                $spans[$phaseIndex] = [
                    'start' => ($known !== null && $known['start']->lessThan($segment->start)) ? $known['start'] : $segment->start,
                    'end' => ($known !== null && $known['end']->greaterThan($segment->end)) ? $known['end'] : $segment->end,
                    'rates' => $disclosedRates,
                ];
                if ($paired !== null) {
                    $display = $disclosedRates['display'];
                    $displayedEnergy = $display['general'] ?? $display['day'] ?? $display['seasonal_winter'] ?? null;
                    $sameRates = $disclosedRates['buckets'] === $rates['buckets']
                        && count(array_unique($rates['buckets'], SORT_REGULAR)) === 1
                        && $displayedEnergy === (array_values($rates['buckets'])[0] ?? null)
                        && ($known === null || $known['rates']['buckets'] === $disclosedRates['buckets']);
                    $spans[$phaseIndex]['energy_price_guaranteed'] = $paired['actual_guaranteed']
                        && $comparability !== ContractComparability::BaseOnlyHybrid
                        && $sameRates && ($known['energy_price_guaranteed'] ?? true);
                }
            }

            $actualBillingFees[] = $rates['monthly_fee'];
            $monthIndex = $this->elapsedMonth($windowStart, $segment->start);
            $monthly[$monthIndex] += $this->costSegment($segment, $profile, $rates, $flatApplied, $phaseIndex, $reset, $supplierAdjusted, $spotEstimate, $energyTotal, $shiftFloorApplied);
            $costedKwh += array_sum($profile[$segment->monthIndex] ?? []) * $segment->annualMonthFraction();

            if ($normalAvailable && ($hasComponentDiscount || $energyRulePlan !== null)) {
                $normalRates = $this->resolvePhaseRates($data->phases[$phaseIndex], $ratePhases, $metering, $spot, $context->isSpot(), normalPrice: true);
                if ($normalRates === null) {
                    return $this->excluded(ContractComparability::ExcludedIncomplete, $context, $data);
                }

                if ($energyRulePlan !== null) {
                    foreach ($normalRates['buckets'] as $bucket => $amount) {
                        $normalRates['buckets'][$bucket] = $paired['normal'][SupplierAdjustedEstimate::energyBucket($bucket)];
                    }
                    $normalRates['monthly_fee'] = $this->energyRuleNormalFee($data, $phaseIndex, $ratePhases, $segments, $segment, $rates['monthly_fee'], $estimated);
                }
                $normalBillingFees[] = $normalRates['monthly_fee'];
                $normalMonthly[$monthIndex] += $this->costSegment($segment, $profile, $normalRates, $normalFlatApplied, $phaseIndex, $reset, $supplierAdjusted, $spotEstimate);
            }
        }

        $currentRates = $currentPhaseIndex !== null
            ? $this->resolvePhaseRates($data->phases[$currentPhaseIndex], $data->phases, $metering, $spot, $context->isSpot())
            : null;

        $currentRuleRates = $energyRulePlan?->rates($windowStart);
        if ($currentRates !== null && ($currentRuleRates['latest_known_estimate'] ?? false)) {
            $currentRates = $this->withEnergyRuleRates($currentRates, $currentRuleRates['actual']);
        }

        // structuredOnly and base carry the same reset shift as the total, so the difference
        // between them keeps measuring only the promotional effect (which is what the integrity
        // label's euro impact reports) instead of mixing in the seasonal repricing.
        $structuredOnly = null;
        if ($currentRates !== null) {
            $structuredOnly = 0.0;
            $structuredFlatApplied = [];
            foreach ($segments as $segment) {
                $structuredOnly += $this->costSegment($segment, $profile, $currentRates, $structuredFlatApplied, $currentPhaseIndex, $reset, $supplierAdjusted, $spotEstimate);
            }
            $structuredOnly *= $annualizationFactor;
        }

        if ($energyRulePlan === null && (! $hasComponentDiscount || $supplierAdjusted !== null)) {
            // Package allowances are contract pricing, not a promotion. Even if package terms
            // change between disclosed phases, the normal-price pass must not replace the
            // timeline with the last package and call the difference an offer saving.
            $normalMonthly = $this->hasEnergyPackage($data) || $data->recurringSchedule->isActiveReset()
                ? $monthly
                : $this->normalHoldMonthlyCosts($data, $context, $metering, $profile, $spot, $segments, $windowStart, $reset, $supplierAdjusted, $spotEstimate);
            if ($normalMonthly === null) {
                $normalMonthly = $monthly;
            }
        }

        $contractTermTotal = null;
        $contractTermBaseTotal = null;
        $contractTermDiscountSavings = null;

        // Preserve the complete real-term values before applying the comparison factor.
        // Estimated term coverage carries the same hold-price assumption as the annual result.
        if ($termMonths !== null
            && (! $this->hasUncovered($segments) || $estimateFill)) {
            $termTotal = array_sum($monthly);
            $termBaseTotal = array_sum($normalMonthly);

            if (is_finite($termTotal)) {
                $contractTermTotal = $termTotal;
                if ($normalAvailable && is_finite($termBaseTotal)) {
                    $contractTermBaseTotal = $termBaseTotal;
                    $contractTermDiscountSavings = $termBaseTotal - $termTotal;
                }
            }
        }

        if ($annualizationFactor !== 1.0) {
            $monthly = array_map(static fn (float $cost): float => $cost * $annualizationFactor, $monthly);
            $normalMonthly = array_map(static fn (float $cost): float => $cost * $annualizationFactor, $normalMonthly);
        }

        $total = array_sum($monthly);
        $base = $normalAvailable ? array_sum($normalMonthly) : null;
        $ruleComparison = null;
        if ($energyRulePlan !== null) {
            $signed = $normalAvailable ? array_map(static fn ($actual, $normal) => $normal - $actual, $monthly, $normalMonthly) : [];
            $normalHeld = $normalRuleEstimated && ($energyRulePlan->projection === null || $energyRulePlan->projection->estimate->basis->value === 'hold_flat');
            $ruleComparison = new EnergyRuleComparison($signed, $normalAvailable ? array_sum($signed) : null, $actualRuleEstimated, $normalRuleEstimated, $normalHeld, $energyRulePlan->projection, $normalAvailable, $costedKwh > 0 ? $energyTotal * 100 / $costedKwh : null,
                currentNormalRates: $normalAvailable && $energyRulePlan->fixedOnly === [] && $currentRuleRates['normal_reference_current'] && $currentRuleRates['normal'] === $energyRulePlan->baseline ? $energyRulePlan->baseline : null,
                disclosedFeeChanges: count(array_unique($actualBillingFees)) > 1 || count(array_unique($normalBillingFees)) > 1);
            if ($actualRuleEstimated) {
                $defaultEstimateMethod = EstimateMethod::SourceEnergyRules;
            }
            // Net the same window before deciding whether there is a positive benefit.
            $monthlySavings = $signed;
            $discountSavings = $normalAvailable ? max(0.0, $ruleComparison->netDifference) : 0.0;
            $structuredOnly = null;
            $estimateFill = $actualRuleEstimated;
            if ($comparability === ContractComparability::ComparableExact && $actualRuleEstimated) {
                $comparability = ContractComparability::ComparableEstimate;
            }
        } else {
            $monthlySavings = $this->monthlySavings($monthly, $normalMonthly);
            $discountSavings = array_sum($monthlySavings);
        }
        $disclosedFeeChanges = $supplierAdjusted !== null
            && count(array_unique(array_map(static fn (array $span) => $span['rates']['monthly_fee'], $spans))) > 1;

        return new CanonicalPricingOutcome(
            comparability: $comparability,
            estimateMethod: $usesSpot
                ? ($spotEstimate?->basis === SpotEstimateBasis::ForwardCurve
                    ? EstimateMethod::ForwardCurveSpot
                    : EstimateMethod::Rolling365Spot)
                : ($this->resetEstimateMethod($reset)
                    ?? ($comparability === ContractComparability::BaseOnlyHybrid && $supplierAdjusted?->basis === SupplierAdjustedEstimateBasis::HoldFlat
                        ? null : $this->supplierAdjustedEstimateMethod($supplierAdjusted))
                    ?? ($estimateFill && $defaultEstimateMethod !== EstimateMethod::HybridBaseOnly
                        ? ($defaultEstimateMethod !== EstimateMethod::None
                            ? $defaultEstimateMethod
                            : ($data->recurringSchedule->isActiveReset() ? EstimateMethod::HoldCurrentRecurringPrice : EstimateMethod::HoldLastKnownPrice))
                        : $defaultEstimateMethod)),
            totalCost: $total,
            monthlyCosts: $monthly,
            baseTotalCost: $base,
            baseMonthlyCosts: $normalMonthly,
            measuredDiscountSavingsTotal: $discountSavings,
            monthlyDiscountSavings: $monthlySavings,
            structuredOnlyTotal: $structuredOnly,
            isSpotContract: $context->isSpot() || $usesSpot,
            vatBasis: $context->includesVat() ? 'included' : 'excluded',
            monthlyFixedFee: $currentRates['monthly_fee'] ?? null,
            spotPriceMargin: $currentRates !== null && $currentRates['spot_margin'] !== null ? $currentRates['spot_margin'] : null,
            generalKwhPrice: $currentRates['display']['general'] ?? null,
            daytimeKwhPrice: $currentRates['display']['day'] ?? null,
            nighttimeKwhPrice: $currentRates['display']['night'] ?? null,
            seasonalWinterDayKwhPrice: $currentRates['display']['seasonal_winter'] ?? null,
            seasonalOtherKwhPrice: $currentRates['display']['seasonal_other'] ?? null,
            spotPriceDayAvg: $usesSpot ? ($spotEstimate?->annualEquivalentDayCentsPerKwh ?? $spot->dayAvgWithTax) : null,
            spotPriceNightAvg: $usesSpot ? ($spotEstimate?->annualEquivalentNightCentsPerKwh ?? $spot->nightAvgWithTax) : null,
            termMonths: $termMonths,
            energyPackage: $currentRates['package'] ?? null,
            contractTermTotalCost: $contractTermTotal,
            contractTermBaseTotalCost: $contractTermBaseTotal,
            contractTermDiscountSavingsTotal: $contractTermDiscountSavings,
            phaseBreakdown: $this->buildBreakdown($data->phases, $spans),
            offerTerms: $energyRulePlan === null ? $this->buildOfferTerms($data, $spans, $windowStart, unchangedEnergy: $supplierAdjusted !== null)
                : EnergyRuleOfferTerms::build($data, $energyRulePlan, $windowStart, $windowStart->addMonthsNoOverflow($termMonths ?? 12)),
            consumptionEffect: $comparability === ContractComparability::BaseOnlyHybrid && $data->consumptionEffect->present
                ? $data->consumptionEffect
                : null,
            assumptions: array_merge($policy === ComparisonPolicy::Current && $shiftFloorApplied ? ['estimated_energy_nonnegative_model_floor_applied'] : [], $energyRulePlan === null ? [] : [
                ! $normalAvailable ? 'energy_rule_normal_unavailable' : ($ruleComparison->normalHeld ? 'energy_rule_normal_held' : ($ruleComparison->normalEstimated ? 'energy_rule_normal_projection' : 'energy_rule_normal_known')),
                'energy_rule_nonnegative_model_floor',
            ], $latestKnownRuleEstimate ? ['energy_rule_latest_known_price_continuation'] : [],
                $modelFloorApplied ? ['energy_rule_nonnegative_model_floor_applied'] : [], $this->assumptions(
                    $comparability,
                    $usesSpot,
                    $estimateFill && $energyRulePlan === null,
                    $reset,
                    termAnnualized: $termMonths !== null && $annualizationFactor !== 1.0,
                    supplierAdjusted: $supplierAdjusted,
                    spotEstimate: $spotEstimate,
                    disclosedFeeChanges: $disclosedFeeChanges,
                )),
            energyRuleComparison: $ruleComparison,
            resetEstimate: $reset?->shiftsPrices() ? array_replace($resetPayload = $reset->toArray(), [
                ...($policy === ComparisonPolicy::Current && $shiftFloorApplied ? [
                    'flags' => in_array('estimated_energy_nonnegative_model_floor_applied', $resetPayload['flags'], true)
                        ? $resetPayload['flags'] : [...$resetPayload['flags'], 'estimated_energy_nonnegative_model_floor_applied'],
                ] : []),
                'annual_equivalent_energy_price' => $costedKwh > 0 ? $energyTotal * 100 / $costedKwh : null,
            ]) : null,
            supplierAdjustedEstimate: $supplierAdjusted !== null ? array_replace($supplierPayload = $supplierAdjusted->toArray(), [
                ...($policy === ComparisonPolicy::Current && $shiftFloorApplied ? [
                    'flags' => in_array('estimated_energy_nonnegative_model_floor_applied', $supplierPayload['flags'], true)
                        ? $supplierPayload['flags'] : [...$supplierPayload['flags'], 'estimated_energy_nonnegative_model_floor_applied'],
                ] : []),
                'annual_equivalent_energy_price' => $costedKwh > 0 ? $energyTotal * 100 / $costedKwh : null,
                'monthly_fee_assumption' => $disclosedFeeChanges ? 'disclosed_phases' : 'held_flat',
            ]) : null,
            spotEstimate: $usesSpot ? $spotEstimate?->toArray() : null,
        );
    }

    /**
     * Cost the whole window by holding one phase's rates forward for an uncovered Hybrid
     * base-only fallback. The comparability/estimate method are supplied.
     *
     * @param  array<int, array<string, float>>  $profile
     */
    private function costHeldForward(
        CanonicalContractData $data,
        ContractContext $context,
        MeteringType $metering,
        array $profile,
        SpotAssumptions $spot,
        CarbonImmutable $windowStart,
        array $segments,
        ?int $currentPhaseIndex,
        ContractComparability $comparability,
        EstimateMethod $estimateMethod,
        ?ResetEstimate $reset = null,
        ?SpotEstimate $spotEstimate = null,
    ): CanonicalPricingOutcome {
        if ($currentPhaseIndex === null) {
            return $this->excluded(ContractComparability::ExcludedIncomplete, $context, $data);
        }

        $rates = $this->resolvePhaseRates($data->phases[$currentPhaseIndex], $data->phases, $metering, $spot, $context->isSpot());
        if ($rates === null) {
            return $this->excluded(ContractComparability::ExcludedIncomplete, $context, $data);
        }

        $monthKeys = $this->windowMonthKeys($windowStart);
        $total = $this->holdForwardTotal($data->phases[$currentPhaseIndex], $data->phases, $metering, $profile, $spot, $context->isSpot(), $reset, $monthKeys, spotEstimate: $spotEstimate);
        if ($total === null) {
            return $this->excluded(ContractComparability::ExcludedIncomplete, $context, $data);
        }

        $base = $this->hasNormalPriceDiscount($data)
            ? $this->holdForwardTotal($data->phases[$currentPhaseIndex], $data->phases, $metering, $profile, $spot, $context->isSpot(), $reset, $monthKeys, normalPrice: true, spotEstimate: $spotEstimate)
            : $total;
        if ($base === null) {
            return $this->excluded(ContractComparability::ExcludedIncomplete, $context, $data);
        }

        $monthly = array_fill(0, 12, $total / 12);
        $baseMonthly = array_fill(0, 12, $base / 12);
        $monthlySavings = $this->monthlySavings($monthly, $baseMonthly);
        $offerSpans = [];

        foreach ($segments as $segment) {
            if ($segment->phaseIndex === null) {
                continue;
            }

            $phaseRates = $this->resolvePhaseRates(
                $data->phases[$segment->phaseIndex],
                $data->phases,
                $metering,
                $spot,
                $context->isSpot(),
            );
            if ($phaseRates === null) {
                return $this->excluded(ContractComparability::ExcludedIncomplete, $context, $data);
            }

            $known = $offerSpans[$segment->phaseIndex] ?? null;
            $offerSpans[$segment->phaseIndex] = [
                'start' => $known !== null && $known['start']->lessThan($segment->start) ? $known['start'] : $segment->start,
                'end' => $known !== null && $known['end']->greaterThan($segment->end) ? $known['end'] : $segment->end,
                'rates' => $phaseRates,
            ];
        }

        return new CanonicalPricingOutcome(
            comparability: $comparability,
            estimateMethod: $estimateMethod,
            totalCost: $total,
            monthlyCosts: $monthly,
            baseTotalCost: $base,
            baseMonthlyCosts: $baseMonthly,
            measuredDiscountSavingsTotal: array_sum($monthlySavings),
            monthlyDiscountSavings: $monthlySavings,
            structuredOnlyTotal: $total,
            isSpotContract: $context->isSpot() || $rates['uses_spot'],
            vatBasis: $context->includesVat() ? 'included' : 'excluded',
            monthlyFixedFee: $rates['monthly_fee'],
            spotPriceMargin: $rates['spot_margin'],
            generalKwhPrice: $rates['display']['general'] ?? null,
            daytimeKwhPrice: $rates['display']['day'] ?? null,
            nighttimeKwhPrice: $rates['display']['night'] ?? null,
            seasonalWinterDayKwhPrice: $rates['display']['seasonal_winter'] ?? null,
            seasonalOtherKwhPrice: $rates['display']['seasonal_other'] ?? null,
            spotPriceDayAvg: $rates['uses_spot'] ? ($spotEstimate?->annualEquivalentDayCentsPerKwh ?? $spot->dayAvgWithTax) : null,
            spotPriceNightAvg: $rates['uses_spot'] ? ($spotEstimate?->annualEquivalentNightCentsPerKwh ?? $spot->nightAvgWithTax) : null,
            energyPackage: $rates['package'] ?? null,
            phaseBreakdown: [],
            offerTerms: $this->buildOfferTerms($data, $offerSpans, $windowStart),
            consumptionEffect: $comparability === ContractComparability::BaseOnlyHybrid && $data->consumptionEffect->present
                ? $data->consumptionEffect
                : null,
            assumptions: $this->assumptions($comparability, $rates['uses_spot'], false, $reset, spotEstimate: $spotEstimate),
            resetEstimate: $reset?->shiftsPrices() ? $reset->toArray() : null,
            spotEstimate: $rates['uses_spot'] ? $spotEstimate?->toArray() : null,
        );
    }

    private function excluded(ContractComparability $comparability, ContractContext $context, CanonicalContractData $data, array $assumptions = []): CanonicalPricingOutcome
    {
        return new CanonicalPricingOutcome(
            comparability: $comparability,
            estimateMethod: EstimateMethod::None,
            totalCost: null,
            monthlyCosts: array_fill(0, 12, 0.0),
            baseTotalCost: null,
            baseMonthlyCosts: array_fill(0, 12, 0.0),
            measuredDiscountSavingsTotal: 0.0,
            monthlyDiscountSavings: array_fill(0, 12, 0.0),
            structuredOnlyTotal: null,
            isSpotContract: $context->isSpot(),
            assumptions: $assumptions,
            vatBasis: $context->includesVat() ? 'included' : 'excluded',
        );
    }

    /**
     * Complete partial realized Spot history for every hour used by the actual or
     * normal-price pass. Same-Helsinki-day observations are preferred; a day with
     * no observation uses the mean of all observed required hours in the period.
     *
     * @param  list<array{segment: WindowSegment, rates: array<string, mixed>, normal_rates: array<string, mixed>|null}>  $resolved
     * @param  array<int, float>  $spotMap
     * @return array{map: array<int, float>, available: bool, filled: bool}
     */
    private function completeRequiredSpotHistory(array $resolved, array $spotMap): array
    {
        $required = [];

        foreach ($resolved as $item) {
            foreach ([$item['rates'], $item['normal_rates']] as $rates) {
                if (! ($rates['uses_spot'] ?? false)) {
                    continue;
                }

                foreach ($this->segmentHourStarts($item['segment']) as $hourStart) {
                    $required[$hourStart->getTimestamp()] = $hourStart;
                }
            }
        }

        if ($required === []) {
            return ['map' => $spotMap, 'available' => true, 'filled' => false];
        }

        ksort($required);
        $observed = [];
        $dailyValues = [];

        foreach ($required as $timestamp => $hourStart) {
            if (! array_key_exists($timestamp, $spotMap)) {
                continue;
            }

            $value = $spotMap[$timestamp];
            $observed[] = $value;
            $day = $hourStart->setTimezone('Europe/Helsinki')->toDateString();
            $dailyValues[$day][] = $value;
        }

        if ($observed === []) {
            return ['map' => $spotMap, 'available' => false, 'filled' => false];
        }

        $periodMean = array_sum($observed) / count($observed);
        $dailyMeans = [];
        foreach ($dailyValues as $day => $values) {
            $dailyMeans[$day] = array_sum($values) / count($values);
        }

        $filled = false;
        foreach ($required as $timestamp => $hourStart) {
            if (array_key_exists($timestamp, $spotMap)) {
                continue;
            }

            $day = $hourStart->setTimezone('Europe/Helsinki')->toDateString();
            $spotMap[$timestamp] = $dailyMeans[$day] ?? $periodMean;
            $filled = true;
        }

        return ['map' => $spotMap, 'available' => true, 'filled' => $filled];
    }

    /**
     * Cost one canonical phase slice against exact period usage. Consumption is
     * flat across the period's real UTC hours. Time and seasonal fixed rates keep
     * the bill comparison's 85/15 convention; Spot uses each matching realized
     * hour. Ordinary monthly fees keep the legacy days/30 convention.
     *
     * @param  array<string, mixed>  $rates
     * @param  array<int, float>  $spotMap
     * @param  array<int, bool>  $flatApplied
     */
    private function costPeriodSegment(
        WindowSegment $segment,
        MeteringType $metering,
        array $rates,
        float $hourlyKwh,
        array $spotMap,
        array &$flatApplied,
        int $phaseIndex,
        ?ResetEstimate $reset,
    ): float {
        $hours = $this->segmentHourStarts($segment);
        $segmentKwh = count($hours) * $hourlyKwh;
        $offset = $reset?->offsetForMonthKey($segment->start->format('Y-m')) ?? 0.0;
        $package = $rates['package'] ?? null;

        if ($package instanceof IncludedEnergyPackageData) {
            $fraction = $segment->monthFraction();
            $allowance = $package->includedKwh * $fraction;
            $excessKwh = max(0.0, $segmentKwh - $allowance);

            return ($package->monthlyFeeEur * $fraction)
                + ($excessKwh * $package->excessRateCentsPerKwh / 100);
        }

        $energyCents = 0.0;
        if ($rates['uses_spot']) {
            $margin = (float) ($rates['spot_margin'] ?? 0.0);
            foreach ($hours as $hourStart) {
                $energyCents += $hourlyKwh * max(0.0, $spotMap[$hourStart->getTimestamp()] + $margin + $offset);
            }
        } else {
            $energyCents = match ($metering) {
                MeteringType::Time => $segmentKwh * (
                    0.85 * max(0.0, ($rates['buckets']['DayTime'] ?? 0.0) + $offset)
                    + 0.15 * max(0.0, ($rates['buckets']['NightTime'] ?? 0.0) + $offset)
                ),
                MeteringType::Season => $this->fixedSeasonalPeriodEnergyCents($segment, $rates, $hourlyKwh, $offset),
                default => $segmentKwh * max(0.0, ($rates['buckets']['General'] ?? 0.0) + $offset),
            };
        }

        $days = $segment->start->diffInDays($segment->end);
        $cost = ($energyCents / 100) + ((float) $rates['monthly_fee'] * ($days / 30));

        foreach ($rates['flat_charges'] ?? [] as $chargeId => $amount) {
            if (! isset($flatApplied[$chargeId])) {
                $cost += $amount;
                $flatApplied[$chargeId] = true;
            }
        }

        return $cost;
    }

    /**
     * @param  array<string, mixed>  $rates
     */
    private function fixedSeasonalPeriodEnergyCents(WindowSegment $segment, array $rates, float $hourlyKwh, float $offset): float
    {
        $winterHours = 0;
        $otherHours = 0;

        foreach ($this->segmentHourStarts($segment) as $hourStart) {
            $month = (int) $hourStart->setTimezone('Europe/Helsinki')->format('n');
            if (in_array($month, [1, 2, 3, 11, 12], true)) {
                $winterHours++;
            } else {
                $otherHours++;
            }
        }

        $winterRate = max(0.0, ($rates['buckets']['SeasonalWinterDay'] ?? 0.0) + $offset);
        $otherRate = max(0.0, ($rates['buckets']['SeasonalOther'] ?? 0.0) + $offset);
        $winterKwh = $winterHours * $hourlyKwh;
        $otherKwh = $otherHours * $hourlyKwh;

        return $winterKwh * ((0.85 * $winterRate) + (0.15 * $otherRate))
            + ($otherKwh * $otherRate);
    }

    /** @return list<CarbonImmutable> */
    private function segmentHourStarts(WindowSegment $segment): array
    {
        $hours = [];
        $cursor = $segment->start->utc();
        $end = $segment->end->utc();

        while ($cursor->lessThan($end)) {
            $hours[] = $cursor;
            $cursor = $cursor->addHour();
        }

        return $hours;
    }

    private function unavailablePeriod(
        ContractComparability $comparability,
        PeriodPricingUnavailableReason $reason,
        bool $usesSpot = false,
    ): CanonicalPeriodPricingOutcome {
        return new CanonicalPeriodPricingOutcome(
            periodTotal: null,
            normalPeriodTotal: null,
            measuredDiscountSavings: 0.0,
            comparability: $comparability,
            unavailableReason: $reason,
            usesSpot: $usesSpot,
            monthlyFixedFee: null,
            generalKwhPrice: null,
            daytimeKwhPrice: null,
            nighttimeKwhPrice: null,
            seasonalWinterDayKwhPrice: null,
            seasonalOtherKwhPrice: null,
            spotMargins: [],
            phaseBreakdown: [],
            assumptions: [],
        );
    }

    /**
     * Cost one segment: energy usage (cents→EUR) plus pro-rated monthly fee plus a one-off flat fee.
     *
     * A market-reset estimate contributes an additive c/kWh offset for this segment's calendar
     * month. The offset is zero for months the contract discloses, and the resulting rate is
     * floored at 0 so a steeply falling curve can never produce a negative energy price.
     *
     * @param  array<int, array<string, float>>  $profile
     * @param  array<string, mixed>  $rates
     * @param  array<int, bool>  $flatApplied
     */
    private function costSegment(
        WindowSegment $segment,
        array $profile,
        array $rates,
        array &$flatApplied,
        int $phaseIndex,
        ?ResetEstimate $reset = null,
        ?SupplierAdjustedEstimate $supplierAdjusted = null,
        ?SpotEstimate $spotEstimate = null,
        ?float &$energyTotal = null,
        ?bool &$shiftFloorApplied = null,
    ): float {
        $fraction = $segment->annualMonthFraction();
        $monthBuckets = $profile[$segment->monthIndex] ?? [];
        $monthKey = $segment->start->format('Y-m');

        $package = $rates['package'] ?? null;
        if ($package instanceof IncludedEnergyPackageData) {
            // Profile buckets are mutually exclusive. Sum them before applying one shared
            // monthly allowance, so day/night or seasonal profiles cannot receive the
            // allowance once per bucket. The fee and allowance are pro-rated together for a
            // partial calendar month, as the package source terms require.
            $monthlyUsage = array_sum($monthBuckets) * $fraction;
            $includedKwh = $package->includedKwh * $segment->monthFraction();
            $excessKwh = max(0.0, $monthlyUsage - $includedKwh);

            return ($package->monthlyFeeEur * $segment->monthFraction())
                + ($excessKwh * $package->excessRateCentsPerKwh / 100);
        }

        $energyCents = 0.0;
        foreach ($rates['buckets'] as $bucket => $rate) {
            $effectiveRate = $rate;
            if ($rates['uses_spot'] && $spotEstimate !== null) {
                $wholesale = $spotEstimate->wholesaleForBucket($monthKey, $bucket);
                if ($wholesale !== null) {
                    $effectiveRate = max(0.0, $wholesale) + (float) ($rates['spot_margin'] ?? 0.0);
                }
            }
            $supplierOffset = $rates['uses_spot'] ? 0.0 : ($supplierAdjusted?->offsetForMonthKey($monthKey, $bucket) ?? 0.0);
            $resetOffset = ! $rates['uses_spot'] && $reset !== null
                && ($reset->tailStartsOn === null || $segment->start->toDateString() >= $reset->tailStartsOn)
                ? $reset->offsetForMonthKey($monthKey, $bucket) : 0.0;
            if (($monthBuckets[$bucket] ?? 0.0) * $fraction > 0
                && ($resetOffset !== 0.0 || $supplierOffset !== 0.0)
                && $effectiveRate + $resetOffset + $supplierOffset < 0.0) {
                $shiftFloorApplied = true;
            }
            $energyCents += ($monthBuckets[$bucket] ?? 0.0) * $fraction * max(0.0, $effectiveRate + $resetOffset + $supplierOffset);
        }

        $energyTotal += $energyCents / 100;
        $cost = $energyCents / 100 + $rates['monthly_fee'] * $segment->billingMonthFraction();

        foreach ($rates['flat_charges'] ?? [] as $chargeId => $amount) {
            if (! isset($flatApplied[$chargeId])) {
                $cost += $amount;
                $flatApplied[$chargeId] = true;
            }
        }

        return $cost;
    }

    /**
     * Total cost of holding one phase's rates over the full 12-month window.
     *
     * @param  array<int, array<string, float>>  $profile
     * @param  array<int, string>  $monthKeys  calendar-month index (0-11) => the `Y-m` that month
     *                                         occupies inside this window
     */
    private function holdForwardTotal(PricingPhase $phase, array $allPhases, MeteringType $metering, array $profile, SpotAssumptions $spot, bool $isSpot = false, ?ResetEstimate $reset = null, array $monthKeys = [], bool $normalPrice = false, ?SupplierAdjustedEstimate $supplierAdjusted = null, ?SpotEstimate $spotEstimate = null): ?float
    {
        $rates = $this->resolvePhaseRates($phase, $allPhases, $metering, $spot, $isSpot, $normalPrice);
        if ($rates === null) {
            return null;
        }

        $package = $rates['package'] ?? null;
        if ($package instanceof IncludedEnergyPackageData) {
            $total = 0.0;
            foreach ($profile as $monthBuckets) {
                $excessKwh = max(0.0, array_sum($monthBuckets) - $package->includedKwh);
                $total += $package->monthlyFeeEur
                    + ($excessKwh * $package->excessRateCentsPerKwh / 100);
            }

            return $total;
        }

        $total = $rates['flat_once'] + $rates['monthly_fee'] * 12;
        foreach ($profile as $monthIndex => $monthBuckets) {
            $monthKey = $monthKeys[$monthIndex] ?? null;

            foreach ($rates['buckets'] as $bucket => $rate) {
                $effectiveRate = $rate;
                if ($rates['uses_spot'] && $spotEstimate !== null && $monthKey !== null) {
                    $wholesale = $spotEstimate->wholesaleForBucket($monthKey, $bucket);
                    if ($wholesale !== null) {
                        $effectiveRate = max(0.0, $wholesale) + (float) ($rates['spot_margin'] ?? 0.0);
                    }
                }
                $supplierOffset = $monthKey !== null && ! $rates['uses_spot'] ? ($supplierAdjusted?->offsetForMonthKey($monthKey, $bucket) ?? 0.0) : 0.0;
                $resetOffset = $monthKey !== null && ! $rates['uses_spot'] ? ($reset?->offsetForMonthKey($monthKey, $bucket) ?? 0.0) : 0.0;
                $total += (($monthBuckets[$bucket] ?? 0.0) * max(0.0, $effectiveRate + $resetOffset + $supplierOffset)) / 100;
            }
        }

        return $total;
    }

    /**
     * Cost the promotion-free fallback by applying the latest disclosed normal phase over the
     * same window segments as the actual result. This keeps usage timing, Spot assumptions, and
     * reset offsets aligned with the actual calculation.
     *
     * Canonical components with a higher `normal_amount` use the actual phase timeline instead;
     * this fallback preserves the existing phase-only promotion behavior.
     *
     * @param  array<int, array<string, float>>  $profile
     * @param  list<WindowSegment>  $segments
     * @return array<int, float>|null
     */
    private function normalHoldMonthlyCosts(
        CanonicalContractData $data,
        ContractContext $context,
        MeteringType $metering,
        array $profile,
        SpotAssumptions $spot,
        array $segments,
        CarbonImmutable $windowStart,
        ?ResetEstimate $reset = null,
        ?SupplierAdjustedEstimate $supplierAdjusted = null,
        ?SpotEstimate $spotEstimate = null,
    ): ?array {
        $lastIndex = $this->lastCoveredPhaseIndex($segments);
        if ($lastIndex === null) {
            return null;
        }

        $rates = $this->resolvePhaseRates($data->phases[$lastIndex], $data->phases, $metering, $spot, $context->isSpot());
        if ($rates === null) {
            return null;
        }

        $monthly = array_fill(0, 12, 0.0);
        $flatApplied = [];

        foreach ($segments as $segment) {
            $segmentRates = $rates;
            if ($supplierAdjusted !== null && $segment->isCovered()) {
                $segmentRates = $this->unchangedEnergyNormalRates($data, $context, $metering, $spot, $segment, $segments);
                if ($segmentRates === null) {
                    return null;
                }
            }
            if (! $segment->isCovered()) {
                // An assumed held discount is not a disclosed offer saving.
                $ratePhases = [];
                $applicableIndex = $this->applicableKnownPhaseIndex($data, $windowStart, $segment->start, $ratePhases);
                $segmentRates = $applicableIndex !== null
                    ? $this->resolvePhaseRates($data->phases[$applicableIndex], $ratePhases, $metering, $spot, $context->isSpot(), normalPrice: true)
                    : null;
                if ($segmentRates === null) {
                    return null;
                }
            }
            $monthIndex = $this->elapsedMonth($windowStart, $segment->start);
            $monthly[$monthIndex] += $this->costSegment($segment, $profile, $segmentRates, $flatApplied, $lastIndex, $reset, $supplierAdjusted, $spotEstimate);
        }

        return $monthly;
    }

    /**
     * Shared normal-price baseline for annual and factual unchanged-energy bills.
     * Ordinary fee changes remain on their own segments; only a typed introduction
     * can use the first normal continuation. Explicit normal amounts stay primary.
     *
     * @param  list<WindowSegment>  $segments
     */
    private function unchangedEnergyNormalRates(
        CanonicalContractData $data,
        ContractContext $context,
        MeteringType $metering,
        SpotAssumptions $spot,
        WindowSegment $segment,
        array $segments,
    ): ?array {
        $phase = $data->phases[$segment->phaseIndex];
        $normalPhase = null;
        $fee = $this->singleMonthlyFee($phase, $data->phases);
        if ($phase->phaseKind === PhaseKind::Introductory && $fee !== null && $fee->normalAmount === null) {
            foreach ($segments as $later) {
                if ($later->isCovered() && $later->start->greaterThanOrEqualTo($segment->end)
                    && in_array($data->phases[$later->phaseIndex]->phaseKind, [PhaseKind::Normal, PhaseKind::Continuation], true)) {
                    $candidate = $data->phases[$later->phaseIndex];
                    $normalPhase = $this->singleMonthlyFee($candidate, $data->phases) !== null ? $candidate : null;
                    break;
                }
            }
        }

        return $this->resolvePhaseRates($normalPhase ?? $phase, $data->phases, $metering, $spot, $context->isSpot(), normalPrice: true);
    }

    /** @param list<PricingPhase> $allPhases */
    private function energyRuleNormalFee(CanonicalContractData $data, int $phaseIndex, array $ratePhases, array $segments, WindowSegment $segment, float $actualFee, bool $actualUsesNormalPrice): float
    {
        $phase = $data->phases[$phaseIndex];
        $fees = array_values(array_filter($this->effectiveBilledComponents($phase, $ratePhases),
            static fn (CanonicalComponent $component) => $component->type === ComponentType::MonthlyFee));
        if ($fees === []) {
            return $actualFee;
        }
        if (count($fees) > 1 || $fees[0]->normalAmount !== null) {
            $actual = max(array_map(static fn ($fee) => $actualUsesNormalPrice && $fee->normalAmount !== null && $fee->normalAmount > $fee->amount
                ? $fee->normalAmount : $fee->amount, $fees));
            $normal = max(array_map(static fn ($fee) => $fee->normalAmount ?? $fee->amount, $fees));

            return $actualFee - $actual + $normal;
        }
        $fee = $fees[0];
        // An energy introduction is not evidence that the monthly fee is discounted.
        if ($fee->priceRole === PriceRole::Introductory) {
            foreach ($segments as $later) {
                if ($later->isCovered() && $later->start->gte($segment->end)
                    && in_array($data->phases[$later->phaseIndex]->phaseKind, [PhaseKind::Normal, PhaseKind::Continuation], true)) {
                    $normal = $this->singleMonthlyFee($data->phases[$later->phaseIndex], $ratePhases);
                    if ($normal !== null) {
                        return $actualFee - $fee->amount + ($normal->normalAmount ?? $normal->amount);
                    }
                }
            }
        }

        return $actualFee;
    }

    private function singleMonthlyFee(PricingPhase $phase, array $allPhases): ?CanonicalComponent
    {
        $fees = array_values(array_filter(
            $this->effectiveBilledComponents($phase, $allPhases),
            static fn (CanonicalComponent $component) => $component->type === ComponentType::MonthlyFee,
        ));

        return count($fees) === 1 ? $fees[0] : null;
    }

    private function hasEnergyPackage(CanonicalContractData $data): bool
    {
        foreach ($data->phases as $phase) {
            if ($phase->package !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * Build exact public offer terms from the same resolved phase spans and
     * component amounts that produced the measured saving. Component-level
     * normal amounts are preferred. A typed introductory phase can also be
     * compared with its typed normal continuation. Recurring market periods
     * never use that phase-only fallback because market movement is not an
     * offer.
     *
     * This is all or nothing: an unsupported changed component or timing makes
     * the public offer term unavailable instead of producing partial copy.
     *
     * @param  array<int, array{start:CarbonImmutable,end:CarbonImmutable,rates:array<string,mixed>}>  $spans
     * @return list<OfferTermData>
     */
    private function buildOfferTerms(CanonicalContractData $data, array $spans, CarbonImmutable $windowStart, bool $unchangedEnergy = false): array
    {
        if ($spans === []) {
            return [];
        }

        uasort($spans, static fn (array $a, array $b): int => $a['start'] <=> $b['start']);

        $normalPhase = null;
        if (! $data->recurringSchedule->isActiveReset()) {
            foreach (array_reverse(array_keys($spans)) as $phaseIndex) {
                $candidate = $data->phases[$phaseIndex] ?? null;
                if ($candidate instanceof PricingPhase
                    && in_array($candidate->phaseKind, [PhaseKind::Normal, PhaseKind::Continuation], true)) {
                    $normalPhase = $candidate;
                    break;
                }
            }
        }

        $terms = [];

        foreach ($spans as $phaseIndex => $span) {
            $phase = $data->phases[$phaseIndex] ?? null;
            if (! $phase instanceof PricingPhase) {
                return [];
            }

            $fallback = $phase->phaseKind === PhaseKind::Introductory ? $normalPhase : null;
            if ($unchangedEnergy && $fallback !== null) {
                foreach ($spans as $nextIndex => $nextSpan) {
                    if ($nextSpan['start']->greaterThanOrEqualTo($span['end'])
                        && in_array($data->phases[$nextIndex]->phaseKind, [PhaseKind::Normal, PhaseKind::Continuation], true)) {
                        $fallback = $data->phases[$nextIndex];
                        break;
                    }
                }
            }
            $components = $this->offerComponents($phase, $data->phases, $fallback, $unchangedEnergy);
            if ($components === null) {
                return [];
            }
            if ($components === []) {
                continue;
            }

            $timing = $this->resolvedOfferTiming($phase, $span, $windowStart);
            if ($timing === null) {
                return [];
            }

            $terms[] = new OfferTermData(
                endKind: $timing['end_kind'],
                startsOn: $span['start'],
                endsOn: $timing['ends_on'],
                durationMonths: $timing['duration_months'],
                startsAfterMonths: $timing['starts_after_months'],
                endsAfterMonths: $timing['ends_after_months'],
                startsAtWindowStart: $span['start']->equalTo($windowStart),
                components: $components,
            );
        }

        return $terms;
    }

    /**
     * @param  list<PricingPhase>  $allPhases
     * @return list<OfferComponentData>|null
     */
    private function offerComponents(PricingPhase $phase, array $allPhases, ?PricingPhase $normalPhase, bool $unchangedEnergy = false): ?array
    {
        if ($unchangedEnergy && $normalPhase !== null) {
            $fee = $this->singleMonthlyFee($phase, $allPhases);
            if ($fee === null || $fee->normalAmount !== null || $this->singleMonthlyFee($normalPhase, $allPhases) === null) {
                $normalPhase = null;
            }
        }
        $components = [];
        $seenTypes = [];

        foreach ($this->effectiveBilledComponents($phase, $allPhases) as $component) {
            if ($component->amount === null
                || $component->normalAmount === null
                || $component->normalAmount <= $component->amount) {
                continue;
            }

            $offer = $this->offerComponent($component, $component->normalAmount);
            if ($offer === null || isset($seenTypes[$component->type->value])) {
                return null;
            }

            $seenTypes[$component->type->value] = true;
            $components[] = $offer;
        }

        if ($components !== [] || $normalPhase === null) {
            return $components;
        }

        $normalByType = [];
        foreach ($this->effectiveBilledComponents($normalPhase, $allPhases) as $normal) {
            if (isset($normalByType[$normal->type->value])) {
                return null;
            }
            $normalByType[$normal->type->value] = $normal;
        }

        foreach ($this->effectiveBilledComponents($phase, $allPhases) as $component) {
            if (isset($seenTypes[$component->type->value])) {
                continue;
            }

            $normal = $normalByType[$component->type->value] ?? null;
            if (! $normal instanceof CanonicalComponent
                || $normal->unit !== $component->unit
                || $normal->amount === null
                || $component->amount === null
                || $normal->amount <= $component->amount) {
                continue;
            }

            $offer = $this->offerComponent($component, $normal->amount);
            if ($offer === null) {
                return null;
            }

            $seenTypes[$component->type->value] = true;
            $components[] = $offer;
        }

        return $components;
    }

    private function offerComponent(CanonicalComponent $component, float $normalAmount): ?OfferComponentData
    {
        $supported = match ($component->type) {
            ComponentType::MonthlyFee => $component->unit === ComponentUnit::EurPerMonth,
            ComponentType::EnergyGeneral,
            ComponentType::EnergyDay,
            ComponentType::EnergyNight,
            ComponentType::EnergySeasonalWinter,
            ComponentType::EnergySeasonalOther,
            ComponentType::SpotMargin => $component->unit === ComponentUnit::CentsPerKwh,
            default => false,
        };

        if (! $supported
            || $component->amount === null
            || ! is_finite($component->amount)
            || ! is_finite($normalAmount)
            || $normalAmount <= $component->amount) {
            return null;
        }

        return new OfferComponentData(
            type: $component->type,
            unit: $component->unit,
            amount: $component->amount,
            normalAmount: $normalAmount,
        );
    }

    /**
     * @param  array{start:CarbonImmutable,end:CarbonImmutable,rates:array<string,mixed>}  $span
     * @return array{end_kind:BoundaryKind,ends_on:CarbonImmutable,duration_months:?int,starts_after_months:?int,ends_after_months:?int}|null
     */
    private function resolvedOfferTiming(PricingPhase $phase, array $span, CarbonImmutable $windowStart): ?array
    {
        if ($phase->ends->kind === BoundaryKind::Date) {
            try {
                $disclosedEnd = CarbonImmutable::parse((string) $phase->ends->value, 'Europe/Helsinki')->startOfDay();
            } catch (\Throwable) {
                return null;
            }

            if ($disclosedEnd->lessThan($span['start']) || ! $span['end']->equalTo($disclosedEnd->addDay())) {
                return null;
            }

            return [
                'end_kind' => BoundaryKind::Date,
                'ends_on' => $disclosedEnd,
                'duration_months' => null,
                'starts_after_months' => null,
                'ends_after_months' => null,
            ];
        }

        if (in_array($phase->ends->kind, [BoundaryKind::None, BoundaryKind::Unknown], true)
            && $span['end']->equalTo($windowStart->addMonthsNoOverflow(12))) {
            return null;
        }

        $startsAfter = $this->exactMonthOffset($windowStart, $span['start']);
        $endsAfter = $this->exactMonthOffset($windowStart, $span['end']);

        if ($startsAfter === null || $endsAfter === null || $endsAfter <= $startsAfter) {
            return null;
        }

        return [
            'end_kind' => BoundaryKind::AfterMonths,
            'ends_on' => $span['end']->subDay(),
            'duration_months' => $endsAfter - $startsAfter,
            'starts_after_months' => $startsAfter,
            'ends_after_months' => $endsAfter,
        ];
    }

    private function exactMonthOffset(CarbonImmutable $windowStart, CarbonImmutable $point): ?int
    {
        for ($months = 0; $months <= 12; $months++) {
            if ($windowStart->addMonthsNoOverflow($months)->equalTo($point)) {
                return $months;
            }
        }

        return null;
    }

    private function hasNormalPriceDiscount(CanonicalContractData $data): bool
    {
        foreach ($data->phases as $phase) {
            foreach ($phase->billedComponents() as $component) {
                if ($component->normalAmount !== null && $component->normalAmount > $component->amount) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param  array<int, float>  $actual
     * @param  array<int, float>  $normal
     * @return array<int, float>
     */
    private function monthlySavings(array $actual, array $normal): array
    {
        $savings = [];

        foreach ($normal as $month => $normalCost) {
            $difference = $normalCost - ($actual[$month] ?? 0.0);
            $savings[$month] = $difference > 0.0000001 ? $difference : 0.0;
        }

        return $savings;
    }

    /**
     * Resolve a phase to per-bucket c/kWh rates plus monthly and flat fees, or null when a
     * spot phase lacks the spot averages needed to cost it.
     *
     * @return array{buckets: array<string, float>, monthly_fee: float, flat_once: float, spot_margin: ?float, uses_spot: bool, package: ?IncludedEnergyPackageData, display: array<string, ?float>}|null
     */
    private function resolvePhaseRates(PricingPhase $phase, array $allPhases, MeteringType $metering, SpotAssumptions $spot, bool $isSpot = false, bool $normalPrice = false): ?array
    {
        if ($phase->hasUncostableComponent()) {
            return null;
        }

        if ($phase->package !== null) {
            return [
                'buckets' => [],
                'monthly_fee' => $phase->package->monthlyFeeEur,
                'flat_once' => 0.0,
                'spot_margin' => null,
                'uses_spot' => false,
                'package' => $phase->package,
                'display' => [
                    'general' => $phase->package->excessRateCentsPerKwh,
                    'day' => null,
                    'night' => null,
                    'seasonal_winter' => null,
                    'seasonal_other' => null,
                ],
            ];
        }

        $energy = [];      // ComponentType value => amount
        $actualEnergy = []; // Actual amounts decide the Spot/fixed mechanism in both price passes.
        $monthlyFeeCandidates = []; // monthly_fee amounts; ambiguous duplicates resolve to the higher
        $flatMonthly = 0.0;         // flat_fee (eur_per_month) package charges, additive on top of the base fee
        $flatOnce = 0.0;
        $flatCharges = [];
        $spotMargin = null;

        foreach ($this->effectiveBilledComponents($phase, $allPhases) as $component) {
            if ($component->isUncostableWhenBilled()) {
                return null;
            }
            $type = $component->type;
            $actualAmount = (float) $component->amount;
            $amount = $normalPrice && $component->normalAmount !== null && $component->normalAmount > $component->amount
                ? $component->normalAmount
                : $actualAmount;

            if ($type === ComponentType::MonthlyFee) {
                $monthlyFeeCandidates[] = $amount;

                continue;
            }

            if ($type === ComponentType::FlatFee) {
                if ($component->unit === ComponentUnit::EurFlat) {
                    $flatOnce += $amount;
                    $flatCharges[spl_object_id($component)] = $amount;
                } else {
                    $flatMonthly += $amount;
                }

                continue;
            }

            if ($type === ComponentType::SpotMargin) {
                $spotMargin = ($spotMargin ?? 0.0) + $amount;

                continue;
            }

            if ($type->isPerKwhEnergy()) {
                // A phase can carry a duplicate component (e.g. a spurious 0 alongside the real
                // rate). Prefer the first non-zero amount so a placeholder 0 never wins.
                $existing = $actualEnergy[$type->value] ?? null;
                if ($existing === null || ((float) $existing === 0.0 && $actualAmount !== 0.0)) {
                    $actualEnergy[$type->value] = $actualAmount;
                    $energy[$type->value] = $amount;
                }
            }
        }

        // Spot contract with the margin misclassified as a small fixed energy rate (e.g.
        // energy_day 0.33) and no explicit spot_margin: fold those sub-ceiling rates into the
        // spot margin so the spot base is applied. Values are equal per bucket in practice, so
        // the max is exact; if they ever differ it is the conservative (higher) choice. A rate
        // above the ceiling is a genuine all-in price and is left as fixed energy.
        if ($isSpot && $spotMargin === null && $actualEnergy !== []
            && max($actualEnergy) <= self::SPOT_MARGIN_CEILING_CENTS) {
            $spotMargin = max($energy);
            $energy = [];
        }

        $usesSpot = $spotMargin !== null;
        if ($usesSpot && ! $spot->isAvailable()) {
            return null;
        }

        // Duplicate/ambiguous monthly fees resolve to the higher, conservative value; package
        // (flat_fee eur_per_month) charges add on top of the resolved base fee.
        $monthlyFee = ($monthlyFeeCandidates !== [] ? max($monthlyFeeCandidates) : 0.0) + $flatMonthly;

        $general = $energy[ComponentType::EnergyGeneral->value] ?? null;
        $spotDay = $usesSpot ? (($spot->dayAvgWithTax ?? 0.0) + $spotMargin) : null;
        $spotNight = $usesSpot ? (($spot->nightAvgWithTax ?? 0.0) + $spotMargin) : null;

        $buckets = [];
        $display = ['general' => null, 'day' => null, 'night' => null, 'seasonal_winter' => null, 'seasonal_other' => null];

        switch ($metering) {
            case MeteringType::Time:
                $day = $energy[ComponentType::EnergyDay->value] ?? $general ?? $spotDay;
                $night = $energy[ComponentType::EnergyNight->value] ?? $general ?? $spotNight;
                if ($day === null || $night === null) {
                    return null;
                }
                $buckets = ['DayTime' => $day, 'NightTime' => $night];
                $display['day'] = $energy[ComponentType::EnergyDay->value] ?? null;
                $display['night'] = $energy[ComponentType::EnergyNight->value] ?? null;
                $display['general'] = $general;
                break;

            case MeteringType::Season:
                // Seasonal buckets are all-hours consumption; a spot contract prices them at the
                // spot rate + margin. Mirror the General-spot approximation (day average).
                $winter = $energy[ComponentType::EnergySeasonalWinter->value] ?? $general ?? $spotDay;
                $other = $energy[ComponentType::EnergySeasonalOther->value] ?? $general ?? $spotDay;
                if ($winter === null || $other === null) {
                    return null;
                }
                $buckets = ['SeasonalWinterDay' => $winter, 'SeasonalOther' => $other];
                $display['seasonal_winter'] = $energy[ComponentType::EnergySeasonalWinter->value] ?? null;
                $display['seasonal_other'] = $energy[ComponentType::EnergySeasonalOther->value] ?? null;
                break;

            case MeteringType::General:
            default:
                $rate = $general ?? $spotDay;
                if ($rate === null && $flatMonthly <= 0) {
                    return null;
                }
                $buckets = ['General' => $rate ?? 0.0];
                $display['general'] = $general;
                break;
        }

        return [
            'buckets' => $buckets,
            'monthly_fee' => $monthlyFee,
            'flat_once' => $flatOnce,
            'flat_charges' => $flatCharges,
            'spot_margin' => $spotMargin,
            'uses_spot' => $usesSpot,
            'package' => null,
            'display' => $display,
        ];
    }

    /** @param list<PricingPhase> $allPhases */
    private function hasAmbiguousEnergyMechanisms(PricingPhase $phase, array $allPhases): bool
    {
        $fixed = false;
        $spot = false;
        foreach ($this->effectiveBilledComponents($phase, $allPhases) as $component) {
            $fixed = $fixed || ($component->type->isPerKwhEnergy() && $component->unit === ComponentUnit::CentsPerKwh);
            $spot = $spot || $component->type === ComponentType::SpotMargin;
        }

        return $fixed && $spot;
    }

    /**
     * The billed components in effect for a phase, letting the phase override the base
     * (standing) price per component type. A promotional phase that lists only the changed
     * component (e.g. `monthly_fee = 0` for the first month) inherits the unchanged energy
     * price from the base phase instead of being read as free energy. A component type the
     * phase specifies at all — including an explicit 0 — is an override and is not inherited.
     *
     * **A phase never inherits the other per-kWh mechanism.** `spot_margin` and the fixed
     * `energy_*` rates are two ways of pricing the same kWh, so a phase that states one must
     * not receive the other from the base phase: `resolvePhaseRates` prefers a fixed rate over
     * the spot base, so an inherited `energy_general` silently overrides the phase's own spot
     * margin. Cheap Markkinahintasähkö is exactly that shape (month 1 flat 6,99 c/kWh, then
     * Nord Pool monthly average + 1,29 c/kWh margin) and the whole year was priced at the
     * one-month promo rate, understating it by about 95 €/yr at 5000 kWh. Inheritance inside
     * one mechanism is unchanged, so a Time phase that restates only `energy_day` still
     * inherits `energy_night`.
     *
     * @param  list<PricingPhase>  $allPhases
     * @return list<CanonicalComponent>
     */
    private function effectiveBilledComponents(PricingPhase $phase, array $allPhases): array
    {
        $own = $phase->billedComponents();
        $base = $this->basePricingPhase($allPhases);

        if ($base === null || $base === $phase) {
            return $own;
        }

        $ownTypes = [];
        $ownFixedEnergy = false;
        $ownSpotMargin = false;
        foreach ($own as $component) {
            $ownTypes[$component->type->value] = true;
            $ownFixedEnergy = $ownFixedEnergy || $component->type->isPerKwhEnergy();
            $ownSpotMargin = $ownSpotMargin || $component->type === ComponentType::SpotMargin;
        }

        $effective = $own;
        foreach ($base->billedComponents() as $component) {
            if (isset($ownTypes[$component->type->value])) {
                continue;
            }

            if ($ownSpotMargin && $component->type->isPerKwhEnergy()) {
                continue;
            }

            if ($ownFixedEnergy && $component->type === ComponentType::SpotMargin) {
                continue;
            }

            $effective[] = $component;
        }

        return $effective;
    }

    /**
     * The phase that best represents the standing (non-promotional) price: the one carrying the
     * most billed energy/margin components. Used as the inheritance base for phases that list
     * only their changed components.
     *
     * @param  list<PricingPhase>  $allPhases
     */
    private function basePricingPhase(array $allPhases): ?PricingPhase
    {
        $best = null;
        $bestScore = 0;

        foreach ($allPhases as $phase) {
            $score = 0;
            foreach ($phase->billedComponents() as $component) {
                if ($component->type->isPerKwhEnergy() || $component->type === ComponentType::SpotMargin) {
                    $score++;
                }
            }
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $phase;
            }
        }

        return $best;
    }

    /**
     * A Spot contract whose current phase yields a costable spot rate (a disclosed margin plus
     * available spot averages). Such a contract is inherently a spot estimate even if the LLM
     * marked it incomplete over description wording.
     */
    private function isCostableSpot(CanonicalContractData $data, ContractContext $context, ?int $currentPhaseIndex, MeteringType $metering, SpotAssumptions $spot): bool
    {
        if (! $context->isSpot() || $currentPhaseIndex === null) {
            return false;
        }

        $rates = $this->resolvePhaseRates($data->phases[$currentPhaseIndex], $data->phases, $metering, $spot, $context->isSpot());

        return $rates !== null && $rates['uses_spot'];
    }

    /**
     * A fully-covered contract whose only gap is a duplicate/ambiguous monthly fee (two monthly_fee
     * components in one phase). It is fully costable — resolvePhaseRates takes the higher fee — so it
     * is listed rather than hidden.
     */
    private function isResolvableDuplicateFee(CanonicalContractData $data, ?int $currentPhaseIndex, bool $fullyCovered, MeteringType $metering, SpotAssumptions $spot, bool $isSpot = false): bool
    {
        if ($currentPhaseIndex === null || ! $fullyCovered) {
            return false;
        }

        $monthlyFeeCount = 0;
        foreach ($data->phases[$currentPhaseIndex]->billedComponents() as $component) {
            if ($component->type === ComponentType::MonthlyFee) {
                $monthlyFeeCount++;
            }
        }

        if ($monthlyFeeCount < 2) {
            return false;
        }

        return $this->resolvePhaseRates($data->phases[$currentPhaseIndex], $data->phases, $metering, $spot, $isSpot) !== null;
    }

    /**
     * @param  list<PricingPhase>  $phases
     */
    private function deriveMetering(array $phases, ?string $contextMetering = null): MeteringType
    {
        $hasTime = false;
        $hasSeason = false;
        $hasPackage = false;

        foreach ($phases as $phase) {
            $hasPackage = $hasPackage || $phase->package !== null;
            foreach ($phase->billedComponents() as $component) {
                $type = $component->type;
                if ($type === ComponentType::SpotMargin || $type === ComponentType::EnergyDay || $type === ComponentType::EnergyNight) {
                    $hasTime = true;
                }
                if ($type === ComponentType::EnergySeasonalWinter || $type === ComponentType::EnergySeasonalOther) {
                    $hasSeason = true;
                }
            }
        }

        return match (true) {
            $hasTime => MeteringType::Time,
            $hasSeason => MeteringType::Season,
            $hasPackage => MeteringType::fromString($contextMetering),
            default => MeteringType::General,
        };
    }

    /**
     * @param  list<WindowSegment>  $segments
     */
    private function phaseIndexAt(array $segments, CarbonImmutable $windowStart): ?int
    {
        foreach ($segments as $segment) {
            if ($segment->start->equalTo($windowStart)) {
                return $segment->phaseIndex;
            }
        }

        return $segments[0]->phaseIndex ?? null;
    }

    /**
     * The phase index of the last (chronologically latest) covered segment — the most recent
     * disclosed price, used to hold the ongoing/recurring price forward across an uncovered tail.
     *
     * @param  list<WindowSegment>  $segments
     */
    private function lastCoveredPhaseIndex(array $segments): ?int
    {
        $last = null;
        foreach ($segments as $segment) {
            if ($segment->phaseIndex !== null) {
                $last = $segment->phaseIndex;
            }
        }

        return $last;
    }

    /**
     * The phase whose price is in effect at signup. Normally the phase covering the window
     * start; when no phase covers it (e.g. a recurring/current phase with unknown boundaries
     * that resolves to no dated range), fall back to the first phase that carries pricing —
     * that is the described current price, held forward for the estimate.
     *
     * @param  list<WindowSegment>  $segments
     */
    private function resolveCurrentPhaseIndex(array $segments, CanonicalContractData $data, CarbonImmutable $windowStart): ?int
    {
        $atStart = $this->phaseIndexAt($segments, $windowStart);
        if ($atStart !== null) {
            return $atStart;
        }

        foreach ($data->phases as $index => $phase) {
            if ($phase->hasKnownPricing()) {
                return $index;
            }
        }

        return null;
    }

    /**
     * @param  list<WindowSegment>  $segments
     */
    private function onlyFuturePricingUnknown(CanonicalContractData $data): bool
    {
        if (array_diff($data->issueCodes, [
            'future_price_unknown', 'future_price_omitted', 'structured_matches_intro_only',
            'promotion_metadata_missing', 'recurring_reset_requires_estimate',
            'structured_matches_description', 'optional_fixing_not_in_base_price',
        ]) !== []) {
            return false;
        }

        foreach ($data->phases as $phase) {
            if ($phase->package !== null) {
                return true;
            }
            foreach ($phase->billedComponents() as $component) {
                if ($component->type->isPerKwhEnergy() || $component->type === ComponentType::SpotMargin) {
                    return true;
                }
            }
        }

        return false;
    }

    /** Latest known billed phase whose start has already occurred; never borrow a future rate. */
    private function applicableKnownPhaseIndex(CanonicalContractData $data, CarbonImmutable $windowStart, CarbonImmutable $point, ?array &$applicablePhases = null): ?int
    {
        $applicablePhases = [];
        $latest = null;
        $latestStart = null;
        foreach ($data->phases as $index => $phase) {
            if (! $phase->hasKnownPricing()) {
                continue;
            }
            $start = match ($phase->starts->kind) {
                BoundaryKind::Date => $this->parseScheduleDate($phase->starts->value),
                BoundaryKind::AfterMonths => is_numeric($phase->starts->value)
                    ? $windowStart->addMonthsNoOverflow((int) $phase->starts->value) : null,
                BoundaryKind::PeriodBoundary => $this->parseScheduleDate($data->recurringSchedule->currentPeriodStart) ?? $windowStart,
                default => $windowStart,
            };
            if ($start !== null && $start->lessThanOrEqualTo($point)) {
                $applicablePhases[$index] = $phase;
                if ($latestStart === null || $start->greaterThanOrEqualTo($latestStart)) {
                    $latest = $index;
                    $latestStart = $start;
                }
            }
        }

        return $latest;
    }

    private function hasUncovered(array $segments): bool
    {
        if ($segments === []) {
            return true;
        }

        foreach ($segments as $segment) {
            if (! $segment->isCovered()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the uncovered tail is a fixed-term contract (< 12 months) with the term
     * disclosed from the start — the "keep and annualize the term price" case.
     *
     * @param  list<WindowSegment>  $segments
     */
    private function isFixedTermTermOnly(ContractContext $context, array $segments, CarbonImmutable $windowStart): bool
    {
        if (! $context->isFixedTerm()) {
            return false;
        }

        $months = $context->fixedTermMonths();
        if ($months === null || $months < 1 || $months >= 12) {
            return false;
        }

        return $this->phaseIndexAt($segments, $windowStart) !== null;
    }

    private function elapsedMonth(CarbonImmutable $windowStart, CarbonImmutable $point): int
    {
        for ($month = 11; $month > 0; $month--) {
            if ($point->greaterThanOrEqualTo($windowStart->addMonthsNoOverflow($month))) {
                return $month;
            }
        }

        return 0;
    }

    /**
     * Keep only the timeline slices inside a real fixed term.
     *
     * @param  list<WindowSegment>  $segments
     * @return list<WindowSegment>
     */
    private function splitSegmentsAt(array $segments, CarbonImmutable $point): array
    {
        $split = [];
        foreach ($segments as $segment) {
            if ($segment->start->lessThan($point) && $segment->end->greaterThan($point)) {
                $split[] = new WindowSegment($segment->start, $point, $segment->monthIndex, $segment->phaseIndex, $segment->annualMonthScale, $segment->billingMonthDays);
                $split[] = new WindowSegment($point, $segment->end, $segment->monthIndex, $segment->phaseIndex, $segment->annualMonthScale, $segment->billingMonthDays);
            } else {
                $split[] = $segment;
            }
        }

        return $split;
    }

    private function segmentsUntil(array $segments, CarbonImmutable $end): array
    {
        $inside = [];

        foreach ($segments as $segment) {
            if ($segment->start->greaterThanOrEqualTo($end)) {
                break;
            }

            $inside[] = $segment->end->greaterThan($end)
                ? new WindowSegment($segment->start, $end, $segment->monthIndex, $segment->phaseIndex, $segment->annualMonthScale, $segment->billingMonthDays)
                : $segment;
        }

        return $inside;
    }

    /**
     * The phases that actually governed the window, with the dates and the rates they were
     * costed at.
     *
     * The resolved dates and rates are here (and not only the phase's own boundary kinds)
     * because the contract detail page has to state a mid-window mechanism change as two
     * dated receipt rows. Cheap Markkinahintasähkö is one flat month at 6,99 c/kWh and then
     * Nord Pool's monthly average + 1,29 c/kWh; without the resolved pair the page had to
     * guess from one relational component and printed "Marginaali 6,99". Re-deriving the
     * timeline in a presenter would be a second implementation of this algorithm, so the
     * record of what happened travels with the cost payload instead.
     *
     * @param  list<PricingPhase>  $phases
     * @param  array<int, array{start: CarbonImmutable, end: CarbonImmutable, rates: array<string, mixed>}>  $spans
     * @return list<array<string, mixed>>
     */
    private function buildBreakdown(array $phases, array $spans): array
    {
        uasort($spans, static fn (array $a, array $b) => $a['start'] <=> $b['start']);

        $breakdown = [];

        foreach ($spans as $index => $span) {
            $phase = $phases[$index];
            $rates = $span['rates'];
            $display = $rates['display'] ?? [];

            $breakdown[] = [
                'label' => $phase->label,
                'phase_kind' => $phase->phaseKind->value,
                'starts' => $phase->starts->kind->value,
                'ends' => $phase->ends->kind->value,
                'ends_value' => $phase->ends->value,
                // Resolved coverage inside the 12-month window. `window_end` is the last day
                // the phase governs (the segment end is exclusive).
                'window_start' => $span['start']->format('Y-m-d'),
                'window_end' => $span['end']->subDay()->format('Y-m-d'),
                // The per-kWh mechanism, so a consumer can see a spot/fixed switch without
                // reinterpreting the components.
                'uses_spot' => (bool) ($rates['uses_spot'] ?? false),
                'energy_cents' => $display['general'] ?? $display['day'] ?? $display['seasonal_winter'] ?? null,
                'spot_margin_cents' => $rates['spot_margin'] ?? null,
                'monthly_fee' => $rates['monthly_fee'] ?? null,
                'energy_package' => ($rates['package'] ?? null)?->toArray(),
                ...(array_key_exists('energy_price_guaranteed', $span) ? ['energy_price_guaranteed' => $span['energy_price_guaranteed']] : []),
            ];
        }

        return $breakdown;
    }

    /**
     * @return list<string>
     */
    private function assumptions(
        ContractComparability $comparability,
        bool $usesSpot,
        bool $estimateFill,
        ?ResetEstimate $reset = null,
        bool $termAnnualized = false,
        ?SupplierAdjustedEstimate $supplierAdjusted = null,
        ?SpotEstimate $spotEstimate = null,
        bool $disclosedFeeChanges = false,
    ): array {
        $assumptions = [];
        if ($usesSpot) {
            $assumptions[] = $spotEstimate?->basis === SpotEstimateBasis::ForwardCurve
                ? (in_array('zero_intraday_shape_fallback', $spotEstimate->flags, true)
                    ? 'spot_forward_curve_flat_baseload_shape'
                    : 'spot_forward_curve_with_rolling_365_intraday_shape')
                : 'spot_rolling_365_day_night_average';
        }
        if ($reset !== null && $reset->shiftsPrices()) {
            $assumptions[] = match ($reset->basis) {
                ResetEstimateBasis::ForwardCurveShift => 'reset_tail_shifted_on_forward_curve',
                ResetEstimateBasis::ForwardPremium => 'reset_tail_current_futures_plus_comparable_premium',
                default => 'reset_tail_shifted_on_spot_seasonal_index',
            };
        } elseif ($estimateFill && ! $usesSpot) {
            $assumptions[] = 'held_current_price_forward';
        }
        if ($estimateFill) {
            $assumptions[] = 'unknown_periods_use_latest_applicable_price_or_disclosed_normal';
        }
        if ($supplierAdjusted !== null) {
            $assumptions[] = match ($supplierAdjusted->basis) {
                SupplierAdjustedEstimateBasis::ForwardCurveShift => 'supplier_adjusted_tail_shifted_on_forward_curve',
                SupplierAdjustedEstimateBasis::ForwardPremium => 'supplier_adjusted_tail_uses_comparable_forward_premium',
                SupplierAdjustedEstimateBasis::SpotSeasonalIndex => 'supplier_adjusted_tail_shifted_on_spot_seasonal_index',
                SupplierAdjustedEstimateBasis::HoldFlat => 'supplier_adjusted_tail_held_current',
            };
            $assumptions[] = $disclosedFeeChanges ? 'supplier_adjusted_monthly_fee_disclosed_phases' : 'supplier_adjusted_monthly_fee_held_flat';
        }
        if ($comparability === ContractComparability::TermPriceOnly || $termAnnualized) {
            $assumptions[] = 'term_price_annualized';
        }
        if ($comparability === ContractComparability::BaseOnlyHybrid) {
            $assumptions[] = 'excludes_consumption_effect';
        }

        return $assumptions;
    }

    private function resetEstimateMethod(?ResetEstimate $reset): ?EstimateMethod
    {
        if ($reset === null || ! $reset->shiftsPrices()) {
            return null;
        }

        return match ($reset->basis) {
            ResetEstimateBasis::ForwardCurveShift => EstimateMethod::RecurringForwardCurveShift,
            ResetEstimateBasis::ForwardPremium => EstimateMethod::RecurringForwardPremium,
            ResetEstimateBasis::SpotSeasonalIndex => EstimateMethod::RecurringSpotSeasonalIndex,
            ResetEstimateBasis::HoldFlat => null,
        };
    }

    private function supplierAdjustedEstimateMethod(?SupplierAdjustedEstimate $estimate): ?EstimateMethod
    {
        return match ($estimate?->basis) {
            SupplierAdjustedEstimateBasis::ForwardCurveShift => EstimateMethod::SupplierAdjustedForwardCurveShift,
            SupplierAdjustedEstimateBasis::ForwardPremium => EstimateMethod::SupplierAdjustedForwardPremium,
            SupplierAdjustedEstimateBasis::SpotSeasonalIndex => EstimateMethod::SupplierAdjustedSpotSeasonalIndex,
            SupplierAdjustedEstimateBasis::HoldFlat => EstimateMethod::HoldCurrentSupplierPrice,
            null => null,
        };
    }

    /**
     * @param  array<int, array<string, float>>  $profile
     * @param  list<WindowSegment>  $segments
     */
    private function resolveSupplierAdjustedEstimate(
        SupplierAdjustedCandidate $candidate,
        array $profile,
        CarbonImmutable $windowStart,
        array $segments,
        PriceEpisodeAnchor $anchor,
        ContractContext $context,
        ?PremiumEstimate $premium = null,
        bool $currentPolicy = false,
    ): SupplierAdjustedEstimate {
        $tailStart = $windowStart->addMonthNoOverflow()->startOfMonth();
        [$monthWeights, $tailMonthKeys] = $this->segmentMonthWeights($profile, $segments, $tailStart);
        $bucketWeights = [];
        foreach ($segments as $segment) {
            foreach ($profile[$segment->monthIndex] ?? [] as $bucket => $kwh) {
                $key = $segment->start->format('Y-m');
                $bucketWeights[$key][$bucket] = ($bucketWeights[$key][$bucket] ?? 0.0) + $kwh * $segment->annualMonthFraction();
            }
        }

        $seasonalAnchor = null;
        if ($currentPolicy) {
            $energyRates = $candidate->normalizedEnergyRates();
            $weighted = $weight = 0.0;
            foreach ($profile as $buckets) {
                foreach ($buckets as $bucket => $kwh) {
                    $rate = $energyRates[SupplierAdjustedEstimate::energyBucket($bucket)] ?? null;
                    if ($rate !== null) {
                        $weighted += $rate * $kwh;
                        $weight += $kwh;
                    }
                }
            }
            $seasonalAnchor = $weight > 0 ? $weighted / $weight : null;
        }

        return $this->supplierAdjustedEstimator->estimate(new SupplierAdjustedEstimateRequest(
            asOfDate: $windowStart,
            priceEpisodeAnchor: $anchor,
            tailMonthKeys: $tailMonthKeys,
            currentEnergyPriceCentsPerKwh: $candidate->currentEnergyPriceCentsPerKwh,
            monthlyFeeEur: $candidate->monthlyFeeEur,
            monthWeights: $monthWeights,
            marketPriceMultiplier: $context->includesVat() ? 1.0 : 1 / $this->vatMultiplier,
            energyRates: $currentPolicy ? $candidate->normalizedEnergyRates() : [],
            premium: $premium,
            bucketMonthWeights: $bucketWeights,
            pricingMechanism: $candidate->pricingMechanism,
            policy: $currentPolicy ? ComparisonPolicy::Current : ComparisonPolicy::Historical,
            seasonalAnchorEnergyPriceCentsPerKwh: $seasonalAnchor,
        ));
    }

    /**
     * Build the shape-only forward-curve shift for an active market-reset product, or null when
     * the shift does not apply (flag off, not a reset, Spot, or no market shape available).
     *
     * The contractually known part of the window is never repriced. `heldForward = true` is only
     * the uncovered Hybrid base-only fallback. A fully covered Hybrid costs its disclosed phase
     * timeline and uses the segment-based reset path.
     *
     * @param  array<int, array<string, float>>  $profile
     * @param  list<WindowSegment>  $segments
     */
    private function resolveResetEstimate(
        CanonicalContractData $data,
        ContractContext $context,
        MeteringType $metering,
        array $profile,
        SpotAssumptions $spot,
        CarbonImmutable $windowStart,
        array $segments,
        ?int $currentPhaseIndex,
        bool $heldForward,
        ComparisonPolicy $policy = ComparisonPolicy::Historical,
        ?PremiumEstimate $premium = null,
        bool $allowZeroAnchor = false,
    ): ?ResetEstimate {
        if (! $this->resetEstimator->enabled()) {
            return null;
        }

        if (! $data->recurringSchedule->isActiveReset()) {
            return null;
        }

        // Spot prices use their own direct wholesale forward strip. A supplier-reset shift would
        // add a second market adjustment to the same kWh.
        if ($context->isSpot()) {
            return null;
        }

        $fillPhaseIndex = $heldForward
            ? $currentPhaseIndex
            : ($this->lastCoveredPhaseIndex($segments) ?? $currentPhaseIndex);

        if ($fillPhaseIndex === null) {
            return null;
        }

        $rates = $this->resolvePhaseRates($data->phases[$fillPhaseIndex], $data->phases, $metering, $spot, $context->isSpot());

        if ($rates === null || $rates['uses_spot']) {
            return null;
        }

        $anchorPrice = $this->weightedEnergyPrice($rates, $profile);

        if ($anchorPrice === null || $anchorPrice < 0 || ($anchorPrice === 0.0 && ! $allowZeroAnchor)) {
            return null;
        }

        $tailStart = $this->resetTailStart($data, $segments, $windowStart, $metering, $spot, $context->isSpot());
        [$monthWeights, $tailMonthKeys] = $heldForward
            ? $this->heldForwardMonthWeights($profile, $windowStart, $tailStart)
            : $this->segmentMonthWeights($profile, $segments, $tailStart);

        if ($tailMonthKeys === []) {
            return null;
        }

        $candidate = $policy === ComparisonPolicy::Current && $premium !== null
            ? $this->resetPremiumCandidate('', $data, $context, $windowStart) : null;
        $bucketWeights = $tailBucketWeights = [];
        if ($candidate !== null) {
            foreach ($segments as $segment) {
                $key = $segment->start->format('Y-m');
                foreach ($profile[$segment->monthIndex] ?? [] as $bucket => $kwh) {
                    $weight = $kwh * $segment->annualMonthFraction();
                    $bucketWeights[$key][$bucket] = ($bucketWeights[$key][$bucket] ?? 0.0) + $weight;
                    if ($segment->start->gte($tailStart)) {
                        $tailBucketWeights[$key][$bucket] = ($tailBucketWeights[$key][$bucket] ?? 0.0) + $weight;
                    }
                }
            }
        }
        $estimate = $this->resetEstimator->estimate(new ResetEstimateRequest(
            cadence: $data->recurringSchedule->cadence,
            asOfDate: $windowStart,
            anchorPeriodMonth: $tailStart->subDay()->startOfMonth(),
            currentPeriodStart: $this->resetPeriodStart($data, $tailStart),
            tailMonthKeys: $tailMonthKeys,
            anchorEnergyPriceCentsPerKwh: $anchorPrice,
            monthWeights: $monthWeights,
            marketPriceMultiplier: $context->includesVat() ? 1.0 : 1 / $this->vatMultiplier,
            policy: $policy,
            energyRates: $candidate?->normalizedEnergyRates() ?? [],
            premium: $candidate !== null ? $premium : null,
            bucketMonthWeights: $bucketWeights,
            tailBucketMonthWeights: $tailBucketWeights,
            pricingMechanism: $candidate?->pricingMechanism ?? $context->pricingModel,
        ));

        return $estimate->shiftsPrices() ? $estimate->withTailStart($tailStart->toDateString()) : null;
    }

    /**
     * The exclusive date at which the repriced reset tail begins. Everything before it is left
     * exactly as the contract discloses it.
     *
     * It is the latest of:
     *  - the end of the cadence period containing the window start (the current period is
     *    contractual, so at minimum that period stays exact);
     *  - the disclosed `current_period_end`, when the provider declares a non-calendar period;
     *  - the end of the latest finite known energy coverage (not a fee-only transition).
     *
     * A phase whose end is `none` is an open-ended claim, not a credible reset-period boundary:
     * a product that resets quarterly does not have a known price for twelve months. Cadence
     * `other` uses the same quarterly calendar proxy because its exact boundaries are unknown.
     * Those are exactly the lineages the hold-flat defect hides in.
     *
     * @param  list<WindowSegment>  $segments
     */
    private function resetTailStart(CanonicalContractData $data, array $segments, CarbonImmutable $windowStart, MeteringType $metering, SpotAssumptions $spot, bool $isSpot): CarbonImmutable
    {
        $windowEnd = $windowStart->addMonthsNoOverflow(12);

        $candidate = $data->recurringSchedule->cadence === 'monthly'
            ? $windowStart->addMonthNoOverflow()->startOfMonth()
            : $windowStart->startOfMonth()->month(((int) floor(($windowStart->month - 1) / 3)) * 3 + 1)->addMonthsNoOverflow(3);

        $declaredEnd = $this->parseScheduleDate($data->recurringSchedule->currentPeriodEnd);
        if ($declaredEnd !== null && $declaredEnd->addDay()->greaterThan($candidate)) {
            $candidate = $declaredEnd->addDay();
        }

        foreach ($segments as $index => $segment) {
            if ($segment->phaseIndex === null) {
                continue;
            }

            $next = $segments[$index + 1] ?? null;
            if ($next?->phaseIndex === $segment->phaseIndex) {
                continue;
            }

            $ends = $data->phases[$segment->phaseIndex]->ends->kind;
            if ($ends === BoundaryKind::None || $ends === BoundaryKind::Unknown || $ends === BoundaryKind::ContractStart) {
                continue;
            }

            // Fee billing keeps its full timeline, but a fee change alone is not evidence
            // that the energy price is known until this boundary.
            if ($ends !== BoundaryKind::PeriodBoundary && $next?->phaseIndex !== null) {
                $rates = $this->resolvePhaseRates($data->phases[$segment->phaseIndex], $data->phases, $metering, $spot, $isSpot);
                $nextRates = $this->resolvePhaseRates($data->phases[$next->phaseIndex], $data->phases, $metering, $spot, $isSpot);
                if ($rates !== null && $nextRates !== null
                    && $rates['package'] === null && $nextRates['package'] === null
                    && $rates['uses_spot'] === $nextRates['uses_spot']
                    && $rates['spot_margin'] === $nextRates['spot_margin']
                    && $rates['buckets'] === $nextRates['buckets']
                    && ($rates['monthly_fee'] !== $nextRates['monthly_fee'] || $rates['flat_once'] !== $nextRates['flat_once'])) {
                    continue;
                }
            }

            if ($segment->end->greaterThan($candidate)) {
                $candidate = $segment->end;
            }
        }

        return $candidate->greaterThan($windowEnd) ? $windowEnd : $candidate;
    }

    /**
     * Start date of the reset period the held-forward price belongs to — the vintage anchor for
     * `F_reference`. The seller set that price before the period began, so the spread has to be
     * read against the forward curve as it stood then.
     *
     * Derived from the cadence calendar of the anchor period, and overridden by a disclosed
     * `current_period_start` when the source declares a non-calendar period that falls inside it.
     * Every non-monthly cadence, including `other`, uses the quarterly calendar proxy. A declared
     * date from an older period can never leak in through that check.
     */
    private function resetPeriodStart(CanonicalContractData $data, CarbonImmutable $tailStart): CarbonImmutable
    {
        $anchorMonth = $tailStart->subDay()->startOfMonth();
        $isMonthly = $data->recurringSchedule->cadence === 'monthly';

        $periodStart = $isMonthly
            ? $anchorMonth
            : $anchorMonth->month(((int) floor(($anchorMonth->month - 1) / 3)) * 3 + 1);
        $periodEnd = $isMonthly
            ? $periodStart->addMonthNoOverflow()
            : $periodStart->addMonthsNoOverflow(3);

        $declared = $this->parseScheduleDate($data->recurringSchedule->currentPeriodStart);

        if ($declared !== null && $declared->greaterThan($periodStart) && $declared->lessThan($periodEnd)) {
            return $declared;
        }

        return $periodStart;
    }

    private function parseScheduleDate(?string $value): ?CarbonImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value, 'Europe/Helsinki')->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Per-`Y-m` kWh weights taken from the actual window segments, plus the month keys that fall
     * inside the repriced tail. Using segments keeps partial first/last months exact and handles
     * a window whose first and last calendar month are the same.
     *
     * @param  array<int, array<string, float>>  $profile
     * @param  list<WindowSegment>  $segments
     * @return array{0: array<string, float>, 1: list<string>}
     */
    private function segmentMonthWeights(array $profile, array $segments, CarbonImmutable $tailStart): array
    {
        $weights = [];
        $tailKeys = [];

        foreach ($segments as $segment) {
            $key = $segment->start->format('Y-m');
            $weights[$key] = ($weights[$key] ?? 0.0)
                + array_sum($profile[$segment->monthIndex] ?? []) * $segment->annualMonthFraction();

            if ($segment->start->greaterThanOrEqualTo($tailStart)) {
                $tailKeys[$key] = true;
            }
        }

        return [$weights, array_keys($tailKeys)];
    }

    /**
     * Per-`Y-m` kWh weights for the annualized hold-forward model, which spreads the usage
     * profile across the twelve calendar months starting at the window start.
     *
     * @param  array<int, array<string, float>>  $profile
     * @return array{0: array<string, float>, 1: list<string>}
     */
    private function heldForwardMonthWeights(array $profile, CarbonImmutable $windowStart, CarbonImmutable $tailStart): array
    {
        $weights = [];
        $tailKeys = [];

        for ($offset = 0; $offset < 12; $offset++) {
            $month = $windowStart->startOfMonth()->addMonthsNoOverflow($offset);
            $key = $month->format('Y-m');
            $weights[$key] = array_sum($profile[(int) $month->month - 1] ?? []);

            if ($month->greaterThanOrEqualTo($tailStart)) {
                $tailKeys[] = $key;
            }
        }

        return [$weights, $tailKeys];
    }

    /**
     * @return array<int, string> calendar-month index (0-11) => the `Y-m` that month occupies
     *                            inside this window
     */
    private function windowMonthKeys(CarbonImmutable $windowStart): array
    {
        $keys = [];

        for ($offset = 0; $offset < 12; $offset++) {
            $month = $windowStart->startOfMonth()->addMonthsNoOverflow($offset);
            $keys[(int) $month->month - 1] = $month->format('Y-m');
        }

        return $keys;
    }

    /**
     * Consumption-weighted energy price of one resolved rate set — the single c/kWh figure the
     * reset shift is anchored on and the plausibility band is tested against.
     *
     * @param  array<string, mixed>  $rates
     * @param  array<int, array<string, float>>  $profile
     */
    private function weightedEnergyPrice(array $rates, array $profile): ?float
    {
        $weighted = 0.0;
        $weights = 0.0;

        foreach ($profile as $monthBuckets) {
            foreach ($rates['buckets'] as $bucket => $rate) {
                $kwh = $monthBuckets[$bucket] ?? 0.0;
                $weighted += $kwh * $rate;
                $weights += $kwh;
            }
        }

        return $weights > 0 ? $weighted / $weights : null;
    }
}
