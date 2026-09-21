<?php

namespace App\Console\Commands;

use App\Models\ContractPriceAnnualCost;
use App\Models\ContractPriceDailyStatistic;
use App\Models\ContractPriceSnapshot;
use App\Services\ContractStatistics\AnnualCostStatisticsWriter;
use App\Services\ContractStatistics\AsOfAnnualCostCalculator;
use App\Services\ContractStatistics\DTO\AnnualCostAggregateSummary;
use App\Services\ContractStatistics\DTO\AnnualCostStatisticsDateSummary;
use App\Services\ContractStatistics\DTO\AsOfAnnualCostResult;
use App\Services\ContractStatistics\Enums\AnnualCostMethodVersion;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

class RebuildAnnualCostStatistics extends Command
{
    protected $signature = 'contracts:rebuild-annual-cost-statistics
                            {--date= : One historical snapshot date}
                            {--from= : First historical snapshot date, inclusive}
                            {--to= : Last historical snapshot date, inclusive}
                            {--contract=* : Include only these contract IDs}
                            {--limit= : Deterministic contract-ID limit per date}
                            {--method=annual_cost_as_of_v2 : Candidate annual method}
                            {--baseline= : Stored baseline method; defaults to the active public method}
                            {--apply : Replace only the selected v2 or v3 method rows; default is dry run}
                            {--stop-on-error : Stop after the first failed date}';

    protected $description = 'Preview or rebuild versioned annual costs from date-bounded historical evidence';

