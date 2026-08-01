<?php

namespace App\Services\CanonicalPricing;

use App\Models\ElectricityContract;
use App\Models\SpotPriceAverage;
use App\Services\CanonicalPricing\DTO\CanonicalPeriodPricingOutcome;
use App\Services\CanonicalPricing\DTO\CanonicalPeriodPricingRequest;
use App\Services\CanonicalPricing\DTO\CanonicalPricingOutcome;
use App\Services\CanonicalPricing\DTO\ContractContext;
use App\Services\CanonicalPricing\DTO\ContractPricingIntegrity;
use App\Services\CanonicalPricing\DTO\SpotAssumptions;
use App\Services\CanonicalPricing\Enums\ContractComparability;
use App\Services\CanonicalPricing\Enums\EstimateMethod;
use App\Services\CanonicalPricing\Enums\PeriodPricingUnavailableReason;
use App\Services\CanonicalPricing\Exceptions\CanonicalPricingParseException;
use App\Services\ContractPricing\CanonicalContractMetric;
use App\Services\DTO\EnergyUsage;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Batch orchestrator and feature-flag gate for canonical phase-aware pricing.
 *
 * Loads spot averages once, parses each contract's canonical interpretation, costs it,
 * and assesses its pricing integrity. A parse failure fails closed: the contract is
 * excluded from comparison (never costed on data the calculator does not understand)
 * and logged once.
 */
class CanonicalContractPricingService
{
    private ?SpotAssumptions $spotAssumptions = null;

    public function __construct(
        private readonly CanonicalContractPriceCalculator $calculator,
        private readonly PricingMode $mode,
        private readonly CanonicalPricingParser $parser = new CanonicalPricingParser,
        private readonly ContractPricingIntegrityService $integrityService = new ContractPricingIntegrityService,
    ) {
        if ($calculator->resetForwardShiftEnabled() !== $mode->resetForwardShiftEnabled()) {
            throw new \InvalidArgumentException('PricingMode and the reset estimator must use the same reset-shift state.');
        }
    }

    public function enabled(): bool
    {
        return $this->mode->enabled();
    }

    /**
     * Whether market-reset contracts are annualized with the shape-only forward-curve shift.
     *
     * This is a second, independent flag: canonical pricing is already enabled in production,
     * so it cannot stage this change. Cache keys must vary by this value the same way they vary
     * by `enabled()`, otherwise stale hold-flat payloads survive the flip.
     */
    public function resetForwardShiftEnabled(): bool
    {
        return $this->mode->resetForwardShiftEnabled();
    }

    /**
     * Typed metrics for cache building and presentation consumers.
     *
     * @param  Collection<int, ElectricityContract>  $contracts
     * @return array<string, CanonicalContractMetric>
     */
    public function metricsForContracts(Collection $contracts, EnergyUsage $usage, ?CarbonInterface $startDate = null): array
    {
        $spot = $this->spotAssumptions();
        $metrics = [];

        foreach ($contracts as $contract) {
            $evaluation = $this->evaluate($contract, $usage, $spot, $startDate);
            $metrics[$contract->id] = CanonicalContractMetric::fromEvaluation(
                $evaluation['outcome'],
                $evaluation['integrity'],
            );
        }

        return $metrics;
    }

    /**
     * Parse each contract once and calculate the requested annual-consumption outcomes.
     * This is used by statistics collection, which needs three stored annual totals plus
     * one set of current typed rates without loading relational component history.
     *
     * @param  Collection<int, ElectricityContract>  $contracts
     * @param  list<int>  $consumptions
     * @return array<string, array<int, CanonicalPricingOutcome>>
     */
    public function outcomesForContractsAtConsumptions(
        Collection $contracts,
        array $consumptions,
        SpotAssumptions $spot,
        ?CarbonInterface $startDate = null,
    ): array {
        $outcomes = [];

        foreach ($contracts as $contract) {
            $context = ContractContext::fromContract($contract);

            try {
                $data = $this->parser->parse(
                    $contract->canonical_pricing,
                    $contract->canonical_calculation,
                    $contract->canonical_source_consistency,
                );
            } catch (CanonicalPricingParseException $e) {
                Log::warning('Canonical pricing parse failed', ['contract_id' => $contract->id, 'error' => $e->getMessage()]);

                foreach ($consumptions as $consumption) {
                    $outcomes[$contract->id][$consumption] = $this->excludedOutcome($context);
                }

                continue;
            }

            foreach ($consumptions as $consumption) {
                $outcomes[$contract->id][$consumption] = $this->calculator->calculate(
                    $data,
                    $context,
                    new EnergyUsage(total: $consumption, basicLiving: $consumption),
                    $spot,
                    $startDate,
                );
            }
        }

        return $outcomes;
    }

