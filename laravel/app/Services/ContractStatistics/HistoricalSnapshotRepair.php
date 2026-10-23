<?php

namespace App\Services\ContractStatistics;

use App\Enums\TargetGroup;
use App\Models\ContractPriceDailyStatistic;
use App\Models\ContractPriceSnapshot;
use App\Models\ContractSourceSnapshot;
use App\Models\ElectricityContract;
use App\Services\ContractImport\ContractImporter;
use App\Services\ContractInterpretation\ContractInterpretationPublisher;
use App\Services\ContractStatistics\DTO\AsOfAnnualCostResult;
use App\Services\ContractStatistics\Enums\AnnualCostMethodVersion;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Adds the canonical snapshots that the current writer missed on one past date, from dated
 * evidence only: the exact covering source payload, the v3-selected interpretation of that
 * payload, and the v3 calculator outcomes of that date. Existing snapshots are never changed.
 * See tasks/statistics-date-repair-2026-10-22/.
 */
class HistoricalSnapshotRepair
{
    private const TIMEZONE = 'Europe/Helsinki';

    private const METHOD = AnnualCostMethodVersion::AsOfV3;

    private const COMPARED_FIELDS = [
        'company_name', 'contract_name', 'pricing_model', 'contract_type', 'fixed_time_range', 'metering',
        'segment_key', 'energy_price_cents_per_kwh', 'spot_margin_cents_per_kwh',
        'spot_total_energy_price_cents_per_kwh', 'monthly_fee_eur', 'annual_cost_2000_kwh',
        'annual_cost_5000_kwh', 'annual_cost_18000_kwh', 'has_discount', 'includes_spot_price',
    ];

    public function __construct(
        private readonly AsOfAnnualCostEvidenceResolver $resolver,
        private readonly AsOfAnnualCostCalculator $calculator,
        private readonly AnnualCostStatisticsWriter $writer,
        private readonly ContractPriceStatisticsService $statistics,
        private readonly ContractStatisticsSegmentClassifier $segmentClassifier,
        private readonly ContractInterpretationPublisher $publisher,
        private readonly SellerSetEnergyPriceIndexService $index,
    ) {}

