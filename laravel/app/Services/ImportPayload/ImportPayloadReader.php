<?php

namespace App\Services\ImportPayload;

use Carbon\CarbonImmutable;
use Generator;
use Throwable;

/**
 * Reads a payload file written by ImportPayloadWriter. open() verifies the SHA-256 and the whole
 * structure (header first, request lines, one end line last, request count) before the store step
 * sees anything, so a store never starts on a missing, changed or truncated file. requests()
 * streams the file again line by line; the file is never held in memory as a whole.
 */
final class ImportPayloadReader
{
    /**
     * @param  array<string, mixed>  $header
     * @param  array<string, mixed>  $end
     */
    private function __construct(
        private readonly string $path,
        private readonly string $sha256,
        private readonly array $header,
        private readonly array $end,
    ) {}

    /**
     * @param  string|null  $sha256  from the payload reference; else read from the .sha256 file beside it
     *
     * @throws ImportPayloadInvalid missing, sha256_mismatch or incomplete
     */
    public static function open(string $path, ?string $sha256 = null): self
    {
        if (! is_file($path)) {
            throw ImportPayloadInvalid::because('missing');
        }
        if ($sha256 === null || $sha256 === '') {
            // The .sha256 file is renamed last, so a payload without it never finished.
            $shaPath = preg_replace('/\.ndjson\.gz$/', '.sha256', $path);
            if ($shaPath === $path || ! is_file($shaPath)) {
                throw ImportPayloadInvalid::because('incomplete');
            }
            $sha256 = trim((string) file_get_contents($shaPath));
        }
        if (! hash_equals(strtolower($sha256), (string) hash_file('sha256', $path))) {
            throw ImportPayloadInvalid::because('sha256_mismatch');
        }

        [$header, $end] = self::scan($path);

        return new self($path, strtolower($sha256), $header, $end);
    }

    public function path(): string
    {
        return $this->path;
    }

    public function sha256(): string
    {
        return $this->sha256;
    }

    /** @return array<string, mixed> */
    public function header(): array
    {
        return $this->header;
    }

    /** @return array<string, mixed> */
    public function end(): array
    {
        return $this->end;
    }

    /** The fetch time from the header; the store step uses it, never the clock, for data meaning (S5). */
    public function fetchedAt(): CarbonImmutable
    {
        return CarbonImmutable::parse($this->header['fetched_at'], 'UTC');
    }

    /** A data-meaning fact the fetch step fixed in the header. */
    public function fact(string $key, mixed $default = null): mixed
    {
        return $this->header['facts'][$key] ?? $default;
    }

    public function complete(): bool
    {
        return $this->end['failed_units'] === [];
    }

    /**
     * The request lines in fetch order, one decoded line at a time.
     *
     * @return Generator<int, array<string, mixed>>
     */
    public function requests(): Generator
    {
        $handle = gzopen($this->path, 'rb');
        try {
            while (($line = gzgets($handle)) !== false) {
                $decoded = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
                if ($decoded['type'] === 'request') {
                    yield $decoded;
                }
            }
        } finally {
            gzclose($handle);
        }
    }

    /** The raw response body of a request line (text or decoded base64), null for a connection failure. */
    public static function body(array $request): ?string
    {
        if (isset($request['body_base64'])) {
            return (string) base64_decode($request['body_base64'], true);
        }

        return $request['body'] ?? null;
    }

    /** @return array{0: array<string, mixed>, 1: array<string, mixed>} */
    private static function scan(string $path): array
    {
        $header = null;
        $end = null;
        $requests = 0;
        $handle = @gzopen($path, 'rb');
        if ($handle === false) {
            throw ImportPayloadInvalid::because('incomplete');
        }

        try {
            while (($line = gzgets($handle)) !== false) {
                $decoded = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
                $type = is_array($decoded) ? ($decoded['type'] ?? null) : null;
                $valid = match (true) {
                    $header === null => $type === 'header' && ($decoded['version'] ?? null) === ImportPayloadWriter::VERSION
                        && is_string($decoded['fetched_at'] ?? null) && is_array($decoded['facts'] ?? null)
                        && is_array($decoded['options'] ?? null),
                    $end !== null => false,
                    default => $type === 'request' || ($type === 'end' && is_array($decoded['failed_units'] ?? null)),
                };
                if (! $valid) {
                    throw ImportPayloadInvalid::because('incomplete');
                }
                match (true) {
                    $header === null => $header = $decoded,
                    $type === 'end' => $end = $decoded,
                    default => $requests++,
                };
            }
        } catch (ImportPayloadInvalid $e) {
            throw $e;
        } catch (Throwable) {
            throw ImportPayloadInvalid::because('incomplete');
        } finally {
            gzclose($handle);
        }

        if ($header === null || $end === null || ($end['requests'] ?? null) !== $requests) {
            throw ImportPayloadInvalid::because('incomplete');
        }

        return [$header, $end];
    }
}
