<?php

namespace App\Console\Commands;

use App\Services\Syke\SykeHydrologyImporter;
use App\Services\Syke\SykeHydrologyService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Throwable;

/**
 * Collect Syke areal hydrology series (config/syke.php) as slow spot model features.
 *
 * Without --from/--to: the daily window, the last syke.daily_lookback_days days through today.
 * With --from and/or --to: a backfill (--from defaults to syke.backfill_start, --to to today).
 * Dates are Finnish calendar dates (the Syke Aika value). One paged OData query per series;
 * read-only towards Syke; upserts locally.
 */
class FetchSykeHydrology extends Command
{
    protected $signature = 'syke:fetch
                            {--from= : Backfill start date (YYYY-MM-DD; default config syke.backfill_start)}
                            {--to= : Backfill end date, inclusive (YYYY-MM-DD; default today)}
                            {--series=* : Only these series (default: all configured)}';

    protected $description = 'Fetch Syke areal precipitation and snow water equivalent for spot model features';

    public function __construct(
        private readonly SykeHydrologyService $client,
        private readonly SykeHydrologyImporter $importer
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $configured = (array) config('syke.series', []);
        $requested = array_map('strval', (array) $this->option('series'));
        $unknown = array_diff($requested, array_keys($configured));

        if ($unknown !== []) {
            $this->error('Unknown series: '.implode(', ', $unknown));

            return Command::FAILURE;
        }

        $seriesKeys = $requested !== [] ? $requested : array_keys($configured);
        $today = Carbon::now('Europe/Helsinki')->startOfDay();
        $isBackfill = $this->option('from') !== null || $this->option('to') !== null;

        $from = $isBackfill
            ? $this->date((string) ($this->option('from') ?? config('syke.backfill_start')))
            : $today->copy()->subDays((int) config('syke.daily_lookback_days', 90));
        $to = $this->option('to') !== null ? $this->date((string) $this->option('to')) : $today->copy();

        if ($from === null || $to === null) {
            $this->error('Invalid date. Use YYYY-MM-DD.');

            return Command::FAILURE;
        }

        // Exclusive end: the day after --to (or after today).
        $to->addDay();

        if (! $from->lessThan($to)) {
            $this->error('--from must not be after --to.');

            return Command::FAILURE;
        }

        $failed = [];

        foreach ($seriesKeys as $series) {
            $definition = $configured[$series];
            $placeIds = array_map('intval', array_keys((array) $definition['places']));
            $jaksoId = isset($definition['jakso_id']) ? (int) $definition['jakso_id'] : null;

            try {
                $rows = $this->client->fetchRows((string) $definition['entity'], $jaksoId, $placeIds, $from, $to);
                $written = $this->importer->import($series, $jaksoId, $placeIds, $rows, Carbon::now('UTC'));
                $this->info("Series {$series}: {$written} rows (".count($rows).' received).');
            } catch (Throwable $e) {
                $failed[] = $series;
                // The exception message can contain the request URL; log only the class.
                logger()->error('Syke fetch failed', [
                    'series' => $series,
                    'exception_class' => $e::class,
                ]);
                $this->error("Series {$series}: fetch failed (".$e::class.').');
            }
        }

        return $failed === [] ? Command::SUCCESS : Command::FAILURE;
    }

    /** Strict YYYY-MM-DD: Carbon would roll 2026-13-01 over to 2027-01-01. */
    private function date(string $value): ?Carbon
    {
        try {
            $date = Carbon::createFromFormat('!Y-m-d', $value, 'Europe/Helsinki');
        } catch (Throwable) {
            return null;
        }

        return $date->format('Y-m-d') === $value ? $date : null;
    }
}
