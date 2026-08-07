<?php

namespace App\Services\ContractStatistics;

use App\Models\ContractPriceAnnualCost;
use App\Models\ContractPriceDailyStatistic;
use App\Services\ContractStatistics\DTO\AnnualCostAggregateSummary;
use App\Services\ContractStatistics\DTO\AnnualCostStatisticsDateSummary;
use App\Services\ContractStatistics\DTO\AsOfAnnualCostResult;
use App\Services\ContractStatistics\Enums\AnnualCostMethodVersion;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class AnnualCostStatisticsWriter
{
    private const MAXIMUM_ANNUAL_COST_EUR = 50000.0;

    /**
     * Validate and summarize one complete date without changing the database.
     *
     * @param  list<AsOfAnnualCostResult>  $results
     */
    public function preview(CarbonInterface|string $date, array $results): AnnualCostStatisticsDateSummary
    {
        return $this->prepare($date, $results, requireCompleteIdentitySet: false)->summary(applied: false);
    }

    /**
     * Replace only annual_cost_as_of_v1 rows for one complete date.
     *
     * @param  list<AsOfAnnualCostResult>  $results
     */
    public function write(CarbonInterface|string $date, array $results): AnnualCostStatisticsDateSummary
    {
        $prepared = $this->prepare($date, $results, requireCompleteIdentitySet: true);
        $dateString = $prepared->date->toDateString();
        $method = AnnualCostMethodVersion::AsOf->value;

        DB::transaction(function () use ($prepared, $dateString, $method): void {
            ContractPriceAnnualCost::query()
                ->whereDate('snapshot_date', $dateString)
                ->where('method_version', $method)
                ->delete();

            foreach (array_chunk($prepared->annualRows, 500) as $rows) {
                ContractPriceAnnualCost::query()->insert($rows);
            }

            ContractPriceDailyStatistic::query()
                ->whereDate('stat_date', $dateString)
                ->where('metric_key', 'annual_cost')
                ->where('method_version', $method)
                ->delete();

            foreach (array_chunk($prepared->aggregateRows, 500) as $rows) {
                ContractPriceDailyStatistic::query()->insert($rows);
            }
        });

        return $prepared->summary(applied: true);
    }

    /**
     * @param  list<AsOfAnnualCostResult>  $results
     */
    private function prepare(
        CarbonInterface|string $date,
        array $results,
        bool $requireCompleteIdentitySet,
    ): PreparedAnnualCostDate {
        $target = CarbonImmutable::parse(
            $date instanceof CarbonInterface ? $date->toDateString() : $date,
            'Europe/Helsinki',
        )->startOfDay();
        $identities = [];

        foreach ($results as $result) {
            if (! $result instanceof AsOfAnnualCostResult) {
                throw new InvalidArgumentException('Every annual cost row must be an AsOfAnnualCostResult.');
            }
            $this->validateResult($target, $result);

            $identity = $result->date->toDateString().'|'.$result->contractId.'|'.$result->consumptionKwh;
            if (isset($identities[$identity])) {
                throw new InvalidArgumentException('Annual cost result identities must be unique within a date.');
            }
            $identities[$identity] = true;
        }

        if ($requireCompleteIdentitySet) {
            $this->assertCompleteIdentitySet($results);
        }

        $now = now();
        $annualRows = [];
        foreach ($results as $result) {
            if (! $result->isAvailable()) {
                continue;
            }

            $annualRows[] = [
                'snapshot_date' => $target->toDateString(),
                'contract_id' => $result->contractId,
                'segment_key' => $result->segmentKey,
                'pricing_basis' => $result->pricingBasis->value,
                'consumption_kwh' => $result->consumptionKwh,
                'annual_cost' => $result->totalCost,
                'method_version' => $result->methodVersion->value,
                'calculation_basis' => $result->calculationBasis->value,
                'estimate_method' => $result->estimateMethod,
                'estimate_basis' => $result->estimateBasis,
                'compatibility_key' => $result->compatibilityKey,
                'source_observation_id' => count($result->sourceEvidenceIds['observation_ids']) === 1
                    ? $result->sourceEvidenceIds['observation_ids'][0]
                    : null,
                'source_snapshot_id' => $result->sourceEvidenceIds['source_snapshot_id'],
                'source_interpretation_id' => $result->sourceEvidenceIds['interpretation_id'],
                'historical_episode_id' => $result->sourceEvidenceIds['historical_episode_id'],
                'historical_interpretation_id' => $result->sourceEvidenceIds['historical_interpretation_id'],
                'historical_evidence_grade' => $result->sourceEvidenceIds['historical_evidence_grade'],
                'price_episode_started_at' => $result->priceEpisodeStartedAt?->toDateTimeString(),
                'provenance' => json_encode([
                    'source_evidence_ids' => $result->sourceEvidenceIds,
                    'flags' => $result->provenanceFlags,
                ], JSON_THROW_ON_ERROR),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        $aggregateRows = [];
        $aggregateSummaries = [];
        $groups = collect($results)->groupBy(
            fn (AsOfAnnualCostResult $result): string => $result->segmentKey.'|'.$result->consumptionKwh,
        );
        foreach ($groups as $group) {
            /** @var \Illuminate\Support\Collection<int, AsOfAnnualCostResult> $group */
            $available = $group->filter->isAvailable()->values();
            if ($available->isEmpty()) {
                continue;
            }

            $values = $available->pluck('totalCost')->map(fn ($value): float => (float) $value)->all();
            $statistics = $this->statistics($values);
            $pricingBasis = $this->homogeneousOr(
                $available->map(fn (AsOfAnnualCostResult $result): string => $result->pricingBasis->value)->all(),
                'mixed_evidence',
            );
            $calculationBasis = $this->homogeneousOr(
                $available->map(fn (AsOfAnnualCostResult $result): string => $result->calculationBasis->value)->all(),
                'mixed',
            );
            $estimateBasis = $this->homogeneousOr(
                $available->map(fn (AsOfAnnualCostResult $result): string => $result->estimateBasis ?? 'none')->all(),
                'mixed',
            );
            $compatibilityKeys = $available->pluck('compatibilityKey')->unique()->sort()->values()->all();
            $compatibilityKey = 'annual-cost-aggregate:'.hash('sha256', implode("\n", $compatibilityKeys));
            /** @var AsOfAnnualCostResult $first */
            $first = $group->first();
            $basisCounts = $this->basisCounts($group->all(), availableContributorsOnly: true);

            $aggregateRows[] = [
                'stat_date' => $target->toDateString(),
                'segment_key' => $first->segmentKey,
                'metric_key' => 'annual_cost',
                'pricing_basis' => $pricingBasis,
                'method_version' => AnnualCostMethodVersion::AsOf->value,
                'calculation_basis' => $calculationBasis,
                'estimate_basis' => $estimateBasis,
                'compatibility_key' => $compatibilityKey,
                'basis_counts' => json_encode($basisCounts, JSON_THROW_ON_ERROR),
                'consumption_kwh' => $first->consumptionKwh,
                'min_value' => $statistics->minimum,
                'p20_value' => $statistics->p20,
                'avg_value' => $statistics->average,
                'median_value' => $statistics->median,
                'p80_value' => $statistics->p80,
                'max_value' => $statistics->maximum,
                'contract_count' => count($values),
                'created_at' => $now,
                'updated_at' => $now,
            ];
            $aggregateSummaries[] = new AnnualCostAggregateSummary(
                segmentKey: $first->segmentKey,
                consumptionKwh: $first->consumptionKwh,
                median: $statistics->median,
                compatibilityKey: $compatibilityKey,
            );
        }

        usort($aggregateSummaries, fn (AnnualCostAggregateSummary $left, AnnualCostAggregateSummary $right): int => [
            $left->segmentKey,
            $left->consumptionKwh,
        ] <=> [
            $right->segmentKey,
            $right->consumptionKwh,
        ]);

        $availableCount = count($annualRows);

        return new PreparedAnnualCostDate(
            date: $target,
            evidenceResultCount: count($results),
            availableCount: $availableCount,
            unavailableCount: count($results) - $availableCount,
            basisCounts: $this->basisCounts($results),
            annualRows: $annualRows,
            aggregateRows: $aggregateRows,
            aggregateSummaries: $aggregateSummaries,
        );
    }

    private function validateResult(CarbonImmutable $target, AsOfAnnualCostResult $result): void
    {
        if ($result->date->toDateString() !== $target->toDateString()) {
            throw new InvalidArgumentException('All annual cost results must belong to the selected date.');
        }
        if ($result->methodVersion !== AnnualCostMethodVersion::AsOf) {
            throw new InvalidArgumentException('The annual cost writer accepts only annual_cost_as_of_v1 results.');
        }
        if (! in_array($result->consumptionKwh, AsOfAnnualCostCalculator::DEFAULT_CONSUMPTIONS, true)) {
            throw new InvalidArgumentException('Annual cost consumption must be 2000, 5000, or 18000 kWh.');
        }
        if (trim($result->contractId) === '' || trim($result->segmentKey) === '') {
            throw new InvalidArgumentException('Annual cost contract and segment identities must not be empty.');
        }
        if (trim($result->compatibilityKey) === '' || strlen($result->compatibilityKey) > 120) {
            throw new InvalidArgumentException('Annual cost compatibility provenance must be present and fit storage.');
        }
        if (! array_is_list($result->provenanceFlags) || $result->provenanceFlags === []
            || collect($result->provenanceFlags)->contains(fn ($flag): bool => ! is_string($flag) || trim($flag) === '')) {
            throw new InvalidArgumentException('Annual cost provenance flags must be a non-empty string list.');
        }
        $this->validateSourceEvidence($result->sourceEvidenceIds);

        if ($result->isAvailable()) {
            if (! is_int($result->sourceEvidenceIds['price_snapshot_id'])
                || $result->sourceEvidenceIds['price_snapshot_id'] <= 0) {
                throw new InvalidArgumentException('Available annual costs require a historical price snapshot identity.');
            }
            if (! is_finite($result->totalCost) || $result->totalCost < 0 || $result->totalCost > self::MAXIMUM_ANNUAL_COST_EUR) {
                throw new InvalidArgumentException('Available annual costs must be finite and between 0 and 50000 euros.');
            }
            if ($result->estimateMethod === null || trim($result->estimateMethod) === ''
                || $result->estimateBasis === null || trim($result->estimateBasis) === '') {
                throw new InvalidArgumentException('Available annual costs must include estimate method and basis provenance.');
            }

            return;
        }

        if ($result->totalCost !== null || $result->unavailableReason === null || trim($result->unavailableReason) === '') {
            throw new InvalidArgumentException('Unavailable annual costs must have a reason and no total.');
        }
    }

    /** @param list<AsOfAnnualCostResult> $results */
    private function assertCompleteIdentitySet(array $results): void
    {
        if ($results === []) {
            throw new InvalidArgumentException('A complete annual cost apply must not use an empty result set.');
        }

        $expected = AsOfAnnualCostCalculator::DEFAULT_CONSUMPTIONS;
        sort($expected);
        foreach (collect($results)->groupBy('contractId') as $contractId => $contractResults) {
            $actual = $contractResults->pluck('consumptionKwh')->unique()->sort()->values()->all();
            if ($actual !== $expected) {
                throw new InvalidArgumentException(
                    'A complete annual cost apply requires 2000, 5000, and 18000 kWh results for contract '.$contractId.'.'
                );
            }
        }
    }

    /** @param array<string, mixed> $source */
    private function validateSourceEvidence(array $source): void
    {
        $required = [
            'price_snapshot_id',
            'price_component_ids',
            'observation_ids',
            'source_snapshot_id',
            'interpretation_id',
            'historical_episode_id',
            'historical_interpretation_id',
            'historical_evidence_grade',
        ];
        foreach ($required as $key) {
            if (! array_key_exists($key, $source)) {
                throw new InvalidArgumentException('Annual cost source evidence is incomplete.');
            }
        }
        if (! $this->isNullablePositiveInteger($source['price_snapshot_id'])
            || ! is_array($source['price_component_ids']) || ! array_is_list($source['price_component_ids'])
            || collect($source['price_component_ids'])->contains(fn ($id): bool => ! is_string($id) || trim($id) === '')
            || ! is_array($source['observation_ids']) || ! array_is_list($source['observation_ids'])
            || collect($source['observation_ids'])->contains(fn ($id): bool => ! is_int($id) || $id <= 0)
            || ! $this->isNullablePositiveInteger($source['source_snapshot_id'])
            || ! $this->isNullablePositiveInteger($source['interpretation_id'])
            || ! $this->isNullablePositiveInteger($source['historical_episode_id'])
            || ! $this->isNullablePositiveInteger($source['historical_interpretation_id'])
            || ! $this->isNullableEvidenceGrade($source['historical_evidence_grade'])) {
            throw new InvalidArgumentException('Annual cost source evidence has invalid identifiers.');
        }

        $hasHistorical = $source['historical_episode_id'] !== null
            || $source['historical_interpretation_id'] !== null
            || $source['historical_evidence_grade'] !== null;
        if ($hasHistorical && ($source['historical_episode_id'] === null
            || $source['historical_interpretation_id'] === null
            || $source['historical_evidence_grade'] === null
            || $source['source_snapshot_id'] !== null
            || $source['interpretation_id'] !== null
            || $source['observation_ids'] !== [])) {
            throw new InvalidArgumentException('Dedicated historical evidence provenance must be complete and isolated.');
        }
    }

    private function isNullableEvidenceGrade(mixed $value): bool
    {
        return $value === null || (is_string($value) && trim($value) !== '' && strlen($value) <= 80);
    }

    private function isNullablePositiveInteger(mixed $value): bool
    {
        return $value === null || (is_int($value) && $value > 0);
    }

    /**
     * @param  list<AsOfAnnualCostResult>  $results
     * @return array{pricing_basis: array<string, int>, calculation_basis: array<string, int>, estimate_method: array<string, int>, estimate_basis: array<string, int>, unavailable_reasons: array<string, int>}
     */
    private function basisCounts(array $results, bool $availableContributorsOnly = false): array
    {
        $counts = [
            'pricing_basis' => [],
            'calculation_basis' => [],
            'estimate_method' => [],
            'estimate_basis' => [],
            'unavailable_reasons' => [],
        ];

        foreach ($results as $result) {
            if (! $availableContributorsOnly || $result->isAvailable()) {
                $this->increment($counts['pricing_basis'], $result->pricingBasis->value);
                $this->increment($counts['calculation_basis'], $result->calculationBasis->value);
                $this->increment($counts['estimate_method'], $result->estimateMethod ?? 'none');
                $this->increment($counts['estimate_basis'], $result->estimateBasis ?? 'none');
            }
            if (! $result->isAvailable() && $result->unavailableReason !== null) {
                $this->increment($counts['unavailable_reasons'], $result->unavailableReason);
            }
        }

        foreach ($counts as &$values) {
            ksort($values);
        }
        unset($values);

        return $counts;
    }

    /** @param array<string, int> $counts */
    private function increment(array &$counts, string $key): void
    {
        $counts[$key] = ($counts[$key] ?? 0) + 1;
    }

    /** @param list<string> $values */
    private function homogeneousOr(array $values, string $mixed): string
    {
        $unique = array_values(array_unique($values));

        return count($unique) === 1 ? $unique[0] : $mixed;
    }

    /** @param list<float> $values */
    private function statistics(array $values): AnnualCostValueStatistics
    {
        sort($values);

        return new AnnualCostValueStatistics(
            minimum: $values[0],
            p20: $this->percentile($values, 20),
            average: array_sum($values) / count($values),
            median: $this->percentile($values, 50),
            p80: $this->percentile($values, 80),
            maximum: $values[array_key_last($values)],
        );
    }

    /** @param list<float> $sortedValues */
    private function percentile(array $sortedValues, float $percentile): float
    {
        if (count($sortedValues) === 1) {
            return $sortedValues[0];
        }

        $index = ($percentile / 100) * (count($sortedValues) - 1);
        $lower = (int) floor($index);
        $upper = (int) ceil($index);
        $weight = $index - $lower;

        return $sortedValues[$lower] * (1 - $weight) + $sortedValues[$upper] * $weight;
    }
}

/** @internal */
readonly class PreparedAnnualCostDate
{
    /**
     * @param  array{pricing_basis: array<string, int>, calculation_basis: array<string, int>, estimate_method: array<string, int>, estimate_basis: array<string, int>, unavailable_reasons: array<string, int>}  $basisCounts
     * @param  list<array<string, mixed>>  $annualRows
     * @param  list<array<string, mixed>>  $aggregateRows
     * @param  list<AnnualCostAggregateSummary>  $aggregateSummaries
     */
    public function __construct(
        public CarbonImmutable $date,
        public int $evidenceResultCount,
        public int $availableCount,
        public int $unavailableCount,
        public array $basisCounts,
        public array $annualRows,
        public array $aggregateRows,
        public array $aggregateSummaries,
    ) {}

    public function summary(bool $applied): AnnualCostStatisticsDateSummary
    {
        return new AnnualCostStatisticsDateSummary(
            date: $this->date,
            methodVersion: AnnualCostMethodVersion::AsOf,
            evidenceResultCount: $this->evidenceResultCount,
            availableCount: $this->availableCount,
            unavailableCount: $this->unavailableCount,
            persistedCount: $applied ? count($this->annualRows) : 0,
            aggregateCount: $applied ? count($this->aggregateRows) : 0,
            basisCounts: $this->basisCounts,
            aggregates: $this->aggregateSummaries,
            applied: $applied,
        );
    }
}

/** @internal */
readonly class AnnualCostValueStatistics
{
    public function __construct(
        public float $minimum,
        public float $p20,
        public float $average,
        public float $median,
        public float $p80,
        public float $maximum,
    ) {}
}
