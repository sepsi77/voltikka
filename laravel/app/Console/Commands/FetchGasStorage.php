<?php

namespace App\Console\Commands;

use App\Services\GasStorage\GasStorageImporter;
use App\Services\GasStorage\GieAgsiService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Throwable;

/**
 * Collect daily EU and German gas storage levels from GIE AGSI+ for market reviews.
 *
 * Without --from: the last `gas_storage.lookback_days` gas days, because GIE confirms estimated
 * days later. With --from/--to: a backfill (AGSI paginates, 300 days per call). Read-only
 * towards GIE; upserts locally. Needs GIE_API_KEY.
 */
class FetchGasStorage extends Command
{
    protected $signature = 'gas-storage:fetch
                            {--from= : Backfill start gas day (YYYY-MM-DD)}
                            {--to= : Backfill end gas day, inclusive (YYYY-MM-DD; default today)}
                            {--area=* : Only these configured areas, for example eu or de}';

    protected $description = 'Fetch GIE AGSI+ gas storage levels for market reviews';

    public function __construct(
        private readonly GieAgsiService $client,
        private readonly GasStorageImporter $importer
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        if ((string) config('gas_storage.api_key') === '') {
            $this->error('GIE_API_KEY is not set. Register for a free key at https://agsi.gie.eu/account.');

            return Command::FAILURE;
        }

        $configured = (array) config('gas_storage.areas', []);
        $requested = array_map('strtolower', (array) $this->option('area'));
        $unknown = array_diff($requested, array_keys($configured));

        if ($unknown !== []) {
            $this->error('Unknown area(s): '.implode(', ', $unknown));

            return Command::FAILURE;
        }

        try {
            $to = $this->option('to') !== null
                ? Carbon::createFromFormat('!Y-m-d', (string) $this->option('to'), 'UTC')
                : Carbon::now('UTC')->startOfDay();
            $from = $this->option('from') !== null
                ? Carbon::createFromFormat('!Y-m-d', (string) $this->option('from'), 'UTC')
                : $to->copy()->subDays((int) config('gas_storage.lookback_days', 14));
        } catch (Throwable) {
            $this->error('Invalid date. Use YYYY-MM-DD.');

            return Command::FAILURE;
        }

        if ($from->greaterThan($to)) {
            $this->error('--from must not be after --to.');

            return Command::FAILURE;
        }

        $failed = [];

        foreach ($requested !== [] ? $requested : array_keys($configured) as $area) {
            try {
                $rows = $this->client->fetchDays((array) $configured[$area]['params'], $from->toDateString(), $to->toDateString());
                $written = $this->importer->import($area, $rows, Carbon::now('UTC'));
                $this->info("Area {$area}: {$written} gas days.");
            } catch (Throwable $e) {
                $failed[] = $area;
                // The exception message can contain the request URL; log only the class.
                logger()->error('GIE gas storage fetch failed', [
                    'area' => $area,
                    'exception_class' => $e::class,
                ]);
                $this->error("Area {$area}: fetch failed (".$e::class.').');
            }
        }

        return $failed === [] ? Command::SUCCESS : Command::FAILURE;
    }
}
