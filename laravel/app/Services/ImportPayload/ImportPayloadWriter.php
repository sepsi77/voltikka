<?php

namespace App\Services\ImportPayload;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;
use RuntimeException;
use Throwable;

/**
 * Writes one fetch run as a gzip NDJSON payload file: header, one line per request, end line.
 *
 * Request lines stream into a compressed temporary body file as they are recorded, so a large run
 * never sits in memory. finish() writes the header (with every fact set during the fetch), copies
 * the body after it into one gzip stream, and renames the result into place. The .sha256 file is
 * renamed last, so its presence means the payload is complete.
 *
 * Secrets are removed here and nowhere else: request headers are never recorded, URL user info
 * and query strings are dropped from `url`, and secret query parameters are removed from `query`.
 */
final class ImportPayloadWriter
{
    public const VERSION = 1;

    /** Query parameter names (lowercase) that are never written. */
    public const SECRET_QUERY_PARAMETERS = [
        'securitytoken', 'token', 'access_token', 'api_key', 'apikey', 'key', 'x-api-key',
        'subscription-key', 'password', 'secret',
    ];

    /** @var resource|null */
    private $body;

    private int $requests = 0;

    /** @var list<array<string, mixed>> */
    private array $failedUnits = [];

    private bool $closed = false;

    /**
     * @param  array<string, mixed>  $options
     * @param  array<string, mixed>  $facts
     */
    private function __construct(
        private readonly string $directory,
        private readonly string $runId,
        private readonly string $source,
        private readonly string $command,
        private readonly array $options,
        private array $facts,
        private readonly CarbonImmutable $fetchedAt,
    ) {
        $body = gzopen($this->bodyTmpPath(), 'wb6');
        if ($body === false) {
            throw new RuntimeException('Import payload file could not be opened.');
        }
        $this->body = $body;
    }

    /**
     * Start a run. The clock is read here, once: fetched_at is the run's data time (S5).
     *
     * @param  array<string, mixed>  $options  the command's data options as given; never secrets
     * @param  array<string, mixed>  $facts  data-meaning times and plans fixed by the fetch step
     */
    public static function open(string $source, string $command, array $options = [], array $facts = []): self
    {
        if (preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/', $source) !== 1) {
            throw new InvalidArgumentException('Import payload source must be a lowercase slug.');
        }

        $fetchedAt = CarbonImmutable::now('UTC')->startOfSecond();
        $directory = rtrim((string) config('import_payloads.path'), '/')
            ."/{$source}/".$fetchedAt->setTimezone('Europe/Helsinki')->toDateString();
        if (! is_dir($directory) && ! @mkdir($directory, 0775, true) && ! is_dir($directory)) {
            throw new RuntimeException('Import payload directory could not be created.');
        }

        return new self($directory, (string) Str::uuid(), $source, $command, $options, $facts, $fetchedAt);
    }

    public function fetchedAt(): CarbonImmutable
    {
        return $this->fetchedAt;
    }

    public function runId(): string
    {
        return $this->runId;
    }

    /** Fix a data-meaning fact (S5, S19). It lands in the header; allowed until finish(). */
    public function setFact(string $key, mixed $value): void
    {
        $this->assertOpen();
        $this->facts[$key] = $value;
    }

    /**
     * Record an answered request. Status, body, method and the redacted effective URI come from
     * the response. Pass $failed when the command treats this answer as a failed unit (S10).
     *
     * @param  array<string, scalar|null>  $unit  closed identifiers that tell the store step what the body is
     * @param  'base64'|null  $bodyEncoding  base64 for binary bodies; invalid UTF-8 is base64 anyway
     */
    public function record(array $unit, Response $response, ?string $bodyEncoding = null, bool $failed = false): void
    {
        $request = $response->transferStats?->getRequest();
        $this->writeRequest(
            $unit,
            $request?->getMethod() ?? 'GET',
            $response->effectiveUri() !== null ? (string) $response->effectiveUri() : null,
            [],
            ['status' => $response->status()],
            $response->body(),
            $bodyEncoding,
            $failed,
        );
    }

    /**
     * Record a request that failed. A RequestException with a response keeps its status and body;
     * any other failure (for example a ConnectionException) is a connection_failed line with no body.
     *
     * @param  array<string, scalar|null>  $unit
     * @param  array<string, mixed>  $query
     */
    public function recordFailure(array $unit, string $method, string $url, array $query, Throwable $e): void
    {
        if ($e instanceof RequestException && $e->response !== null) {
            $effective = $e->response->effectiveUri();
            $this->writeRequest(
                $unit,
                $e->response->transferStats?->getRequest()?->getMethod() ?? $method,
                $effective !== null ? (string) $effective : $url,
                $effective !== null ? [] : $query,
                ['status' => $e->response->status()],
                $e->response->body(),
                null,
                true,
            );

            return;
        }

        $this->writeRequest($unit, $method, $url, $query, ['connection_failed' => true, 'exception' => $e::class], null, null, true);
    }

