<?php

namespace App\Console\Commands;

use App\Console\Concerns\StagedImport;
use App\Services\Fmi\FmiForecastImporter;
use App\Services\Fmi\FmiMultiPointCoverageParser;
use App\Services\Fmi\FmiObservationImporter;
use App\Services\Fmi\FmiOpenDataService;
use App\Services\Fmi\FmiRequestException;
use App\Services\ImportPayload\ImportPayloadReader;
use App\Services\ImportPayload\ImportPayloadWriter;
use App\Services\ImportPayload\ImportStoreResult;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;
use UnexpectedValueException;

/**
 * Collect FMI open data (config/fmi.php) for own spot price models.
 *
 * Parts: "observed" (hourly temperature and wind at 29 stations), "radiation" (1-minute global
 * radiation at the 7 radiation stations, stored as hourly means) and "forecast" (one vintage of
 * the edited and the ECMWF point forecasts). Without --from: every part, observations for the
 * last 72 hours. With --from/--to: an observed/radiation backfill in 744 h / 168 h pieces.
 * Observation windows end at the start of the current UTC hour. Read-only towards FMI.
 *
 * Staged import (StagedImport): the fetch step records every raw XML response and fixes the
 * current hour, the observation window and each forecast product's point list in the header
 * (S5); the store step parses one request line at a time (radiation is about 5 MB of XML per
 * request) and writes observations (upsert) and forecast vintages (insert-only). The default run
 * stores in one transaction; a --from backfill commits once per request piece (S21). A failed
 * piece or an unusable response fails only itself (fetch_incomplete); a configured forecast
 * point without values fails the run (forecast_points_missing). Not date-bound (S9).
 */
class FetchFmiData extends Command
{
    use StagedImport;

    protected $signature = 'fmi:fetch
                            {--from= : Backfill start date (YYYY-MM-DD, UTC); observed and radiation only}
                            {--to= : Backfill end date, inclusive (YYYY-MM-DD, UTC; default today)}
                            {--only=* : Only these parts: observed, radiation, forecast}';

    protected $description = 'Fetch FMI weather observations, radiation and forecast vintages for own spot price models';

    private const PARTS = ['observed', 'radiation', 'forecast'];

    public function __construct(
        private readonly FmiOpenDataService $client,
        private readonly FmiMultiPointCoverageParser $parser,
        private readonly FmiObservationImporter $observations,
        private readonly FmiForecastImporter $forecasts
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $parts = (array) $this->option('only');
        $unknown = array_diff($parts, self::PARTS);
        if ($unknown !== []) {
            $this->error('Unknown part(s): '.implode(', ', $unknown));

            return $this->stagedFailure('fetch', 'invalid_options');
        }

        $backfill = $this->option('from') !== null;
        if ($backfill && in_array('forecast', $parts, true)) {
            $this->error('Forecasts have no backfill: FMI serves only the current forecast.');

            return $this->stagedFailure('fetch', 'invalid_options');
        }
        if ($parts === []) {
            $parts = $backfill ? ['observed', 'radiation'] : self::PARTS;
        }

        $currentHour = Carbon::now('UTC')->startOfHour();

        $start = $backfill
            ? $this->date((string) $this->option('from'))
            : $currentHour->copy()->subHours((int) config('fmi.observation_lookback_hours', 72));
        $end = $this->option('to') !== null
            ? $this->date((string) $this->option('to'))?->addDay()
            : $currentHour->copy();

        if ($start === null || $end === null) {
            $this->error('Invalid date. Use YYYY-MM-DD.');

            return $this->stagedFailure('fetch', 'invalid_options');
        }

        if ($end->greaterThan($currentHour)) {
            $end = $currentHour->copy();
        }

        return $this->runStaged(
            fn (ImportPayloadWriter $payload) => $this->fetch($payload, $parts, $start, $end, $currentHour),
            fn (ImportPayloadReader $payload): ImportStoreResult => $this->store($payload, $backfill),
            ! $backfill,
        );
    }

    protected function stagedImportSource(): string
    {
        return 'fmi';
    }

