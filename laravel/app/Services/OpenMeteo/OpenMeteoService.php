<?php

namespace App\Services\OpenMeteo;

use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Read-only client for the free Open-Meteo APIs (archive, forecast, ensemble, seasonal). No key.
 *
 * Every request after the first waits for the given delay, because the free tier allows fewer
 * than 600 calls per minute and 5,000 per hour, and a long or wide request counts as several
 * calls. HTTP 429, 5xx and connection failures are retried.
 *
 * The client only sends requests and returns raw responses: weather:fetch saves them in a payload
 * file (tasks/staged-api-imports/), and the store step parses the saved bodies.
 */
class OpenMeteoService
{
    private ?float $lastRequestAt = null;

    /**
     * Request of observed daily values for one point and an inclusive range of local days.
     *
     * @param  array{latitude: float, longitude: float}  $point
     * @return array{0: string, 1: array<string, mixed>} URL and query for fetch()
     */
    public function archiveRequest(array $point, CarbonInterface $from, CarbonInterface $to): array
    {
        return [(string) config('open_meteo.archive_url'), [
            'latitude' => $point['latitude'],
            'longitude' => $point['longitude'],
            'start_date' => $from->format('Y-m-d'),
            'end_date' => $to->format('Y-m-d'),
            'daily' => implode(',', (array) config('open_meteo.observed_variables')),
            'models' => (string) config('open_meteo.observed_model'),
            'timezone' => (string) config('open_meteo.timezone'),
            'wind_speed_unit' => 'ms',
        ]];
    }

    /**
     * Request of all ensemble members of one outlook product for one point.
     *
     * @param  array{url: string, model: string, forecast_days: int, variables: list<string>}  $product
     * @param  array{latitude: float, longitude: float}  $point
     * @return array{0: string, 1: array<string, mixed>}
     */
    public function outlookRequest(array $product, array $point): array
    {
        return [$product['url'], [
            'latitude' => $point['latitude'],
            'longitude' => $point['longitude'],
            'models' => $product['model'],
            'daily' => implode(',', $product['variables']),
            'forecast_days' => $product['forecast_days'],
            'timezone' => (string) config('open_meteo.timezone'),
            'wind_speed_unit' => 'ms',
        ]];
    }

    /**
     * Request of observed hourly values for one point and an inclusive range of UTC days (ERA5).
     *
     * @param  array{latitude: float, longitude: float}  $point
     * @return array{0: string, 1: array<string, mixed>}
     */
    public function archiveHourlyRequest(array $point, CarbonInterface $from, CarbonInterface $to): array
    {
        return [(string) config('open_meteo.archive_url'), [
            'latitude' => $point['latitude'],
            'longitude' => $point['longitude'],
            'start_date' => $from->format('Y-m-d'),
            'end_date' => $to->format('Y-m-d'),
            'hourly' => implode(',', (array) config('open_meteo.observed_hourly_variables')),
            'models' => (string) config('open_meteo.observed_hourly_model'),
            'timezone' => 'GMT',
            'timeformat' => 'unixtime',
            'wind_speed_unit' => 'ms',
        ]];
    }

    /**
     * Request of one hourly forecast product (single model) for one point, in UTC hours.
     *
     * @param  array{url: string, model: string, forecast_days: int, variables: list<string>}  $product
     * @param  array{latitude: float, longitude: float}  $point
     * @return array{0: string, 1: array<string, mixed>}
     */
    public function hourlyForecastRequest(array $product, array $point, ?string $run = null): array
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
        if (isset($product['past_days'])) {
            $query['past_days'] = $product['past_days']; // Previous Runs API: past valid hours
        }
        if ($run !== null) {
            $query['run'] = $run; // Single Runs API: one model run, e.g. 2026-11-06T00:00
        }

        return [$product['url'], $query];
    }

    /**
     * Send one data request and return the answered response with its raw body (the fetch step
     * saves it; OpenMeteoWeatherImporter::decode() parses it in the store step).
     *
     * @param  array<string, mixed>  $query
     *
     * @throws RequestException|ConnectionException
     */
    public function fetch(string $url, array $query, float $delaySeconds): Response
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

        return $response->throw();
    }

    /**
     * True when the Single Runs API answered that the requested run is not available yet
     * (HTTP 400, reason "The requested model run is not available", checked 2026-11-06).
     */
    public static function isRunNotAvailable(Throwable $e): bool
    {
        return $e instanceof RequestException
            && self::isRunNotAvailableAnswer($e->response->status(), $e->response->body());
    }

    /** The same check on a saved status and body (the store step). */
    public static function isRunNotAvailableAnswer(int $status, ?string $body): bool
    {
        $decoded = json_decode((string) $body, true);

        return $status === 400
            && is_array($decoded)
            && str_contains(strtolower((string) ($decoded['reason'] ?? '')), 'not available');
    }

    /**
     * The model-run metadata file, answered with any status (no retry, no throw). Best effort: the
     * file is not a documented API. modelRunAt() reads the saved answer.
     *
     * @throws ConnectionException
     */
    public function fetchMeta(string $metaUrl, float $delaySeconds): Response
    {
        $this->waitForRateLimit($delaySeconds);

        return Http::acceptJson()
            ->connectTimeout((float) config('open_meteo.connect_timeout', 5))
            ->timeout((float) config('open_meteo.timeout', 60))
            ->get($metaUrl);
    }

    /**
     * Initialisation time of the latest model run (UTC 'Y-m-d H:i:s') from a saved metadata
     * answer, or null when it was not answered, not successful or has no usable time.
     */
    public static function modelRunAt(?int $status, ?string $body): ?string
    {
        if ($status === null || $status < 200 || $status >= 300) {
            return null;
        }
        $decoded = json_decode((string) $body, true);
        $initialisedAt = is_array($decoded) ? ($decoded['last_run_initialisation_time'] ?? null) : null;

        return is_int($initialisedAt) && $initialisedAt > 0
            ? Carbon::createFromTimestampUTC($initialisedAt)->format('Y-m-d H:i:s')
            : null;
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