    /** @param  array<string, mixed>  $endFacts  completeness facts for the end line */
    public function finish(array $endFacts = []): ImportPayloadReference
    {
        $this->assertOpen();
        $this->writeLine([
            'type' => 'end',
            'requests' => $this->requests,
            'failed_units' => $this->failedUnits,
            'facts' => (object) $endFacts,
        ]);
        $this->closed = true;
        if (gzclose($this->body) === false) {
            throw new RuntimeException('Import payload file could not be closed.');
        }
        $this->body = null;

        $header = $this->encode([
            'type' => 'header',
            'version' => self::VERSION,
            'source' => $this->source,
            'command' => $this->command,
            'options' => (object) $this->options,
            'run_id' => $this->runId,
            'fetched_at' => $this->fetchedAt->format('Y-m-d\TH:i:s\Z'),
            'facts' => (object) $this->facts,
        ])."\n";
        // One gzip member: the header, then the compressed body copied through in 1 MB chunks.
        $out = gzopen($this->payloadTmpPath(), 'wb6');
        $in = gzopen($this->bodyTmpPath(), 'rb');
        $written = $out !== false && $in !== false && gzwrite($out, $header) === strlen($header);
        while ($written && ($chunk = gzread($in, 1 << 20)) !== false && $chunk !== '') {
            $written = gzwrite($out, $chunk) === strlen($chunk);
        }
        if (! $written || ! gzclose($out) || ! gzclose($in)) {
            throw new RuntimeException('Import payload file could not be written.');
        }
        unlink($this->bodyTmpPath());

        $sha256 = hash_file('sha256', $this->payloadTmpPath());
        $size = filesize($this->payloadTmpPath());
        $path = $this->directory."/{$this->runId}.ndjson.gz";
        $shaTmp = $this->directory."/{$this->runId}.sha256.tmp";
        if (! rename($this->payloadTmpPath(), $path)
            || file_put_contents($shaTmp, $sha256."\n") === false
            || ! rename($shaTmp, $this->directory."/{$this->runId}.sha256")) {
            throw new RuntimeException('Import payload file could not be renamed.');
        }

        return new ImportPayloadReference(
            $path, $sha256, $size, $this->fetchedAt, $this->source, $this->runId, $this->requests, count($this->failedUnits),
        );
    }

    /** Drop an unfinished run's temporary files (a failed fetch). Safe to call more than once. */
    public function abandon(): void
    {
        if (is_resource($this->body)) {
            gzclose($this->body);
        }
        $this->body = null;
        $this->closed = true;
        foreach ([$this->bodyTmpPath(), $this->payloadTmpPath()] as $tmp) {
            if (is_file($tmp)) {
                unlink($tmp);
            }
        }
    }

    /**
     * The URL without user info and query string, and its query without secret parameters.
     * Repeated query names become a list; names keep their exact spelling.
     *
     * @return array{0: string, 1: array<string, mixed>}
     */
    public static function redactUrl(string $url): array
    {
        $parts = parse_url($url);
        if ($parts === false || ! isset($parts['host'])) {
            return ['', []];
        }

        $clean = ($parts['scheme'] ?? 'https').'://'.$parts['host']
            .(isset($parts['port']) ? ':'.$parts['port'] : '').($parts['path'] ?? '');
        $query = [];
        foreach (explode('&', $parts['query'] ?? '') as $pair) {
            if ($pair === '') {
                continue;
            }
            [$name, $value] = array_pad(explode('=', $pair, 2), 2, '');
            $name = urldecode($name);
            $value = urldecode($value);
            if (array_key_exists($name, $query)) {
                $query[$name] = [...(array) $query[$name], $value];
            } else {
                $query[$name] = $value;
            }
        }

        return [$clean, self::redactQuery($query)];
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    public static function redactQuery(array $query): array
    {
        return array_filter(
            $query,
            fn (string|int $name) => ! in_array(strtolower((string) $name), self::SECRET_QUERY_PARAMETERS, true),
            ARRAY_FILTER_USE_KEY,
        );
    }

    /**
     * @param  array<string, scalar|null>  $unit
     * @param  array<string, mixed>  $query
     * @param  array<string, mixed>  $outcome
     */
    private function writeRequest(array $unit, string $method, ?string $url, array $query, array $outcome, ?string $body, ?string $bodyEncoding, bool $failed): void
    {
        $this->assertOpen();
        if ($bodyEncoding !== null && $bodyEncoding !== 'base64') {
            throw new InvalidArgumentException('Unknown body encoding.');
        }

        [$cleanUrl, $urlQuery] = $url !== null ? self::redactUrl($url) : [null, []];
        $line = [
            'type' => 'request',
            'unit' => (object) $unit,
            'method' => strtoupper($method),
            'url' => $cleanUrl,
            'query' => (object) ($urlQuery + self::redactQuery($query)),
            ...$outcome,
        ];
        if ($body !== null) {
            $binary = $bodyEncoding === 'base64' || ! mb_check_encoding($body, 'UTF-8');
            $line[$binary ? 'body_base64' : 'body'] = $binary ? base64_encode($body) : $body;
        }

        $this->writeLine($line);
        $this->requests++;
        if ($failed) {
            $this->failedUnits[] = $unit;
        } else {
            // A later answered line of the same unit (an in-run retry pass) recovers it; the store
            // reads the last answered line of a unit.
            $this->failedUnits = array_values(array_filter($this->failedUnits, fn (array $failedUnit): bool => $failedUnit != $unit));
        }
    }

    /** @param  array<string, mixed>  $line */
    private function writeLine(array $line): void
    {
        $encoded = $this->encode($line)."\n";
        if (gzwrite($this->body, $encoded) !== strlen($encoded)) {
            throw new RuntimeException('Import payload line could not be written.');
        }
    }

    /** @param  array<string, mixed>  $line */
    private function encode(array $line): string
    {
        return json_encode($line, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    private function assertOpen(): void
    {
        if ($this->closed) {
            throw new LogicException('Import payload run is already finished.');
        }
    }

    private function bodyTmpPath(): string
    {
        return $this->directory."/{$this->runId}.body.ndjson.gz.tmp";
    }

    private function payloadTmpPath(): string
    {
        return $this->directory."/{$this->runId}.ndjson.gz.tmp";
    }
}