    /** @param  list<string>  $parts */
    private function fetch(ImportPayloadWriter $payload, array $parts, Carbon $start, Carbon $end, Carbon $currentHour): void
    {
        $payload->setFact('current_hour', $this->isoTime($currentHour));
        $payload->setFact('parts', array_values($parts));
        if (array_intersect($parts, ['observed', 'radiation']) !== []) {
            $payload->setFact('observation_window', ['start' => $this->isoTime($start), 'end' => $this->isoTime($end)]);
        }

        if (in_array('observed', $parts, true)) {
            $this->fetchHourly($payload, $start, $end);
        }
        if (in_array('radiation', $parts, true)) {
            $this->fetchRadiation($payload, $start, $end);
        }
        if (in_array('forecast', $parts, true)) {
            $forecastPoints = [];
            foreach (array_keys((array) config('fmi.forecasts', [])) as $productKey) {
                $forecastPoints[$productKey] = $this->fetchForecast($payload, (string) $productKey, $currentHour);
            }
            // The missing-point check of the store step compares the response with this list.
            $payload->setFact('forecast_points', $forecastPoints);
        }
    }

    /**
     * Hourly values are labelled with the END of their hour, so the hours [S, E) are the labels
     * (S, E]: starttime S + 1 h, endtime E (FMI includes both ends).
     */
    private function fetchHourly(ImportPayloadWriter $payload, Carbon $start, Carbon $end): void
    {
        $config = (array) config('fmi.hourly');
        $stations = array_keys((array) config('fmi.weather_stations', []));

        foreach ($this->chunks($start, $end, (int) $config['chunk_hours']) as [$chunkStart, $chunkEnd]) {
            $this->requestPiece($payload, 'observed', $chunkStart, $chunkEnd, (string) $config['stored_query'], [
                ...$this->stationParameters($stations),
                ['parameters', implode(',', $config['parameters'])],
                ['starttime', $this->isoTime($chunkStart->copy()->addHour())],
                ['endtime', $this->isoTime($chunkEnd)],
            ]);
        }
    }

    /**
     * 1-minute values are labelled with the END of their minute, so the hours [S, E) are the
     * labels (S, E]: starttime S + 1 min, endtime E.
     */
    private function fetchRadiation(ImportPayloadWriter $payload, Carbon $start, Carbon $end): void
    {
        $config = (array) config('fmi.radiation');
        $stations = array_keys((array) config('fmi.radiation_stations', []));

        foreach ($this->chunks($start, $end, (int) $config['chunk_hours']) as [$chunkStart, $chunkEnd]) {
            $this->requestPiece($payload, 'radiation', $chunkStart, $chunkEnd, (string) $config['stored_query'], [
                ...$this->stationParameters($stations),
                ['parameters', (string) $config['parameter']],
                ['starttime', $this->isoTime($chunkStart->copy()->addMinute())],
                ['endtime', $this->isoTime($chunkEnd)],
            ]);
        }
    }

    /**
     * One request per product: every point from the current UTC hour to the forecast horizon.
     * Returns the configured points, which the store step checks against the response.
     *
     * @return array<string, array{latitude: float, longitude: float}>
     */
    private function fetchForecast(ImportPayloadWriter $payload, string $productKey, Carbon $currentHour): array
    {
        $product = (array) config("fmi.forecasts.{$productKey}");
        $stations = $product['stations'] !== null ? (array) config('fmi.'.$product['stations'], []) : [];
        $latlonPoints = [];
        foreach ((array) ($product['latlon_points'] ?? []) as $listKey) {
            $latlonPoints += (array) config('fmi.'.$listKey, []);
        }

        $points = [];
        $parameters = $this->stationParameters(array_keys($stations));
        foreach ($stations as $fmisid => $station) {
            $points[(string) $fmisid] = ['latitude' => (float) $station['latitude'], 'longitude' => (float) $station['longitude']];
        }
        foreach ($latlonPoints as $key => $point) {
            $points[(string) $key] = ['latitude' => (float) $point['latitude'], 'longitude' => (float) $point['longitude']];
            $parameters[] = ['latlon', $point['latitude'].','.$point['longitude']];
        }

        $horizonEnd = $currentHour->copy()->addHours((int) config('fmi.forecast_hours', 240));

        $this->requestPiece($payload, "forecast {$productKey}", $currentHour, $horizonEnd, (string) $product['stored_query'], [
            ...$parameters,
            ['parameters', implode(',', array_keys($product['parameters']))],
            ['timestep', '60'],
            ['starttime', $this->isoTime($currentHour)],
            ['endtime', $this->isoTime($horizonEnd)],
        ], ['product' => $productKey]);

        return $points;
    }

