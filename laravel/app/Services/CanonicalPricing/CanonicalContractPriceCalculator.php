<?php

namespace App\Services\CanonicalPricing;

use App\Enums\MeteringType;
use App\Services\CanonicalPricing\DTO\CanonicalComponent;
use App\Services\CanonicalPricing\DTO\CanonicalContractData;
use App\Services\CanonicalPricing\DTO\CanonicalPricingOutcome;
use App\Services\CanonicalPricing\DTO\ContractContext;
use App\Services\CanonicalPricing\DTO\PricingPhase;
use App\Services\CanonicalPricing\DTO\SpotAssumptions;
use App\Services\CanonicalPricing\DTO\WindowSegment;
use App\Services\CanonicalPricing\Enums\BoundaryKind;
use App\Services\CanonicalPricing\Enums\CalculationStatus;
use App\Services\CanonicalPricing\Enums\ComponentType;
use App\Services\CanonicalPricing\Enums\ComponentUnit;
use App\Services\CanonicalPricing\Enums\ContractComparability;
use App\Services\CanonicalPricing\Enums\EstimateMethod;
use App\Services\CanonicalPricing\MarketReset\DTO\ResetEstimate;
use App\Services\CanonicalPricing\MarketReset\DTO\ResetEstimateRequest;
use App\Services\CanonicalPricing\MarketReset\Enums\ResetEstimateBasis;
use App\Services\CanonicalPricing\MarketReset\MarketResetPriceEstimator;
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

    /**
     * @param  MarketResetPriceEstimator|null  $resetEstimator  Reprices the held-forward tail of a
     *                                                          market-reset product. Null (the plain
     *                                                          `new` default used by pure unit tests)
     *                                                          keeps the hold-flat behaviour.
     */
    public function __construct(
        private readonly PhaseTimelineBuilder $timelineBuilder = new PhaseTimelineBuilder(),
        private readonly MonthlyUsageProfileBuilder $usageProfileBuilder = new MonthlyUsageProfileBuilder(),
        private readonly ?MarketResetPriceEstimator $resetEstimator = null,
    ) {
    }

    public function calculate(
        CanonicalContractData $data,
        ContractContext $context,
        EnergyUsage $usage,
        SpotAssumptions $spot,
        ?CarbonInterface $startDate = null,
    ): CanonicalPricingOutcome {
        $windowStart = CarbonImmutable::parse(($startDate ?? CarbonImmutable::now('Europe/Helsinki'))->toDateString(), 'Europe/Helsinki')->startOfDay();

        $metering = $this->deriveMetering($data->phases);
        $profile = $this->usageProfileBuilder->build($metering, $usage, isSpotContract: false);

        $segments = $this->timelineBuilder->build($data->phases, $data->recurringSchedule, $windowStart);
        $currentPhaseIndex = $this->resolveCurrentPhaseIndex($segments, $data, $windowStart);
        $hasUncovered = $this->hasUncovered($segments);
        $fullyCovered = ! $hasUncovered;

        // 1. Hybrid / unsupported: base-only, hold current phase forward.
        if ($data->calculationStatus === CalculationStatus::Unsupported) {
            return $this->costHeldForward(
                $data,
                $context,
                $metering,
                $profile,
                $spot,
                $windowStart,
                $currentPhaseIndex,
                ContractComparability::BaseOnlyHybrid,
                EstimateMethod::HybridBaseOnly,
                reset: $this->resolveResetEstimate($data, $context, $metering, $profile, $spot, $windowStart, $segments, $currentPhaseIndex, heldForward: true),
            );
        }

        // 2. Structural fixed-term with an unknown continuation tail: keep, annualize the term price.
        if (! $fullyCovered && $this->isFixedTermTermOnly($context, $segments, $windowStart)) {
            return $this->costHeldForward(
                $data,
                $context,
                $metering,
                $profile,
                $spot,
                $windowStart,
                $currentPhaseIndex,
                ContractComparability::TermPriceOnly,
                EstimateMethod::TermPriceAnnualized,
                termMonths: $context->fixedTermMonths(),
            );
        }

        // 3. Genuinely broken structured pricing — with two documented exceptions that are fully
        //    costable despite the LLM marking them incomplete:
        //    (a) a Spot contract with a disclosed margin (price = spot market + margin + fee); some are
        //        marked incomplete only because the description phrases the margin as a "toimitusmaksu";
        //    (b) a fully-covered contract whose only gap is a duplicate/ambiguous monthly fee — we
        //        resolve it conservatively to the higher fee.
        if ($data->calculationStatus === CalculationStatus::Incomplete
            && ! $this->isCostableSpot($data, $context, $currentPhaseIndex, $metering, $spot)
            && ! $this->isResolvableDuplicateFee($data, $currentPhaseIndex, $fullyCovered, $metering, $spot, $context->isSpot())) {
            return $this->excluded(ContractComparability::ExcludedIncomplete, $context, $data);
        }

        // 4. A flagged-deceptive contract must be fully covered by disclosed phases to rank —
        //    UNLESS it is a legitimate recurring market product (monthly/quarterly/seasonal reset).
        //    Those behave like Spot: the current period price is known, future periods reset with the
        //    market, and a small first-period intro is not deceptive. They are estimated, not hidden.
        if (! $fullyCovered && $data->misleadingState->value === 'detected' && ! $data->recurringSchedule->isActiveReset()) {
            return $this->excluded(ContractComparability::ExcludedUnknownFuture, $context, $data);
        }

        // 5. Fill an uncovered tail only for legitimate recurring resets or Spot.
        $estimateFill = false;
        if (! $fullyCovered) {
            if ($data->recurringSchedule->isActiveReset() || $context->isSpot()) {
                $estimateFill = true;
            } else {
                return $this->excluded(ContractComparability::ExcludedUnknownFuture, $context, $data);
            }
        }

        $comparability = $data->calculationStatus === CalculationStatus::Exact
            ? ContractComparability::ComparableExact
            : ContractComparability::ComparableEstimate;

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
            $this->resolveResetEstimate($data, $context, $metering, $profile, $spot, $windowStart, $segments, $currentPhaseIndex, heldForward: false),
        );
    }

    /**
     * Cost every window segment at its governing phase's rates; optionally hold the current
     * phase forward across uncovered slices.
     *
     * @param array<int, array<string, float>> $profile
     * @param list<WindowSegment> $segments
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
    ): CanonicalPricingOutcome {
        $usesSpot = false;
        $monthly = array_fill(0, 12, 0.0);
        $flatApplied = [];
        $spans = [];
        $monthKeys = $this->windowMonthKeys($windowStart);

        // Fill an uncovered tail with the most recent disclosed price (the ongoing/recurring
        // price), not the phase at signup — for a recurring product that avoids holding a cheap
        // first-period intro across the whole year.
        $fillPhaseIndex = $this->lastCoveredPhaseIndex($segments) ?? $currentPhaseIndex;

        foreach ($segments as $segment) {
            $phaseIndex = $segment->phaseIndex;
            if ($phaseIndex === null) {
                if (! $estimateFill || $fillPhaseIndex === null) {
                    continue; // genuinely uncovered and not filling (should not happen for listed verdicts)
                }
                $phaseIndex = $fillPhaseIndex;
            }

            $rates = $this->resolvePhaseRates($data->phases[$phaseIndex], $data->phases, $metering, $spot, $context->isSpot());
            if ($rates === null) {
                // A spot phase without spot averages cannot be costed; fail closed.
                return $this->excluded(ContractComparability::ExcludedIncomplete, $context, $data);
            }
            $usesSpot = $usesSpot || $rates['uses_spot'];

            // Record the resolved coverage per phase. A filled tail extends the phase that
            // fills it, which is what the customer will actually be charged.
            $known = $spans[$phaseIndex] ?? null;
            $spans[$phaseIndex] = [
                'start' => ($known !== null && $known['start']->lessThan($segment->start)) ? $known['start'] : $segment->start,
                'end' => ($known !== null && $known['end']->greaterThan($segment->end)) ? $known['end'] : $segment->end,
                'rates' => $rates,
            ];

            $cost = $this->costSegment($segment, $profile, $rates, $flatApplied, $phaseIndex, $reset);
            $monthly[$this->elapsedMonth($windowStart, $segment->start)] += $cost;
        }

        $total = array_sum($monthly);

        $currentRates = $currentPhaseIndex !== null
            ? $this->resolvePhaseRates($data->phases[$currentPhaseIndex], $data->phases, $metering, $spot, $context->isSpot())
            : null;

        // structuredOnly and base carry the same reset shift as the total, so the difference
        // between them keeps measuring only the promotional effect (which is what the integrity
        // label's euro impact reports) instead of mixing in the seasonal repricing.
        $structuredOnly = $currentPhaseIndex !== null
            ? $this->holdForwardTotal($data->phases[$currentPhaseIndex], $data->phases, $metering, $profile, $spot, $context->isSpot(), $reset, $monthKeys)
            : null;

        $base = $this->normalHoldTotal($data, $metering, $profile, $spot, $segments, $windowStart, $context->isSpot(), $reset, $monthKeys);

        return new CanonicalPricingOutcome(
            comparability: $comparability,
            estimateMethod: $usesSpot
                ? EstimateMethod::Rolling365Spot
                : ($this->resetEstimateMethod($reset)
                    ?? ($estimateFill ? EstimateMethod::HoldCurrentRecurringPrice : EstimateMethod::None)),
            totalCost: $total,
            monthlyCosts: $monthly,
            baseTotalCost: $base,
            baseMonthlyCosts: $base !== null ? array_fill(0, 12, $base / 12) : array_fill(0, 12, 0.0),
            structuredOnlyTotal: $structuredOnly,
            isSpotContract: $context->isSpot() || $usesSpot,
            monthlyFixedFee: $currentRates['monthly_fee'] ?? null,
            spotPriceMargin: $currentRates !== null && $currentRates['spot_margin'] !== null ? $currentRates['spot_margin'] : null,
            generalKwhPrice: $currentRates['display']['general'] ?? null,
            daytimeKwhPrice: $currentRates['display']['day'] ?? null,
            nighttimeKwhPrice: $currentRates['display']['night'] ?? null,
            seasonalWinterDayKwhPrice: $currentRates['display']['seasonal_winter'] ?? null,
            seasonalOtherKwhPrice: $currentRates['display']['seasonal_other'] ?? null,
            spotPriceDayAvg: $usesSpot ? $spot->dayAvgWithTax : null,
            spotPriceNightAvg: $usesSpot ? $spot->nightAvgWithTax : null,
            phaseBreakdown: $this->buildBreakdown($data->phases, $spans),
            consumptionEffect: null,
            assumptions: $this->assumptions($comparability, $usesSpot, $estimateFill, $reset),
            resetEstimate: $reset?->shiftsPrices() ? $reset->toArray() : null,
        );
    }

    /**
     * Cost the whole window by holding one phase's rates forward (Hybrid base-only and
     * fixed-term-annualized cases). The comparability/estimate method are supplied.
     *
     * @param array<int, array<string, float>> $profile
     */
    private function costHeldForward(
        CanonicalContractData $data,
        ContractContext $context,
        MeteringType $metering,
        array $profile,
        SpotAssumptions $spot,
        CarbonImmutable $windowStart,
        ?int $currentPhaseIndex,
        ContractComparability $comparability,
        EstimateMethod $estimateMethod,
        ?int $termMonths = null,
        ?ResetEstimate $reset = null,
    ): CanonicalPricingOutcome {
        if ($currentPhaseIndex === null) {
            return $this->excluded(ContractComparability::ExcludedIncomplete, $context, $data);
        }

        $rates = $this->resolvePhaseRates($data->phases[$currentPhaseIndex], $data->phases, $metering, $spot, $context->isSpot());
        if ($rates === null) {
            return $this->excluded(ContractComparability::ExcludedIncomplete, $context, $data);
        }

        $monthKeys = $this->windowMonthKeys($windowStart);
        $total = $this->holdForwardTotal($data->phases[$currentPhaseIndex], $data->phases, $metering, $profile, $spot, $context->isSpot(), $reset, $monthKeys);
        if ($total === null) {
            return $this->excluded(ContractComparability::ExcludedIncomplete, $context, $data);
        }

        return new CanonicalPricingOutcome(
            comparability: $comparability,
            estimateMethod: $estimateMethod,
            totalCost: $total,
            monthlyCosts: array_fill(0, 12, $total / 12),
            baseTotalCost: $total,
            baseMonthlyCosts: array_fill(0, 12, $total / 12),
            structuredOnlyTotal: $total,
            isSpotContract: $context->isSpot() || $rates['uses_spot'],
            monthlyFixedFee: $rates['monthly_fee'],
            spotPriceMargin: $rates['spot_margin'],
            generalKwhPrice: $rates['display']['general'] ?? null,
            daytimeKwhPrice: $rates['display']['day'] ?? null,
            nighttimeKwhPrice: $rates['display']['night'] ?? null,
            seasonalWinterDayKwhPrice: $rates['display']['seasonal_winter'] ?? null,
            seasonalOtherKwhPrice: $rates['display']['seasonal_other'] ?? null,
            spotPriceDayAvg: $rates['uses_spot'] ? $spot->dayAvgWithTax : null,
            spotPriceNightAvg: $rates['uses_spot'] ? $spot->nightAvgWithTax : null,
            termMonths: $termMonths,
            phaseBreakdown: [],
            consumptionEffect: $comparability === ContractComparability::BaseOnlyHybrid && $data->consumptionEffect->present
                ? $data->consumptionEffect
                : null,
            assumptions: $this->assumptions($comparability, $rates['uses_spot'], false, $reset),
            resetEstimate: $reset?->shiftsPrices() ? $reset->toArray() : null,
        );
    }

    private function excluded(ContractComparability $comparability, ContractContext $context, CanonicalContractData $data): CanonicalPricingOutcome
    {
        return new CanonicalPricingOutcome(
            comparability: $comparability,
            estimateMethod: EstimateMethod::None,
            totalCost: null,
            monthlyCosts: array_fill(0, 12, 0.0),
            baseTotalCost: null,
            baseMonthlyCosts: array_fill(0, 12, 0.0),
            structuredOnlyTotal: null,
            isSpotContract: $context->isSpot(),
        );
    }

    /**
     * Cost one segment: energy usage (cents→EUR) plus pro-rated monthly fee plus a one-off flat fee.
     *
     * A market-reset estimate contributes an additive c/kWh offset for this segment's calendar
     * month. The offset is zero for months the contract discloses, and the resulting rate is
     * floored at 0 so a steeply falling curve can never produce a negative energy price.
     *
     * @param array<int, array<string, float>> $profile
     * @param array<string, mixed> $rates
     * @param array<int, bool> $flatApplied
     */
    private function costSegment(WindowSegment $segment, array $profile, array $rates, array &$flatApplied, int $phaseIndex, ?ResetEstimate $reset = null): float
    {
        $fraction = $segment->monthFraction();
        $monthBuckets = $profile[$segment->monthIndex] ?? [];
        $offset = $reset?->offsetForMonthKey($segment->start->format('Y-m')) ?? 0.0;

        $energyCents = 0.0;
        foreach ($rates['buckets'] as $bucket => $rate) {
            $energyCents += ($monthBuckets[$bucket] ?? 0.0) * $fraction * max(0.0, $rate + $offset);
        }

        $cost = $energyCents / 100 + $rates['monthly_fee'] * $fraction;

        if ($rates['flat_once'] > 0 && ! isset($flatApplied[$phaseIndex])) {
            $cost += $rates['flat_once'];
            $flatApplied[$phaseIndex] = true;
        }

        return $cost;
    }

    /**
     * Total cost of holding one phase's rates over the full 12-month window.
     *
     * @param array<int, array<string, float>> $profile
     * @param array<int, string> $monthKeys calendar-month index (0-11) => the `Y-m` that month
     *                                      occupies inside this window
     */
    private function holdForwardTotal(PricingPhase $phase, array $allPhases, MeteringType $metering, array $profile, SpotAssumptions $spot, bool $isSpot = false, ?ResetEstimate $reset = null, array $monthKeys = []): ?float
    {
        $rates = $this->resolvePhaseRates($phase, $allPhases, $metering, $spot, $isSpot);
        if ($rates === null) {
            return null;
        }

        $total = $rates['flat_once'] + $rates['monthly_fee'] * 12;
        foreach ($profile as $monthIndex => $monthBuckets) {
            $offset = ($reset !== null && isset($monthKeys[$monthIndex]))
                ? $reset->offsetForMonthKey($monthKeys[$monthIndex])
                : 0.0;

            foreach ($rates['buckets'] as $bucket => $rate) {
                $total += (($monthBuckets[$bucket] ?? 0.0) * max(0.0, $rate + $offset)) / 100;
            }
        }

        return $total;
    }

    /**
     * The "after promotion" annual cost at the normal/continuation rates, when known.
     *
     * @param array<int, array<string, float>> $profile
     * @param list<WindowSegment> $segments
     * @param array<int, string> $monthKeys
     */
    private function normalHoldTotal(CanonicalContractData $data, MeteringType $metering, array $profile, SpotAssumptions $spot, array $segments, CarbonImmutable $windowStart, bool $isSpot = false, ?ResetEstimate $reset = null, array $monthKeys = []): ?float
    {
        $lastIndex = null;
        foreach ($segments as $segment) {
            if ($segment->phaseIndex !== null) {
                $lastIndex = $segment->phaseIndex;
            }
        }

        if ($lastIndex === null) {
            return null;
        }

        return $this->holdForwardTotal($data->phases[$lastIndex], $data->phases, $metering, $profile, $spot, $isSpot, $reset, $monthKeys);
    }

    /**
     * Resolve a phase to per-bucket c/kWh rates plus monthly and flat fees, or null when a
     * spot phase lacks the spot averages needed to cost it.
     *
     * @return array{buckets: array<string, float>, monthly_fee: float, flat_once: float, spot_margin: ?float, uses_spot: bool, display: array<string, ?float>}|null
     */
    private function resolvePhaseRates(PricingPhase $phase, array $allPhases, MeteringType $metering, SpotAssumptions $spot, bool $isSpot = false): ?array
    {
        $energy = [];      // ComponentType value => amount
        $monthlyFeeCandidates = []; // monthly_fee amounts; ambiguous duplicates resolve to the higher
        $flatMonthly = 0.0;         // flat_fee (eur_per_month) package charges, additive on top of the base fee
        $flatOnce = 0.0;
        $spotMargin = null;

        foreach ($this->effectiveBilledComponents($phase, $allPhases) as $component) {
            $type = $component->type;
            $amount = (float) $component->amount;

            if ($type === ComponentType::MonthlyFee) {
                $monthlyFeeCandidates[] = $amount;

                continue;
            }

            if ($type === ComponentType::FlatFee) {
                if ($component->unit === ComponentUnit::EurFlat) {
                    $flatOnce += $amount;
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
                $existing = $energy[$type->value] ?? null;
                if ($existing === null || ((float) $existing === 0.0 && $amount !== 0.0)) {
                    $energy[$type->value] = $amount;
                }
            }
        }

        // Spot contract with the margin misclassified as a small fixed energy rate (e.g.
        // energy_day 0.33) and no explicit spot_margin: fold those sub-ceiling rates into the
        // spot margin so the spot base is applied. Values are equal per bucket in practice, so
        // the max is exact; if they ever differ it is the conservative (higher) choice. A rate
        // above the ceiling is a genuine all-in price and is left as fixed energy.
        if ($isSpot && $spotMargin === null && $energy !== []
            && max($energy) <= self::SPOT_MARGIN_CEILING_CENTS) {
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
                if ($rate === null && $monthlyFee <= 0 && $flatOnce <= 0) {
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
            'spot_margin' => $spotMargin,
            'uses_spot' => $usesSpot,
            'display' => $display,
        ];
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
     * @param list<PricingPhase> $allPhases
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
     * @param list<PricingPhase> $allPhases
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
     * @param list<PricingPhase> $phases
     */
    private function deriveMetering(array $phases): MeteringType
    {
        $hasTime = false;
        $hasSeason = false;

        foreach ($phases as $phase) {
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
            default => MeteringType::General,
        };
    }

    /**
     * @param list<WindowSegment> $segments
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
     * @param list<WindowSegment> $segments
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
     * @param list<WindowSegment> $segments
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
     * @param list<WindowSegment> $segments
     */
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
     * @param list<WindowSegment> $segments
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
        $months = $windowStart->diffInMonths($point);

        return max(0, min(11, (int) $months));
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
     * @param list<PricingPhase> $phases
     * @param array<int, array{start: CarbonImmutable, end: CarbonImmutable, rates: array<string, mixed>}> $spans
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
            ];
        }

        return $breakdown;
    }

    /**
     * @return list<string>
     */
    private function assumptions(ContractComparability $comparability, bool $usesSpot, bool $estimateFill, ?ResetEstimate $reset = null): array
    {
        $assumptions = [];
        if ($usesSpot) {
            $assumptions[] = 'spot_rolling_365_day_night_average';
        }
        if ($reset !== null && $reset->shiftsPrices()) {
            $assumptions[] = $reset->basis === ResetEstimateBasis::ForwardCurveShift
                ? 'reset_tail_shifted_on_forward_curve'
                : 'reset_tail_shifted_on_spot_seasonal_index';
        } elseif ($estimateFill && ! $usesSpot) {
            $assumptions[] = 'held_current_price_forward';
        }
        if ($comparability === ContractComparability::TermPriceOnly) {
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
            ResetEstimateBasis::SpotSeasonalIndex => EstimateMethod::RecurringSpotSeasonalIndex,
            ResetEstimateBasis::HoldFlat => null,
        };
    }

    /**
     * Build the shape-only forward-curve shift for an active market-reset product, or null when
     * the shift does not apply (flag off, not a reset, Spot, or no market shape available).
     *
     * The contractually known part of the window is never repriced. `heldForward = true` is the
     * Hybrid base-only path, which annualizes one phase across twelve calendar months instead of
     * costing segments.
     *
     * @param array<int, array<string, float>> $profile
     * @param list<WindowSegment> $segments
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
    ): ?ResetEstimate {
        if ($this->resetEstimator === null || ! $this->resetEstimator->enabled()) {
            return null;
        }

        if (! $data->recurringSchedule->isActiveReset()) {
            return null;
        }

        // Spot contracts keep their rolling-365 basis; moving them to a per-month vector is
        // separate deferred work with a much smaller payoff.
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

        if ($anchorPrice === null || $anchorPrice <= 0) {
            return null;
        }

        $tailStart = $this->resetTailStart($data, $segments, $windowStart);
        [$monthWeights, $tailMonthKeys] = $heldForward
            ? $this->heldForwardMonthWeights($profile, $windowStart, $tailStart)
            : $this->segmentMonthWeights($profile, $segments, $tailStart);

        if ($tailMonthKeys === []) {
            return null;
        }

        $estimate = $this->resetEstimator->estimate(new ResetEstimateRequest(
            cadence: $data->recurringSchedule->cadence,
            asOfDate: $windowStart,
            anchorPeriodMonth: $tailStart->subDay()->startOfMonth(),
            currentPeriodStart: $this->resetPeriodStart($data, $tailStart),
            tailMonthKeys: $tailMonthKeys,
            anchorEnergyPriceCentsPerKwh: $anchorPrice,
            monthWeights: $monthWeights,
        ));

        return $estimate->shiftsPrices() ? $estimate : null;
    }

    /**
     * The exclusive date at which the repriced reset tail begins. Everything before it is left
     * exactly as the contract discloses it.
     *
     * It is the latest of:
     *  - the end of the cadence period containing the window start (the current period is
     *    contractual, so at minimum that period stays exact);
     *  - the disclosed `current_period_end`, when the provider declares a non-calendar period;
     *  - the end of the latest window coverage that comes from a phase with a *dated* end.
     *
     * A phase whose end is `none` is an open-ended claim, not a credible reset-period boundary:
     * a product that resets quarterly does not have a known price for twelve months. Those are
     * exactly the lineages the hold-flat defect hides in.
     *
     * @param list<WindowSegment> $segments
     */
    private function resetTailStart(CanonicalContractData $data, array $segments, CarbonImmutable $windowStart): CarbonImmutable
    {
        $windowEnd = $windowStart->addYear();

        $candidate = $data->recurringSchedule->cadence === 'monthly'
            ? $windowStart->addMonthNoOverflow()->startOfMonth()
            : $windowStart->startOfMonth()->month(((int) floor(($windowStart->month - 1) / 3)) * 3 + 1)->addMonthsNoOverflow(3);

        $declaredEnd = $this->parseScheduleDate($data->recurringSchedule->currentPeriodEnd);
        if ($declaredEnd !== null && $declaredEnd->addDay()->greaterThan($candidate)) {
            $candidate = $declaredEnd->addDay();
        }

        foreach ($segments as $segment) {
            if ($segment->phaseIndex === null) {
                continue;
            }

            $ends = $data->phases[$segment->phaseIndex]->ends->kind;
            if ($ends === BoundaryKind::None || $ends === BoundaryKind::Unknown || $ends === BoundaryKind::ContractStart) {
                continue;
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
     * A declared date from an older period can never leak in through that check.
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
     * @param array<int, array<string, float>> $profile
     * @param list<WindowSegment> $segments
     * @return array{0: array<string, float>, 1: list<string>}
     */
    private function segmentMonthWeights(array $profile, array $segments, CarbonImmutable $tailStart): array
    {
        $weights = [];
        $tailKeys = [];

        foreach ($segments as $segment) {
            $key = $segment->start->format('Y-m');
            $weights[$key] = ($weights[$key] ?? 0.0)
                + array_sum($profile[$segment->monthIndex] ?? []) * $segment->monthFraction();

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
     * @param array<int, array<string, float>> $profile
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
     * @param array<string, mixed> $rates
     * @param array<int, array<string, float>> $profile
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
