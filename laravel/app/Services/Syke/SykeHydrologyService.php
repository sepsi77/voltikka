<?php

namespace App\Services\Syke;

use Carbon\CarbonInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Read-only client for the Syke Hydrologiarajapinta OData API (no key).
 *
 * The server returns at most 500 rows per page and an absolute `odata.nextLink` for the next
 * page. The staged import (FetchSykeHydrology) requests and records one page at a time;
 * parsePage() reads a saved body and accepts a nextLink only when it points to the entity URL
 * under the given base URL (the store step passes the one recorded at fetch time). Every request
 * after the first waits for the configured delay. HTTP 429, 5xx and connection failures are
 * retried.
 */
class SykeHydrologyService
{
    private ?float $lastRequestAt = null;

    /**
     * URL and query of the first page of one entity for the given places and a half-open date
     * range [from, to).
     *
     * @param  list<int>  $placeIds
     * @return array{0: string, 1: array<string, string>}
     */
    public function firstPage(string $entity, ?int $jaksoId, array $placeIds, CarbonInterface $from, CarbonInterface $to): array
    {
        $places = implode(' or ', array_map(fn (int $id) => "Paikka_Id eq {$id}", $placeIds));
        $filter = "({$places})"
            .($jaksoId !== null ? " and Jakso_Id eq {$jaksoId}" : '')
            ." and Aika ge datetime'".$from->format('Y-m-d')."T00:00:00'"
            ." and Aika lt datetime'".$to->format('Y-m-d')."T00:00:00'";

        return [$this->entityUrl($entity), ['$filter' => $filter, '$orderby' => 'Paikka_Id,Aika']];
    }

    /** The configured API base URL without a trailing slash; the fetch step records it. */
    public function baseUrl(): string
    {
        return rtrim((string) config('syke.base_url'), '/');
    }

    public function maxPages(): int
    {
        return (int) config('syke.max_pages', 500);
    }

    /**
     * One page request. A nextLink already holds $filter, $orderby and $skip, so it is passed with
     * a null query: an empty array would make Guzzle remove the query string of the link.
     *
     * @param  array<string, string>|null  $query
     *
     * @throws RequestException|ConnectionException
     */
    public function requestPage(string $url, ?array $query): Response
    {
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

        return $response->throw();
    }

    /**
     * The rows and the next page URL (null on the last page) of one saved page body. A nextLink
     * must point to the entity URL under $baseUrl, the base URL the page was fetched from.
     *
     * @return array{rows: list<array<string, mixed>>, next: string|null}
     *
     * @throws RuntimeException an unexpected payload or a nextLink outside the API
     */
    public function parsePage(string $entity, string $body, string $baseUrl): array
    {
        $payload = json_decode($body, true);
        if (! is_array($payload) || ! is_array($payload['value'] ?? null)) {
            throw new RuntimeException("Syke {$entity} returned an unexpected payload.");
        }

        $next = $payload['odata.nextLink'] ?? null;
        if (! is_string($next) || $next === '') {
            $next = null;
        } elseif (! str_starts_with($next, rtrim($baseUrl, '/').'/'.$entity.'?')) {
            throw new RuntimeException("Syke {$entity} returned a nextLink outside the API.");
        }

        return ['rows' => array_values(array_filter($payload['value'], 'is_array')), 'next' => $next];
    }

    private function entityUrl(string $entity): string
    {
        return $this->baseUrl().'/'.$entity;
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