    /**
     * Batch annual and exact-period evaluations for bill comparison. Canonical JSON is
     * parsed once per contract. Annual Spot assumptions and period history are shared by
     * the whole batch, so this method does not issue per-contract queries.
     *
     * @param  Collection<int, ElectricityContract>  $contracts
     * @return array<string, array{annual: CanonicalPricingOutcome, period: CanonicalPeriodPricingOutcome}>
     */
    public function periodEvaluationsForContracts(
        Collection $contracts,
        CanonicalPeriodPricingRequest $request,
        ?SpotAssumptions $spot = null,
    ): array {
        $spot ??= $this->spotAssumptions();
        $evaluations = [];

        foreach ($contracts as $contract) {
            $context = ContractContext::fromContract($contract);

            try {
                $data = $this->parser->parse(
                    $contract->canonical_pricing,
                    $contract->canonical_calculation,
                    $contract->canonical_source_consistency,
                );
            } catch (CanonicalPricingParseException $e) {
                Log::warning('Canonical pricing parse failed', ['contract_id' => $contract->id, 'error' => $e->getMessage()]);

                $annual = $this->excludedOutcome($context);
                $evaluations[$contract->id] = [
                    'annual' => $annual,
                    'period' => $this->unavailablePeriodOutcome($annual, PeriodPricingUnavailableReason::NotComparable),
                ];

                continue;
            }

            $annual = $this->calculator->calculate(
                $data,
                $context,
                new EnergyUsage(total: $request->annualizedKwh, basicLiving: $request->annualizedKwh),
                $spot,
                $request->startDate,
            );

            $evaluations[$contract->id] = [
                'annual' => $annual,
                'period' => $this->calculator->calculatePeriod($data, $context, $request, $spot, $annual),
            ];
        }

        return $evaluations;
    }

    /**
     * Typed evaluation for a single contract (detail page, statistics, bill comparison).
     *
     * @return array{outcome: CanonicalPricingOutcome, integrity: ContractPricingIntegrity}
     */
    public function evaluate(ElectricityContract $contract, EnergyUsage $usage, ?SpotAssumptions $spot = null, ?CarbonInterface $startDate = null): array
    {
        $spot ??= $this->spotAssumptions();
        $context = ContractContext::fromContract($contract);

        try {
            $data = $this->parser->parse(
                $contract->canonical_pricing,
                $contract->canonical_calculation,
                $contract->canonical_source_consistency,
            );
        } catch (CanonicalPricingParseException $e) {
            Log::warning('Canonical pricing parse failed', ['contract_id' => $contract->id, 'error' => $e->getMessage()]);

            return [
                'outcome' => $this->excludedOutcome($context),
                'integrity' => ContractPricingIntegrity::none(),
            ];
        }

        $outcome = $this->calculator->calculate($data, $context, $usage, $spot, $startDate);
        $integrity = $this->integrityService->assess($data, $outcome, $context);

        return ['outcome' => $outcome, 'integrity' => $integrity];
    }

    public function spotAssumptions(): SpotAssumptions
    {
        if ($this->spotAssumptions !== null) {
            return $this->spotAssumptions;
        }

        $avg = SpotPriceAverage::latestRolling365Days();

        return $this->spotAssumptions = new SpotAssumptions(
            dayAvgWithTax: $avg?->day_avg_with_tax,
            nightAvgWithTax: $avg?->night_avg_with_tax,
        );
    }

    /**
     * Override the spot averages (tests, statistics for a historical date).
     */
    public function withSpotAssumptions(SpotAssumptions $spot): self
    {
        $this->spotAssumptions = $spot;

        return $this;
    }

    private function unavailablePeriodOutcome(
        CanonicalPricingOutcome $annual,
        PeriodPricingUnavailableReason $reason,
    ): CanonicalPeriodPricingOutcome {
        return new CanonicalPeriodPricingOutcome(
            periodTotal: null,
            normalPeriodTotal: null,
            measuredDiscountSavings: 0.0,
            comparability: $annual->comparability,
            unavailableReason: $reason,
            usesSpot: $annual->isSpotContract,
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

    private function excludedOutcome(ContractContext $context): CanonicalPricingOutcome
    {
        return new CanonicalPricingOutcome(
            comparability: ContractComparability::ExcludedIncomplete,
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
}
