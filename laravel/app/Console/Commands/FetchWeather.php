<?php

namespace App\Console\Commands;

use App\Console\Concerns\StagedImport;
use App\Services\ImportPayload\ImportPayloadReader;
use App\Services\ImportPayload\ImportPayloadWriter;
use App\Services\ImportPayload\ImportStoreResult;
use App\Services\OpenMeteo\OpenMeteoService;
use App\Services\OpenMeteo\OpenMeteoWeatherImporter;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use PDOException;
use RuntimeException;
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
 *
 * Staged import (StagedImport, tasks/staged-api-imports/): the fetch step saves every answer in
 * one payload file, the store step parses that file and writes the rows. Not date-bound (S9): a
 * saved payload is stored with its own fetch-time facts whenever it is replayed.
 */
class FetchWeather extends Command
{
    use StagedImport;

    protected $signature = 'weather:fetch
                            {--from= : Observed backfill start date (YYYY-MM-DD, Europe/Helsinki local day)}
                            {--to= : Observed backfill end date, inclusive (default yesterday)}
                            {--point=* : Only these point keys (default: all configured points of each part)}
                            {--only=* : Only these parts: observed, observed_hourly, ecmwf_ens, ecmwf_ec46, ecmwf_seas5, solar_ghi, ecmwf_00z, ecmwf_prev_day1}';

    protected $description = 'Fetch Open-Meteo observed weather and forecast/outlook vintages for market reviews and models';

    private const EARLIEST_DATE = '1940-01-01';

    /** @var array<string, string> request key => UTC fetched_at of an answered data request (fetch step) */
    private array $requestFetchedAt = [];

    /** @var array<string, array<string, mixed>> part => ecmwf_00z run plan (fetch step, S19) */
    private array $singleRuns = [];

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
            if (isset($product['point_keys'])) {
                // Only these points of the set (the five southern stations of ecmwf_prev_day1).
                $pointSets[$key] = array_intersect_key($pointSets[$key], array_flip($product['point_keys']));
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

            return $this->stagedFailure('fetch', 'invalid_options');
        }

        $requestedParts = (array) $this->option('only');
        $unknownParts = array_diff($requestedParts, array_keys($pointSets));
        if ($unknownParts !== []) {
            $this->error('Unknown part(s): '.implode(', ', $unknownParts));

            return $this->stagedFailure('fetch', 'invalid_options');
        }

        $timezone = (string) config('open_meteo.timezone');
        $yesterday = Carbon::now($timezone)->startOfDay()->subDay();
        $backfill = $this->option('from') !== null;

        if ($backfill) {
            if (array_diff($requestedParts, ['observed', 'observed_hourly']) !== []) {
                $this->error('--from/--to backfills observed data only.');

                return $this->stagedFailure('fetch', 'invalid_options');
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

            return $this->stagedFailure('fetch', 'invalid_options');
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

            return $this->stagedFailure('fetch', 'invalid_options');
        }

        if ($to->greaterThan($yesterday)) {
            $to = $yesterday->copy();
        }
        if ($from->lessThan(Carbon::createFromFormat('!Y-m-d', self::EARLIEST_DATE, $timezone)) || $from->greaterThan($to)) {
            $this->error('The date range must be within '.self::EARLIEST_DATE.' .. yesterday, with --from before --to.');

            return $this->stagedFailure('fetch', 'invalid_options');
        }

        return $this->runStaged(
            fn (ImportPayloadWriter $payload): array => $this->fetch($payload, $parts, $partPoints, $pointSets, $backfill, $yesterday, $backfill ? $this->yearChunks($from, $to) : [[$from, $to]]),
            fn (ImportPayloadReader $payload): ImportStoreResult => $this->store($payload, $backfill),
            // A backfill commits once per point and year piece (S21); other runs in one transaction.
            ! $backfill,
        );
    }

    protected function stagedImportSource(): string
    {
        return 'open-meteo';
    }

