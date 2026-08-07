<?php

namespace App\Services\ContractStatistics;

use App\Models\ElectricityContract;
use App\Services\CanonicalPricing\DTO\CanonicalPricingOutcome;
use App\Services\CanonicalPricing\MarketReset\Enums\ResetEstimateBasis;
use App\Services\CanonicalPricing\SpotForward\Enums\SpotEstimateBasis;
use App\Services\CanonicalPricing\SupplierAdjusted\Enums\SupplierAdjustedEstimateBasis;
use App\Services\ContractStatistics\DTO\AsOfAnnualCostResult;
use App\Services\ContractStatistics\Enums\AnnualCostCalculationBasis;
use App\Services\ContractStatistics\Enums\AnnualCostMethodVersion;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use InvalidArgumentException;

/** Adapts already-calculated current canonical outcomes to versioned annual-cost rows. */
class CurrentCanonicalAnnualCostResultFactory
{
    private const CONSUMPTIONS = [2000, 5000, 18000];

    public function __construct(
        private readonly ContractStatisticsSegmentClassifier $segmentClassifier,
    ) {}

    /**
     * @param  array<string, array<int, CanonicalPricingOutcome>>  $outcomesByContract
     * @return list<AsOfAnnualCostResult>
     */
    public function create(CarbonInterface|string $date, array $outcomesByContract): array
    {
        $target = CarbonImmutable::parse(
            $date instanceof CarbonInterface ? $date->toDateString() : $date,
            'Europe/Helsinki',
        )->startOfDay();

        if ($outcomesByContract === []) {
            return [];
        }

        $contractIds = array_keys($outcomesByContract);
        $rows = ElectricityContract::query()
            ->from('electricity_contracts as contracts')
            ->leftJoin('contract_price_snapshots as snapshots', function ($join) use ($target): void {
                $join->on('snapshots.contract_id', '=', 'contracts.id')
                    ->whereDate('snapshots.snapshot_date', $target->toDateString())
                    ->where('snapshots.pricing_basis', ContractPriceBasis::CanonicalCalculation->value);
            })
            ->leftJoin('contract_source_observations as observations', function ($join): void {
                $join->on('observations.id', '=', 'contracts.current_source_observation_id')
                    ->on('observations.contract_id', '=', 'contracts.id');
            })
            ->leftJoin('contract_interpretations as interpretations', function ($join): void {
                $join->on('interpretations.id', '=', 'contracts.published_interpretation_id')
                    ->on('interpretations.contract_id', '=', 'contracts.id')
                    ->on('interpretations.source_snapshot_id', '=', 'observations.source_snapshot_id');
            })
            ->whereIn('contracts.id', $contractIds)
            ->orderBy('contracts.id')
            ->get([
                'contracts.*',
                'contracts.id as contract_id',
                'snapshots.id as price_snapshot_id',
                'snapshots.segment_key',
                'snapshots.annual_cost_2000_kwh',
                'snapshots.annual_cost_5000_kwh',
                'snapshots.annual_cost_18000_kwh',
                'observations.id as observation_id',
                'observations.source_snapshot_id',
                'interpretations.id as interpretation_id',
            ]);

        $resolvedContractIds = $rows->pluck('contract_id')->map(fn ($id): string => (string) $id)->all();
        $missingContractIds = array_values(array_diff($contractIds, $resolvedContractIds));
        if ($missingContractIds !== []) {
            throw new InvalidArgumentException(
                'Current annual pricing outcomes are missing canonical snapshot identities: '.implode(', ', $missingContractIds)
            );
        }

        $results = [];
        foreach ($rows as $row) {
            $contractId = (string) $row->contract_id;
            $outcomes = $outcomesByContract[$contractId] ?? [];

            foreach (self::CONSUMPTIONS as $consumption) {
                $outcome = $outcomes[$consumption] ?? null;
                if ($outcome !== null && ! $outcome instanceof CanonicalPricingOutcome) {
                    throw new InvalidArgumentException('Current annual pricing outcomes must be CanonicalPricingOutcome values.');
                }

                $results[] = $this->result($target, $row, $consumption, $outcome);
            }
        }

        return $results;
    }

