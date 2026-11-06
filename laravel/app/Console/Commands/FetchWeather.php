<?php

namespace App\Console\Commands;

use App\Services\OpenMeteo\OpenMeteoService;
use App\Services\OpenMeteo\OpenMeteoWeatherImporter;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Collect Open-Meteo weather (config/open_meteo.php) for market reviews and own spot price models.
 *
 * Without --only: the observed daily window (last 21 local days up to yesterday, ERA5), the
 * observed hourly window of the neighbour wind points (same 21 days as UTC days, ERA5) and the
 * default outlooks (ECMWF 15-day ensemble). --only selects parts: "observed", "observed_hourly",
 * an outlook key or an hourly forecast key (e.g. "solar_ghi", never in the default run).
 * With --from/--to: an observed backfill ("observed" by default, or --only=observed_hourly) in
 * calendar-year pieces with a longer pause between requests. Each part uses its own point list;
 * --point limits every selected part to the given keys. Read-only towards Open-Meteo.
 */
class FetchWeather extends Command
{
    protected $signature = 'weather:fetch
                            {--from= : Observed backfill start date (YYYY-MM-DD, Europe/Helsinki local day)}
                            {--to= : Observed backfill end date, inclusive (default yesterday)}
                            {--point=* : Only these point keys (default: all configured points of each part)}
                            {--only=* : Only these parts: observed, observed_hourly, ecmwf_ens, ecmwf_ec46, ecmwf_seas5, solar_ghi, ecmwf_00z}';

    protected $description = 'Fetch Open-Meteo observed weather and forecast/outlook vintages for market reviews and models';

    private const EARLIEST_DATE = '1940-01-01';

