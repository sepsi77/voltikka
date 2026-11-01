<?php

namespace App\Console\Commands;

use App\Services\Jao\JaoMaxBorderFlowImporter;
use App\Services\Jao\JaoNordicPublicationService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Throwable;

/**
 * Collect JAO Nordic day-ahead maxBorderFlow on the Finnish borders (config/jao.php) as hourly
 * values for market reviews.
 *
 * Without --from: UTC days today - 2 to tomorrow inclusive (tomorrow is published about
 * 10-11 CET). With --from/--to: a backfill. Each 48-hour window is one request and one unit:
 * a failed window does not stop the others, and the run then exits 1. Read-only towards JAO;
 * upserts locally.
 */
class FetchJaoMaxBorderFlows extends Command
{
    protected $signature = 'jao:fetch
                            {--from= : Backfill start date (YYYY-MM-DD, UTC; history from 2024-10-29)}
                            {--to= : Backfill end date, inclusive (YYYY-MM-DD, UTC; default tomorrow)}';

    protected $description = 'Fetch JAO Nordic day-ahead max border flows for the Finnish borders';

    public function __construct(
        private readonly JaoNordicPublicationService $client,
        private readonly JaoMaxBorderFlowImporter $importer
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $today = Carbon::now('UTC')->startOfDay();
        $latestEnd = $today->copy()->addDays((int) config('jao.lookahead_days', 2));

        try {
            $start = $this->option('from') !== null
                ? Carbon::createFromFormat('!Y-m-d', (string) $this->option('from'), 'UTC')
                : $today->copy()->subDays((int) config('jao.lookback_days', 2));
            $end = $this->option('to') !== null
                ? Carbon::createFromFormat('!Y-m-d', (string) $this->option('to'), 'UTC')->addDay()
                : $latestEnd->copy();
        } catch (Throwable) {
            $this->error('Invalid date. Use YYYY-MM-DD.');

            return Command::FAILURE;
        }

        if ($end->greaterThan($latestEnd)) {
            $end = $latestEnd->copy();
        }
        if (! $start->lessThan($end)) {
            $this->error('The start date must be before the end date.');

            return Command::FAILURE;
        }

        $hours = (int) config('jao.max_window_hours', 48);
        $written = 0;
        $failed = 0;
        $cursor = $start->copy();

        while ($cursor->lessThan($end)) {
            $windowEnd = $cursor->copy()->addHours($hours);
            if ($windowEnd->greaterThan($end)) {
                $windowEnd = $end->copy();
            }

            try {
                $rows = $this->client->fetchMaxBorderFlows($cursor, $windowEnd);
                $written += $this->importer->import($rows, Carbon::now('UTC'));
            } catch (Throwable $e) {
                $failed++;
                // The exception message can contain the request URL; log only the class and window.
                logger()->error('JAO maxBorderFlow fetch failed', [
                    'window_start' => $cursor->format('Y-m-d\TH:i:s\Z'),
                    'window_end' => $windowEnd->format('Y-m-d\TH:i:s\Z'),
                    'exception_class' => $e::class,
                ]);
                $this->error("Window {$cursor->toDateString()}: fetch failed (".$e::class.').');
            }

            $cursor = $windowEnd;
        }

        $this->info("JAO maxBorderFlow: {$written} hourly border rows, {$failed} failed window(s).");

        return $failed === 0 ? Command::SUCCESS : Command::FAILURE;
    }
}
