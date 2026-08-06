<?php

namespace App\Console\Commands;

use App\Models\SpotPriceHour;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class CheckSpotPriceFreshness extends Command
{
    protected $signature = 'spot:check-freshness';

    protected $description = 'Check that official FI Spot prices cover the current Helsinki hour';

    public function handle(): int
    {
        $currentHourUtc = Carbon::now('Europe/Helsinki')->startOfHour()->utc();
        $latestValue = SpotPriceHour::query()
            ->forRegion('FI')
            ->max('utc_datetime');
        $latestHourUtc = $latestValue === null
            ? null
            : Carbon::parse($latestValue, 'UTC')->utc();

        if ($latestHourUtc === null || $latestHourUtc->lt($currentHourUtc)) {
            $context = [
                'current_hour_utc' => $currentHourUtc->format('Y-m-d\TH:i:s\Z'),
                'latest_hour_utc' => $latestHourUtc?->format('Y-m-d\TH:i:s\Z'),
                'lag_minutes' => $latestHourUtc === null
                    ? null
                    : (int) floor(($currentHourUtc->timestamp - $latestHourUtc->timestamp) / 60),
            ];

            Log::error('Official FI Spot price data is stale.', $context);
            $this->error('Official FI Spot price data is stale.');

            return Command::FAILURE;
        }

        $this->info('Official FI Spot price data is current.');

        return Command::SUCCESS;
    }
}
