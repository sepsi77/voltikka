<?php

namespace App\Jobs;

use App\Livewire\ContractPriceStatistics;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class WarmContractPriceStatisticsCache implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** Avoid duplicate warm jobs while imports run close together. */
    public int $uniqueFor = 3600;

    /** The statistics payload can be slow on a cold cache. */
    public int $timeout = 300;

    public function __construct(
        public readonly string $period = 'weekly',
        public readonly int $consumption = 5000,
    ) {
    }

    public function uniqueId(): string
    {
        return $this->period . ':' . $this->consumption;
    }

    public function handle(): void
    {
        $startedAt = microtime(true);

        /** @var ContractPriceStatistics $component */
        $component = app(ContractPriceStatistics::class);
        $component->period = $this->period;
        $component->consumption = $this->consumption;

        $component->warmPreparedViewDataCache();

        Log::info('Warmed contract price statistics cache', [
            'period' => $this->period,
            'consumption' => $this->consumption,
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
        ]);
    }
}
