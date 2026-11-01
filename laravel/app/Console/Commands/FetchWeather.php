<?php

namespace App\Console\Commands;

use App\Services\OpenMeteo\OpenMeteoService;
use App\Services\OpenMeteo\OpenMeteoWeatherImporter;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Throwable;

/**
 * Collect Open-Meteo weather (config/open_meteo.php) for market reviews.
 *
 * Without --only: the observed daily window (last 21 local days up to yesterday, ERA5) and the
 * default outlooks (ECMWF 15-day ensemble). --only selects parts: "observed" or an outlook key.
 * With --from/--to: an observed-only backfill in calendar-year pieces with a longer pause
 * between requests. Read-only towards Open-Meteo; writes locally.
 */
class FetchWeather extends Command
{
    protected $signature = 'weather:fetch
                            {--from= : Observed backfill start date (YYYY-MM-DD, Europe/Helsinki local day)}
                            {--to= : Observed backfill end date, inclusive (default yesterday)}
                            {--point=* : Only these point keys (default: all configured)}
                            {--only=* : Only these parts: observed, ecmwf_ens, ecmwf_ec46, ecmwf_seas5}';

    protected $description = 'Fetch Open-Meteo observed weather and outlook vintages for market reviews';

    private const EARLIEST_DATE = '1940-01-01';

    public function __construct(
        private readonly OpenMeteoService $client,
        private readonly OpenMeteoWeatherImporter $importer
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $points = (array) config('open_meteo.points', []);
        $outlooks = (array) config('open_meteo.outlooks', []);

        $requestedPoints = (array) $this->option('point');
        $unknownPoints = array_diff($requestedPoints, array_keys($points));
        if ($unknownPoints !== []) {
            $this->error('Unknown point(s): '.implode(', ', $unknownPoints));

            return Command::FAILURE;
        }
        $pointKeys = $requestedPoints !== [] ? $requestedPoints : array_keys($points);

        $requestedParts = (array) $this->option('only');
        $unknownParts = array_diff($requestedParts, ['observed', ...array_keys($outlooks)]);
        if ($unknownParts !== []) {
            $this->error('Unknown part(s): '.implode(', ', $unknownParts));

            return Command::FAILURE;
        }

        $timezone = (string) config('open_meteo.timezone');
        $yesterday = Carbon::now($timezone)->startOfDay()->subDay();
        $backfill = $this->option('from') !== null;

        if ($backfill) {
            if (array_diff($requestedParts, ['observed']) !== []) {
                $this->error('--from/--to backfills observed data only.');

                return Command::FAILURE;
            }
            $parts = ['observed'];
        } else {
            $defaults = array_keys(array_filter($outlooks, fn ($product) => (bool) ($product['default'] ?? false)));
            $parts = $requestedParts !== [] ? $requestedParts : ['observed', ...$defaults];
        }

        try {
            $from = $backfill
                ? Carbon::createFromFormat('!Y-m-d', (string) $this->option('from'), $timezone)
                : $yesterday->copy()->subDays((int) config('open_meteo.observed_lookback_days', 21) - 1);
            $to = $this->option('to') !== null
                ? Carbon::createFromFormat('!Y-m-d', (string) $this->option('to'), $timezone)
                : $yesterday->copy();
        } catch (Throwable) {
            $this->error('Invalid date. Use YYYY-MM-DD.');

            return Command::FAILURE;
        }

        if ($to->greaterThan($yesterday)) {
            $to = $yesterday->copy();
        }
        if ($from->lessThan(Carbon::createFromFormat('!Y-m-d', self::EARLIEST_DATE, $timezone)) || $from->greaterThan($to)) {
            $this->error('The date range must be within '.self::EARLIEST_DATE.' .. yesterday, with --from before --to.');

            return Command::FAILURE;
        }

        $failed = 0;

        if (in_array('observed', $parts, true)) {
            $delay = (float) config($backfill ? 'open_meteo.backfill_request_delay_seconds' : 'open_meteo.request_delay_seconds');
            $windows = $backfill ? $this->yearChunks($from, $to) : [[$from, $to]];

            foreach ($pointKeys as $point) {
                $written = 0;
                foreach ($windows as [$start, $end]) {
                    try {
                        $payload = $this->client->fetchArchive($points[$point], $start, $end, $delay);
                        $written += $this->importer->importObserved($point, $payload, Carbon::now('UTC'));
                    } catch (Throwable $e) {
                        $failed++;
                        $this->logFailure('observed', $point, $e, $start->format('Y-m-d').'..'.$end->format('Y-m-d'));

                        continue 2; // a failed point must not stop the others
                    }
                }
                $this->info("Observed {$point}: {$written} daily rows.");
            }
        }

        $delay = (float) config('open_meteo.request_delay_seconds');
        foreach ($parts as $part) {
            if ($part === 'observed') {
                continue;
            }

            $product = $outlooks[$part];
            $modelRunAt = $this->client->fetchModelRunAt((string) $product['meta_url'], $delay);

            foreach ($pointKeys as $point) {
                try {
                    $payload = $this->client->fetchOutlook($product, $points[$point], $delay);
                    $stored = $this->importer->storeOutlookRun($part, $product, $point, $payload, $modelRunAt, Carbon::now('UTC'));
                    $this->info("Outlook {$part} {$point}: ".($stored ? 'new run stored' : 'unchanged').'.');
                } catch (Throwable $e) {
                    $failed++;
                    $this->logFailure($part, $point, $e);
                }
            }
        }

        return $failed === 0 ? Command::SUCCESS : Command::FAILURE;
    }

    private function logFailure(string $part, string $point, Throwable $e, ?string $dates = null): void
    {
        // The exception message can contain the request URL; log only the class.
        logger()->error('Open-Meteo fetch failed', array_filter([
            'part' => $part,
            'point' => $point,
            'dates' => $dates,
            'exception_class' => $e::class,
        ]));
        $this->error("{$part} {$point}: fetch failed (".$e::class.').');
    }

    /**
     * Calendar-year pieces of an inclusive local-day range.
     *
     * @return list<array{0: Carbon, 1: Carbon}>
     */
    private function yearChunks(Carbon $from, Carbon $to): array
    {
        $chunks = [];
        $cursor = $from->copy();

        while ($cursor->lessThanOrEqualTo($to)) {
            $end = $cursor->copy()->endOfYear()->startOfDay();
            if ($end->greaterThan($to)) {
                $end = $to->copy();
            }
            $chunks[] = [$cursor->copy(), $end];
            $cursor = $end->copy()->addDay();
        }

        return $chunks;
    }
}
