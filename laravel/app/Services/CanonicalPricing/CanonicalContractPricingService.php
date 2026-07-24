<?php

namespace App\Services\CanonicalPricing;

use App\Models\ElectricityContract;
use App\Models\SpotPriceAverage;
use App\Services\CanonicalPricing\DTO\CanonicalPricingOutcome;
use App\Services\CanonicalPricing\DTO\ContractContext;
use App\Services\CanonicalPricing\DTO\ContractPricingIntegrity;
use App\Services\CanonicalPricing\DTO\SpotAssumptions;
use App\Services\CanonicalPricing\Enums\ContractComparability;
use App\Services\CanonicalPricing\Enums\EstimateMethod;
use App\Services\CanonicalPricing\Exceptions\CanonicalPricingParseException;
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
        private readonly CanonicalPricingParser $parser = new CanonicalPricingParser(),
        private readonly CanonicalContractPriceCalculator $calculator = new CanonicalContractPriceCalculator(),
        private readonly ContractPricingIntegrityService $integrityService = new ContractPricingIntegrityService(),
    ) {
    }

    public function enabled(): bool
    {
        return (bool) config('canonical_pricing.enabled', false);
    }

    /**
     * Array-only metrics for cache building and listings.
     *
     * @param Collection<int, ElectricityContract> $contracts
     * @return array<string, array{calculated_cost: array<string, mixed>, comparability: string, is_listed: bool, sort_key: float|null, integrity: array<string, mixed>}>
     */
    public function metricsForContracts(Collection $contracts, EnergyUsage $usage, ?CarbonInterface $startDate = null): array
    {
        $spot = $this->spotAssumptions();
        $metrics = [];

        foreach ($contracts as $contract) {
            $evaluation = $this->evaluate($contract, $usage, $spot, $startDate);
            $outcome = $evaluation['outcome'];

            $metrics[$contract->id] = [
                'calculated_cost' => $outcome->toCalculatedCostArray(),
                'comparability' => $outcome->comparability->value,
                'is_listed' => $outcome->isListed(),
                'sort_key' => $outcome->isListed() ? $outcome->totalCost : null,
                'integrity' => $evaluation['integrity']->toArray(),
            ];
        }

        return $metrics;
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

    private function excludedOutcome(ContractContext $context): CanonicalPricingOutcome
    {
        return new CanonicalPricingOutcome(
            comparability: ContractComparability::ExcludedIncomplete,
            estimateMethod: EstimateMethod::None,
            totalCost: null,
            monthlyCosts: array_fill(0, 12, 0.0),
            baseTotalCost: null,
            baseMonthlyCosts: array_fill(0, 12, 0.0),
            structuredOnlyTotal: null,
            isSpotContract: $context->isSpot(),
        );
    }
}
