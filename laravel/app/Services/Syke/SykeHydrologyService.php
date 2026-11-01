<?php

namespace App\Services\Syke;

use Carbon\CarbonInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Read-only client for the Syke Hydrologiarajapinta OData API (no key).
 *
 * The server returns at most 500 rows per page and an absolute `odata.nextLink` for the next
 * page. The client follows it only when it points to the configured base URL. Every request
 * after the first waits for the configured delay. HTTP 429, 5xx and connection failures are retried.
 */
class SykeHydrologyService
{
    private ?float $lastRequestAt = null;

    /**
     * Fetch the rows of one entity for the given places and a half-open date range [from, to).
     *
     * @param  list<int>  $placeIds
     * @return list<array<string, mixed>>
     *
     * @throws RequestException|ConnectionException
     */
    public function fetchRows(string $entity, ?int $jaksoId, array $placeIds, CarbonInterface $from, CarbonInterface $to): array
    {
        $baseUrl = rtrim((string) config('syke.base_url'), '/');

        $places = implode(' or ', array_map(fn (int $id) => "Paikka_Id eq {$id}", $placeIds));
        $filter = "({$places})"
            .($jaksoId !== null ? " and Jakso_Id eq {$jaksoId}" : '')
            ." and Aika ge datetime'".$from->format('Y-m-d')."T00:00:00'"
            ." and Aika lt datetime'".$to->format('Y-m-d')."T00:00:00'";

        $url = "{$baseUrl}/{$entity}";
        $query = ['$filter' => $filter, '$orderby' => 'Paikka_Id,Aika'];
        $rows = [];
        $pages = 0;

        do {
            if (++$pages > (int) config('syke.max_pages', 500)) {
                throw new RuntimeException("Syke {$entity} paging did not end.");
            }

            $this->waitForRateLimit();

            $response = Http::acceptJson()
                ->connectTimeout((float) config('syke.connect_timeout', 5))
                ->timeout((float) config('syke.timeout', 30))
                ->retry(3, (int) config('syke.retry_sleep_ms', 2000), function ($exception) {
                    if ($exception instanceof ConnectionException) {
                        return true;
                    }

                    return $exception instanceof RequestException
                        && ($exception->response->status() === 429 || $exception->response->serverError());
                })
                ->get($url, $query);

            $response->throw();

            $payload = $response->json();
            if (! is_array($payload) || ! is_array($payload['value'] ?? null)) {
                throw new RuntimeException("Syke {$entity} returned an unexpected payload.");
            }

            foreach ($payload['value'] as $row) {
                if (is_array($row)) {
                    $rows[] = $row;
                }
            }

            $next = $payload['odata.nextLink'] ?? null;
            if (is_string($next) && $next !== '') {
                if (! str_starts_with($next, "{$baseUrl}/{$entity}?")) {
                    throw new RuntimeException("Syke {$entity} returned a nextLink outside the API.");
                }
                // The nextLink already holds $filter, $orderby and $skip. Pass no query: an
                // empty array would make Guzzle remove the query string of the link.
                $url = $next;
                $query = null;
            } else {
                $url = null;
            }
        } while ($url !== null);

        return $rows;
    }

    private function waitForRateLimit(): void
    {
        $delay = (float) config('syke.request_delay_seconds', 1.0);

        if ($this->lastRequestAt !== null && $delay > 0) {
            $remaining = $delay - (microtime(true) - $this->lastRequestAt);
            if ($remaining > 0) {
                usleep((int) round($remaining * 1_000_000));
            }
        }

        $this->lastRequestAt = microtime(true);
    }
}