    /**
     * @return array{added: array<string, array<string, mixed>>, skipped: array<string, string>, compared: array{matched: int, differences: array<string, array<string, array{0: mixed, 1: mixed}>>}}
     */
    public function plan(CarbonImmutable $date, bool $compareExisting = false): array
    {
        $date = CarbonImmutable::parse($date->toDateString(), self::TIMEZONE)->startOfDay();
        $dateString = $date->toDateString();
        $stored = ContractPriceSnapshot::query()->whereDate('snapshot_date', $dateString)->get()->keyBy('contract_id');
        if ($stored->contains(fn (ContractPriceSnapshot $row): bool => $row->pricing_basis !== ContractPriceBasis::CanonicalCalculation->value)) {
            throw new RuntimeException("{$dateString} has observed snapshots; one basis owns a date.");
        }
        $hadPreviousSnapshot = ContractPriceSnapshot::query()->whereDate('snapshot_date', $date->subDay()->toDateString())
            ->pluck('contract_id')->map(fn ($id): string => (string) $id)->flip();

        // The contracts that the upstream API listed on the date.
        $covering = DB::table('contract_source_observations')
            ->where('first_observed_at', '<=', $date->endOfDay()->utc()->format('Y-m-d H:i:s'))
            ->where('last_observed_at', '>=', $date->utc()->format('Y-m-d H:i:s'))
            ->get(['contract_id', 'source_snapshot_id'])
            ->groupBy('contract_id');
        $skipped = [];
        $sources = [];
        foreach ($covering as $contractId => $rows) {
            $contractId = (string) $contractId;
            if ($stored->has($contractId) && ! $compareExisting) {
                continue;
            }
            if ($rows->pluck('source_snapshot_id')->unique()->count() !== 1) {
                $skipped[$contractId] = 'ambiguous_covering_source';

                continue;
            }
            $sources[$contractId] = (int) $rows->first()->source_snapshot_id;
        }

        $payloads = ContractSourceSnapshot::query()->whereIn('id', array_values($sources))->get()->keyBy('id');
        $contracts = [];
        foreach ($sources as $contractId => $sourceId) {
            $payload = $payloads->get($sourceId)?->source_payload;
            if (! is_array($payload) || $payloads->get($sourceId)->contract_id !== $contractId) {
                $skipped[$contractId] = 'source_payload_unavailable';

                continue;
            }
            $attributes = ContractImporter::sourceAttributes($payload);
            if ($attributes['target_group'] === TargetGroup::Company->value) {
                continue;
            }
            $contracts[$contractId] = (new ElectricityContract)->forceFill(['id' => $contractId, ...$attributes]);
        }

        // Pass 1 selects the dated interpretation; identity is not known before it.
        $missing = array_values(array_filter(array_keys($contracts), fn (string $id): bool => ! $stored->has($id)));
        $evidence = $this->resolver->resolveDate($date, self::METHOD, array_map(
            fn (string $id): object => $this->identityRow($date, $contracts[$id], 'unclassified'),
            $missing,
        ));
        $interpretations = DB::table('contract_interpretations')
            ->whereIn('id', collect($evidence)->map(fn ($item) => $item->sourceEvidenceIds['interpretation_id'] ?? null)->filter()->values())
            ->get(['id', 'output'])
            ->keyBy('id');
        foreach ($contracts as $contractId => $contract) {
            $item = $evidence[$contractId] ?? null;
            $interpretation = $interpretations->get($item?->sourceEvidenceIds['interpretation_id'] ?? null);
            $output = $interpretation !== null ? json_decode((string) $interpretation->output, true) : null;
            $reason = match (true) {
                $item === null => 'no_dated_evidence',
                $item->exclusionReason !== null => $item->exclusionReason,
                $item->householdAudienceConflict => 'company_audience',
                $item->canonicalData === null || ! is_array($output) => 'no_dated_interpretation',
                // A new contract activates only when its source pricing passes the gate.
                ! $hadPreviousSnapshot->has($contractId) && ! $this->publisher->canPublishSourcePricing($output) => 'source_pricing_gate',
                default => null,
            };
            if ($reason !== null) {
                $skipped[$contractId] = $reason;
                unset($contracts[$contractId]);

                continue;
            }
            $contract->forceFill($this->publisher->publicationAttributes($output));
        }

        // Pass 2 calculates the whole date with dated identities for the missing contracts.
        $identityRows = [];
        foreach ($contracts as $contractId => $contract) {
            if (! $stored->has($contractId)) {
                $identityRows[] = $this->identityRow($date, $contract, $this->segmentClassifier->classify($contract, ContractPriceBasis::CanonicalCalculation));
            }
        }
        $outcomes = [];
        foreach ($this->calculator->calculate($date, self::METHOD, $identityRows) as $result) {
            /** @var AsOfAnnualCostResult $result */
            if ($result->outcome !== null) {
                $outcomes[$result->contractId][$result->consumptionKwh] = $result->outcome;
            }
        }

        $added = [];
        $compared = ['matched' => 0, 'differences' => []];
        foreach ($contracts as $contractId => $contract) {
            $snapshot = $this->statistics->canonicalSnapshot($contract, $outcomes[$contractId] ?? []);
            if (! $stored->has($contractId)) {
                if ($snapshot === null) {
                    $skipped[$contractId] = 'no_numeric_price';
                } else {
                    $added[$contractId] = $snapshot;
                }

                continue;
            }
            $differences = $this->differences($stored->get($contractId), $snapshot);
            if ($differences === []) {
                $compared['matched']++;
            } else {
                $compared['differences'][$contractId] = $differences;
            }
        }
        ksort($added);
        ksort($skipped);

        return ['added' => $added, 'skipped' => $skipped, 'compared' => $compared];
    }