    /**
     * The fetch step: every request is recorded, including failed ones and the metadata answers,
     * so the store step decides issued_at and the exit code from the saved bodies alone. The
     * fetch-time facts are the observed windows, the ecmwf_00z run plan (S19) and the fetched_at
     * of every answered data request (the vintage time of today's rows).
     *
     * @param  list<string>  $parts
     * @param  array<string, list<string>>  $partPoints
     * @param  array<string, array<array-key, array{latitude: float, longitude: float}>>  $pointSets
     * @param  list<array{0: Carbon, 1: Carbon}>  $windows
     * @return array<string, int> end facts
     */
    private function fetch(ImportPayloadWriter $payload, array $parts, array $partPoints, array $pointSets, bool $backfill, Carbon $yesterday, array $windows): array
    {
        $this->requestFetchedAt = [];
        $this->singleRuns = [];
        $outlooks = (array) config('open_meteo.outlooks', []);
        $hourlyForecasts = (array) config('open_meteo.hourly_forecasts', []);
        $payload->setFact('yesterday', $yesterday->format('Y-m-d'));
        $payload->setFact('windows', array_map(fn (array $window) => [$window[0]->format('Y-m-d'), $window[1]->format('Y-m-d')], $windows));

        $failed = 0;
        $delay = (float) config($backfill ? 'open_meteo.backfill_request_delay_seconds' : 'open_meteo.request_delay_seconds');

        // Observed parts. The hourly part sends the same date strings as UTC days.
        foreach (['observed' => 'daily', 'observed_hourly' => 'hourly'] as $part => $kind) {
            if (! in_array($part, $parts, true)) {
                continue;
            }

            foreach ($partPoints[$part] as $point) {
                foreach ($windows as [$start, $end]) {
                    $unit = ['part' => $part, 'point' => $point, 'from' => $start->format('Y-m-d'), 'to' => $end->format('Y-m-d')];
                    $request = $kind === 'daily'
                        ? $this->client->archiveRequest($pointSets[$part][$point], $start, $end)
                        : $this->client->archiveHourlyRequest($pointSets[$part][$point], $start, $end);
                    if (($e = $this->fetchRequest($payload, $unit, $request, $delay)) !== null) {
                        $failed++;
                        $this->logFailure($part, $point, $e, "{$unit['from']}..{$unit['to']}");

                        continue 2; // a failed point must not stop the others
                    }
                }
            }
        }

        $delay = (float) config('open_meteo.request_delay_seconds');
        foreach ($parts as $part) {
            if (isset($outlooks[$part])) {
                $failed += $this->fetchOutlookPart($payload, $part, $outlooks[$part], $partPoints[$part], $pointSets[$part], $delay);
            } elseif (isset($hourlyForecasts[$part])) {
                $failed += $this->fetchHourlyForecastPart($payload, $part, $hourlyForecasts[$part], $partPoints[$part], $pointSets[$part], $delay);
            }
        }

        $payload->setFact('single_runs', (object) $this->singleRuns);
        $payload->setFact('request_fetched_at', (object) $this->requestFetchedAt);

        return ['failed_points' => $failed];
    }

    /**
     * The model run time is read once per product before the point loop.
     *
     * @param  array<string, mixed>  $product
     * @param  list<string>  $pointKeys
     * @param  array<array-key, array{latitude: float, longitude: float}>  $points
     * @return int Number of failed points
     */
    private function fetchOutlookPart(ImportPayloadWriter $payload, string $part, array $product, array $pointKeys, array $points, float $delay): int
    {
        $this->fetchMeta($payload, ['part' => $part, 'meta' => 'run'], (string) $product['meta_url'], $delay);

        $failed = 0;
        foreach ($pointKeys as $point) {
            if (($e = $this->fetchRequest($payload, ['part' => $part, 'point' => $point], $this->client->outlookRequest($product, $points[$point]), $delay)) !== null) {
                $failed++;
                $this->logFailure($part, $point, $e);
            }
        }

        return $failed;
    }

    /**
     * The model run time is read before and after the point loop. When it changed during the
     * loop, a point can hold either run, so the store step leaves issued_at null (fetched_at is
     * then the vintage).
     *
     * @param  array<string, mixed>  $product
     * @param  list<string>  $pointKeys
     * @param  array<array-key, array{latitude: float, longitude: float}>  $points
     * @return int Number of failed points
     */
    private function fetchHourlyForecastPart(ImportPayloadWriter $payload, string $part, array $product, array $pointKeys, array $points, float $delay): int
    {
        if (isset($product['run_hour_utc'])) {
            return $this->fetchSingleRunPart($payload, $part, $product, $pointKeys, $points, $delay);
        }

        // Without a metadata file (Previous Runs: one series mixes many runs) issued_at stays null.
        $metaUrl = isset($product['meta_url']) ? (string) $product['meta_url'] : null;
        if ($metaUrl !== null) {
            $this->fetchMeta($payload, ['part' => $part, 'meta' => 'before'], $metaUrl, $delay);
        }

        $failed = 0;
        $answered = 0;
        foreach ($pointKeys as $point) {
            if (($e = $this->fetchRequest($payload, ['part' => $part, 'point' => $point], $this->client->hourlyForecastRequest($product, $points[$point]), $delay)) !== null) {
                $failed++;
                $this->logFailure($part, $point, $e);
            } else {
                $answered++;
            }
        }

        if ($answered > 0 && $metaUrl !== null) {
            $this->fetchMeta($payload, ['part' => $part, 'meta' => 'after'], $metaUrl, $delay);
        }

        return $failed;
    }

