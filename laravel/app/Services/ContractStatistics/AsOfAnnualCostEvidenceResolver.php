<?php

namespace App\Services\ContractStatistics;

use App\Services\CanonicalPricing\CanonicalPricingParser;
use App\Services\CanonicalPricing\DTO\CanonicalContractData;
use App\Services\CanonicalPricing\Exceptions\CanonicalPricingParseException;
use App\Services\ContractStatistics\DTO\AsOfAnnualCostEvidence;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class AsOfAnnualCostEvidenceResolver
{
    private const TIMEZONE = 'Europe/Helsinki';

    private const CONSUMPTIONS = [2000, 5000, 18000];

    public function __construct(private readonly CanonicalPricingParser $parser) {}

    /**
     * Resolve all requested dates with one query per evidence table.
     *
     * @param  iterable<CarbonInterface|string>  $dates
     * @return array<string, array<string, AsOfAnnualCostEvidence>>
     */
    public function resolveForDates(iterable $dates): array
    {
        $targets = collect($dates)
            ->map(fn (CarbonInterface|string $date): CarbonImmutable => $this->date($date))
            ->unique(fn (CarbonImmutable $date): string => $date->toDateString())
            ->sort()
            ->values();

        if ($targets->isEmpty()) {
            return [];
        }

        $dateStrings = $targets->map->toDateString()->all();
        $snapshots = DB::table('contract_price_snapshots')
            ->whereIn(DB::raw('DATE(snapshot_date)'), $dateStrings)
            ->orderBy('snapshot_date')
            ->orderBy('contract_id')
            ->get([
                'id',
                'snapshot_date',
                'contract_id',
                'pricing_model',
                'contract_type',
                'fixed_time_range',
                'metering',
                'segment_key',
                'pricing_basis',
                'annual_cost_2000_kwh',
                'annual_cost_5000_kwh',
                'annual_cost_18000_kwh',
            ]);
        $snapshotsByDateContract = $snapshots->groupBy(
            fn (object $row): string => $this->dateString($row->snapshot_date).'|'.$row->contract_id,
        );

        $componentRows = DB::table('price_components')
            ->whereIn(DB::raw('DATE(price_date)'), $dateStrings)
            ->orderBy('price_date')
            ->orderBy('electricity_contract_id')
            ->orderBy('id')
            ->get([
                'id',
                'price_date',
                'electricity_contract_id',
                'price_component_type',
                'payment_unit',
                'price',
                'has_discount',
                'discount_value',
                'discount_is_percentage',
                'discount_type',
                'discount_discount_n_first_kwh',
                'discount_discount_n_first_months',
                'discount_discount_until_date',
            ]);
        $componentsByDateContract = $componentRows->groupBy(
            fn (object $row): string => $this->dateString($row->price_date).'|'.$row->electricity_contract_id,
        );

        $contractIdsByDate = array_fill_keys($dateStrings, []);
        foreach ($snapshots as $snapshot) {
            $contractIdsByDate[$this->dateString($snapshot->snapshot_date)][(string) $snapshot->contract_id] = true;
        }
        foreach ($componentRows as $component) {
            $contractIdsByDate[$this->dateString($component->price_date)][(string) $component->electricity_contract_id] = true;
        }

        $snapshotContractIds = $snapshots->pluck('contract_id')->map(fn ($id): string => (string) $id)->unique()->values()->all();
        $minimumStart = $targets->first()->startOfDay()->utc();
        $maximumEnd = $targets->last()->endOfDay()->utc();
        $observations = $snapshotContractIds === []
            ? collect()
            : DB::table('contract_source_observations')
                ->whereIn('contract_id', $snapshotContractIds)
                ->where('first_observed_at', '<=', $maximumEnd->format('Y-m-d H:i:s'))
                ->where('last_observed_at', '>=', $minimumStart->format('Y-m-d H:i:s'))
                ->orderBy('contract_id')
                ->orderBy('first_observed_at')
                ->get(['id', 'contract_id', 'source_snapshot_id', 'first_observed_at', 'last_observed_at'])
                ->groupBy('contract_id');

        $sourceSnapshotIds = $observations->flatten(1)->pluck('source_snapshot_id')->unique()->values()->all();
        $interpretations = $sourceSnapshotIds === []
            ? collect()
            : DB::table('contract_interpretations')
                ->whereIn('source_snapshot_id', $sourceSnapshotIds)
                ->whereNotNull('completed_at')
                ->where('completed_at', '<=', $maximumEnd->format('Y-m-d H:i:s'))
                ->orderBy('source_snapshot_id')
                ->orderBy('completed_at')
                ->orderBy('id')
                ->get([
                    'id',
                    'contract_id',
                    'source_snapshot_id',
                    'analysis_source_observation_id',
                    'status',
                    'output',
                    'validation_errors',
                    'completed_at',
                ])
                ->groupBy('source_snapshot_id');

        $resolved = array_fill_keys($dateStrings, []);
        foreach ($targets as $target) {
            $dateString = $target->toDateString();
            $contractIds = array_keys($contractIdsByDate[$dateString]);
            sort($contractIds);

            foreach ($contractIds as $contractId) {
                $key = $dateString.'|'.$contractId;
                $snapshotRows = $snapshotsByDateContract->get($key, collect());
                $snapshot = $snapshotRows->count() === 1 ? $snapshotRows->first() : null;
                $rawComponents = $componentsByDateContract->get($key, collect());

                if ($snapshot === null) {
                    $reason = $snapshotRows->isEmpty()
                        ? 'missing_historical_snapshot_identity'
                        : 'ambiguous_historical_snapshot_identity';
                    $resolved[$dateString][$contractId] = $this->excludedEvidence(
                        $contractId,
                        $target,
                        $rawComponents,
                        $reason,
                    );

                    continue;
                }

                $basis = ContractPriceBasis::tryFrom((string) $snapshot->pricing_basis);
                if ($basis === null) {
                    $resolved[$dateString][$contractId] = $this->excludedEvidence(
                        $contractId,
                        $target,
                        $rawComponents,
                        'invalid_historical_snapshot_pricing_basis',
                        (int) $snapshot->id,
                    );

                    continue;
                }

                [$canonical, $sourceIds, $flags] = $this->canonicalEvidence(
                    $contractId,
                    $target,
                    $observations->get($contractId, collect()),
                    $interpretations,
                );

                $sourceIds = [
                    'price_snapshot_id' => (int) $snapshot->id,
                    'price_component_ids' => $rawComponents->pluck('id')->map(fn ($id): string => (string) $id)->values()->all(),
                    ...$sourceIds,
                ];

                $resolved[$dateString][$contractId] = new AsOfAnnualCostEvidence(
                    contractId: $contractId,
                    date: $target,
                    segmentKey: (string) $snapshot->segment_key,
                    pricingModel: (string) ($snapshot->pricing_model ?? ''),
                    contractType: (string) ($snapshot->contract_type ?? ''),
                    fixedTimeRange: $snapshot->fixed_time_range !== null ? (string) $snapshot->fixed_time_range : null,
                    metering: $snapshot->metering !== null ? (string) $snapshot->metering : null,
                    pricingBasis: $basis,
                    priceComponents: $this->normalizeComponents($rawComponents),
                    consumptionAvailability: [
                        2000 => $snapshot->annual_cost_2000_kwh !== null,
                        5000 => $snapshot->annual_cost_5000_kwh !== null,
                        18000 => $snapshot->annual_cost_18000_kwh !== null,
                    ],
                    canonicalData: $canonical,
                    sourceEvidenceIds: $sourceIds,
                    provenanceFlags: $flags,
                );
            }
        }

        return $resolved;
    }

    /** @return array<string, AsOfAnnualCostEvidence> */
    public function resolveDate(CarbonInterface|string $date): array
    {
        $target = $this->date($date);

        return $this->resolveForDates([$target])[$target->toDateString()] ?? [];
    }

    /**
     * @param  Collection<int, object>  $contractObservations
     * @param  Collection<int|string, Collection<int, object>>  $interpretations
     * @return array{0: CanonicalContractData|null, 1: array{observation_ids: list<int>, source_snapshot_id: int|null, interpretation_id: int|null}, 2: list<string>}
     */
    private function canonicalEvidence(
        string $contractId,
        CarbonImmutable $target,
        Collection $contractObservations,
        Collection $interpretations,
    ): array {
        $startUtc = $target->startOfDay()->utc();
        $endUtc = $target->endOfDay()->utc();
        $covering = $contractObservations->filter(function (object $row) use ($startUtc, $endUtc): bool {
            $first = CarbonImmutable::parse((string) $row->first_observed_at, 'UTC');
            $last = CarbonImmutable::parse((string) $row->last_observed_at, 'UTC');

            return $first->lessThanOrEqualTo($endUtc) && $last->greaterThanOrEqualTo($startUtc);
        })->values();

        $observationIds = $covering->pluck('id')->map(fn ($id): int => (int) $id)->values()->all();
        $sourceSnapshotIds = $covering->pluck('source_snapshot_id')->map(fn ($id): int => (int) $id)->unique()->values();
        $ids = [
            'observation_ids' => $observationIds,
            'source_snapshot_id' => $sourceSnapshotIds->count() === 1 ? $sourceSnapshotIds->first() : null,
            'interpretation_id' => null,
        ];

        if ($covering->isEmpty()) {
            return [null, $ids, ['canonical_omitted_no_covering_source_observation']];
        }
        if ($sourceSnapshotIds->count() !== 1) {
            return [null, $ids, ['canonical_omitted_ambiguous_covering_source_snapshots']];
        }

        $sourceSnapshotId = (int) $sourceSnapshotIds->first();
        $valid = [];
        $sawInvalid = false;
        foreach ($interpretations->get($sourceSnapshotId, collect()) as $row) {
            $completedAt = CarbonImmutable::parse((string) $row->completed_at, 'UTC');
            if ($completedAt->greaterThan($target->endOfDay()->utc())) {
                continue;
            }
            if ((string) $row->contract_id !== $contractId
                || ! in_array((string) $row->status, ['published', 'superseded'], true)
                || ! $this->isEmptyJsonList($row->validation_errors)) {
                $sawInvalid = true;

                continue;
            }
            if ($row->analysis_source_observation_id !== null
                && ! in_array((int) $row->analysis_source_observation_id, $observationIds, true)) {
                $sawInvalid = true;

                continue;
            }

            $output = $this->jsonObject($row->output);
            try {
                $data = $this->parser->parse(
                    $this->arrayValue($output, 'pricing'),
                    $this->arrayValue($output, 'calculation'),
                    $this->arrayValue($output, 'source_consistency'),
                );
            } catch (CanonicalPricingParseException) {
                $sawInvalid = true;

                continue;
            }

            $valid[] = ['row' => $row, 'data' => $data, 'completed_at' => $completedAt];
        }

        if ($valid === []) {
            return [null, $ids, [$sawInvalid
                ? 'canonical_omitted_no_valid_interpretation_as_of_date'
                : 'canonical_omitted_no_interpretation_as_of_date']];
        }

        usort($valid, fn (array $left, array $right): int => $right['completed_at']->getTimestamp() <=> $left['completed_at']->getTimestamp());
        $latestTimestamp = $valid[0]['completed_at']->getTimestamp();
        $latest = array_values(array_filter(
            $valid,
            fn (array $candidate): bool => $candidate['completed_at']->getTimestamp() === $latestTimestamp,
        ));
        if (count($latest) !== 1) {
            return [null, $ids, ['canonical_omitted_ambiguous_interpretation_chronology']];
        }

        $ids['interpretation_id'] = (int) $latest[0]['row']->id;

        return [$latest[0]['data'], $ids, ['historical_household_statistics_scope_assumed']];
    }

    /** @param Collection<int, object> $components */
    private function excludedEvidence(
        string $contractId,
        CarbonImmutable $target,
        Collection $components,
        string $reason,
        ?int $priceSnapshotId = null,
    ): AsOfAnnualCostEvidence {
        return new AsOfAnnualCostEvidence(
            contractId: $contractId,
            date: $target,
            segmentKey: 'unclassified',
            pricingModel: '',
            contractType: '',
            fixedTimeRange: null,
            metering: null,
            pricingBasis: ContractPriceBasis::ObservedSellerData,
            priceComponents: $this->normalizeComponents($components),
            consumptionAvailability: array_fill_keys(self::CONSUMPTIONS, false),
            canonicalData: null,
            sourceEvidenceIds: [
                'price_snapshot_id' => $priceSnapshotId,
                'price_component_ids' => $components->pluck('id')->map(fn ($id): string => (string) $id)->values()->all(),
                'observation_ids' => [],
                'source_snapshot_id' => null,
                'interpretation_id' => null,
            ],
            provenanceFlags: [$reason],
            exclusionReason: $reason,
        );
    }

    /** @param Collection<int, object> $rows
     * @return array<int, array<string, mixed>>
     */
    private function normalizeComponents(Collection $rows): array
    {
        return $rows
            ->groupBy('price_component_type')
            ->map(function (Collection $group): object {
                return $group->first(fn (object $row): bool => (float) $row->price > 0) ?? $group->first();
            })
            ->values()
            ->map(fn (object $row): array => [
                'price_component_type' => $row->price_component_type,
                'payment_unit' => $row->payment_unit,
                'price' => (float) $row->price,
                'has_discount' => (bool) $row->has_discount,
                'discount_value' => $row->discount_value,
                'discount_is_percentage' => $row->discount_is_percentage === null ? null : (bool) $row->discount_is_percentage,
                'discount_type' => $row->discount_type,
                'discount_discount_n_first_kwh' => $row->discount_discount_n_first_kwh,
                'discount_discount_n_first_months' => $row->discount_discount_n_first_months,
                'discount_discount_until_date' => $row->discount_discount_until_date,
            ])
            ->all();
    }

    private function isEmptyJsonList(mixed $value): bool
    {
        if ($value === null || $value === '') {
            return true;
        }

        $decoded = is_string($value) ? json_decode($value, true) : $value;

        return is_array($decoded) && $decoded === [];
    }

    /** @return array<string, mixed> */
    private function jsonObject(mixed $value): array
    {
        $decoded = is_string($value) ? json_decode($value, true) : $value;

        return is_array($decoded) ? $decoded : [];
    }

    /** @param array<string, mixed> $values
     * @return array<string, mixed>|null
     */
    private function arrayValue(array $values, string $key): ?array
    {
        return isset($values[$key]) && is_array($values[$key]) ? $values[$key] : null;
    }

    private function date(CarbonInterface|string $date): CarbonImmutable
    {
        return CarbonImmutable::parse(
            $date instanceof CarbonInterface ? $date->toDateString() : $date,
            self::TIMEZONE,
        )->startOfDay();
    }

    private function dateString(mixed $date): string
    {
        return CarbonImmutable::parse((string) $date, self::TIMEZONE)->toDateString();
    }
}
