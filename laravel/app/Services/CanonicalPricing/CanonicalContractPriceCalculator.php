<?php

namespace App\Services\CanonicalPricing;

use App\Enums\MeteringType;
use App\Services\CanonicalPricing\DTO\CanonicalComponent;
use App\Services\CanonicalPricing\DTO\CanonicalContractData;
use App\Services\CanonicalPricing\DTO\CanonicalPeriodPricingOutcome;
use App\Services\CanonicalPricing\DTO\CanonicalPeriodPricingRequest;
use App\Services\CanonicalPricing\DTO\CanonicalPricingOutcome;
use App\Services\CanonicalPricing\DTO\ContractContext;
use App\Services\CanonicalPricing\DTO\IncludedEnergyPackageData;
use App\Services\CanonicalPricing\DTO\OfferComponentData;
use App\Services\CanonicalPricing\DTO\OfferTermData;
use App\Services\CanonicalPricing\DTO\PricingPhase;
use App\Services\CanonicalPricing\DTO\SpotAssumptions;
use App\Services\CanonicalPricing\DTO\WindowSegment;
use App\Services\CanonicalPricing\Enums\BoundaryKind;
use App\Services\CanonicalPricing\Enums\CalculationStatus;
use App\Services\CanonicalPricing\Enums\ComponentType;
use App\Services\CanonicalPricing\Enums\ComponentUnit;
use App\Services\CanonicalPricing\Enums\ContractComparability;
use App\Services\CanonicalPricing\Enums\EstimateMethod;
use App\Services\CanonicalPricing\Enums\PeriodPricingUnavailableReason;
use App\Services\CanonicalPricing\Enums\PhaseKind;
use App\Services\CanonicalPricing\MarketReset\DTO\ResetEstimate;
use App\Services\CanonicalPricing\MarketReset\DTO\ResetEstimateRequest;
use App\Services\CanonicalPricing\MarketReset\Enums\ResetEstimateBasis;
use App\Services\CanonicalPricing\MarketReset\MarketResetPriceEstimator;
use App\Services\CanonicalPricing\SupplierAdjusted\DTO\PriceEpisodeAnchor;
use App\Services\CanonicalPricing\SupplierAdjusted\DTO\SupplierAdjustedCandidate;
use App\Services\CanonicalPricing\SupplierAdjusted\DTO\SupplierAdjustedEstimate;
use App\Services\CanonicalPricing\SupplierAdjusted\DTO\SupplierAdjustedEstimateRequest;
use App\Services\CanonicalPricing\SupplierAdjusted\Enums\SupplierAdjustedEstimateBasis;
use App\Services\CanonicalPricing\SupplierAdjusted\SupplierAdjustedEligibility;
use App\Services\CanonicalPricing\SupplierAdjusted\SupplierAdjustedPriceEstimator;
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
    ) {}

    public function resetForwardShiftEnabled(): bool
    {
        return $this->resetEstimator->enabled();
    }

    public function supplierAdjustedCandidate(
        string $contractId,
        CanonicalContractData $data,
        ContractContext $context,
    ): ?SupplierAdjustedCandidate {
        return $this->supplierAdjustedEligibility->candidate($contractId, $data, $context);
    }

    public function calculate(
        CanonicalContractData $data,
        ContractContext $context,
        EnergyUsage $usage,
        SpotAssumptions $spot,
        ?CarbonInterface $startDate = null,
        ?PriceEpisodeAnchor $priceEpisodeAnchor = null,
    ): CanonicalPricingOutcome {
        $windowStart = CarbonImmutable::parse(($startDate ?? CarbonImmutable::now('Europe/Helsinki'))->toDateString(), 'Europe/Helsinki')->startOfDay();

        $metering = $this->deriveMetering($data->phases, $context->metering);
        $profile = $this->usageProfileBuilder->build($metering, $usage, isSpotContract: false);

        $segments = $this->timelineBuilder->build($data->phases, $data->recurringSchedule, $windowStart);
        $currentPhaseIndex = $this->resolveCurrentPhaseIndex($segments, $data, $windowStart);
        $hasUncovered = $this->hasUncovered($segments);
        $fullyCovered = ! $hasUncovered;

        // 1. Hybrid / unsupported: cost every disclosed base-price phase when the full
        //    comparison window is covered. The unknown consumption effect stays excluded.
        if ($data->calculationStatus === CalculationStatus::Unsupported) {
            // A short Hybrid still has a real finite contract term. Cost only that term,
            // preserve its unannualized offer benefit, and annualize the same base-only
            // result for comparison. Handling Unsupported first must not erase Fixed6.
            if ($this->isFixedTermTermOnly($context, $segments, $windowStart)) {
                $termMonths = $context->fixedTermMonths();
                $termSegments = $this->segmentsUntil($segments, $windowStart->addMonths($termMonths));
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
                    heldForward: $termHasUncovered,
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
                );
            }

            $reset = $this->resolveResetEstimate(
                $data,
                $context,
                $metering,
                $profile,
                $spot,
                $windowStart,
                $segments,
                $currentPhaseIndex,
                heldForward: ! $fullyCovered,
            );

            if ($fullyCovered) {
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
                    false,
                    $reset,
                    EstimateMethod::HybridBaseOnly,
                );
            }

            return $this->costHeldForward(
                $data,
                $context,
                $metering,
                $profile,
                $spot,
                $windowStart,
                $segments,
                $currentPhaseIndex,
                ContractComparability::BaseOnlyHybrid,
                EstimateMethod::HybridBaseOnly,
                reset: $reset,
            );
        }

        // 2. Structural fixed-term with an unknown continuation tail: cost every disclosed
        //    phase inside the real term, then annualize the complete term result.
        if (! $fullyCovered && $this->isFixedTermTermOnly($context, $segments, $windowStart)) {
            $termMonths = $context->fixedTermMonths();

            return $this->costWindow(
                $data,
                $context,
                $metering,
                $profile,
                $spot,
                $windowStart,
                $this->segmentsUntil($segments, $windowStart->addMonths($termMonths)),
                $currentPhaseIndex,
                ContractComparability::TermPriceOnly,
                false,
                null,
                EstimateMethod::TermPriceAnnualized,
                12 / $termMonths,
                $termMonths,
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
        //    UNLESS it is a legitimate recurring market product (monthly/quarterly/seasonal/other reset).
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

        $supplierCandidate = $this->supplierAdjustedCandidate('', $data, $context);
        $supplierAdjusted = $supplierCandidate !== null
            ? $this->resolveSupplierAdjustedEstimate(
                $supplierCandidate,
                $profile,
                $windowStart,
                $segments,
                $priceEpisodeAnchor ?? PriceEpisodeAnchor::missing(),
            )
            : null;
        $comparability = $supplierAdjusted !== null
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
            $this->resolveResetEstimate($data, $context, $metering, $profile, $spot, $windowStart, $segments, $currentPhaseIndex, heldForward: false),
            supplierAdjusted: $supplierAdjusted,
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

        if ($periodEnd->lessThanOrEqualTo($windowStart) || $periodEnd->greaterThan($windowStart->addYear())) {
            return $this->unavailablePeriod($annualOutcome->comparability, PeriodPricingUnavailableReason::NoPricing);
        }

        $metering = $this->deriveMetering($data->phases, $context->metering);
        $annualUsage = new EnergyUsage(total: $request->annualizedKwh, basicLiving: $request->annualizedKwh);
        $annualProfile = $this->usageProfileBuilder->build($metering, $annualUsage, isSpotContract: false);
        $annualSegments = $this->timelineBuilder->build($data->phases, $data->recurringSchedule, $windowStart);
        $periodSegments = $this->segmentsUntil($annualSegments, $periodEnd);
        $currentPhaseIndex = $this->resolveCurrentPhaseIndex($annualSegments, $data, $windowStart);
        $lastCoveredPhaseIndex = $this->lastCoveredPhaseIndex($annualSegments);
        $hasUncovered = $this->hasUncovered($annualSegments);
        $fillPhaseIndex = $annualOutcome->comparability === ContractComparability::BaseOnlyHybrid && $hasUncovered
            ? $currentPhaseIndex
            : ($lastCoveredPhaseIndex ?? $currentPhaseIndex);
        $canFill = $data->recurringSchedule->isActiveReset()
            || $context->isSpot()
            || $annualOutcome->comparability === ContractComparability::BaseOnlyHybrid;

        $periodSpot = new SpotAssumptions(0.0, 0.0);
        $resolved = [];
        $usesSpot = false;

        foreach ($periodSegments as $segment) {
            $phaseIndex = $segment->phaseIndex;
            if ($phaseIndex === null && $canFill) {
                $phaseIndex = $fillPhaseIndex;
            }

            if ($phaseIndex === null) {
                continue;
            }

            $rates = $this->resolvePhaseRates($data->phases[$phaseIndex], $data->phases, $metering, $periodSpot, $context->isSpot());
            if ($rates === null) {
                continue;
            }

            $usesSpot = $usesSpot || $rates['uses_spot'];
            $resolved[] = ['segment' => $segment, 'phase_index' => $phaseIndex, 'rates' => $rates];
        }

        $spotMap = [];
        foreach ($request->historicalSpotPrices as $price) {
            $spotMap[$price->startsAtUtc->utc()->getTimestamp()] = $price->centsPerKwhWithTax;
        }

        $hasComponentDiscount = $this->hasNormalPriceDiscount($data);
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

        $reset = $this->resolveResetEstimate(
            $data,
            $context,
            $metering,
            $annualProfile,
            $annualSpot,
            $windowStart,
            $annualSegments,
            $currentPhaseIndex,
            heldForward: $annualOutcome->comparability === ContractComparability::BaseOnlyHybrid && $hasUncovered,
        );

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
            ];
        }

        $saving = max(0.0, $normalTotal - $actualTotal);
        $factualAnnualAssumptions = array_values(array_filter(
            $annualOutcome->assumptions,
            static fn (string $assumption): bool => ! str_starts_with($assumption, 'supplier_adjusted_'),
        ));
        $assumptions = array_values(array_unique(array_merge($factualAnnualAssumptions, [
            'contract_offer_available_at_period_start',
            'relative_phases_anchor_at_period_start',
            'absolute_phase_dates_preserved',
            'period_consumption_flat_by_actual_hour',
            'monthly_fee_prorated_by_days_over_30',
        ], $usesSpot ? ['actual_hourly_spot_prices'] : [], $completedSpot['filled'] ? [
            'missing_spot_hours_filled_with_observed_average',
        ] : [], $hasPackage ? [
            'package_allowance_resets_each_calendar_month',
            'partial_package_fee_and_allowance_prorated_by_calendar_month_fraction',
        ] : [])));

        return new CanonicalPeriodPricingOutcome(
            periodTotal: $actualTotal,
            normalPeriodTotal: $normalTotal,
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
    ): CanonicalPricingOutcome {
        $usesSpot = false;
        $monthly = array_fill(0, 12, 0.0);
        $normalMonthly = array_fill(0, 12, 0.0);
        $flatApplied = [];
        $normalFlatApplied = [];
        $spans = [];
        $monthKeys = $this->windowMonthKeys($windowStart);
        $hasComponentDiscount = $this->hasNormalPriceDiscount($data);

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

            $monthIndex = $this->elapsedMonth($windowStart, $segment->start);
            $monthly[$monthIndex] += $this->costSegment($segment, $profile, $rates, $flatApplied, $phaseIndex, $reset, $supplierAdjusted);

            if ($hasComponentDiscount) {
                $normalRates = $this->resolvePhaseRates($data->phases[$phaseIndex], $data->phases, $metering, $spot, $context->isSpot(), normalPrice: true);
                if ($normalRates === null) {
                    return $this->excluded(ContractComparability::ExcludedIncomplete, $context, $data);
                }

                $normalMonthly[$monthIndex] += $this->costSegment($segment, $profile, $normalRates, $normalFlatApplied, $phaseIndex, $reset, $supplierAdjusted);
            }
        }

        $currentRates = $currentPhaseIndex !== null
            ? $this->resolvePhaseRates($data->phases[$currentPhaseIndex], $data->phases, $metering, $spot, $context->isSpot())
            : null;

        // structuredOnly and base carry the same reset shift as the total, so the difference
        // between them keeps measuring only the promotional effect (which is what the integrity
        // label's euro impact reports) instead of mixing in the seasonal repricing.
        $structuredOnly = $currentPhaseIndex !== null
            ? $this->holdForwardTotal($data->phases[$currentPhaseIndex], $data->phases, $metering, $profile, $spot, $context->isSpot(), $reset, $monthKeys, supplierAdjusted: $supplierAdjusted)
            : null;

        if (! $hasComponentDiscount) {
            // Package allowances are contract pricing, not a promotion. Even if package terms
            // change between disclosed phases, the normal-price pass must not replace the
            // timeline with the last package and call the difference an offer saving.
            $normalMonthly = $this->hasEnergyPackage($data)
                ? $monthly
                : $this->normalHoldMonthlyCosts($data, $context, $metering, $profile, $spot, $segments, $windowStart, $reset, $supplierAdjusted);
            if ($normalMonthly === null) {
                $normalMonthly = $monthly;
            }
        }

        $contractTermTotal = null;
        $contractTermBaseTotal = null;
        $contractTermDiscountSavings = null;

        // Preserve the complete real-term values before applying the comparison factor.
        // Only a fully covered finite term gets this payload; other annualized or excluded
        // outcomes must not imply that an actual contract-period cost is known.
        if ($termMonths !== null
            && (! $this->hasUncovered($segments) || $estimateFill)) {
            $termTotal = array_sum($monthly);
            $termBaseTotal = array_sum($normalMonthly);

            if (is_finite($termTotal) && is_finite($termBaseTotal)) {
                $contractTermTotal = $termTotal;
                $contractTermBaseTotal = $termBaseTotal;
                $contractTermDiscountSavings = $termBaseTotal - $termTotal;
            }
        }

        if ($annualizationFactor !== 1.0) {
            $monthly = array_map(static fn (float $cost): float => $cost * $annualizationFactor, $monthly);
            $normalMonthly = array_map(static fn (float $cost): float => $cost * $annualizationFactor, $normalMonthly);
        }

        $total = array_sum($monthly);
        $base = array_sum($normalMonthly);
        $monthlySavings = $this->monthlySavings($monthly, $normalMonthly);
        $discountSavings = array_sum($monthlySavings);

        return new CanonicalPricingOutcome(
            comparability: $comparability,
            estimateMethod: $usesSpot
                ? EstimateMethod::Rolling365Spot
                : ($this->resetEstimateMethod($reset)
                    ?? $this->supplierAdjustedEstimateMethod($supplierAdjusted)
                    ?? ($estimateFill && $defaultEstimateMethod !== EstimateMethod::HybridBaseOnly
                        ? EstimateMethod::HoldCurrentRecurringPrice
                        : $defaultEstimateMethod)),
            totalCost: $total,
            monthlyCosts: $monthly,
            baseTotalCost: $base,
            baseMonthlyCosts: $normalMonthly,
            measuredDiscountSavingsTotal: $discountSavings,
            monthlyDiscountSavings: $monthlySavings,
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
            termMonths: $termMonths,
            energyPackage: $currentRates['package'] ?? null,
            contractTermTotalCost: $contractTermTotal,
            contractTermBaseTotalCost: $contractTermBaseTotal,
            contractTermDiscountSavingsTotal: $contractTermDiscountSavings,
            phaseBreakdown: $this->buildBreakdown($data->phases, $spans),
            offerTerms: $this->buildOfferTerms($data, $spans, $windowStart),
            consumptionEffect: $comparability === ContractComparability::BaseOnlyHybrid && $data->consumptionEffect->present
                ? $data->consumptionEffect
                : null,
            assumptions: $this->assumptions(
                $comparability,
                $usesSpot,
                $estimateFill,
                $reset,
                termAnnualized: $termMonths !== null && $annualizationFactor !== 1.0,
                supplierAdjusted: $supplierAdjusted,
            ),
            resetEstimate: $reset?->shiftsPrices() ? $reset->toArray() : null,
            supplierAdjustedEstimate: $supplierAdjusted?->toArray(),
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

        $base = $this->hasNormalPriceDiscount($data)
            ? $this->holdForwardTotal($data->phases[$currentPhaseIndex], $data->phases, $metering, $profile, $spot, $context->isSpot(), $reset, $monthKeys, normalPrice: true)
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
            monthlyFixedFee: $rates['monthly_fee'],
            spotPriceMargin: $rates['spot_margin'],
            generalKwhPrice: $rates['display']['general'] ?? null,
            daytimeKwhPrice: $rates['display']['day'] ?? null,
            nighttimeKwhPrice: $rates['display']['night'] ?? null,
            seasonalWinterDayKwhPrice: $rates['display']['seasonal_winter'] ?? null,
            seasonalOtherKwhPrice: $rates['display']['seasonal_other'] ?? null,
            spotPriceDayAvg: $rates['uses_spot'] ? $spot->dayAvgWithTax : null,
            spotPriceNightAvg: $rates['uses_spot'] ? $spot->nightAvgWithTax : null,
            energyPackage: $rates['package'] ?? null,
            phaseBreakdown: [],
            offerTerms: $this->buildOfferTerms($data, $offerSpans, $windowStart),
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
            measuredDiscountSavingsTotal: 0.0,
            monthlyDiscountSavings: array_fill(0, 12, 0.0),
            structuredOnlyTotal: null,
            isSpotContract: $context->isSpot(),
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

        if ($rates['flat_once'] > 0 && ! isset($flatApplied[$phaseIndex])) {
            $cost += $rates['flat_once'];
            $flatApplied[$phaseIndex] = true;
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
    ): float {
        $fraction = $segment->monthFraction();
        $monthBuckets = $profile[$segment->monthIndex] ?? [];
        $monthKey = $segment->start->format('Y-m');
        $offset = ($reset?->offsetForMonthKey($monthKey) ?? 0.0)
            + ($supplierAdjusted?->offsetForMonthKey($monthKey) ?? 0.0);

        $package = $rates['package'] ?? null;
        if ($package instanceof IncludedEnergyPackageData) {
            // Profile buckets are mutually exclusive. Sum them before applying one shared
            // monthly allowance, so day/night or seasonal profiles cannot receive the
            // allowance once per bucket. The fee and allowance are pro-rated together for a
            // partial calendar month, as the package source terms require.
            $monthlyUsage = array_sum($monthBuckets) * $fraction;
            $includedKwh = $package->includedKwh * $fraction;
            $excessKwh = max(0.0, $monthlyUsage - $includedKwh);

            return ($package->monthlyFeeEur * $fraction)
                + ($excessKwh * $package->excessRateCentsPerKwh / 100);
        }

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
     * @param  array<int, array<string, float>>  $profile
     * @param  array<int, string>  $monthKeys  calendar-month index (0-11) => the `Y-m` that month
     *                                         occupies inside this window
     */
    private function holdForwardTotal(PricingPhase $phase, array $allPhases, MeteringType $metering, array $profile, SpotAssumptions $spot, bool $isSpot = false, ?ResetEstimate $reset = null, array $monthKeys = [], bool $normalPrice = false, ?SupplierAdjustedEstimate $supplierAdjusted = null): ?float
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
            $offset = $monthKey !== null
                ? ($reset?->offsetForMonthKey($monthKey) ?? 0.0)
                    + ($supplierAdjusted?->offsetForMonthKey($monthKey) ?? 0.0)
                : 0.0;

            foreach ($rates['buckets'] as $bucket => $rate) {
                $total += (($monthBuckets[$bucket] ?? 0.0) * max(0.0, $rate + $offset)) / 100;
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
            $monthIndex = $this->elapsedMonth($windowStart, $segment->start);
            $monthly[$monthIndex] += $this->costSegment($segment, $profile, $rates, $flatApplied, $lastIndex, $reset, $supplierAdjusted);
        }

        return $monthly;
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
    private function buildOfferTerms(CanonicalContractData $data, array $spans, CarbonImmutable $windowStart): array
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
            $components = $this->offerComponents($phase, $data->phases, $fallback);
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
    private function offerComponents(PricingPhase $phase, array $allPhases, ?PricingPhase $normalPhase): ?array
    {
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
            && $span['end']->equalTo($windowStart->addYear())) {
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
            if ($windowStart->addMonths($months)->equalTo($point)) {
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
        $spotMargin = null;

        foreach ($this->effectiveBilledComponents($phase, $allPhases) as $component) {
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
            'package' => null,
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
        $months = $windowStart->diffInMonths($point);

        return max(0, min(11, (int) $months));
    }

    /**
     * Keep only the timeline slices inside a real fixed term.
     *
     * @param  list<WindowSegment>  $segments
     * @return list<WindowSegment>
     */
    private function segmentsUntil(array $segments, CarbonImmutable $end): array
    {
        $inside = [];

        foreach ($segments as $segment) {
            if ($segment->start->greaterThanOrEqualTo($end)) {
                break;
            }

            $inside[] = $segment->end->greaterThan($end)
                ? new WindowSegment($segment->start, $end, $segment->monthIndex, $segment->phaseIndex)
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
    ): array {
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
        if ($supplierAdjusted !== null) {
            $assumptions[] = match ($supplierAdjusted->basis) {
                SupplierAdjustedEstimateBasis::ForwardCurveShift => 'supplier_adjusted_tail_shifted_on_forward_curve',
                SupplierAdjustedEstimateBasis::SpotSeasonalIndex => 'supplier_adjusted_tail_shifted_on_spot_seasonal_index',
                SupplierAdjustedEstimateBasis::HoldFlat => 'supplier_adjusted_tail_held_current',
            };
            $assumptions[] = 'supplier_adjusted_monthly_fee_held_flat';
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
            ResetEstimateBasis::SpotSeasonalIndex => EstimateMethod::RecurringSpotSeasonalIndex,
            ResetEstimateBasis::HoldFlat => null,
        };
    }

    private function supplierAdjustedEstimateMethod(?SupplierAdjustedEstimate $estimate): ?EstimateMethod
    {
        return match ($estimate?->basis) {
            SupplierAdjustedEstimateBasis::ForwardCurveShift => EstimateMethod::SupplierAdjustedForwardCurveShift,
            SupplierAdjustedEstimateBasis::SpotSeasonalIndex => EstimateMethod::SupplierAdjustedSpotSeasonalIndex,
            SupplierAdjustedEstimateBasis::HoldFlat => EstimateMethod::HoldCurrentSupplierPrice,
            null => null,
        };
    }

    /**
     * @param array<int, array<string, float>> $profile
     * @param list<WindowSegment> $segments
     */
    private function resolveSupplierAdjustedEstimate(
        SupplierAdjustedCandidate $candidate,
        array $profile,
        CarbonImmutable $windowStart,
        array $segments,
        PriceEpisodeAnchor $anchor,
    ): SupplierAdjustedEstimate {
        $tailStart = $windowStart->addMonthNoOverflow()->startOfMonth();
        [$monthWeights, $tailMonthKeys] = $this->segmentMonthWeights($profile, $segments, $tailStart);

        return $this->supplierAdjustedEstimator->estimate(new SupplierAdjustedEstimateRequest(
            asOfDate: $windowStart,
            priceEpisodeAnchor: $anchor,
            tailMonthKeys: $tailMonthKeys,
            currentEnergyPriceCentsPerKwh: $candidate->currentEnergyPriceCentsPerKwh,
            monthlyFeeEur: $candidate->monthlyFeeEur,
            monthWeights: $monthWeights,
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
    ): ?ResetEstimate {
        if (! $this->resetEstimator->enabled()) {
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
     * a product that resets quarterly does not have a known price for twelve months. Cadence
     * `other` uses the same quarterly calendar proxy because its exact boundaries are unknown.
     * Those are exactly the lineages the hold-flat defect hides in.
     *
     * @param  list<WindowSegment>  $segments
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
