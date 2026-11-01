<?php

namespace App\Console\Commands;

use App\Services\Fingrid\FingridHourlyImporter;
use App\Services\Fingrid\FingridOpenDataService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Throwable;

/**
 * Collect Fingrid open-data series (config/fingrid.php) as hourly means for market reviews.
 *
 * Without --from: the daily window (real-time series: last 3 days; Datahub: last 16 days,
 * because Datahub arrives 4 days late and is corrected for 11 days). With --from/--to: a
 * backfill in 7-day pieces. Both end at the start of the current UTC hour, so a partial
 * hour is never stored. Read-only towards Fingrid; upserts locally.
 */
class FetchFingridData extends Command
{
    protected $signature = 'fingrid:fetch
                            {--from= : Backfill start date (YYYY-MM-DD, UTC)}
                            {--to= : Backfill end date, inclusive (YYYY-MM-DD, UTC; default today)}
                            {--dataset=* : Only these dataset IDs (default: all configured)}';

    protected $description = 'Fetch Fingrid open data as hourly values for market reviews';

    private const BACKFILL_CHUNK_DAYS = 7;

    public function __construct(
        private readonly FingridOpenDataService $client,
        private readonly FingridHourlyImporter $importer
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $configured = (array) config('fingrid.datasets', []);
        $requested = array_map('intval', (array) $this->option('dataset'));
        $unknown = array_diff($requested, array_keys($configured));

        if ($unknown !== []) {
            $this->error('Unknown dataset ID(s): '.implode(', ', $unknown));

            return Command::FAILURE;
        }

        $datasetIds = $requested !== [] ? $requested : array_keys($configured);
        $currentHour = Carbon::now('UTC')->startOfHour();

        try {
            $backfillStart = $this->option('from') !== null
                ? Carbon::createFromFormat('!Y-m-d', (string) $this->option('from'), 'UTC')
                : null;
            $backfillEnd = $this->option('to') !== null
                ? Carbon::createFromFormat('!Y-m-d', (string) $this->option('to'), 'UTC')->addDay()
                : $currentHour->copy();
        } catch (Throwable) {
            $this->error('Invalid date. Use YYYY-MM-DD.');

            return Command::FAILURE;
        }

        if ($backfillEnd->greaterThan($currentHour)) {
            $backfillEnd = $currentHour->copy();
        }

        $failed = [];

        foreach ($datasetIds as $datasetId) {
            $isDatahub = ($configured[$datasetId]['group'] ?? null) === 'datahub';

            if ($backfillStart !== null) {
                $windows = $this->chunks($backfillStart, $backfillEnd);
            } else {
                $lookback = (int) config($isDatahub ? 'fingrid.datahub_lookback_days' : 'fingrid.realtime_lookback_days');
                $windows = [[$currentHour->copy()->subDays($lookback), $currentHour->copy()]];
            }

            $written = 0;
            try {
                foreach ($windows as [$start, $end]) {
                    $rows = $this->client->fetchRows($datasetId, $start, $end);
                    $written += $this->importer->import($datasetId, $rows, Carbon::now('UTC'));
                }
                $this->info("Dataset {$datasetId} ({$configured[$datasetId]['name']}): {$written} hourly rows.");
            } catch (Throwable $e) {
                $failed[] = $datasetId;
                // The exception message can contain the request URL; log only the class.
                logger()->error('Fingrid fetch failed', [
                    'dataset_id' => $datasetId,
                    'exception_class' => $e::class,
                ]);
                $this->error("Dataset {$datasetId}: fetch failed (".$e::class.').');
            }
        }

        return $failed === [] ? Command::SUCCESS : Command::FAILURE;
    }

    /**
     * @return list<array{0: Carbon, 1: Carbon}>
     */
    private function chunks(Carbon $start, Carbon $end): array
    {
        $chunks = [];
        $cursor = $start->copy();

        while ($cursor->lessThan($end)) {
            $chunkEnd = $cursor->copy()->addDays(self::BACKFILL_CHUNK_DAYS);
            if ($chunkEnd->greaterThan($end)) {
                $chunkEnd = $end->copy();
            }
            $chunks[] = [$cursor->copy(), $chunkEnd];
            $cursor = $chunkEnd;
        }

        return $chunks;
    }
}
