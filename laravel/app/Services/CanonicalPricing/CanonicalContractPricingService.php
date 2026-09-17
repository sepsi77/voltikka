<?php

namespace App\Services\CanonicalPricing;

use App\Models\ElectricityContract;
use App\Models\SpotPriceAverage;
use App\Services\CanonicalPricing\DTO\CanonicalContractData;
use App\Services\CanonicalPricing\DTO\CanonicalPeriodPricingOutcome;
use App\Services\CanonicalPricing\DTO\CanonicalPeriodPricingRequest;
use App\Services\CanonicalPricing\DTO\CanonicalPricingOutcome;
use App\Services\CanonicalPricing\DTO\ContractContext;
use App\Services\CanonicalPricing\DTO\ContractPricingIntegrity;
use App\Services\CanonicalPricing\DTO\EnergyRulePlan;
use App\Services\CanonicalPricing\DTO\SpotAssumptions;
use App\Services\CanonicalPricing\Enums\ContractComparability;
use App\Services\CanonicalPricing\Enums\EstimateMethod;
use App\Services\CanonicalPricing\Enums\PeriodPricingUnavailableReason;
use App\Services\CanonicalPricing\Exceptions\CanonicalPricingParseException;
use App\Services\CanonicalPricing\ForwardPremium\CurrentPremiumEvidenceLoader;
use App\Services\CanonicalPricing\ForwardPremium\PremiumEstimate;
use App\Services\CanonicalPricing\MarketReset\DTO\ResetEstimatorSettings;
use App\Services\CanonicalPricing\MarketReset\EexMarketReferenceCurveProvider;
use App\Services\CanonicalPricing\MarketReset\MarketReferenceCurveProvider;
use App\Services\CanonicalPricing\SpotForward\DTO\SpotEstimate;
use App\Services\CanonicalPricing\SpotForward\SpotForwardPriceEstimator;
use App\Services\CanonicalPricing\SupplierAdjusted\CurrentNormalCandidateExtractor;
use App\Services\CanonicalPricing\SupplierAdjusted\CurrentPriceEpisodeResolver;
use App\Services\CanonicalPricing\SupplierAdjusted\DTO\PriceEpisodeAnchor;
use App\Services\ContractPricing\CanonicalContractMetric;
use App\Services\DTO\EnergyUsage;
use Carbon\CarbonImmutable;
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

    /** @var array<string, array{signature: string, anchor: PriceEpisodeAnchor}> */
    private array $priceEpisodeAnchors = [];

    /** @var array<string, SpotEstimate> */
    private array $spotEstimates = [];

    private ?CurrentPremiumEvidenceLoader $premiumEvidence = null;

    public function __construct(
        private readonly CanonicalContractPriceCalculator $calculator,
        private readonly PricingMode $mode,
        private readonly CanonicalPricingParser $parser = new CanonicalPricingParser,
        private readonly ContractPricingIntegrityService $integrityService = new ContractPricingIntegrityService,
        private readonly CurrentPriceEpisodeResolver $priceEpisodeResolver = new CurrentPriceEpisodeResolver,
        private readonly ?SpotForwardPriceEstimator $spotEstimator = null,
        private readonly ?MarketReferenceCurveProvider $marketReference = null,
    ) {
        if ($marketReference !== null) {
            $this->premiumEvidence = new CurrentPremiumEvidenceLoader(
                $calculator, $marketReference,
                ResetEstimatorSettings::fromConfig(false),
                (float) config('price_forecasting.fixed_term.vat_multiplier', 1.255),
            );
        }
        if ($calculator->resetForwardShiftEnabled() !== $mode->resetForwardShiftEnabled()) {
            throw new \InvalidArgumentException('PricingMode and the reset estimator must use the same reset-shift state.');
        }
    }

    public function resetMemoization(): void
    {
        $this->spotAssumptions = null;
        $this->priceEpisodeAnchors = [];
        $this->spotEstimates = [];
        $this->premiumEvidence?->resetMemoization();
        if ($this->marketReference instanceof EexMarketReferenceCurveProvider) {
            $this->marketReference->resetMemoization();
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
        [$parsed, $anchors, $premiums, $resetPremiums] = $this->parseAndResolveAnchors($contracts, $startDate);
        $spotEstimate = $this->spotEstimateForParsed($parsed, $spot, $startDate);
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
                    $spotEstimate,
                    premium: $premiums[$contractId] ?? null,
                    resetPremium: $resetPremiums[$contractId] ?? null,
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
        [$parsed, $anchors, $premiums, $resetPremiums] = $this->parseAndResolveAnchors($contracts, $startDate);
        $spotEstimate = $this->spotEstimateForParsed($parsed, $spot, $startDate);

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
                        $spotEstimate,
                        premium: $premiums[$contractId] ?? null,
                        resetPremium: $resetPremiums[$contractId] ?? null,
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
        [$parsed, $anchors, $premiums, $resetPremiums] = $this->parseAndResolveAnchors($contracts, $request->startDate, CarbonImmutable::now('Europe/Helsinki'));
        $spotEstimate = $this->spotEstimateForParsed($parsed, $spot, $request->startDate);

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
                $spotEstimate,
                premium: $premiums[$contractId] ?? null,
                resetPremium: $resetPremiums[$contractId] ?? null,
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
        [$parsed, $anchors, $premiums, $resetPremiums] = $this->parseAndResolveAnchors(collect([$contract]), $startDate);
        $context = $parsed[(string) $contract->id]['context'];
        $data = $parsed[(string) $contract->id]['data'];
        if ($data === null) {
            return [
                'outcome' => $this->excludedOutcome($context),
                'integrity' => ContractPricingIntegrity::none(),
            ];
        }
        $spotEstimate = $this->calculator->usesSpotPricing($data, $context, $startDate)
            ? $this->resolveSpotEstimate($spot, $startDate)
            : null;
        $outcome = $this->calculator->calculate(
            $data,
            $context,
            $usage,
            $spot,
            $startDate,
            $anchors[(string) $contract->id] ?? null,
            $spotEstimate,
            premium: $premiums[(string) $contract->id] ?? null,
            resetPremium: $resetPremiums[(string) $contract->id] ?? null,
        );
        $integrity = $this->integrityService->assess($data, $outcome, $context);

        return ['outcome' => $outcome, 'integrity' => $integrity];
    }

    /**
     * Parse all contracts first, resolve supplier episodes, and prepare both premium families
     * in one request-local evidence flow before consumption-dependent costing.
     *
     * @param  Collection<int, ElectricityContract>  $contracts
     * @return array{0: array<string, array{data: CanonicalContractData|null, context: ContractContext}>, 1: array<string, PriceEpisodeAnchor>, 2: array<string, PremiumEstimate|null>, 3: array<string, PremiumEstimate|null>}
     */
    private function parseAndResolveAnchors(Collection $contracts, ?CarbonInterface $startDate = null, ?CarbonInterface $publicationDate = null): array
    {
        $asOf = CarbonImmutable::parse(
            ($startDate ?? CarbonImmutable::now('Europe/Helsinki'))->toDateString(),
            'Europe/Helsinki',
        )->startOfDay();
        $contractsById = $contracts->keyBy('id');
        $signatures = [];
        $parsed = [];
        $candidates = [];
        $resetCandidates = [];
        // A hypothetical past signup is not the publication cutoff for today's offers.
        $sourceEvidence = (new CurrentSourcePromotionEvidence)->forContracts($contracts, $publicationDate ?? $asOf);

        foreach ($contracts as $contract) {
            $contractId = (string) $contract->id;
            $context = ContractContext::fromContract($contract);
            $requiresRules = $sourceEvidence[$contractId]['energy_rules_required']
                ?? CurrentSourcePromotionEvidence::requiresEnergyRuleProof($contract->canonical_pricing);
            if ((isset($sourceEvidence[$contractId]) && ! $sourceEvidence[$contractId]['valid'])
                || ($requiresRules && ! ($sourceEvidence[$contractId]['energy_rules_valid'] ?? false))) {
                $parsed[$contractId] = ['data' => null, 'context' => $context];

                continue;
            }
            try {
                $data = $this->parser->parse(
                    $contract->canonical_pricing,
                    $contract->canonical_calculation,
                    $contract->canonical_source_consistency,
                    withEnergyRules: $sourceEvidence[$contractId]['energy_rules_valid'] ?? false,
                );
            } catch (CanonicalPricingParseException $e) {
                Log::warning('Canonical pricing parse failed', ['contract_id' => $contractId, 'error' => $e->getMessage()]);
                $parsed[$contractId] = ['data' => null, 'context' => $context];

                continue;
            }

            $data = $data->withComparisonEvidence(sourceCampaignEnergyRates: $sourceEvidence[$contractId]['rates'] ?? []);
            $parsed[$contractId] = ['data' => $data, 'context' => $context];
            $normalTarget = $this->calculator->normalEnergyTargetData($data, $context, $asOf);
            $hasRules = EnergyRulePlan::hasKnownRules($data);
            $resetCandidate = $hasRules && $normalTarget === null ? null
                : $this->calculator->resetPremiumCandidate($contractId, $normalTarget ?? $data, $context, $asOf);
            if ($resetCandidate !== null) {
                $resetCandidates[$contractId] = $resetCandidate;
            }
            $candidate = $hasRules
                ? $this->calculator->normalEnergyCandidate($contractId, $data, $context, $asOf)
                : $this->calculator->supplierAdjustedCandidate($contractId, $data, $context, $asOf);
            if ($candidate !== null) {
                $candidates[$contractId] = $candidate;
            }
        }

        $anchors = [];
        $unresolved = [];
        foreach ($candidates as $contractId => $candidate) {
            $contract = $contractsById->get($contractId);
            $signatures[$contractId] = json_encode([
                $candidate->normalTariffEvidence,
                $candidate->normalizedEnergyRates(),
                $parsed[$contractId]['context'],
                $candidate->metering,
                $candidate->includesVat,
                $candidate->pricingMechanism,
                $asOf->toDateString(),
                $contract->current_source_observation_id,
                $contract->published_interpretation_id,
                $candidate->normalTariffEvidence ? $contract->canonical_pricing : null,
                $candidate->normalTariffEvidence ? $contract->canonical_calculation : null,
                $candidate->normalTariffEvidence ? $contract->canonical_source_consistency : null,
            ], JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
            $cached = $this->priceEpisodeAnchors[$contractId] ?? null;
            if ($cached !== null && $cached['signature'] === $signatures[$contractId]) {
                $anchors[$contractId] = $cached['anchor'];

                continue;
            }
            if ($candidate->normalTariffEvidence && (new CurrentNormalCandidateExtractor)->candidate(
                $contractId, $parsed[$contractId]['data'], $parsed[$contractId]['context'], $asOf,
            ) === null) {
                // A fixed normal span is a target, not a monthly own-reference observation.
                $anchors[$contractId] = PriceEpisodeAnchor::missing();

                continue;
            }
            $unresolved[$contractId] = $candidate;
        }

        foreach ($this->priceEpisodeResolver->resolve($unresolved, $asOf) as $contractId => $anchor) {
            $this->priceEpisodeAnchors[$contractId] = [
                'signature' => $signatures[$contractId],
                'anchor' => $anchor,
            ];
            $anchors[$contractId] = $anchor;
        }

        $premiums = $this->premiumEvidence?->forCandidates($candidates, $contracts, $anchors, $asOf, $resetCandidates) ?? [];

        return [$parsed, $anchors, array_intersect_key($premiums, $candidates), array_intersect_key($premiums, $resetCandidates)];
    }

    /**
     * @param  array<string, array{data: CanonicalContractData|null, context: ContractContext}>  $parsed
     */
    private function spotEstimateForParsed(array $parsed, SpotAssumptions $spot, ?CarbonInterface $startDate): ?SpotEstimate
    {
        foreach ($parsed as $record) {
            if ($record['data'] !== null && $this->calculator->usesSpotPricing($record['data'], $record['context'], $startDate)) {
                return $this->resolveSpotEstimate($spot, $startDate);
            }
        }

        return null;
    }

    private function resolveSpotEstimate(SpotAssumptions $spot, ?CarbonInterface $startDate): SpotEstimate
    {
        $windowStart = CarbonImmutable::parse(
            ($startDate ?? CarbonImmutable::now('Europe/Helsinki'))->toDateString(),
            'Europe/Helsinki',
        )->startOfDay();
        $key = implode('|', [
            $windowStart->toDateString(),
            $spot->overallAvgWithTax ?? 'null',
            $spot->dayAvgWithTax ?? 'null',
            $spot->nightAvgWithTax ?? 'null',
            $spot->periodStart?->toDateString() ?? 'null',
            $spot->periodEnd?->toDateString() ?? 'null',
            json_encode($spot->coverage()),
        ]);

        return $this->spotEstimates[$key] ??= ($this->spotEstimator ?? app(SpotForwardPriceEstimator::class))
            ->estimate($windowStart, $spot);
    }

    public function spotAssumptions(): SpotAssumptions
    {
        if ($this->spotAssumptions !== null) {
            return $this->spotAssumptions;
        }

        $avg = SpotPriceAverage::latestRolling365Days();

        $periodEnd = $avg?->period_end !== null
            ? CarbonImmutable::parse($avg->period_end->toDateString(), 'Europe/Helsinki')->startOfDay()
            : null;
        $isLocal = $avg?->period_type === SpotPriceAverage::PERIOD_ROLLING_365D_LOCAL;
        $periodStart = $isLocal ? $periodEnd?->subDays(364) : null;

        return $this->spotAssumptions = new SpotAssumptions(
            dayAvgWithTax: $avg?->day_avg_with_tax,
            nightAvgWithTax: $avg?->night_avg_with_tax,
            overallAvgWithTax: $avg?->avg_price_with_tax,
            periodStart: $periodStart,
            periodEnd: $periodEnd,
            actualHours: $avg?->hours_count,
            expectedHours: $periodStart !== null ? (int) $periodStart->utc()->diffInHours($periodEnd->addDay()->utc()) : null,
            windowSemantics: $avg === null ? 'missing' : ($isLocal ? 'helsinki_dates_v2' : 'legacy_utc_dates'),
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
