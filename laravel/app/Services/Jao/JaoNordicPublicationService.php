<?php

namespace App\Services\Jao;

use Carbon\CarbonInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use RuntimeException;

/**
 * Read-only client for the JAO Nordic flow-based publication tool.
 *
 * Every request after the first waits for the configured delay. HTTP 429, 5xx and connection
 * failures are retried. The API rejects a range over 2 days (HTTP 400), so callers split.
 */
class JaoNordicPublicationService
{
    private ?float $lastRequestAt = null;

    /**
     * Fetch maxBorderFlow rows (one per MTU) for a half-open UTC range of at most 48 hours.
     *
     * @return list<array<string, mixed>>
     *
     * @throws RequestException|ConnectionException
     */
    public function fetchMaxBorderFlows(CarbonInterface $start, CarbonInterface $end): array
    {
        $maxHours = (int) config('jao.max_window_hours', 48);
        if ($start->diffInHours($end) > $maxHours) {
            throw new InvalidArgumentException("JAO request range exceeds {$maxHours} hours.");
        }

        $this->waitForRateLimit();

        $response = Http::acceptJson()
            ->connectTimeout((float) config('jao.connect_timeout', 5))
            ->timeout((float) config('jao.timeout', 30))
            ->retry(3, (int) config('jao.retry_sleep_ms', 5000), function ($exception) {
                if ($exception instanceof ConnectionException) {
                    return true;
                }

                return $exception instanceof RequestException
                    && ($exception->response->status() === 429 || $exception->response->serverError());
            })
            ->get(rtrim((string) config('jao.base_url'), '/').'/maxBorderFlow', [
                'FromUtc' => $start->copy()->utc()->format('Y-m-d\TH:i:s.000\Z'),
                'ToUtc' => $end->copy()->utc()->format('Y-m-d\TH:i:s.000\Z'),
            ]);

        $response->throw();

        $payload = $response->json();
        if (! is_array($payload) || ! is_array($payload['data'] ?? null)) {
            throw new RuntimeException('JAO maxBorderFlow returned an unexpected payload.');
        }

        return array_values($payload['data']);
    }

    private function waitForRateLimit(): void
    {
        $delay = (float) config('jao.request_delay_seconds', 2);

        if ($this->lastRequestAt !== null && $delay > 0) {
            $remaining = $delay - (microtime(true) - $this->lastRequestAt);
            if ($remaining > 0) {
                usleep((int) round($remaining * 1_000_000));
            }
        }

        $this->lastRequestAt = microtime(true);
    }
}
