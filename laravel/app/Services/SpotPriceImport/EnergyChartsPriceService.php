<?php

namespace App\Services\SpotPriceImport;

use Carbon\Carbon;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;
use UnexpectedValueException;

/**
 * DE-LU day-ahead prices of SMARD (Bundesnetzagentur | SMARD.de, CC BY 4.0) via the Energy-Charts
 * API. spot:fetch-neighbours uses them only for DE-LU quarter-hours that ENTSO-E sequence 1 does
 * not cover (ENTSO-E sometimes publishes DE-LU only as sequence 2). See AGENTS.md.
 */
class EnergyChartsPriceService
{
    public const REGION = 'DE-LU';

    private const MAX_RETRIES = 3;

    private const RETRY_DELAY_MS = 1000;

    /**
     * Fetch the raw price answer for a half-open UTC window, with the ENTSO-E timeouts and retry
     * rule: every failed attempt is retried, and any answer outside 2xx is a failed attempt.
     *
     * @throws RequestException|ConnectionException after the last attempt
     */
    public function fetchResponse(Carbon $start, Carbon $end): Response
    {
        [$url, $query] = $this->request($start, $end);

        return retry(self::MAX_RETRIES, function () use ($url, $query): Response {
            $response = Http::connectTimeout((int) config('services.energy_charts.connect_timeout', 5))
                ->timeout((int) config('services.energy_charts.timeout', 30))
                ->acceptJson()
                ->get($url, $query);
            if (! $response->successful()) {
                throw new RequestException($response);
            }

            return $response;
        }, self::RETRY_DELAY_MS, fn (Throwable $exception): bool => $exception instanceof ConnectionException || $exception instanceof RequestException);
    }

    /**
     * The URL and query of one request. Energy-Charts treats `end` as inclusive; parsePrices()
     * keeps the window half-open.
     *
     * @return array{0: string, 1: array<string, string>}
     */
    public function request(Carbon $start, Carbon $end): array
    {
        return [
            (string) config('services.energy_charts.price_url', 'https://api.energy-charts.info/price'),
            [
                'bzn' => self::REGION,
                'start' => $start->copy()->utc()->format('Y-m-d\TH:i\Z'),
                'end' => $end->copy()->utc()->format('Y-m-d\TH:i\Z'),
            ],
        ];
    }

    /**
     * Parse an answer into records of the same shape as EntsoeService::parseDayAheadPrices()
     * (quarter-hour resolution, c/kWh VAT 0). Null prices are skipped; points outside the
     * half-open window are dropped.
     *
     * @return list<array{region: string, timestamp: int, utc_datetime: Carbon, price_without_tax: float, resolution_minutes: int}>
     *
     * @throws UnexpectedValueException for a body that is not the expected price answer
     */
    public function parsePrices(string $body, Carbon $start, Carbon $end): array
    {
        $data = json_decode($body, true);
        $times = $data['unix_seconds'] ?? null;
        $prices = $data['price'] ?? null;
        if (! is_array($times) || ! is_array($prices) || ! array_is_list($times) || ! array_is_list($prices)
            || count($times) !== count($prices) || ($data['unit'] ?? null) !== 'EUR / MWh') {
            throw new UnexpectedValueException('Energy-Charts price answer has an unexpected shape.');
        }

        $records = [];
        $previous = null;
        foreach ($times as $index => $timestamp) {
            $price = $prices[$index];
            // Quarter-hour points in strictly increasing order; a price is a number or null.
            if (! is_int($timestamp) || $timestamp % 900 !== 0 || ($previous !== null && $timestamp <= $previous)
                || ($price !== null && ! is_int($price) && ! is_float($price))) {
                throw new UnexpectedValueException('Energy-Charts price answer has an invalid point.');
            }
            $previous = $timestamp;
            if ($price === null || $timestamp < $start->timestamp || $timestamp >= $end->timestamp) {
                continue;
            }

            $records[] = [
                'region' => self::REGION,
                'timestamp' => $timestamp,
                'utc_datetime' => Carbon::createFromTimestampUTC($timestamp),
                'price_without_tax' => (float) $price / 10, // EUR/MWh to c/kWh, as parseDayAheadPrices()
                'resolution_minutes' => 15,
            ];
        }

        return $records;
    }
}
