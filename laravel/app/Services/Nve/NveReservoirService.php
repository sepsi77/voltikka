<?php

namespace App\Services\Nve;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Read-only client for NVE Magasinstatistikk (https://biapi.nve.no/magasinstatistikk).
 *
 * Two endpoints: the full weekly history from 1995 (about 4 MB, one request) and the
 * min/median/max per ISO week for the last twenty years. The staged import
 * (FetchNveReservoirs) records each raw response; parse() reads a saved body. Every request
 * after the first waits for the configured delay. HTTP 429, 5xx and connection failures are retried.
 */
class NveReservoirService
{
    public const WEEKS = 'HentOffentligData';

    public const REFERENCES = 'HentOffentligDataMinMaxMedian';

    private ?float $lastRequestAt = null;

    public function url(string $endpoint): string
    {
        return rtrim((string) config('nve.base_url'), '/').'/'.$endpoint;
    }

    /**
     * The raw response of one endpoint; a failed status after retries throws.
     *
     * @throws RequestException|ConnectionException
     */
    public function request(string $endpoint): Response
    {
        $this->waitForRateLimit();

        $response = Http::acceptJson()
            ->connectTimeout((float) config('nve.connect_timeout', 5))
            ->timeout((float) config('nve.timeout', 60))
            ->retry(3, (int) config('nve.retry_sleep_ms', 5000), function ($exception) {
                if ($exception instanceof ConnectionException) {
                    return true;
                }

                return $exception instanceof RequestException
                    && ($exception->response->status() === 429 || $exception->response->serverError());
            })
            ->get($this->url($endpoint));

        return $response->throw();
    }

    /**
     * The rows of one saved response body.
     *
     * @return list<array<string, mixed>>
     *
     * @throws RuntimeException
     */
    public static function parse(string $endpoint, string $body): array
    {
        $payload = json_decode($body, true);
        if (! is_array($payload) || ! array_is_list($payload)) {
            throw new RuntimeException("NVE {$endpoint} returned an unexpected payload.");
        }

        return $payload;
    }

    private function waitForRateLimit(): void
    {
        $delay = (float) config('nve.request_delay_seconds', 2);

        if ($this->lastRequestAt !== null && $delay > 0) {
            $remaining = $delay - (microtime(true) - $this->lastRequestAt);
            if ($remaining > 0) {
                usleep((int) round($remaining * 1_000_000));
            }
        }

        $this->lastRequestAt = microtime(true);
    }
}
