<?php

namespace App\Services\ElectricityFutures;

use Carbon\CarbonInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

class EexFuturesService
{
    private ?float $lastRequestStartedAt = null;

    /**
     * Fetch end-of-day futures chart data from EEX.
     *
     * @param array<string, mixed> $instrument
     * @return array<string, mixed>
     * @throws RequestException|ConnectionException
     */
    public function fetchEndOfDayData(
        array $instrument,
        string $maturity,
        CarbonInterface $startDate,
        CarbonInterface $endDate
    ): array {
        foreach (['area', 'short_code'] as $requiredKey) {
            if (empty($instrument[$requiredKey])) {
                throw new InvalidArgumentException("EEX futures instrument is missing {$requiredKey}.");
            }
        }

        $params = [
            'commodity' => $instrument['commodity'] ?? 'POWER',
            'pricing' => $instrument['pricing'] ?? 'F',
            'area' => $instrument['area'],
            'product' => $instrument['product'] ?? 'Base',
            'maturity' => $maturity,
            'startDate' => $startDate->toDateString(),
            'endDate' => $endDate->toDateString(),
            'shortCode' => $instrument['short_code'],
        ];

        $this->throttleRequests();

        $response = Http::withHeaders([
            'Referer' => config('eex_futures.referer', 'https://www.eex.com/'),
            'Accept' => 'application/json',
        ])
            ->connectTimeout((float) config('eex_futures.connect_timeout', 5))
            ->timeout((float) config('eex_futures.timeout', 20))
            ->retry(
                (int) config('eex_futures.retry_times', 3),
                (int) config('eex_futures.retry_sleep_ms', 1000),
                fn ($exception) => $exception instanceof ConnectionException
                    || ($exception instanceof RequestException
                        && ($exception->response?->serverError() || $exception->response === null))
            )
            ->get(config('eex_futures.endpoint'), $params);

        if ($response->failed()) {
            Log::warning('Failed to fetch EEX futures EOD data', [
                'status' => $response->status(),
                'area' => $instrument['area'],
                'short_code' => $instrument['short_code'],
                'maturity' => $maturity,
                'body' => substr($response->body(), 0, 1000),
            ]);

            throw new RequestException($response);
        }

        return $response->json() ?? [];
    }

    /**
     * Fetch the lightweight price ticker payload for one futures maturity.
     *
     * EEX returns HTTP 200 with an empty `data` array when the maturity is out
     * of bounds for the selected short code / area / product combination.
     *
     * @param array<string, mixed> $instrument
     * @return array<string, mixed>
     * @throws RequestException|ConnectionException
     */
    public function fetchPriceTickerData(array $instrument, string $maturity): array
    {
        foreach (['area', 'short_code'] as $requiredKey) {
            if (empty($instrument[$requiredKey])) {
                throw new InvalidArgumentException("EEX futures instrument is missing {$requiredKey}.");
            }
        }

        $params = [
            'shortCode' => $instrument['short_code'],
            'area' => $instrument['area'],
            'product' => $instrument['product'] ?? 'Base',
            'commodity' => $instrument['commodity'] ?? 'POWER',
            'pricing' => $instrument['pricing'] ?? 'F',
            'maturity' => $maturity,
        ];

        $this->throttleRequests();

        $response = Http::withHeaders([
            'Referer' => config('eex_futures.referer', 'https://www.eex.com/'),
            'Accept' => 'application/json',
        ])
            ->connectTimeout((float) config('eex_futures.connect_timeout', 5))
            ->timeout((float) config('eex_futures.timeout', 20))
            ->retry(
                (int) config('eex_futures.retry_times', 3),
                (int) config('eex_futures.retry_sleep_ms', 1000),
                fn ($exception) => $exception instanceof ConnectionException
                    || ($exception instanceof RequestException
                        && ($exception->response?->serverError() || $exception->response === null))
            )
            ->get(config('eex_futures.price_ticker_endpoint'), $params);

        if ($response->failed()) {
            Log::warning('Failed to fetch EEX futures price ticker data', [
                'status' => $response->status(),
                'area' => $instrument['area'],
                'short_code' => $instrument['short_code'],
                'maturity' => $maturity,
                'body' => substr($response->body(), 0, 1000),
            ]);

            throw new RequestException($response);
        }

        return $response->json() ?? [];
    }