    public function __construct(
        private readonly OpenMeteoService $client,
        private readonly OpenMeteoWeatherImporter $importer
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $outlooks = (array) config('open_meteo.outlooks', []);
        $hourlyForecasts = (array) config('open_meteo.hourly_forecasts', []);

        // Point list of every part. Solar keys are FMISID strings, so cast the array keys.
        $pointSets = ['observed' => (array) config('open_meteo.points', []), 'observed_hourly' => (array) config('open_meteo.neighbour_wind_points', [])];
        foreach (array_keys($outlooks) as $key) {
            $pointSets[$key] = $pointSets['observed'];
        }
        foreach ($hourlyForecasts as $key => $product) {
            if (is_array($product['points'])) {
                // A list of full config keys (other config files too); keys stay as strings.
                $pointSets[$key] = [];
                foreach ($product['points'] as $configKey) {
                    foreach ((array) config($configKey, []) as $pointKey => $point) {
                        $pointSets[$key][(string) $pointKey] = $point;
                    }
                }
            } else {
                $pointSets[$key] = (array) config('open_meteo.'.$product['points'], []);
            }
        }

        $requestedPoints = array_map('strval', (array) $this->option('point'));
        $allPoints = [];
        foreach ($pointSets as $set) {
            $allPoints = [...$allPoints, ...array_map('strval', array_keys($set))];
        }
        $unknownPoints = array_diff($requestedPoints, $allPoints);
        if ($unknownPoints !== []) {
            $this->error('Unknown point(s): '.implode(', ', $unknownPoints));

            return Command::FAILURE;
        }

        $requestedParts = (array) $this->option('only');
        $unknownParts = array_diff($requestedParts, array_keys($pointSets));
        if ($unknownParts !== []) {
            $this->error('Unknown part(s): '.implode(', ', $unknownParts));

            return Command::FAILURE;
        }

        $timezone = (string) config('open_meteo.timezone');
        $yesterday = Carbon::now($timezone)->startOfDay()->subDay();
        $backfill = $this->option('from') !== null;

        if ($backfill) {
            if (array_diff($requestedParts, ['observed', 'observed_hourly']) !== []) {
                $this->error('--from/--to backfills observed data only.');

                return Command::FAILURE;
            }
            $parts = $requestedParts !== [] ? $requestedParts : ['observed'];
        } else {
            $defaults = array_keys(array_filter($outlooks, fn ($product) => (bool) ($product['default'] ?? false)));
            $parts = $requestedParts !== [] ? $requestedParts : ['observed', 'observed_hourly', ...$defaults];
        }

        // Point keys per selected part; a part without a requested point is skipped.
        $partPoints = [];
        foreach ($parts as $part) {
            $keys = array_map('strval', array_keys($pointSets[$part]));
            $partPoints[$part] = $requestedPoints !== [] ? array_values(array_intersect($keys, $requestedPoints)) : $keys;
        }
        $unusedPoints = array_diff($requestedPoints, array_merge(...array_values($partPoints)));
        if ($unusedPoints !== []) {
            $this->error('Point(s) not used by the selected parts: '.implode(', ', $unusedPoints));

            return Command::FAILURE;
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
        $delay = (float) config($backfill ? 'open_meteo.backfill_request_delay_seconds' : 'open_meteo.request_delay_seconds');
        $windows = $backfill ? $this->yearChunks($from, $to) : [[$from, $to]];

        // Observed parts. The hourly part sends the same date strings as UTC days.
        foreach (['observed' => 'daily', 'observed_hourly' => 'hourly'] as $part => $kind) {
            if (! in_array($part, $parts, true)) {
                continue;
            }

            foreach ($partPoints[$part] as $point) {
                $written = 0;
                foreach ($windows as [$start, $end]) {
                    try {
                        if ($kind === 'daily') {
                            $payload = $this->client->fetchArchive($pointSets[$part][$point], $start, $end, $delay);
                            $written += $this->importer->importObserved($point, $payload, Carbon::now('UTC'));
                        } else {
                            $payload = $this->client->fetchArchiveHourly($pointSets[$part][$point], $start, $end, $delay);
                            $written += $this->importer->importObservedHourly($point, $payload, Carbon::now('UTC'));
                        }
                    } catch (Throwable $e) {
                        $failed++;
                        $this->logFailure($part, $point, $e, $start->format('Y-m-d').'..'.$end->format('Y-m-d'));

                        continue 2; // a failed point must not stop the others
                    }
                }
                $this->info(($kind === 'daily' ? 'Observed' : 'Observed hourly')." {$point}: {$written} {$kind} rows.");
            }
        }

        $delay = (float) config('open_meteo.request_delay_seconds');
        foreach ($parts as $part) {
            if (isset($outlooks[$part])) {
                $failed += $this->fetchOutlookPart($part, $outlooks[$part], $partPoints[$part], $pointSets[$part], $delay);
            } elseif (isset($hourlyForecasts[$part])) {
                $failed += $this->fetchHourlyForecastPart($part, $hourlyForecasts[$part], $partPoints[$part], $pointSets[$part], $delay);
            }
        }

        return $failed === 0 ? Command::SUCCESS : Command::FAILURE;
    }

    /**
     * @param  array<string, mixed>  $product
     * @param  list<string>  $pointKeys
     * @param  array<array-key, array{latitude: float, longitude: float}>  $points
     * @return int Number of failed points
     */
    private function fetchOutlookPart(string $part, array $product, array $pointKeys, array $points, float $delay): int
    {
        $failed = 0;
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

        return $failed;
    }

    /**
     * The model run time is read before and after the point loop. When it changed during the
     * loop, a point can hold either run, so issued_at stays null (fetched_at is then the vintage).
     *
     * @param  array<string, mixed>  $product
     * @param  list<string>  $pointKeys
     * @param  array<array-key, array{latitude: float, longitude: float}>  $points
     * @return int Number of failed points
     */
    private function fetchHourlyForecastPart(string $part, array $product, array $pointKeys, array $points, float $delay): int
    {
        if (isset($product['run_hour_utc'])) {
            return $this->fetchSingleRunPart($part, $product, $pointKeys, $points, $delay);
        }

        $failed = 0;
        $runBefore = $this->client->fetchModelRunAt((string) $product['meta_url'], $delay);
        $payloads = [];

        foreach ($pointKeys as $point) {
            try {
                $payloads[$point] = [$this->client->fetchHourlyForecast($product, $points[$point], $delay), Carbon::now('UTC')];
            } catch (Throwable $e) {
                $failed++;
                $this->logFailure($part, $point, $e);
            }
        }

        $runAfter = $payloads === [] ? null : $this->client->fetchModelRunAt((string) $product['meta_url'], $delay);
        $issuedAt = $runBefore !== null && $runBefore === $runAfter ? $runBefore : null;

        foreach ($payloads as $point => [$payload, $fetchedAt]) {
            try {
                $stored = $this->importer->storeHourlyForecastRun($part, $product, (string) $point, $payload, $issuedAt, $fetchedAt);
                $this->info("Forecast {$part} {$point}: ".($stored ? 'new run stored' : 'unchanged').'.');
            } catch (Throwable $e) {
                $failed++;
                $this->logFailure($part, (string) $point, $e);
            }
        }

        return $failed;
    }

    /**
     * One exact model run (Single Runs API): the run of the Helsinki day at run_hour_utc, so
     * issued_at is known without the metadata file. Points already stored for this run are
     * skipped, so the hourly schedule only fills what is missing. A run that is not ready yet is
     * a quiet wait (exit 0, nothing stored); the S workflow's readiness check owns the deadline.
     *
     * @param  array<string, mixed>  $product
     * @param  list<string>  $pointKeys
     * @param  array<array-key, array{latitude: float, longitude: float}>  $points
     * @return int Number of failed points
     */
    private function fetchSingleRunPart(string $part, array $product, array $pointKeys, array $points, float $delay): int
    {
        $runDate = Carbon::now((string) config('open_meteo.timezone'))->format('Y-m-d');
        $hour = sprintf('%02d:00', (int) $product['run_hour_utc']);
        $issuedAt = "{$runDate} {$hour}:00";

        $stored = DB::table('hourly_forecast_runs')
            ->where('source', 'open_meteo')->where('product', $part)->where('issued_at', $issuedAt)
            ->whereIn('point', $pointKeys)->pluck('point')->map(fn ($point) => (string) $point)->all();
        $missing = array_values(array_diff($pointKeys, $stored));
        if ($missing === []) {
            $this->info("Forecast {$part}: run {$issuedAt} UTC already stored for every point.");

            return 0;
        }

        $failed = 0;
        foreach ($missing as $point) {
            try {
                $payload = $this->client->fetchHourlyForecast($product, $points[$point], $delay, "{$runDate}T{$hour}");
                $this->importer->storeHourlyForecastRun($part, $product, $point, $payload, $issuedAt, Carbon::now('UTC'));
                $this->info("Forecast {$part} {$point}: run {$issuedAt} UTC stored.");
            } catch (Throwable $e) {
                if (OpenMeteoService::isRunNotAvailable($e)) {
                    $this->info("Forecast {$part}: run {$issuedAt} UTC is not available yet.");

                    return $failed;
                }
                $failed++;
                $this->logFailure($part, $point, $e);
            }
        }

        return $failed;
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