    private function result(
        CarbonImmutable $date,
        ElectricityContract $row,
        int $consumption,
        ?CanonicalPricingOutcome $outcome,
    ): AsOfAnnualCostResult {
        $estimateMethod = $outcome?->estimateMethod->value;
        $estimateBasis = $outcome !== null ? $this->estimateBasis($outcome) : null;
        $unavailableReason = $this->unavailableReason($row, $consumption, $outcome);
        $totalCost = $unavailableReason === null ? $outcome?->totalCost : null;
        $observationId = $row->observation_id !== null ? (int) $row->observation_id : null;

        return new AsOfAnnualCostResult(
            contractId: (string) $row->contract_id,
            date: $date,
            segmentKey: $row->segment_key !== null
                ? (string) $row->segment_key
                : $this->segmentClassifier->classify(
                    $row,
                    ContractPriceBasis::CanonicalCalculation,
                ),
            consumptionKwh: $consumption,
            totalCost: $totalCost,
            methodVersion: AnnualCostMethodVersion::AsOf,
            pricingBasis: ContractPriceBasis::CanonicalCalculation,
            calculationBasis: AnnualCostCalculationBasis::CanonicalOutcome,
            estimateMethod: $estimateMethod,
            estimateBasis: $estimateBasis,
            compatibilityKey: AnnualCostCompatibilityKey::make(
                AnnualCostMethodVersion::AsOf,
                AnnualCostCalculationBasis::CanonicalOutcome,
                $estimateMethod,
                $estimateBasis,
            ),
            sourceEvidenceIds: [
                'price_snapshot_id' => $row->price_snapshot_id !== null ? (int) $row->price_snapshot_id : null,
                'price_component_ids' => [],
                'observation_ids' => $observationId !== null ? [$observationId] : [],
                'source_snapshot_id' => $row->source_snapshot_id !== null ? (int) $row->source_snapshot_id : null,
                'interpretation_id' => $row->interpretation_id !== null ? (int) $row->interpretation_id : null,
                'historical_episode_id' => null,
                'historical_interpretation_id' => null,
                'historical_evidence_grade' => null,
            ],
            priceEpisodeStartedAt: $this->priceEpisodeStartedAt($outcome),
            provenanceFlags: $this->provenanceFlags($outcome, $unavailableReason),
            unavailableReason: $unavailableReason,
        );
    }

    private function unavailableReason(
        ElectricityContract $row,
        int $consumption,
        ?CanonicalPricingOutcome $outcome,
    ): ?string {
        if ($outcome === null) {
            return 'canonical_outcome_missing';
        }
        if (! $outcome->isListed()) {
            return 'canonical_outcome_not_listed';
        }
        if ($outcome->totalCost === null) {
            return 'canonical_annual_cost_unavailable';
        }
        if ($row->price_snapshot_id === null) {
            return 'current_canonical_snapshot_missing';
        }

        $column = 'annual_cost_'.$consumption.'_kwh';

        return $row->{$column} === null ? 'consumption_out_of_range' : null;
    }

    private function estimateBasis(CanonicalPricingOutcome $outcome): string
    {
        if ($outcome->spotEstimate !== null) {
            $basis = SpotEstimateBasis::tryFrom((string) ($outcome->spotEstimate['basis'] ?? ''));
            if ($basis === null) {
                throw new InvalidArgumentException('Current canonical Spot estimate basis is invalid.');
            }

            return $basis->value;
        }

        if ($outcome->supplierAdjustedEstimate !== null) {
            $basis = SupplierAdjustedEstimateBasis::tryFrom((string) ($outcome->supplierAdjustedEstimate['basis'] ?? ''));
            if ($basis === null) {
                throw new InvalidArgumentException('Current canonical supplier estimate basis is invalid.');
            }

            return 'supplier_adjusted_'.$basis->value;
        }

        if ($outcome->resetEstimate !== null) {
            $basis = ResetEstimateBasis::tryFrom((string) ($outcome->resetEstimate['basis'] ?? ''));
            if ($basis === null) {
                throw new InvalidArgumentException('Current canonical reset estimate basis is invalid.');
            }

            return 'market_reset_'.$basis->value;
        }

        return 'canonical_disclosed_phase_timeline';
    }

    private function priceEpisodeStartedAt(?CanonicalPricingOutcome $outcome): ?CarbonImmutable
    {
        $startedAt = $outcome?->supplierAdjustedEstimate['price_episode_started_at'] ?? null;

        return is_string($startedAt) && trim($startedAt) !== ''
            ? CarbonImmutable::parse($startedAt, 'Europe/Helsinki')->startOfDay()
            : null;
    }

    /** @return list<string> */
    private function provenanceFlags(?CanonicalPricingOutcome $outcome, ?string $unavailableReason): array
    {
        $flags = ['current_canonical_outcome_reused'];
        if ($outcome !== null) {
            $flags[] = 'canonical_comparability_'.$outcome->comparability->value;
            $flags = [
                ...$flags,
                ...$outcome->assumptions,
                ...$this->nestedFlags($outcome->spotEstimate),
                ...$this->nestedFlags($outcome->supplierAdjustedEstimate),
                ...$this->nestedFlags($outcome->resetEstimate),
            ];
        }
        if ($unavailableReason !== null) {
            $flags[] = 'unavailable_'.$unavailableReason;
        }

        return array_values(array_unique(array_filter(
            $flags,
            fn ($flag): bool => is_string($flag) && trim($flag) !== '',
        )));
    }

    /**
     * @param  array<string, mixed>|null  $payload
     * @return list<string>
     */
    private function nestedFlags(?array $payload): array
    {
        $flags = $payload['flags'] ?? [];

        return is_array($flags)
            ? array_values(array_filter($flags, fn ($flag): bool => is_string($flag) && trim($flag) !== ''))
            : [];
    }
}