    /**
     * Returns true when EEX currently exposes this maturity for the instrument.
     *
     * @param array<string, mixed> $instrument
     * @throws RequestException|ConnectionException
     */
    public function maturityHasTickerData(array $instrument, string $maturity): bool
    {
        $payload = $this->fetchPriceTickerData($instrument, $maturity);

        return !empty($payload['data'] ?? []);
    }

    /**
     * Convert EEX chart payload series into database-ready point arrays.
     *
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $instrument
     * @return array<int, array<string, mixed>>
     */
    public function extractPricePoints(array $payload, array $instrument, string $maturity): array
    {
        $settlementPrices = $this->seriesValues($payload, 'settlPx');
        $volumes = $this->seriesValues($payload, 'volume');
        $lotSizes = $this->seriesValues($payload, 'lotSize');

        $points = [];

        foreach ($settlementPrices as $tradeDate => $settlementPrice) {
            if ($settlementPrice === null || $settlementPrice === '') {
                continue;
            }

            $points[] = [
                'exchange' => 'EEX',
                'commodity' => $instrument['commodity'] ?? 'POWER',
                'pricing' => $instrument['pricing'] ?? 'F',
                'product' => $instrument['product'] ?? 'Base',
                'market_region' => $instrument['market_region'] ?? null,
                'area' => $instrument['area'],
                'area_name' => $instrument['area_name'] ?? null,
                'short_code' => $instrument['short_code'],
                'maturity' => $maturity,
                'maturity_type' => $instrument['maturity_type'] ?? 'year',
                'display_year' => $payload['displayYear'] ?? null,
                'display_season' => $payload['displaySeason'] ?? null,
                'display_quarter' => $payload['displayQuarter'] ?? null,
                'display_month' => $payload['displayMonth'] ?? null,
                'display_week' => $payload['displayWeek'] ?? null,
                'display_day' => $payload['displayDay'] ?? null,
                'trade_date' => $tradeDate,
                'settlement_price' => $settlementPrice,
                'volume' => $volumes[$tradeDate] ?? null,
                'lot_size' => $lotSizes[$tradeDate] ?? null,
                'currency' => $payload['currency'] ?? null,
                'unit' => $payload['uOM'] ?? null,
                'long_name' => $payload['longName'] ?? null,
                'last_update' => $payload['lastUpdate'] ?? null,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        return $points;
    }

    private function throttleRequests(): void
    {
        if (app()->runningUnitTests()) {
            return;
        }

        $baseDelaySeconds = max(0.0, (float) config('eex_futures.request_delay_seconds', 15));
        $jitterSeconds = max(0.0, (float) config('eex_futures.request_delay_jitter_seconds', 5));

        if ($baseDelaySeconds <= 0.0 && $jitterSeconds <= 0.0) {
            $this->lastRequestStartedAt = microtime(true);
            return;
        }

        if ($this->lastRequestStartedAt !== null) {
            $minimumDelay = max(0.0, $baseDelaySeconds - $jitterSeconds);
            $maximumDelay = $baseDelaySeconds + $jitterSeconds;
            $targetDelay = random_int(
                (int) round($minimumDelay * 1_000_000),
                (int) round($maximumDelay * 1_000_000)
            ) / 1_000_000;

            $elapsed = microtime(true) - $this->lastRequestStartedAt;
            $sleepSeconds = $targetDelay - $elapsed;

            if ($sleepSeconds > 0) {
                usleep((int) round($sleepSeconds * 1_000_000));
            }
        }

        $this->lastRequestStartedAt = microtime(true);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function seriesValues(array $payload, string $seriesName): array
    {
        foreach (($payload['series'] ?? []) as $series) {
            if (($series['serieName'] ?? null) !== $seriesName) {
                continue;
            }

            $values = [];
            foreach (($series['timeAndValue'] ?? []) as $point) {
                if (!is_array($point) || count($point) < 2) {
                    continue;
                }

                $values[(string) $point[0]] = $point[1];
            }

            return $values;
        }

        return [];
    }
}
