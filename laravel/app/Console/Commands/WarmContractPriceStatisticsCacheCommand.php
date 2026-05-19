<?php

namespace App\Console\Commands;

use App\Jobs\WarmContractPriceStatisticsCache;
use Illuminate\Console\Command;

class WarmContractPriceStatisticsCacheCommand extends Command
{
    protected $signature = 'contracts:warm-price-statistics-cache
                            {--period=* : Periods to warm: weekly, monthly, daily}
                            {--consumption=* : Consumptions to warm: 2000, 5000, 18000}
                            {--sync : Warm immediately instead of dispatching queue jobs}';

    protected $description = 'Warm prepared view-data cache for the contract price statistics page';

    /** @var array<string,true> */
    private array $allowedPeriods = [
        'daily' => true,
        'weekly' => true,
        'monthly' => true,
    ];

    /** @var array<int,true> */
    private array $allowedConsumptions = [
        2000 => true,
        5000 => true,
        18000 => true,
    ];

    public function handle(): int
    {
        $periods = $this->normalisePeriods($this->option('period'));
        $consumptions = $this->normaliseConsumptions($this->option('consumption'));

        foreach ($periods as $period) {
            foreach ($consumptions as $consumption) {
                $job = new WarmContractPriceStatisticsCache($period, $consumption);

                if ($this->option('sync')) {
                    $this->info("Warming contract price statistics cache now: period={$period}, consumption={$consumption}");
                    dispatch_sync($job);
                } else {
                    $this->info("Queueing contract price statistics cache warm: period={$period}, consumption={$consumption}");
                    dispatch($job);
                }
            }
        }

        $this->info('Contract price statistics cache warm request completed.');

        return self::SUCCESS;
    }

    /**
     * @param array<int,string> $periods
     * @return array<int,string>
     */
    private function normalisePeriods(array $periods): array
    {
        $periods = $periods === [] ? ['weekly'] : $periods;

        return collect($periods)
            ->map(fn (string $period) => strtolower(trim($period)))
            ->filter(fn (string $period) => isset($this->allowedPeriods[$period]))
            ->unique()
            ->values()
            ->all() ?: ['weekly'];
    }

    /**
     * @param array<int,string|int> $consumptions
     * @return array<int,int>
     */
    private function normaliseConsumptions(array $consumptions): array
    {
        $consumptions = $consumptions === [] ? [5000] : $consumptions;

        return collect($consumptions)
            ->map(fn (string|int $consumption) => (int) $consumption)
            ->filter(fn (int $consumption) => isset($this->allowedConsumptions[$consumption]))
            ->unique()
            ->values()
            ->all() ?: [5000];
    }
}