    /**
     * Request and record one piece. A connection failure, an HTTP error or an FMI exception
     * report is a failed unit: it is logged here and does not stop the other pieces.
     *
     * @param  list<array{0: string, 1: string}>  $parameters
     * @param  array<string, string>  $extraUnit
     */
    private function requestPiece(ImportPayloadWriter $payload, string $label, Carbon $from, Carbon $to, string $storedQuery, array $parameters, array $extraUnit = []): void
    {
        $part = str_starts_with($label, 'forecast') ? 'forecast' : $label;
        $unit = ['part' => $part, ...$extraUnit, 'from' => $this->isoTime($from), 'to' => $this->isoTime($to)];

        try {
            $response = $this->client->request($storedQuery, $parameters);
        } catch (Throwable $e) {
            $payload->recordFailure($unit, 'GET', $this->client->url($storedQuery, $parameters), [], $e);
            $this->reportFailure($label, $from, $to, $e);

            return;
        }

        $failure = $this->client->failure($response);
        $payload->record($unit, $response, failed: $failure !== null);
        if ($failure !== null) {
            $this->reportFailure($label, $from, $to, $failure);
        }
    }

    /**
     * Store one request line at a time; the parsed XML of a line is released before the next.
     * Runs inside the StagedImport transaction, or one transaction per line for a backfill.
     */
    private function store(ImportPayloadReader $payload, bool $backfill): ImportStoreResult
    {
        $failedUnits = $payload->end()['failed_units'];
        $counts = ['observed_rows' => 0, 'radiation_rows' => 0, 'forecast_stored' => 0, 'forecast_unchanged' => 0, 'unusable_pieces' => 0];
        $forecasts = [];

        foreach ($payload->requests() as $request) {
            $unit = $request['unit'];
            if (in_array($unit, $failedUnits, true)) {
                continue;
            }

            $work = fn (): array => $this->storePiece($unit, (string) ImportPayloadReader::body($request), $payload);
            try {
                $result = $backfill ? DB::transaction($work) : $work();
            } catch (FmiRequestException $e) {
                // An unusable response (no field, unknown position, bad XML) fails before any write of its piece.
                $counts['unusable_pieces']++;
                $this->reportFailure($unit['part'] === 'forecast' ? "forecast {$unit['product']}" : $unit['part'], Carbon::parse($unit['from']), Carbon::parse($unit['to']), $e);

                continue;
            }

            if ($unit['part'] === 'forecast') {
                $forecasts[$unit['product']] = $result;
                $counts['forecast_stored'] += $result['stored'];
                $counts['forecast_unchanged'] += $result['unchanged'];
            } else {
                $counts["{$unit['part']}_rows"] += $result['rows'];
            }
        }

        $parts = (array) $payload->fact('parts', []);
        $missing = array_filter(array_map(fn (array $result) => $result['missing'], $forecasts));
        [$error, $step] = match (true) {
            $failedUnits !== [] || $counts['unusable_pieces'] > 0 => ['fetch_incomplete', 'fetch'],
            $missing !== [] => ['forecast_points_missing', 'fetch'],
            default => [null, 'store'],
        };

        return new ImportStoreResult($counts, [function () use ($parts, $counts, $forecasts): void {
            if (in_array('observed', $parts, true)) {
                $this->info("Observed: {$counts['observed_rows']} hourly rows.");
            }
            if (in_array('radiation', $parts, true)) {
                $this->info("Radiation: {$counts['radiation_rows']} hourly rows.");
            }
            foreach ($forecasts as $productKey => $result) {
                $this->info("Forecast {$productKey}: {$result['stored']} new vintages, {$result['unchanged']} unchanged.");
                if ($result['missing'] !== []) {
                    logger()->error('FMI forecast points missing', ['product' => $productKey, 'points' => $result['missing']]);
                    $this->error("Forecast {$productKey}: no values for point(s) ".implode(', ', $result['missing']).'.');
                }
            }
        }], $error, $step);
    }

