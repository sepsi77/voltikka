<?php

namespace App\Services\OpenMeteo;

use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Read-only client for the free Open-Meteo APIs (archive, forecast, ensemble, seasonal). No key.
 *
 * Every request after the first waits for the given delay, because the free tier allows fewer
 * than 600 calls per minute and 5,000 per hour, and a long or wide request counts as several
 * calls. HTTP 429, 5xx and connection failures are retried.
 */
class OpenMeteoService
{
    private ?float $lastRequestAt = null;

    /**
     * Observed daily values for one point and an inclusive range of local days.
     *
     * @param  array{latitude: float, longitude: float}  $point
     * @return array<string, mixed> Decoded response with a 'daily' block
     *
     * @throws RequestException|ConnectionException
     */
    public function fetchArchive(array $point, CarbonInterface $from, CarbonInterface $to, float $delaySeconds): array
    {
        return $this->getBlock((string) config('open_meteo.archive_url'), [
            'latitude' => $point['latitude'],
            'longitude' => $point['longitude'],
            'start_date' => $from->format('Y-m-d'),
            'end_date' => $to->format('Y-m-d'),
            'daily' => implode(',', (array) config('open_meteo.observed_variables')),
            'models' => (string) config('open_meteo.observed_model'),
            'timezone' => (string) config('open_meteo.timezone'),
            'wind_speed_unit' => 'ms',
        ], $delaySeconds, 'daily');
    }

    /**
     * All ensemble members of one outlook product for one point.
     *
     * @param  array{url: string, model: string, forecast_days: int, variables: list<string>}  $product
     * @param  array{latitude: float, longitude: float}  $point
     * @return array<string, mixed>
     *
     * @throws RequestException|ConnectionException
     */
    public function fetchOutlook(array $product, array $point, float $delaySeconds): array
    {
        return $this->getBlock($product['url'], [
            'latitude' => $point['latitude'],
            'longitude' => $point['longitude'],
            'models' => $product['model'],
            'daily' => implode(',', $product['variables']),
            'forecast_days' => $product['forecast_days'],
            'timezone' => (string) config('open_meteo.timezone'),
            'wind_speed_unit' => 'ms',
        ], $delaySeconds, 'daily');
    }

    /**
     * Observed hourly values for one point and an inclusive range of UTC days (ERA5).
     *
     * @param  array{latitude: float, longitude: float}  $point
     * @return array<string, mixed> Decoded response with an 'hourly' block (unix-second times)
     *
     * @throws RequestException|ConnectionException
     */
    public function fetchArchiveHourly(array $point, CarbonInterface $from, CarbonInterface $to, float $delaySeconds): array
    {
        return $this->getBlock((string) config('open_meteo.archive_url'), [
            'latitude' => $point['latitude'],
            'longitude' => $point['longitude'],
            'start_date' => $from->format('Y-m-d'),
            'end_date' => $to->format('Y-m-d'),
            'hourly' => implode(',', (array) config('open_meteo.observed_hourly_variables')),
            'models' => (string) config('open_meteo.observed_hourly_model'),
            'timezone' => 'GMT',
            'timeformat' => 'unixtime',
            'wind_speed_unit' => 'ms',
        ], $delaySeconds, 'hourly');
    }

    /**
     * One hourly forecast product (single model) for one point, in UTC hours.
     *
     * @param  array{url: string, model: string, forecast_days: int, variables: list<string>}  $product
     * @param  array{latitude: float, longitude: float}  $point
     * @return array<string, mixed>
     *
     * @throws RequestException|ConnectionException
     */
    public function fetchHourlyForecast(array $product, array $point, float $delaySeconds, ?string $run = null): array
    {
        $query = [
            'latitude' => $point['latitude'],
            'longitude' => $point['longitude'],
            'models' => $product['model'],
            'hourly' => implode(',', $product['variables']),
            'forecast_days' => $product['forecast_days'],
            'timezone' => 'GMT',
            'timeformat' => 'unixtime',
            'wind_speed_unit' => 'ms',
        ];
        if ($run !== null) {
            $query['run'] = $run; // Single Runs API: one model run, e.g. 2026-11-06T00:00
        }

        return $this->getBlock($product['url'], $query, $delaySeconds, 'hourly');
    }

    /**
     * True when the Single Runs API answered that the requested run is not available yet
     * (HTTP 400, reason "The requested model run is not available", checked 2026-11-06).
     */
    public static function isRunNotAvailable(Throwable $e): bool
    {
        return $e instanceof RequestException
            && $e->response->status() === 400
            && str_contains(strtolower((string) $e->response->json('reason')), 'not available');
    }

    /**
     * Initialisation time of the latest model run (UTC 'Y-m-d H:i:s'), or null when the
     * metadata cannot be read. Best effort: the metadata file is not a documented API.
     */
    public function fetchModelRunAt(string $metaUrl, float $delaySeconds): ?string
    {
        try {
            $this->waitForRateLimit($delaySeconds);
            $response = Http::acceptJson()
                ->connectTimeout((float) config('open_meteo.connect_timeout', 5))
                ->timeout((float) config('open_meteo.timeout', 60))
                ->get($metaUrl);
            $initialisedAt = $response->successful() ? $response->json('last_run_initialisation_time') : null;
        } catch (Throwable) {
            return null;
        }

        return is_int($initialisedAt) && $initialisedAt > 0
            ? Carbon::createFromTimestampUTC($initialisedAt)->format('Y-m-d H:i:s')
            : null;
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    private function getBlock(string $url, array $query, float $delaySeconds, string $block): array
    {
        $this->waitForRateLimit($delaySeconds);

        $response = Http::acceptJson()
            ->connectTimeout((float) config('open_meteo.connect_timeout', 5))
            ->timeout((float) config('open_meteo.timeout', 60))
            ->retry(3, (int) config('open_meteo.retry_sleep_ms', 5000), function ($exception) {
                if ($exception instanceof ConnectionException) {
                    return true;
                }

                return $exception instanceof RequestException
                    && ($exception->response->status() === 429 || $exception->response->serverError());
            })
            ->get($url, $query);

        $response->throw();

        $payload = $response->json();
        if (! is_array($payload) || ! is_array($payload[$block]['time'] ?? null)) {
            throw new RuntimeException('Open-Meteo returned an unexpected payload.');
        }

        return $payload;
    }

    private function waitForRateLimit(float $delaySeconds): void
    {
        if ($this->lastRequestAt !== null && $delaySeconds > 0) {
            $remaining = $delaySeconds - (microtime(true) - $this->lastRequestAt);
            if ($remaining > 0) {
                usleep((int) round($remaining * 1_000_000));
            }
        }

        $this->lastRequestAt = microtime(true);
    }
}
