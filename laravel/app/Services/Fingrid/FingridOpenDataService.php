<?php

namespace App\Services\Fingrid;

use Carbon\CarbonInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Read-only client for Fingrid open data (https://data.fingrid.fi/api).
 *
 * Fingrid permits one request every 2 seconds per key, so every request after the first waits
 * for the configured delay. HTTP 429, 5xx and connection failures are retried.
 */
class FingridOpenDataService
{
    private ?float $lastRequestAt = null;

    /**
     * Fetch all raw rows of one dataset for a half-open UTC range, following pagination.
     *
     * @return list<array{datasetId:int,startTime:string,endTime:string,value:float|int|null,additionalJson?:array<string,mixed>|null}>
     *
     * @throws RequestException|ConnectionException
     */
    public function fetchRows(int $datasetId, CarbonInterface $start, CarbonInterface $end): array
    {
        $apiKey = (string) config('fingrid.api_key');
        if ($apiKey === '') {
            throw new RuntimeException('FINGRID_API_KEY is not configured.');
        }

        $rows = [];
        $page = 1;

        do {
            $this->waitForRateLimit();

            $response = Http::withHeaders(['x-api-key' => $apiKey])
                ->acceptJson()
                ->connectTimeout((float) config('fingrid.connect_timeout', 5))
                ->timeout((float) config('fingrid.timeout', 30))
                ->retry(3, (int) config('fingrid.retry_sleep_ms', 3000), function ($exception) {
                    if ($exception instanceof ConnectionException) {
                        return true;
                    }

                    return $exception instanceof RequestException
                        && ($exception->response->status() === 429 || $exception->response->serverError());
                })
                ->get(rtrim((string) config('fingrid.base_url'), '/')."/datasets/{$datasetId}/data", [
                    'startTime' => $start->copy()->utc()->format('Y-m-d\TH:i:s\Z'),
                    'endTime' => $end->copy()->utc()->format('Y-m-d\TH:i:s\Z'),
                    'format' => 'json',
                    'pageSize' => (int) config('fingrid.page_size', 20000),
                    'page' => $page,
                    'sortOrder' => 'asc',
                ]);

            $response->throw();

            $payload = $response->json();
            if (! is_array($payload) || ! is_array($payload['data'] ?? null)) {
                throw new RuntimeException("Fingrid dataset {$datasetId} returned an unexpected payload.");
            }

            foreach ($payload['data'] as $row) {
                $rows[] = $row;
            }

            $nextPage = $payload['pagination']['nextPage'] ?? null;
            $page = is_int($nextPage) ? $nextPage : null;
        } while ($page !== null);

        return $rows;
    }

    private function waitForRateLimit(): void
    {
        $delay = (float) config('fingrid.request_delay_seconds', 2.5);

        if ($this->lastRequestAt !== null && $delay > 0) {
            $remaining = $delay - (microtime(true) - $this->lastRequestAt);
            if ($remaining > 0) {
                usleep((int) round($remaining * 1_000_000));
            }
        }

        $this->lastRequestAt = microtime(true);
    }
}
