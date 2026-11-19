<?php

namespace App\Services\EntsoeTransparency;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use SimpleXMLElement;
use ZipArchive;

/**
 * Read-only client for the ENTSO-E Transparency Platform API (market-review datasets).
 *
 * get() returns the raw answer of one query (the staged fetch step records it); documents() reads
 * it. Unavailability answers are ZIP files with one XML document per outage version; other answers
 * are one XML document. An acknowledgement with reason 999 means "no matching data" and returns no
 * documents.
 *
 * Exception messages can contain the request URL with the securityToken: callers must log the
 * exception class only.
 */
class EntsoeTransparencyClient
{
    /** The unavailability API returns at most 200 documents per request; `offset` pages. */
    public const OUTAGE_PAGE_SIZE = 200;

    /** The API rejects an offset above 4800. */
    public const MAX_OFFSET = 4800;

    private ?float $lastRequestAt = null;

    /** Fail the whole run before any request when the key is missing. */
    public function assertConfigured(): void
    {
        if ((string) config('services.entsoe.api_key') === '') {
            throw new RuntimeException('ENTSOE_API_KEY is not configured.');
        }
    }

    public function baseUrl(): string
    {
        return (string) config('services.entsoe.base_url', 'https://web-api.tp.entsoe.eu/api');
    }

    /**
     * One answered request with retries. The raw body goes into the import payload; parse it with
     * documents(). The staged fetch step pages unavailability queries itself (offset), because the
     * next page depends on the size of this one.
     *
     * @param  array<string, string>  $params  Query parameters without the securityToken
     *
     * @throws RequestException|ConnectionException
     */
    public function get(array $params): Response
    {
        $this->assertConfigured();
        $this->waitForRateLimit();

        // Retry every failed attempt. ENTSO-E sometimes holds a request for 18-30 s and then answers
        // with a non-5xx error (see EntsoeService).
        $response = Http::connectTimeout((float) config('services.entsoe.connect_timeout', 5))
            ->timeout((float) config('entsoe_transparency.timeout', 60))
            ->retry(3, (int) config('entsoe_transparency.retry_sleep_ms', 2000), function ($exception) {
                return $exception instanceof ConnectionException || $exception instanceof RequestException;
            })
            ->get($this->baseUrl(), ['securityToken' => (string) config('services.entsoe.api_key')] + $params);

        return $response->throw();
    }

    /** Unavailability answers are ZIP files (binary; the payload keeps them base64). */
    public static function isZip(string $body): bool
    {
        return str_starts_with($body, "PK\x03\x04");
    }

    /**
     * The XML documents of one answer body: the files of a ZIP, else the body itself. An
     * acknowledgement with reason 999 ("no matching data") gives no documents; any other
     * acknowledgement or an unreadable ZIP throws.
     *
     * @return list<string>
     */
    public function documents(string $body): array
    {
        return self::isZip($body) ? $this->unzip($body) : $this->xmlDocuments($body);
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