    public function handle(
        AsOfAnnualCostCalculator $calculator,
        AnnualCostStatisticsWriter $writer,
    ): int {
        $method = AnnualCostMethodVersion::tryFrom((string) $this->option('method'));
        $baseline = $this->option('baseline') === null
            ? ContractPriceDailyStatistic::activeAnnualMethodVersion()
            : AnnualCostMethodVersion::tryFrom((string) $this->option('baseline'));
        if ($method === null || ! $method->isAsOf() || $baseline === null) {
            $this->error('Unknown or invalid annual method. The target must be an AsOf method.');

            return self::FAILURE;
        }
        if ($this->option('apply') && ! $method->usesReconstructionSafety()) {
            $this->error('Historical correction apply requires annual_cost_as_of_v2 or annual_cost_as_of_v3. Stored v1 must remain unchanged.');

            return self::FAILURE;
        }
        if ($method === AnnualCostMethodVersion::AsOfV3) {
            $this->warn('V3 is not ready for release: full-history target-evidence and continuity review, dated current-producer parity, verified backup, apply approval and active-method approval remain required.');
        }
        $selection = $this->dateSelection();
        if ($selection === null) {
            return self::FAILURE;
        }

        [$from, $to] = $selection;
        $contractFilter = collect((array) $this->option('contract'))
            ->map(fn ($id): string => trim((string) $id))
            ->filter()
            ->unique()
            ->sort()
            ->values();
        $limit = $this->contractLimit();
        if ($limit === false) {
            return self::FAILURE;
        }

        $apply = (bool) $this->option('apply');
        if ($apply && ($contractFilter->isNotEmpty() || $limit !== null)) {
            $this->error('--contract and --limit are dry-run diagnostics. A partial apply could delete unselected annual rows for the date.');

            return self::FAILURE;
        }

        $dates = $this->evidenceDates($from, $to, $baseline);

        if ($dates->isEmpty()) {
            $this->warn('No historical evidence or stored baseline dates were found for the selected range.');

            return $apply ? self::FAILURE : self::SUCCESS;
        }

        $this->info(sprintf(
            '%s %s against stored %s for %d historical snapshot date(s).',
            $apply ? 'Applying' : 'Dry run:',
            $method->value,
            $baseline->value,
            $dates->count(),
        ));
        if ($method === AnnualCostMethodVersion::AsOf) {
            $this->warn('Recalculated v1 is diagnostic only. It does not reproduce frozen original v1 mathematics.');
        }
        $coverageTotals = [];
        $deltaCount = 0;
        $deltaMinimum = null;
        $deltaMaximum = null;
        $missingBaselineDates = 0;

        $totals = [
            'dates' => 0,
            'failed' => 0,
            'evidence' => 0,
            'available' => 0,
            'unavailable' => 0,
            'persisted' => 0,
            'aggregates' => 0,
            'estimate_methods' => [],
            'unavailable_reasons' => [],
        ];

        foreach ($dates as $date) {
            try {
                // Read the stored baseline before any write, including baseline=target.
                $baselineRows = $this->baselineRows($date, $baseline);
                $results = $calculator->calculate($date, $method);
                $universe = collect($results)->pluck('contractId')->merge($baselineRows->pluck('contract_id'))->unique()->sort()->values();
                if ($contractFilter->isNotEmpty()) {
                    $universe = $universe->intersect($contractFilter)->values();
                }
                if ($limit !== null) {
                    $universe = $universe->take($limit);
                }
                $results = array_values(array_filter($results, fn (AsOfAnnualCostResult $result): bool => $universe->contains($result->contractId)));
                $baselineRows = $baselineRows->whereIn('contract_id', $universe->all());
                $summary = $writer->preview($date, $results, $method);
                $comparison = $this->compareBaseline($date, $baseline, $baselineRows, $results, $summary, $contractFilter->isNotEmpty() || $limit !== null);
                $deltas = $comparison['deltas'];
                $this->mergeCounts($coverageTotals, $comparison['coverage']);
                $missingBaselineDates += $comparison['has_baseline'] ? 0 : 1;
                if ($deltas !== []) {
                    $deltaCount += count($deltas);
                    $deltaMinimum = min($deltaMinimum ?? min($deltas), min($deltas));
                    $deltaMaximum = max($deltaMaximum ?? max($deltas), max($deltas));
                }
                $this->line($date.' contract-consumption coverage: '.$this->boundedCounts($comparison['coverage']));
                $this->line('  baseline: '.($comparison['has_baseline'] ? 'stored evidence' : 'NO BASELINE').' unavailable='.($baseline->isAsOf() ? 'not stored' : (string) $baselineRows->whereNull('annual_cost')->count()));
                if ($apply) {
                    $summary = $writer->write($date, $results, $method);
                }

                $totals['dates']++;
                $totals['evidence'] += $summary->evidenceResultCount;
                $totals['available'] += $summary->availableCount;
                $totals['unavailable'] += $summary->unavailableCount;
                $totals['persisted'] += $summary->persistedCount;
                $totals['aggregates'] += $summary->aggregateCount;
                $this->mergeCounts($totals['estimate_methods'], $summary->basisCounts['estimate_method']);
                $this->mergeCounts($totals['unavailable_reasons'], $summary->basisCounts['unavailable_reasons']);

                $this->line(sprintf(
                    '%s evidence=%d available=%d unavailable=%d%s',
                    $date,
                    $summary->evidenceResultCount,
                    $summary->availableCount,
                    $summary->unavailableCount,
                    $apply ? sprintf(' persisted=%d aggregates=%d', $summary->persistedCount, $summary->aggregateCount) : '',
                ));
                $this->line('  estimate methods: '.$this->boundedCounts($summary->basisCounts['estimate_method']));
                $this->line('  pricing basis: '.$this->boundedCounts($summary->basisCounts['pricing_basis']));
                $this->line('  calculation basis: '.$this->boundedCounts($summary->basisCounts['calculation_basis']));
                $this->line('  estimate basis: '.$this->boundedCounts($summary->basisCounts['estimate_basis']));
                $flags = [];
                foreach ($results as $result) {
                    foreach ($result->provenanceFlags as $flag) {
                        $flags[$flag] = ($flags[$flag] ?? 0) + 1;
                    }
                }
                $this->line('  provenance flags: '.$this->boundedCounts($flags));
                $this->line('  unavailable reasons: '.$this->boundedCounts($summary->basisCounts['unavailable_reasons']));
                if ($deltas !== []) {
                    $this->line(sprintf(
                        '  matched aggregate median deltas: n=%d min=%+.2f EUR max=%+.2f EUR',
                        count($deltas),
                        min($deltas),
                        max($deltas),
                    ));
                }
            } catch (Throwable $exception) {
                $totals['failed']++;
                $message = preg_replace('/\s+/', ' ', $exception->getMessage()) ?: $exception::class;
                $this->error(sprintf('%s failed: %s: %s', $date, $exception::class, mb_substr($message, 0, 240)));

                if ((bool) $this->option('stop-on-error')) {
                    break;
                }
            }
        }

        $this->newLine();
        $this->info(sprintf(
            'Totals: dates=%d failed=%d evidence=%d available=%d unavailable=%d%s',
            $totals['dates'],
            $totals['failed'],
            $totals['evidence'],
            $totals['available'],
            $totals['unavailable'],
            $apply ? sprintf(' persisted=%d aggregates=%d', $totals['persisted'], $totals['aggregates']) : '',
        ));
        $this->line('Coverage totals (compared dates, including failed writes): '.$this->boundedCounts($coverageTotals));
        $this->line('Dates without baseline: '.$missingBaselineDates);
        if ($deltaCount > 0) {
            $this->line(sprintf('Matched median delta totals: n=%d min=%+.2f EUR max=%+.2f EUR', $deltaCount, $deltaMinimum, $deltaMaximum));
        }
        $this->line('Estimate methods: '.$this->boundedCounts($totals['estimate_methods']));
        $this->line('Unavailable reasons: '.$this->boundedCounts($totals['unavailable_reasons']));

        return $totals['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    /** @return array{CarbonImmutable|null, CarbonImmutable}|null */
    private function dateSelection(): ?array
    {
        $dateOption = $this->option('date');
        $fromOption = $this->option('from');
        $toOption = $this->option('to');
        if ($dateOption !== null && ($fromOption !== null || $toOption !== null)) {
            $this->error('--date cannot be combined with --from or --to.');

            return null;
        }

        $today = CarbonImmutable::now('Europe/Helsinki')->startOfDay();
        $date = $dateOption !== null ? $this->parseDate((string) $dateOption) : null;
        $from = $fromOption !== null ? $this->parseDate((string) $fromOption) : null;
        $to = $toOption !== null ? $this->parseDate((string) $toOption) : null;
        if (($dateOption !== null && $date === null)
            || ($fromOption !== null && $from === null)
            || ($toOption !== null && $to === null)) {
            $this->error('Date options must use valid YYYY-MM-DD dates.');

            return null;
        }

        if ($date !== null) {
            $from = $date;
            $to = $date;
        } else {
            $to ??= $today->subDay();
        }

        if (($from !== null && ! $from->isBefore($today)) || ! $to->isBefore($today)) {
            $this->error('The historical rebuild accepts only dates before today. Use contracts:calculate-price-statistics for today.');

            return null;
        }
        if ($from !== null && $from->isAfter($to)) {
            $this->error('--from must not be after --to.');

            return null;
        }

        return [$from, $to];
    }

    private function parseDate(string $value): ?CarbonImmutable
    {
        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $value, 'Europe/Helsinki');
        } catch (Throwable) {
            return null;
        }

        return $date !== false && $date->format('Y-m-d') === $value ? $date : null;
    }