    /**
     * @param  array<string, string>  $unit
     * @return array{rows: int}|array{stored: int, unchanged: int, missing: list<string>}
     *
     * @throws FmiRequestException
     */
    private function storePiece(array $unit, string $body, ImportPayloadReader $payload): array
    {
        $members = $this->parser->parse($body);
        $fetchedAt = $payload->fetchedAt();

        return match ($unit['part']) {
            'observed' => ['rows' => $this->observations->importHourly($members, (array) config('fmi.hourly.parameters'), $fetchedAt)],
            'radiation' => ['rows' => $this->observations->importMinuteMeans(
                $members,
                (string) config('fmi.radiation.parameter'),
                (string) config('fmi.radiation.stored_parameter'),
                $fetchedAt
            )],
            'forecast' => $this->forecasts->store(
                (string) $unit['product'],
                (array) config("fmi.forecasts.{$unit['product']}"),
                $members,
                $this->forecastPoints($payload, (string) $unit['product']),
                $fetchedAt
            ),
            default => throw new UnexpectedValueException('Unknown FMI payload unit.'),
        };
    }

    /** @return array<string, array{latitude: float, longitude: float}> */
    private function forecastPoints(ImportPayloadReader $payload, string $productKey): array
    {
        $points = $payload->fact('forecast_points', [])[$productKey] ?? throw new UnexpectedValueException('FMI payload has no point list for its forecast.');
        $typed = [];
        foreach ($points as $key => $point) {
            $typed[(string) $key] = ['latitude' => (float) $point['latitude'], 'longitude' => (float) $point['longitude']];
        }

        return $typed;
    }

    /**
     * @param  list<int|string>  $stations
     * @return list<array{0: string, 1: string}>
     */
    private function stationParameters(array $stations): array
    {
        return array_map(fn ($fmisid) => ['fmisid', (string) $fmisid], $stations);
    }

    /**
     * @return list<array{0: Carbon, 1: Carbon}>
     */
    private function chunks(Carbon $start, Carbon $end, int $hours): array
    {
        $chunks = [];
        $cursor = $start->copy();

        while ($cursor->lessThan($end)) {
            $chunkEnd = $cursor->copy()->addHours($hours);
            if ($chunkEnd->greaterThan($end)) {
                $chunkEnd = $end->copy();
            }
            $chunks[] = [$cursor->copy(), $chunkEnd];
            $cursor = $chunkEnd;
        }

        return $chunks;
    }

    /**
     * Strict UTC date: createFromFormat alone would turn 2026-13-01 into 2027-01-01.
     */
    private function date(string $value): ?Carbon
    {
        try {
            $date = Carbon::createFromFormat('!Y-m-d', $value, 'UTC');
        } catch (Throwable) {
            return null;
        }

        return $date !== null && $date->format('Y-m-d') === $value ? $date : null;
    }

    private function isoTime(CarbonInterface $time): string
    {
        return $time->copy()->utc()->format('Y-m-d\TH:i:s\Z');
    }

    private function reportFailure(string $part, CarbonInterface $start, CarbonInterface $end, Throwable $e): void
    {
        // An HTTP exception message can contain the request URL; FmiRequestException never does.
        $context = [
            'part' => $part,
            'from' => $this->isoTime($start),
            'to' => $this->isoTime($end),
            'exception_class' => $e::class,
        ];
        if ($e instanceof FmiRequestException) {
            $context['message'] = $e->getMessage();
        }
        logger()->error('FMI fetch failed', $context);

        $detail = $e instanceof FmiRequestException ? $e->getMessage() : $e::class;
        $this->error("FMI {$part} {$context['from']}..{$context['to']}: fetch failed ({$detail}).");
    }
}
