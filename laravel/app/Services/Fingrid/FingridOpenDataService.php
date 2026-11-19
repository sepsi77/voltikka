<?php

namespace App\Services\Fingrid;

use App\Services\ImportPayload\ImportPayloadWriter;
use Carbon\CarbonInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Read-only client for Fingrid open data (https://data.fingrid.fi/api).
 *
 * Fingrid permits one request every 2 seconds per key, so every request after the first waits
 * for the configured delay. HTTP 429, 5xx and connection failures are retried. get() returns the
 * raw answer of one page (the staged fetch step records it); page() reads it.
 */
class FingridOpenDataService
{
    private ?float $lastRequestAt = null;

    /** Fail the whole run before any request when the key is missing. */
    public function assertConfigured(): void
    {
        if ((string) config('fingrid.api_key') === '') {
            throw new RuntimeException('FINGRID_API_KEY is not configured.');
        }
    }

    public function url(int $datasetId): string
    {
        return rtrim((string) config('fingrid.base_url'), '/')."/datasets/{$datasetId}/data";
    }

    /**
     * The query of one page of a half-open UTC range.
     *
     * @return array<string, string|int>
     */
    public function query(CarbonInterface $start, CarbonInterface $end, int $page): array
    {
        return [
            'startTime' => $start->copy()->utc()->format('Y-m-d\TH:i:s\Z'),
            'endTime' => $end->copy()->utc()->format('Y-m-d\TH:i:s\Z'),
            'format' => 'json',
            'pageSize' => (int) config('fingrid.page_size', 20000),
            'page' => $page,
            'sortOrder' => 'asc',
        ];
    }

    /**
     * One answered page with retries. The raw body goes into the import payload; read it with
     * page(), whose `next_page` says whether the staged fetch step requests another page. The key
     * travels in the x-api-key header, which the payload never records.
     *
     * @throws RequestException|ConnectionException
     */
    public function get(int $datasetId, CarbonInterface $start, CarbonInterface $end, int $page): Response
    {
        $this->assertConfigured();
        $this->waitForRateLimit();

        return Http::withHeaders(['x-api-key' => (string) config('fingrid.api_key')])
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
            ->get($this->url($datasetId), $this->query($start, $end, $page))
            ->throw();
    }

    /**
     * The raw rows and the next page number of one answer body.
     *
     * @return array{rows: list<array{datasetId:int,startTime:string,endTime:string,value:float|int|null,additionalJson?:array<string,mixed>|null}>, next_page: int|null}
     */
    public static function page(string $body): array
    {
        $payload = json_decode($body, true);
        if (! is_array($payload) || ! is_array($payload['data'] ?? null)) {
            throw new RuntimeException('Fingrid returned an unexpected payload.');
        }
        $nextPage = $payload['pagination']['nextPage'] ?? null;

        return ['rows' => array_values($payload['data']), 'next_page' => is_int($nextPage) ? $nextPage : null];
    }

    /**
     * Fetch step: record every page of one range on the payload, following nextPage. Returns null
     * when every page was answered and readable, else the failure: the failed request is recorded
     * (failed unit) and no later page is requested. The caller logs the class only.
     *
     * @param  array<string, scalar>  $unit  closed identifiers; `page` is added per request
     */
    public function recordPages(ImportPayloadWriter $payload, array $unit, int $datasetId, CarbonInterface $start, CarbonInterface $end): ?Throwable
    {
        $page = 1;
        do {
            $key = $unit + ['page' => $page];
            try {
                $response = $this->get($datasetId, $start, $end, $page);
            } catch (Throwable $e) {
                $payload->recordFailure($key, 'GET', $this->url($datasetId), $this->query($start, $end, $page), $e);

                return $e;
            }
            try {
                $page = self::page($response->body())['next_page'];
            } catch (Throwable $e) {
                $payload->record($key, $response, failed: true);

                return $e;
            }
            $payload->record($key, $response);
        } while ($page !== null);

        return null;
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
