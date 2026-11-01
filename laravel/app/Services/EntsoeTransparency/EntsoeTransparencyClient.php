<?php

namespace App\Services\EntsoeTransparency;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use SimpleXMLElement;
use ZipArchive;

/**
 * Read-only client for the ENTSO-E Transparency Platform API (market-review datasets).
 *
 * Returns the XML documents of one query. Unavailability answers are ZIP files with one XML
 * document per outage version; other answers are one XML document. An acknowledgement with
 * reason 999 means "no matching data" and returns no documents.
 *
 * Exception messages can contain the request URL with the securityToken: callers must log the
 * exception class only.
 */
class EntsoeTransparencyClient
{
    /** The unavailability API returns at most 200 documents per request; `offset` pages. */
    public const OUTAGE_PAGE_SIZE = 200;

    /** The API rejects an offset above 4800. */
    private const MAX_OFFSET = 4800;

    private ?float $lastRequestAt = null;

    /**
     * @param  array<string, string>  $params  Query parameters without the securityToken
     * @return list<string> XML documents
     *
     * @throws RequestException|ConnectionException
     */
    public function fetchDocuments(array $params): array
    {
        $apiKey = (string) config('services.entsoe.api_key');
        if ($apiKey === '') {
            throw new RuntimeException('ENTSOE_API_KEY is not configured.');
        }

        $this->waitForRateLimit();

        // Retry every failed attempt. ENTSO-E sometimes holds a request for 18-30 s and then answers
        // with a non-5xx error (see EntsoeService).
        $response = Http::connectTimeout((float) config('services.entsoe.connect_timeout', 5))
            ->timeout((float) config('entsoe_transparency.timeout', 60))
            ->retry(3, (int) config('entsoe_transparency.retry_sleep_ms', 2000), function ($exception) {
                return $exception instanceof ConnectionException || $exception instanceof RequestException;
            })
            ->get((string) config('services.entsoe.base_url', 'https://web-api.tp.entsoe.eu/api'), ['securityToken' => $apiKey] + $params);

        $response->throw();

        $body = $response->body();

        return str_starts_with($body, "PK\x03\x04") ? $this->unzip($body) : $this->xmlDocuments($body);
    }

    /**
     * Fetch every page of an unavailability query (offset paging, 200 documents per page).
     *
     * @param  array<string, string>  $params
     * @return list<string>
     *
     * @throws RequestException|ConnectionException
     */
    public function fetchAllOutageDocuments(array $params): array
    {
        $documents = [];
        $offset = 0;

        while (true) {
            $page = $this->fetchDocuments($params + ['offset' => (string) $offset]);
            array_push($documents, ...$page);

            if (count($page) < self::OUTAGE_PAGE_SIZE) {
                return $documents;
            }

            $offset += self::OUTAGE_PAGE_SIZE;
            if ($offset > self::MAX_OFFSET) {
                throw new RuntimeException('ENTSO-E unavailability query has more than 5000 documents; use a shorter period.');
            }
        }
    }

    /**
     * @return list<string>
     */
    private function xmlDocuments(string $body): array
    {
        if (! str_contains($body, 'Acknowledgement_MarketDocument')) {
            return [$body];
        }

        try {
            $ack = new SimpleXMLElement($body);
        } catch (\Exception) {
            throw new RuntimeException('ENTSO-E returned an unreadable acknowledgement.');
        }

        $code = (string) ($ack->Reason->code ?? '');
        if ($code === '999') {
            return [];
        }

        throw new RuntimeException("ENTSO-E returned an acknowledgement with reason {$code}.");
    }

    /**
     * @return list<string>
     */
    private function unzip(string $body): array
    {
        $path = tempnam(sys_get_temp_dir(), 'entsoe-zip-');
        if ($path === false) {
            throw new RuntimeException('Cannot create a temporary file for the ENTSO-E ZIP.');
        }

        try {
            file_put_contents($path, $body);
            $zip = new ZipArchive;
            if ($zip->open($path) !== true) {
                throw new RuntimeException('ENTSO-E returned an unreadable ZIP file.');
            }

            $documents = [];
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = (string) $zip->getNameIndex($i);
                if (str_ends_with(strtolower($name), '.xml')) {
                    $documents[$name] = (string) $zip->getFromIndex($i);
                }
            }
            $zip->close();
            ksort($documents);

            return array_values($documents);
        } finally {
            @unlink($path);
        }
    }

    private function waitForRateLimit(): void
    {
        $delay = (float) config('entsoe_transparency.request_delay_seconds', 0.5);

        if ($this->lastRequestAt !== null && $delay > 0) {
            $remaining = $delay - (microtime(true) - $this->lastRequestAt);
            if ($remaining > 0) {
                usleep((int) round($remaining * 1_000_000));
            }
        }

        $this->lastRequestAt = microtime(true);
    }
}
