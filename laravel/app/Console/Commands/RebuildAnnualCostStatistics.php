<?php

namespace App\Console\Commands;

use App\Models\ContractPriceDailyStatistic;
use App\Services\ContractStatistics\AnnualCostStatisticsWriter;
use App\Services\ContractStatistics\AsOfAnnualCostCalculator;
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
                            {--apply : Replace annual_cost_as_of_v1 rows; default is dry run}
                            {--stop-on-error : Stop after the first failed date}';

    protected $description = 'Preview or rebuild annual_cost_as_of_v1 from date-bounded historical evidence';

    public function handle(
        AsOfAnnualCostCalculator $calculator,
        AnnualCostStatisticsWriter $writer,
    ): int {
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

        $dates = $this->evidenceDates($from, $to);

        if ($dates->isEmpty()) {
            $this->warn('No contract price snapshot or component dates were found for the selected range.');

            return self::SUCCESS;
        }

        $this->info(sprintf(
            '%s annual_cost_as_of_v1 for %d historical snapshot date(s).',
            $apply ? 'Applying' : 'Dry run:',
            $dates->count(),
        ));

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
                $results = $calculator->calculate($date);
                $results = $this->filterResults($results, $contractFilter, $limit === false ? null : $limit);
                $summary = $apply
                    ? $writer->write($date, $results)
                    : $writer->preview($date, $results);
                $deltas = $this->medianDeltas($date, $summary);

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
                $this->line('  unavailable reasons: '.$this->boundedCounts($summary->basisCounts['unavailable_reasons']));
                if ($deltas !== []) {
                    $this->line(sprintf(
                        '  old/new median deltas: n=%d min=%+.2f EUR max=%+.2f EUR',
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
    private function evidenceDates(?CarbonImmutable $from, CarbonImmutable $to): Collection
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

        return $snapshotDates
            ->merge($componentDates)
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

    /**
     * @param  list<AsOfAnnualCostResult>  $results
     * @param  Collection<int, string>  $contractFilter
     * @return list<AsOfAnnualCostResult>
     */
    private function filterResults(array $results, Collection $contractFilter, ?int $limit): array
    {
        $selected = collect($results);
        if ($contractFilter->isNotEmpty()) {
            $selected = $selected->filter(
                fn (AsOfAnnualCostResult $result): bool => $contractFilter->contains($result->contractId),
            );
        }
        if ($limit !== null) {
            $contractIds = $selected->pluck('contractId')->unique()->sort()->take($limit);
            $selected = $selected->filter(
                fn (AsOfAnnualCostResult $result): bool => $contractIds->contains($result->contractId),
            );
        }

        return $selected
            ->sortBy(fn (AsOfAnnualCostResult $result): string => $result->contractId.'|'.str_pad((string) $result->consumptionKwh, 5, '0', STR_PAD_LEFT))
            ->values()
            ->all();
    }

    /** @return list<float> */
    private function medianDeltas(string $date, AnnualCostStatisticsDateSummary $summary): array
    {
        $legacy = ContractPriceDailyStatistic::query()
            ->whereDate('stat_date', $date)
            ->where('metric_key', 'annual_cost')
            ->where('method_version', AnnualCostMethodVersion::Legacy->value)
            ->get(['segment_key', 'consumption_kwh', 'median_value'])
            ->keyBy(fn (ContractPriceDailyStatistic $row): string => $row->segment_key.'|'.$row->consumption_kwh);
        $deltas = [];

        foreach ($summary->aggregates as $aggregate) {
            $old = $legacy->get($aggregate->segmentKey.'|'.$aggregate->consumptionKwh)?->median_value;
            if ($old === null || ! is_finite((float) $old) || ! is_finite($aggregate->median)) {
                continue;
            }
            $deltas[] = $aggregate->median - (float) $old;
        }

        return $deltas;
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
