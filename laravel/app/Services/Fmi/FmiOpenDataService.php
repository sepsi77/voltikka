<?php

namespace App\Services\Fmi;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

/**
 * Read-only client for the FMI open data WFS (https://opendata.fmi.fi/wfs).
 *
 * FMI permits 600 requests per 5 minutes, so every request after the first waits for the
 * configured delay. HTTP 429, 5xx and connection failures are retried. An FMI exception report
 * (HTTP 400 with ExceptionText) becomes an FmiRequestException without the request URL.
 */
class FmiOpenDataService
{
    private ?float $lastRequestAt = null;

    /**
     * Run one stored query and return the XML body.
     *
     * @param  list<array{0: string, 1: string}>  $parameters  Name/value pairs; a name can repeat (fmisid, latlon)
     *
     * @throws FmiRequestException|RequestException|ConnectionException
     */
    public function getFeature(string $storedQuery, array $parameters): string
    {
        $pairs = [
            ['service', 'WFS'],
            ['version', '2.0.0'],
            ['request', 'getFeature'],
            ['storedquery_id', $storedQuery],
            ...$parameters,
        ];
        // Build the query by hand: Laravel's array encoding cannot repeat a parameter name.
        $query = implode('&', array_map(fn (array $pair) => rawurlencode($pair[0]).'='.rawurlencode($pair[1]), $pairs));

        $this->waitForRateLimit();

        $response = Http::accept('application/xml')
            ->connectTimeout((float) config('fmi.connect_timeout', 10))
            ->timeout((float) config('fmi.timeout', 120))
            ->retry((int) config('fmi.retry_times', 3), (int) config('fmi.retry_sleep_ms', 5000), function ($exception) {
                if ($exception instanceof ConnectionException) {
                    return true;
                }

                return $exception instanceof RequestException
                    && ($exception->response->status() === 429 || $exception->response->serverError());
            }, throw: false)
            ->get(rtrim((string) config('fmi.base_url'), '/').'?'.$query);

        $body = $response->body();

        if ($response->clientError() && str_contains($body, 'ExceptionText')) {
            throw new FmiRequestException($this->exceptionText($body));
        }

        $response->throw();

        return $body;
    }

    /**
     * First ExceptionText of an FMI exception report. FMI repeats the request URI in a later
     * ExceptionText ("URI: ..."); that one is never used.
     */
    private function exceptionText(string $body): string
    {
        preg_match_all('#<ExceptionText>(.*?)</ExceptionText>#s', $body, $matches);

        foreach ($matches[1] as $text) {
            $text = trim(html_entity_decode($text, ENT_QUOTES | ENT_XML1));
            if ($text !== '' && ! str_starts_with($text, 'URI:')) {
                return 'FMI request failed: '.mb_substr($text, 0, 200);
            }
        }

        return 'FMI request failed with an exception report.';
    }

    private function waitForRateLimit(): void
    {
        $delay = (float) config('fmi.request_delay_seconds', 1);

        if ($this->lastRequestAt !== null && $delay > 0) {
            $remaining = $delay - (microtime(true) - $this->lastRequestAt);
            if ($remaining > 0) {
                usleep((int) round($remaining * 1_000_000));
            }
        }

        $this->lastRequestAt = microtime(true);
    }
}
