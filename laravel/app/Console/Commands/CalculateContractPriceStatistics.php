<?php

namespace App\Console\Commands;

use App\Models\ActiveContract;
use App\Services\ContractStatistics\ContractPriceStatisticsService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

class CalculateContractPriceStatistics extends Command
{
    protected $signature = 'contracts:calculate-price-statistics
                            {--date= : Date to calculate, defaults to today}
                            {--overwrite : Recalculate existing snapshots/statistics for the date}';

    protected $description = 'Calculate daily contract price statistics from active contracts';

    public function handle(ContractPriceStatisticsService $statistics): int
    {
        $today = CarbonImmutable::now('Europe/Helsinki')->startOfDay();
        if ($this->option('date') !== null) {
            $date = $this->parseDate((string) $this->option('date'));
            if ($date === null) {
                $this->error('The --date value must be a valid date in YYYY-MM-DD format.');

                return self::FAILURE;
            }
            if (! $date->equalTo($today)) {
                $this->error('This current-state command accepts only today. Use contracts:rebuild-annual-cost-statistics for historical annual costs.');

                return self::FAILURE;
            }
            $date = $date->toDateString();
        } else {
            $date = $today->toDateString();
        }

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

        $this->info('Queueing contract price statistics page cache warm...');
        $this->call('contracts:warm-price-statistics-cache', [
            '--period' => ['weekly'],
            '--consumption' => [5000],
        ]);

        return self::SUCCESS;
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
}
