<?php

namespace App\Services\GasStorage;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Read-only client for GIE AGSI+ aggregated gas storage data (https://agsi.gie.eu/api).
 *
 * The staged import (FetchGasStorage) requests one page at a time and records the raw response;
 * parsePage() reads a saved body. AGSI answers an invalid key or bad query with HTTP 200 and an
 * `error` field, so parsePage() checks the payload as well as the status. GIE allows 60 calls per
 * minute; every call after the first waits for the configured delay. The key goes in the `x-key`
 * header, never in the query, so a payload file never holds it.
 */
class GieAgsiService
{
    public const MAX_PAGES = 100;

    private ?float $lastRequestAt = null;

    public function url(): string
    {
        return (string) config('gas_storage.base_url');
    }

    /**
     * Query of one page for one area (AGSI `type`/`country` parameters) and an inclusive gas-day range.
     *
     * @param  array<string, string>  $areaParams
     * @return array<string, mixed>
     */
    public function pageQuery(array $areaParams, string $from, string $to, int $page): array
    {
        return $areaParams + [
            'from' => $from,
            'to' => $to,
            'size' => (int) config('gas_storage.page_size', 300),
            'page' => $page,
        ];
    }

    /**
     * One page request. The response is returned raw; a failed status after retries throws.
     *
     * @param  array<string, mixed>  $query  from pageQuery()
     *
     * @throws RequestException|ConnectionException|RuntimeException
     */
    public function requestPage(array $query): Response
    {
        $apiKey = (string) config('gas_storage.api_key');
        if ($apiKey === '') {
            throw new RuntimeException('GIE_API_KEY is not configured.');
        }

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
            ->get($this->url(), $query);

        return $response->throw();
    }

    /**
     * The daily rows and the last page number of one page body.
     *
     * @return array{rows: list<array<string, mixed>>, last_page: int}
     *
     * @throws RuntimeException an unexpected payload or an AGSI error response
     */
    public static function parsePage(string $body): array
    {
        $payload = json_decode($body, true);
        if (! is_array($payload) || ! is_array($payload['data'] ?? null)) {
            throw new RuntimeException('GIE AGSI returned an unexpected payload.');
        }
        if (! empty($payload['error'])) {
            throw new RuntimeException('GIE AGSI returned an error response.');
        }

        return [
            'rows' => array_values(array_filter($payload['data'], 'is_array')),
            'last_page' => (int) ($payload['last_page'] ?? 1),
        ];
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