    /**
     * One exact model run (Single Runs API): the run of the Helsinki day at run_hour_utc, so
     * issued_at is known without the metadata file. Points already stored for this run are not
     * requested (S19: the plan is read from the database and saved in the header), so the hourly
     * schedule only fills what is missing. A run that is not ready yet is a quiet wait (exit 0,
     * nothing stored): its HTTP 400 answer is saved, and the other points are not requested. The
     * S workflow's readiness check owns the deadline.
     *
     * @param  array<string, mixed>  $product
     * @param  list<string>  $pointKeys
     * @param  array<array-key, array{latitude: float, longitude: float}>  $points
     * @return int Number of failed points
     */
    private function fetchSingleRunPart(ImportPayloadWriter $payload, string $part, array $product, array $pointKeys, array $points, float $delay): int
    {
        $runDate = $payload->fetchedAt()->setTimezone((string) config('open_meteo.timezone'))->format('Y-m-d');
        $hour = sprintf('%02d:00', (int) $product['run_hour_utc']);
        $issuedAt = "{$runDate} {$hour}:00";

        $stored = DB::table('hourly_forecast_runs')
            ->where('source', 'open_meteo')->where('product', $part)->where('issued_at', $issuedAt)
            ->whereIn('point', $pointKeys)->pluck('point')->map(fn ($point) => (string) $point)->all();
        $missing = array_values(array_diff($pointKeys, $stored));
        $this->singleRuns[$part] = ['run_date' => $runDate, 'issued_at' => $issuedAt, 'already_stored' => array_values(array_intersect($pointKeys, $stored)), 'requested' => $missing];
        if ($missing === []) {
            $this->info("Forecast {$part}: run {$issuedAt} UTC already stored for every point.");

            return 0;
        }

        $failed = 0;
        foreach ($missing as $point) {
            $request = $this->client->hourlyForecastRequest($product, $points[$point], "{$runDate}T{$hour}");
            if (($e = $this->fetchRequest($payload, ['part' => $part, 'point' => $point], $request, $delay, true)) === null) {
                continue;
            }
            if (OpenMeteoService::isRunNotAvailable($e)) {
                return $failed;
            }
            $failed++;
            $this->logFailure($part, $point, $e);
        }

        return $failed;
    }

    /**
     * Send one data request and record it. Returns null when answered, else the failure (already
     * recorded as a failed unit, except a Single Runs "not available" answer, which is a wait).
     *
     * @param  array<string, string>  $unit
     * @param  array{0: string, 1: array<string, mixed>}  $request
     */
    private function fetchRequest(ImportPayloadWriter $payload, array $unit, array $request, float $delay, bool $singleRun = false): ?Throwable
    {
        [$url, $query] = $request;
        try {
            $response = $this->client->fetch($url, $query, $delay);
        } catch (Throwable $e) {
            if ($singleRun && OpenMeteoService::isRunNotAvailable($e)) {
                $payload->record($unit, $e->response);
            } else {
                $payload->recordFailure($unit, 'GET', $url, $query, $e);
            }

            return $e;
        }

        $payload->record($unit, $response);
        // The vintage time of this point, as before the staged import: right after its answer.
        $this->requestFetchedAt[self::requestKey($unit)] = Carbon::now('UTC')->format('Y-m-d H:i:s');

        return null;
    }

    /**
     * Record a metadata answer. Best effort: any status is saved; only a request without an
     * answer is a failed unit (the run then has no issued_at, as before, and exit 0).
     *
     * @param  array<string, string>  $unit
     */
    private function fetchMeta(ImportPayloadWriter $payload, array $unit, string $url, float $delay): void
    {
        try {
            $payload->record($unit, $this->client->fetchMeta($url, $delay));
        } catch (Throwable $e) {
            $payload->recordFailure($unit, 'GET', $url, [], $e);
        }
    }