    /**
     * Insert the planned snapshots, rebuild the unit and legacy aggregates of the date, replace
     * its v3 annual rows from the complete date, and rebuild its seller-set index rows. The index
     * writer reads eligibility and family from contract rows, so it is skipped when any of them
     * has drifted from the date's snapshot. A dry run rolls everything back.
     *
     * @param  array<string, array<string, mixed>>  $added
     * @return array{snapshots: int, daily_statistics: int, annual_rows: int, aggregates: int, index_rows: int|null, index_drift: list<string>, medians: array<string, array{0: float|null, 1: float|null}>}
     */
    public function apply(CarbonImmutable $date, array $added, bool $dryRun): array
    {
        $dateString = $date->toDateString();
        if ($dateString >= CarbonImmutable::now(self::TIMEZONE)->toDateString()) {
            throw new RuntimeException('Repair accepts only dates before today. Use contracts:calculate-price-statistics for today.');
        }
        if (ContractPriceDailyStatistic::activeAnnualMethodVersion() !== self::METHOD) {
            throw new RuntimeException('Repair requires the active annual method annual_cost_as_of_v3.');
        }

        $before = $this->medians($dateString);
        DB::beginTransaction();
        try {
            foreach ($added as $contractId => $attributes) {
                ContractPriceSnapshot::query()->create(['snapshot_date' => $dateString, 'contract_id' => $contractId, ...$attributes]);
            }
            $daily = $this->statistics->recalculateDailyStatistics($dateString, ContractPriceBasis::CanonicalCalculation);
            $summary = $this->writer->write($date, $this->calculator->calculate($date, self::METHOD), self::METHOD);
            $drift = $this->index->contractFieldDrift($dateString);
            $indexRows = $drift === [] ? $this->index->writeForDate($dateString) : null;
            $after = $this->medians($dateString);
            $dryRun ? DB::rollBack() : DB::commit();
        } catch (\Throwable $exception) {
            DB::rollBack();

            throw $exception;
        }

        $medians = [];
        foreach (array_unique([...array_keys($before), ...array_keys($after)]) as $key) {
            $medians[$key] = [$before[$key] ?? null, $after[$key] ?? null];
        }
        ksort($medians);

        return [
            'snapshots' => count($added),
            'daily_statistics' => $daily,
            'annual_rows' => $summary->persistedCount,
            'aggregates' => $summary->aggregateCount,
            'index_rows' => $indexRows,
            'index_drift' => $drift,
            'medians' => $medians,
        ];
    }

    /** The snapshot row shape that the v3 resolver reads, for a contract without a snapshot. */
    private function identityRow(CarbonImmutable $date, ElectricityContract $contract, string $segment): object
    {
        $inRange = fn (int $kwh): ?int => $contract->isConsumptionInRange($kwh) ? 1 : null;

        return (object) [
            'id' => null,
            'snapshot_date' => $date->toDateString(),
            'contract_id' => (string) $contract->id,
            'company_name' => $contract->company_name,
            'contract_name' => $contract->name,
            'pricing_model' => $contract->pricing_model,
            'contract_type' => $contract->contract_type,
            'fixed_time_range' => $contract->fixed_time_range,
            'metering' => $contract->metering,
            'segment_key' => $segment,
            'pricing_basis' => ContractPriceBasis::CanonicalCalculation->value,
            'has_discount' => null,
            'includes_spot_price' => null,
            'annual_cost_2000_kwh' => $inRange(2000),
            'annual_cost_5000_kwh' => $inRange(5000),
            'annual_cost_18000_kwh' => $inRange(18000),
        ];
    }

    /**
     * @param  array<string, mixed>|null  $rebuilt
     * @return array<string, array{0: mixed, 1: mixed}>
     */
    private function differences(ContractPriceSnapshot $stored, ?array $rebuilt): array
    {
        $differences = [];
        foreach (self::COMPARED_FIELDS as $field) {
            $old = $stored->getAttribute($field);
            $new = $rebuilt[$field] ?? null;
            $same = is_numeric($old) && is_numeric($new) && ! is_bool($old)
                ? abs((float) $old - (float) $new) < 0.0001
                : $old == $new;
            if (! $same) {
                $differences[$field] = [$old, $new];
            }
        }

        return $differences;
    }

    /** @return array<string, float|null> */
    private function medians(string $dateString): array
    {
        return ContractPriceDailyStatistic::query()
            ->whereDate('stat_date', $dateString)
            ->whereIn('metric_key', ['energy_price', 'annual_cost', SellerSetEnergyPriceIndexService::METRIC_KEY])
            ->whereIn('method_version', [ContractPriceDailyStatistic::UNIT_STATISTICS_METHOD_VERSION, self::METHOD->value])
            ->where(fn ($query) => $query->whereNull('consumption_kwh')->orWhere('consumption_kwh', 5000))
            ->get()
            ->mapWithKeys(function (ContractPriceDailyStatistic $row): array {
                // The index stores its value as the average of supplier medians.
                $value = $row->metric_key === SellerSetEnergyPriceIndexService::METRIC_KEY ? $row->avg_value : $row->median_value;

                return [$row->method_version.'|'.$row->metric_key.'|'.$row->segment_key => $value === null ? null : round((float) $value, 4)];
            })
            ->all();
    }
}
