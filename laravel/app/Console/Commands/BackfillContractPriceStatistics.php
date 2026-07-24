<?php

namespace App\Console\Commands;

use App\Services\ContractStatistics\ContractPriceStatisticsService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class BackfillContractPriceStatistics extends Command
{
    protected $signature = 'contracts:backfill-price-statistics
                            {--from= : First price date to backfill, inclusive}
                            {--to= : Last price date to backfill, inclusive}
                            {--overwrite : Recalculate existing snapshots/statistics}
                            {--limit= : Maximum number of dates to process}';

    protected $description = 'Backfill daily contract price statistics from historical price component dates';

    public function handle(ContractPriceStatisticsService $statistics): int
    {
        $from = $this->option('from') ? Carbon::parse($this->option('from'))->toDateString() : null;
        $to = $this->option('to') ? Carbon::parse($this->option('to'))->toDateString() : null;
        $limit = $this->option('limit') !== null ? (int) $this->option('limit') : null;

        $dates = $statistics->availablePriceComponentDates($from, $to);
        if ($limit !== null && $limit > 0) {
            $dates = $dates->take($limit);
        }

        if ($dates->isEmpty()) {
            $this->warn('No price component dates found for the selected range.');
            return self::SUCCESS;
        }

        $this->info(sprintf('Backfilling contract price statistics for %d date(s)...', $dates->count()));
        $bar = $this->output->createProgressBar($dates->count());
        $bar->start();

        $totalSnapshots = 0;
        $totalStatistics = 0;

        foreach ($dates as $date) {
            $contractIds = $statistics->contractIdsWithPricesForDate($date);
            $result = $statistics->calculateForDate(
                date: $date,
                contractIds: $contractIds,
                overwrite: (bool) $this->option('overwrite'),
                // Historical backfills never use today's canonical interpretation.
                useCanonical: false,
            );

            $totalSnapshots += $result['snapshots'];
            $totalStatistics += $result['statistics'];
            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);
        $this->info(sprintf(
            'Done. Snapshots: %d, daily statistic rows: %d.',
            $totalSnapshots,
            $totalStatistics,
        ));

        return self::SUCCESS;
    }
}