    /**
     * The store step: parse every saved answer and write it as before (upserts for observed
     * rows, append-only outlook and hourly forecast vintages). Times come from the header facts,
     * never from the clock (S5). A point whose answer has a wrong unit or shape fails alone, as
     * before; a database error fails the whole store (StagedImport rolls back the run).
     */
    private function store(ImportPayloadReader $payload, bool $backfill): ImportStoreResult
    {
        $outlooks = (array) config('open_meteo.outlooks', []);
        $hourlyForecasts = (array) config('open_meteo.hourly_forecasts', []);
        $fetchedAt = (array) $payload->fact('request_fetched_at', []);
        $singleRuns = (array) $payload->fact('single_runs', []);

        // Model run times from the saved metadata answers (first pass; the bodies are small).
        $modelRuns = [];
        foreach ($payload->requests() as $request) {
            if (isset($request['unit']['meta'])) {
                $modelRuns[$request['unit']['part']][$request['unit']['meta']] = OpenMeteoService::modelRunAt($request['status'] ?? null, ImportPayloadReader::body($request));
            }
        }

        $counts = ['daily_rows' => 0, 'hourly_rows' => 0, 'outlook_runs_stored' => 0, 'forecast_runs_stored' => 0, 'failed_points' => 0, 'invalid_points' => 0];
        $messages = [];
        $observedRows = [];
        $invalid = [];

        foreach ($payload->requests() as $request) {
            $unit = $request['unit'];
            if (isset($unit['meta'])) {
                continue;
            }
            $part = (string) $unit['part'];
            $point = (string) $unit['point'];
            if (isset($invalid["{$part}/{$point}"])) {
                continue; // an observed point stops at its first failed piece, as before
            }

            $status = $request['status'] ?? null;
            $body = ImportPayloadReader::body($request);
            if (isset($hourlyForecasts[$part]['run_hour_utc']) && is_int($status) && OpenMeteoService::isRunNotAvailableAnswer($status, $body)) {
                $messages[] = "Forecast {$part}: run {$singleRuns[$part]['issued_at']} UTC is not available yet.";

                continue;
            }
            if (! is_int($status) || $status < 200 || $status >= 300) {
                $counts['failed_points']++; // logged by the fetch step

                continue;
            }

            $time = CarbonImmutable::parse($fetchedAt[self::requestKey($unit)] ?? $payload->fetchedAt(), 'UTC');
            $write = function () use ($part, $point, $body, $time, $outlooks, $hourlyForecasts, $singleRuns, $modelRuns, &$counts, &$messages, &$observedRows): void {
                if ($part === 'observed' || $part === 'observed_hourly') {
                    $daily = $part === 'observed';
                    $written = $daily
                        ? $this->importer->importObserved($point, $this->importer->decode($body, 'daily'), $time)
                        : $this->importer->importObservedHourly($point, $this->importer->decode($body, 'hourly'), $time);
                    $counts[$daily ? 'daily_rows' : 'hourly_rows'] += $written;
                    $observedRows[$part][$point] = ($observedRows[$part][$point] ?? 0) + $written;
                } elseif (isset($outlooks[$part])) {
                    $stored = $this->importer->storeOutlookRun($part, $outlooks[$part], $point, $this->importer->decode($body, 'daily'), $modelRuns[$part]['run'] ?? null, $time);
                    $counts['outlook_runs_stored'] += (int) $stored;
                    $messages[] = "Outlook {$part} {$point}: ".($stored ? 'new run stored' : 'unchanged').'.';
                } elseif (isset($hourlyForecasts[$part])) {
                    if (isset($hourlyForecasts[$part]['run_hour_utc'])) {
                        $issuedAt = (string) $singleRuns[$part]['issued_at'];
                    } else {
                        $before = $modelRuns[$part]['before'] ?? null;
                        $issuedAt = $before !== null && $before === ($modelRuns[$part]['after'] ?? null) ? $before : null;
                    }
                    $stored = $this->importer->storeHourlyForecastRun($part, $hourlyForecasts[$part], $point, $this->importer->decode($body, 'hourly'), $issuedAt, $time);
                    $counts['forecast_runs_stored'] += (int) $stored;
                    $messages[] = "Forecast {$part} {$point}: ".($stored ? 'new run stored' : 'unchanged').'.';
                } else {
                    throw new RuntimeException('Unknown weather part in the payload.');
                }
            };

            try {
                $backfill ? DB::transaction($write) : $write();
            } catch (Throwable $e) {
                if ($e instanceof PDOException) {
                    throw $e; // a database error fails the store, never one point
                }
                $invalid["{$part}/{$point}"] = true;
                unset($observedRows[$part][$point]); // no row count line for a failed point, as before
                $counts['invalid_points']++;
                $this->logFailure($part, $point, $e, isset($unit['from']) ? "{$unit['from']}..{$unit['to']}" : null);
            }
        }

        foreach ($observedRows as $part => $points) {
            foreach ($points as $point => $written) {
                $messages[] = ($part === 'observed' ? "Observed {$point}: {$written} daily rows." : "Observed hourly {$point}: {$written} hourly rows.");
            }
        }

        [$error, $step] = match (true) {
            $counts['failed_points'] > 0 => ['fetch_incomplete', 'fetch'],
            $counts['invalid_points'] > 0 => ['invalid_response', 'store'],
            default => [null, 'store'],
        };

        return new ImportStoreResult($counts, [function () use ($messages): void {
            foreach ($messages as $message) {
                $this->info($message);
            }
        }], $error, $step);
    }

    /** @param  array<array-key, scalar>  $unit */
    private static function requestKey(array $unit): string
    {
        return implode('/', array_map('strval', $unit));
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
