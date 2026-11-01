<?php

namespace App\Services\GasStorage;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Read-only client for GIE AGSI+ aggregated gas storage data (https://agsi.gie.eu/api).
 *
 * AGSI answers an invalid key or bad query with HTTP 200 and an `error` field, so the payload
 * is checked as well as the status. GIE allows 60 calls per minute; every call after the first
 * waits for the configured delay.
 */
class GieAgsiService
{
    private const MAX_PAGES = 100;

    private ?float $lastRequestAt = null;

    /**
     * Fetch all daily rows for one area (AGSI `type`/`country` parameters) and an inclusive
     * gas-day range, following pagination.
     *
     * @param  array<string, string>  $areaParams
     * @return list<array<string, mixed>>
     *
     * @throws RequestException|ConnectionException|RuntimeException
     */
    public function fetchDays(array $areaParams, string $from, string $to): array
    {
        $apiKey = (string) config('gas_storage.api_key');
        if ($apiKey === '') {
            throw new RuntimeException('GIE_API_KEY is not configured.');
        }

        $rows = [];
        $page = 1;

        do {
            $this->waitForRateLimit();

            $response = Http::withHeaders(['x-key' => $apiKey])
                ->acceptJson()
                ->connectTimeout((float) config('gas_storage.connect_timeout', 5))
                ->timeout((float) config('gas_storage.timeout', 30))
                ->retry(3, (int) config('gas_storage.retry_sleep_ms', 5000), function ($exception) {
                    if ($exception instanceof ConnectionException) {
                        return true;
                    }

                    return $exception instanceof RequestException
                        && ($exception->response->status() === 429 || $exception->response->serverError());
                })
                ->get((string) config('gas_storage.base_url'), $areaParams + [
                    'from' => $from,
                    'to' => $to,
                    'size' => (int) config('gas_storage.page_size', 300),
                    'page' => $page,
                ]);

            $response->throw();

            $payload = $response->json();
            if (! is_array($payload) || ! is_array($payload['data'] ?? null)) {
                throw new RuntimeException('GIE AGSI returned an unexpected payload.');
            }
            if (! empty($payload['error'])) {
                throw new RuntimeException('GIE AGSI returned an error response.');
            }

            foreach ($payload['data'] as $row) {
                if (is_array($row)) {
                    $rows[] = $row;
                }
            }

            $lastPage = (int) ($payload['last_page'] ?? 1);
            $page++;
        } while ($page <= $lastPage && $page <= self::MAX_PAGES);

        return $rows;
    }

    private function waitForRateLimit(): void
    {
        $delay = (float) config('gas_storage.request_delay_seconds', 1.5);

        if ($this->lastRequestAt !== null && $delay > 0) {
            $remaining = $delay - (microtime(true) - $this->lastRequestAt);
            if ($remaining > 0) {
                usleep((int) round($remaining * 1_000_000));
            }
        }

        $this->lastRequestAt = microtime(true);
    }
}
