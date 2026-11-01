<?php

namespace App\Console\Commands;

use App\Services\Fmi\FmiForecastImporter;
use App\Services\Fmi\FmiMultiPointCoverageParser;
use App\Services\Fmi\FmiObservationImporter;
use App\Services\Fmi\FmiOpenDataService;
use App\Services\Fmi\FmiRequestException;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Throwable;

/**
 * Collect FMI open data (config/fmi.php) for own spot price models.
 *
 * Parts: "observed" (hourly temperature and wind at 29 stations), "radiation" (1-minute global
 * radiation at the 7 radiation stations, stored as hourly means) and "forecast" (one vintage of
 * the edited and the ECMWF point forecasts). Without --from: every part, observations for the
 * last 72 hours. With --from/--to: an observed/radiation backfill in 744 h / 168 h pieces.
 * Observation windows end at the start of the current UTC hour. Read-only towards FMI.
 */
class FetchFmiData extends Command
{
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

            return Command::FAILURE;
        }

        $backfill = $this->option('from') !== null;
        if ($backfill && in_array('forecast', $parts, true)) {
            $this->error('Forecasts have no backfill: FMI serves only the current forecast.');

            return Command::FAILURE;
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

            return Command::FAILURE;
        }

        if ($end->greaterThan($currentHour)) {
            $end = $currentHour->copy();
        }

        $failed = 0;

        if (in_array('observed', $parts, true)) {
            $failed += $this->fetchHourly($start, $end);
        }
        if (in_array('radiation', $parts, true)) {
            $failed += $this->fetchRadiation($start, $end);
        }
        if (in_array('forecast', $parts, true)) {
            foreach (array_keys((array) config('fmi.forecasts', [])) as $productKey) {
                $failed += $this->fetchForecast((string) $productKey, $currentHour);
            }
        }

        return $failed === 0 ? Command::SUCCESS : Command::FAILURE;
    }

    /**
     * Hourly values are labelled with the END of their hour, so the hours [S, E) are the labels
     * (S, E]: starttime S + 1 h, endtime E (FMI includes both ends).
     */
    private function fetchHourly(Carbon $start, Carbon $end): int
    {
        $config = (array) config('fmi.hourly');
        $stations = array_keys((array) config('fmi.weather_stations', []));
        $failed = 0;
        $written = 0;

        foreach ($this->chunks($start, $end, (int) $config['chunk_hours']) as [$chunkStart, $chunkEnd]) {
            try {
                $xml = $this->client->getFeature((string) $config['stored_query'], [
                    ...$this->stationParameters($stations),
                    ['parameters', implode(',', $config['parameters'])],
                    ['starttime', $this->isoTime($chunkStart->copy()->addHour())],
                    ['endtime', $this->isoTime($chunkEnd)],
                ]);
                $written += $this->observations->importHourly($this->parser->parse($xml), $config['parameters'], Carbon::now('UTC'));
            } catch (Throwable $e) {
                $failed++;
                $this->reportFailure('observed', $chunkStart, $chunkEnd, $e);
            }
        }

        $this->info("Observed: {$written} hourly rows.");

        return $failed;
    }

    /**
     * 1-minute values are labelled with the END of their minute, so the hours [S, E) are the
     * labels (S, E]: starttime S + 1 min, endtime E.
     */
    private function fetchRadiation(Carbon $start, Carbon $end): int
    {
        $config = (array) config('fmi.radiation');
        $stations = array_keys((array) config('fmi.radiation_stations', []));
        $failed = 0;
        $written = 0;

        foreach ($this->chunks($start, $end, (int) $config['chunk_hours']) as [$chunkStart, $chunkEnd]) {
            try {
                $xml = $this->client->getFeature((string) $config['stored_query'], [
                    ...$this->stationParameters($stations),
                    ['parameters', (string) $config['parameter']],
                    ['starttime', $this->isoTime($chunkStart->copy()->addMinute())],
                    ['endtime', $this->isoTime($chunkEnd)],
                ]);
                $written += $this->observations->importMinuteMeans(
                    $this->parser->parse($xml),
                    (string) $config['parameter'],
                    (string) $config['stored_parameter'],
                    Carbon::now('UTC')
                );
            } catch (Throwable $e) {
                $failed++;
                $this->reportFailure('radiation', $chunkStart, $chunkEnd, $e);
            }
        }

        $this->info("Radiation: {$written} hourly rows.");

        return $failed;
    }

    /**
     * One request per product: every point from the current UTC hour to the forecast horizon.
     * A configured point that the response does not contain fails the product.
     */
    private function fetchForecast(string $productKey, Carbon $currentHour): int
    {
        $product = (array) config("fmi.forecasts.{$productKey}");
        $stations = $product['stations'] !== null ? (array) config('fmi.'.$product['stations'], []) : [];
        $latlonPoints = $product['latlon_points'] !== null ? (array) config('fmi.'.$product['latlon_points'], []) : [];

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

        try {
            $xml = $this->client->getFeature((string) $product['stored_query'], [
                ...$parameters,
                ['parameters', implode(',', array_keys($product['parameters']))],
                ['timestep', '60'],
                ['starttime', $this->isoTime($currentHour)],
                ['endtime', $this->isoTime($horizonEnd)],
            ]);
            $result = $this->forecasts->store($productKey, $product, $this->parser->parse($xml), $points, Carbon::now('UTC'));
        } catch (Throwable $e) {
            $this->reportFailure("forecast {$productKey}", $currentHour, $horizonEnd, $e);

            return 1;
        }

        $this->info("Forecast {$productKey}: {$result['stored']} new vintages, {$result['unchanged']} unchanged.");

        if ($result['missing'] !== []) {
            logger()->error('FMI forecast points missing', ['product' => $productKey, 'points' => $result['missing']]);
            $this->error("Forecast {$productKey}: no values for point(s) ".implode(', ', $result['missing']).'.');

            return 1;
        }

        return 0;
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

    private function isoTime(Carbon $time): string
    {
        return $time->copy()->utc()->format('Y-m-d\TH:i:s\Z');
    }

    private function reportFailure(string $part, Carbon $start, Carbon $end, Throwable $e): void
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
