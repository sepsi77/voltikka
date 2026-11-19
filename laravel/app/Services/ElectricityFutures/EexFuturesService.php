<?php

namespace App\Services\ElectricityFutures;

use Carbon\CarbonInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;

class EexFuturesService
{
    private ?float $lastRequestStartedAt = null;

    /**
     * Fetch end-of-day futures chart data from EEX. The raw response goes into the import payload
     * (futures:fetch-eex, futures:fetch-eex-europe); decode() and extractPricePoints() parse it in
     * the store step.
     *
     * @param  array<string, mixed>  $instrument
     *
     * @throws RequestException|ConnectionException
     */
    public function fetchEndOfDay(
        array $instrument,
        string $maturity,
        CarbonInterface $startDate,
        CarbonInterface $endDate
    ): Response {
        return $this->get((string) config('eex_futures.endpoint'), $this->endOfDayQuery($instrument, $maturity, $startDate, $endDate));
    }

    /**
     * @param  array<string, mixed>  $instrument
     * @return array<string, string>
     */
    public function endOfDayQuery(
        array $instrument,
        string $maturity,
        CarbonInterface $startDate,
        CarbonInterface $endDate
    ): array {
        $this->assertInstrument($instrument);

        return [
            'commodity' => $instrument['commodity'] ?? 'POWER',
            'pricing' => $instrument['pricing'] ?? 'F',
            'area' => $instrument['area'],
            'product' => $instrument['product'] ?? 'Base',
            'maturity' => $maturity,
            'startDate' => $startDate->toDateString(),
            'endDate' => $endDate->toDateString(),
            'shortCode' => $instrument['short_code'],
        ];
    }

    /**
     * Fetch the lightweight price ticker payload for one futures maturity.
     *
     * EEX returns HTTP 200 with an empty `data` array when the maturity is out
     * of bounds for the selected short code / area / product combination.
     *
     * @param  array<string, mixed>  $instrument
     *
     * @throws RequestException|ConnectionException
     */
    public function fetchPriceTicker(array $instrument, string $maturity): Response
    {
        return $this->get((string) config('eex_futures.price_ticker_endpoint'), $this->priceTickerQuery($instrument, $maturity));
    }

    /**
     * @param  array<string, mixed>  $instrument
     * @return array<string, string>
     */
    public function priceTickerQuery(array $instrument, string $maturity): array
    {
        $this->assertInstrument($instrument);

        return [
            'shortCode' => $instrument['short_code'],
            'area' => $instrument['area'],
            'product' => $instrument['product'] ?? 'Base',
            'commodity' => $instrument['commodity'] ?? 'POWER',
            'pricing' => $instrument['pricing'] ?? 'F',
            'maturity' => $maturity,
        ];
    }

    /**
     * Returns true when a decoded price-ticker payload shows the maturity as listed.
     *
     * @param  array<string, mixed>  $payload
     */
    public function tickerHasData(array $payload): bool
    {
        // An unlisted maturity can come back as one row of nulls (`data: [[null, ...]]`, seen
        // 2026-11-01), not only as an empty array. Count a maturity as listed only when a row
        // has at least one non-null value; otherwise discovery accepted every candidate and the
        // 04:00 run made 793 EOD requests, about 525 of them for maturities without prices.
        foreach (($payload['data'] ?? []) as $row) {
            if (is_array($row) && array_filter($row, fn ($value) => $value !== null) !== []) {
                return true;
            }
        }

        return false;
    }

    /**
     * Decode a raw EEX JSON body like Response::json() did before the staged import: a body that
     * is not a JSON object or list gives an empty payload, not an error.
     *
     * @return array<string, mixed>
     */
    public function decode(?string $body): array
    {
        $decoded = json_decode((string) $body, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Convert EEX chart payload series into database-ready point arrays.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $instrument
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

    /**
     * One throttled, retried public EEX GET. A failed final answer throws RequestException, which
     * keeps the response, so the payload records its status and body.
     *
     * @param  array<string, string>  $params
     *
     * @throws RequestException|ConnectionException
     */
    private function get(string $endpoint, array $params): Response
    {
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
            ->get($endpoint, $params);

        if ($response->failed()) {
            throw new RequestException($response);
        }

        return $response;
    }

    /** @param array<string, mixed> $instrument */
    private function assertInstrument(array $instrument): void
    {
        foreach (['area', 'short_code'] as $requiredKey) {
            if (empty($instrument[$requiredKey])) {
                throw new InvalidArgumentException("EEX futures instrument is missing {$requiredKey}.");
            }
        }
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
     * @param  array<string, mixed>  $payload
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
                if (! is_array($point) || count($point) < 2) {
                    continue;
                }

                $values[(string) $point[0]] = $point[1];
            }

            return $values;
        }

        return [];
    }
}