    /** @return Collection<int, string> */
    private function evidenceDates(?CarbonImmutable $from, CarbonImmutable $to, AnnualCostMethodVersion $baseline): Collection
    {
        $snapshotDates = DB::table('contract_price_snapshots')
            ->selectRaw('DATE(snapshot_date) as evidence_date')
            ->when($from !== null, fn ($query) => $query->whereDate('snapshot_date', '>=', $from->toDateString()))
            ->whereDate('snapshot_date', '<=', $to->toDateString())
            ->distinct()
            ->pluck('evidence_date');
        $componentDates = DB::table('price_components')
            ->selectRaw('DATE(price_date) as evidence_date')
            ->when($from !== null, fn ($query) => $query->whereDate('price_date', '>=', $from->toDateString()))
            ->whereDate('price_date', '<=', $to->toDateString())
            ->distinct()
            ->pluck('evidence_date');

        $baselineDates = ContractPriceDailyStatistic::query()
            ->where('metric_key', 'annual_cost')
            ->where('method_version', $baseline->value)
            ->when($from !== null, fn ($query) => $query->whereDate('stat_date', '>=', $from->toDateString()))
            ->whereDate('stat_date', '<=', $to->toDateString())
            ->pluck('stat_date')
            ->map(fn ($date): string => $date->toDateString());
        $annualDates = ContractPriceAnnualCost::query()
            ->where('method_version', $baseline->value)
            ->when($from !== null, fn ($query) => $query->whereDate('snapshot_date', '>=', $from->toDateString()))
            ->whereDate('snapshot_date', '<=', $to->toDateString())
            ->distinct()
            ->pluck('snapshot_date')
            ->map(fn ($date): string => $date->toDateString());

        return $snapshotDates
            ->merge($componentDates)
            ->merge($baselineDates)
            ->merge($annualDates)
            ->map(fn ($date): string => (string) $date)
            ->filter()
            ->unique()
            ->sort()
            ->values();
    }

    private function contractLimit(): int|false|null
    {
        if ($this->option('limit') === null) {
            return null;
        }

        $raw = (string) $this->option('limit');
        if (! ctype_digit($raw) || (int) $raw < 1) {
            $this->error('--limit must be a positive integer.');

            return false;
        }

        return (int) $raw;
    }

