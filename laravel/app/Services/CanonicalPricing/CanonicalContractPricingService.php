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
use App\Services\CanonicalPricing\SupplierAdjusted\CurrentPriceEpisodeResolver;
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

    /** @var array<string, array{energy: float, fee: float, anchor: \App\Services\CanonicalPricing\SupplierAdjusted\DTO\PriceEpisodeAnchor}> */
    private array $priceEpisodeAnchors = [];

    public function __construct(
        private readonly CanonicalContractPriceCalculator $calculator,
        private readonly PricingMode $mode,
        private readonly CanonicalPricingParser $parser = new CanonicalPricingParser,
        private readonly ContractPricingIntegrityService $integrityService = new ContractPricingIntegrityService,
        private readonly CurrentPriceEpisodeResolver $priceEpisodeResolver = new CurrentPriceEpisodeResolver,
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
        [$parsed, $anchors] = $this->parseAndResolveAnchors($contracts);
        $metrics = [];

        foreach ($parsed as $contractId => $record) {
            if ($record['data'] === null) {
                $outcome = $this->excludedOutcome($record['context']);
                $integrity = ContractPricingIntegrity::none();
            } else {
                $outcome = $this->calculator->calculate(
                    $record['data'],
                    $record['context'],
                    $usage,
                    $spot,
                    $startDate,
                    $anchors[$contractId] ?? null,
                );
                $integrity = $this->integrityService->assess($record['data'], $outcome, $record['context']);
            }

            $metrics[$contractId] = CanonicalContractMetric::fromEvaluation($outcome, $integrity);
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
        [$parsed, $anchors] = $this->parseAndResolveAnchors($contracts);

        foreach ($parsed as $contractId => $record) {
            foreach ($consumptions as $consumption) {
                $outcomes[$contractId][$consumption] = $record['data'] === null
                    ? $this->excludedOutcome($record['context'])
                    : $this->calculator->calculate(
                        $record['data'],
                        $record['context'],
                        new EnergyUsage(total: $consumption, basicLiving: $consumption),
                        $spot,
                        $startDate,
                        $anchors[$contractId] ?? null,
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
        [$parsed, $anchors] = $this->parseAndResolveAnchors($contracts);

        foreach ($parsed as $contractId => $record) {
            if ($record['data'] === null) {
                $annual = $this->excludedOutcome($record['context']);
                $evaluations[$contractId] = [
                    'annual' => $annual,
                    'period' => $this->unavailablePeriodOutcome($annual, PeriodPricingUnavailableReason::NotComparable),
                ];
                continue;
            }

            $annual = $this->calculator->calculate(
                $record['data'],
                $record['context'],
                new EnergyUsage(total: $request->annualizedKwh, basicLiving: $request->annualizedKwh),
                $spot,
                $request->startDate,
                $anchors[$contractId] ?? null,
            );

            $evaluations[$contractId] = [
                'annual' => $annual,
                'period' => $this->calculator->calculatePeriod($record['data'], $record['context'], $request, $spot, $annual),
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

        $candidate = $this->calculator->supplierAdjustedCandidate((string) $contract->id, $data, $context);
        $anchors = $candidate !== null
            ? $this->priceEpisodeResolver->resolve([(string) $contract->id => $candidate])
            : [];
        $outcome = $this->calculator->calculate(
            $data,
            $context,
            $usage,
            $spot,
            $startDate,
            $anchors[(string) $contract->id] ?? null,
        );
        $integrity = $this->integrityService->assess($data, $outcome, $context);

        return ['outcome' => $outcome, 'integrity' => $integrity];
    }

    /**
     * Parse all contracts first, identify supplier-adjusted candidates, then resolve their
     * episode anchors in one batch before any outcome is calculated.
     *
     * @param Collection<int, ElectricityContract> $contracts
     * @return array{0: array<string, array{data: \App\Services\CanonicalPricing\DTO\CanonicalContractData|null, context: ContractContext}>, 1: array<string, \App\Services\CanonicalPricing\SupplierAdjusted\DTO\PriceEpisodeAnchor>}
     */
    private function parseAndResolveAnchors(Collection $contracts): array
    {
        $parsed = [];
        $candidates = [];

        foreach ($contracts as $contract) {
            $contractId = (string) $contract->id;
            $context = ContractContext::fromContract($contract);
            try {
                $data = $this->parser->parse(
                    $contract->canonical_pricing,
                    $contract->canonical_calculation,
                    $contract->canonical_source_consistency,
                );
            } catch (CanonicalPricingParseException $e) {
                Log::warning('Canonical pricing parse failed', ['contract_id' => $contractId, 'error' => $e->getMessage()]);
                $parsed[$contractId] = ['data' => null, 'context' => $context];
                continue;
            }

            $parsed[$contractId] = ['data' => $data, 'context' => $context];
            $candidate = $this->calculator->supplierAdjustedCandidate($contractId, $data, $context);
            if ($candidate !== null) {
                $candidates[$contractId] = $candidate;
            }
        }

        $anchors = [];
        $unresolved = [];
        foreach ($candidates as $contractId => $candidate) {
            $cached = $this->priceEpisodeAnchors[$contractId] ?? null;
            if ($cached !== null
                && abs($cached['energy'] - $candidate->currentEnergyPriceCentsPerKwh) <= 0.0001
                && abs($cached['fee'] - $candidate->monthlyFeeEur) <= 0.0001) {
                $anchors[$contractId] = $cached['anchor'];
                continue;
            }
            $unresolved[$contractId] = $candidate;
        }

        foreach ($this->priceEpisodeResolver->resolve($unresolved) as $contractId => $anchor) {
            $candidate = $unresolved[$contractId];
            $this->priceEpisodeAnchors[$contractId] = [
                'energy' => $candidate->currentEnergyPriceCentsPerKwh,
                'fee' => $candidate->monthlyFeeEur,
                'anchor' => $anchor,
            ];
            $anchors[$contractId] = $anchor;
        }

        return [$parsed, $anchors];
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
