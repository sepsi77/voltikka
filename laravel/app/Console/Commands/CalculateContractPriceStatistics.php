<?php

namespace App\Console\Commands;

use App\Models\ActiveContract;
use App\Services\ContractStatistics\ContractPriceStatisticsService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class CalculateContractPriceStatistics extends Command
{
    protected $signature = 'contracts:calculate-price-statistics
                            {--date= : Date to calculate, defaults to today}
                            {--overwrite : Recalculate existing snapshots/statistics for the date}';

    protected $description = 'Calculate daily contract price statistics from active contracts';

    public function handle(ContractPriceStatisticsService $statistics): int
    {
        $date = $this->option('date')
            ? Carbon::parse($this->option('date'))->toDateString()
            : Carbon::now('Europe/Helsinki')->toDateString();

        $contractIds = ActiveContract::query()->pluck('id');

        if ($contractIds->isEmpty()) {
            $this->warn('No active contracts found.');
            return self::SUCCESS;
        }

        $this->info("Calculating contract price statistics for {$date} from {$contractIds->count()} active contracts...");

        $result = $statistics->calculateForDate(
            date: $date,
            contractIds: $contractIds,
            overwrite: (bool) $this->option('overwrite'),
        );

        $this->info(sprintf(
            'Done. Snapshots: %d, daily statistic rows: %d.',
            $result['snapshots'],
            $result['statistics'],
        ));

        return self::SUCCESS;
    }
}