    /** @return Collection<int, array{contract_id: string, segment_key: string, consumption_kwh: int, annual_cost: ?float}> */
    private function baselineRows(string $date, AnnualCostMethodVersion $method): Collection
    {
        if ($method->isAsOf()) {
            return ContractPriceAnnualCost::query()
                ->whereDate('snapshot_date', $date)
                ->where('method_version', $method->value)
                ->get(['contract_id', 'segment_key', 'consumption_kwh', 'annual_cost'])
                ->toBase()
                ->map(fn (ContractPriceAnnualCost $row): array => [
                    'contract_id' => (string) $row->contract_id,
                    'segment_key' => $row->segment_key,
                    'consumption_kwh' => (int) $row->consumption_kwh,
                    'annual_cost' => $row->annual_cost === null ? null : (float) $row->annual_cost,
                ]);
        }

        return ContractPriceSnapshot::query()
            ->whereDate('snapshot_date', $date)
            ->get(['contract_id', 'segment_key', 'annual_cost_2000_kwh', 'annual_cost_5000_kwh', 'annual_cost_18000_kwh'])
            ->toBase()
            ->flatMap(function (ContractPriceSnapshot $row): array {
                $rows = [];
                foreach ([2000, 5000, 18000] as $consumption) {
                    $value = $row->{'annual_cost_'.$consumption.'_kwh'};
                    $rows[] = [
                        'contract_id' => (string) $row->contract_id,
                        'segment_key' => $row->segment_key,
                        'consumption_kwh' => $consumption,
                        'annual_cost' => $value === null ? null : (float) $value,
                    ];
                }

                return $rows;
            });
    }

    /**
     * @param  Collection<int, array{contract_id: string, segment_key: string, consumption_kwh: int, annual_cost: ?float}>  $baselineRows
     * @param  list<AsOfAnnualCostResult>  $results
     * @return array{has_baseline: bool, coverage: array<string, int>, deltas: list<float>}
     */
    private function compareBaseline(string $date, AnnualCostMethodVersion $baseline, Collection $baselineRows, array $results, AnnualCostStatisticsDateSummary $summary, bool $partial): array
    {
        $available = $baselineRows->filter(fn (array $row): bool => $row['annual_cost'] !== null && is_finite($row['annual_cost']));
        $oldIdentities = $available->map(fn (array $row): string => $row['contract_id'].'|'.$row['consumption_kwh']);
        $newIdentities = collect($results)->filter->isAvailable()
            ->map(fn (AsOfAnnualCostResult $row): string => $row->contractId.'|'.$row->consumptionKwh);
        $oldMedians = $partial
            ? $available->groupBy(fn (array $row): string => $row['segment_key'].'|'.$row['consumption_kwh'])
                ->map(fn (Collection $rows): float => (float) $rows->median('annual_cost'))
            : ContractPriceDailyStatistic::query()
                ->whereDate('stat_date', $date)
                ->where('metric_key', 'annual_cost')
                ->where('method_version', $baseline->value)
                ->whereNotNull('median_value')
                ->get(['segment_key', 'consumption_kwh', 'median_value'])
                ->toBase()
                ->mapWithKeys(fn (ContractPriceDailyStatistic $row): array => [$row->segment_key.'|'.$row->consumption_kwh => (float) $row->median_value]);
        $newMedians = collect($summary->aggregates)
            ->mapWithKeys(fn (AnnualCostAggregateSummary $row): array => [$row->segmentKey.'|'.$row->consumptionKwh => $row->median]);
        $oldMedians = $oldMedians->filter(fn (float $median): bool => is_finite($median));
        $matched = $oldMedians->keys()->intersect($newMedians->keys());

        return [
            'has_baseline' => $baselineRows->isNotEmpty() || $oldMedians->isNotEmpty(),
            'coverage' => [
                'contracts_matched' => $oldIdentities->intersect($newIdentities)->count(),
                'contracts_new' => $newIdentities->diff($oldIdentities)->count(),
                'contracts_lost' => $oldIdentities->diff($newIdentities)->count(),
                'aggregates_matched' => $matched->count(),
                'aggregates_new' => $newMedians->keys()->diff($oldMedians->keys())->count(),
                'aggregates_lost' => $oldMedians->keys()->diff($newMedians->keys())->count(),
            ],
            'deltas' => $matched->map(fn (string $key): float => $newMedians[$key] - $oldMedians[$key])->values()->all(),
        ];
    }

    /** @param array<string, int> $target
     * @param  array<string, int>  $source
     */
    private function mergeCounts(array &$target, array $source): void
    {
        foreach ($source as $key => $count) {
            $target[$key] = ($target[$key] ?? 0) + $count;
        }
        ksort($target);
    }

    /** @param array<string, int> $counts */
    private function boundedCounts(array $counts): string
    {
        if ($counts === []) {
            return 'none';
        }

        ksort($counts);
        $shown = array_slice($counts, 0, 8, true);
        $text = collect($shown)->map(fn (int $count, string $key): string => $key.'='.$count)->implode(', ');

        return count($counts) > 8 ? $text.', …' : $text;
    }
}
