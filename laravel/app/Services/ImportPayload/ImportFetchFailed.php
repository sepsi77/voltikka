<?php

namespace App\Services\ImportPayload;

use RuntimeException;
use Throwable;

/**
 * A fetch that recorded its requests but got nothing usable (for example every unit failed). The
 * fetch closure throws it after the last request; StagedImport then finishes the payload file, so
 * the recorded error bodies stay for diagnosis, and fails the fetch step. Any other throwable from
 * a fetch abandons the file. The reason is closed; the message never holds a URL or a body.
 */
final class ImportFetchFailed extends RuntimeException
{
    public const REASONS = ['all_failed'];

    /** @param  array<string, mixed>  $endFacts  completeness facts for the payload's end line */
    private function __construct(public readonly string $reason, public readonly array $endFacts, ?Throwable $previous)
    {
        parent::__construct("Import fetch failed: {$reason}", previous: $previous);
    }

    /** @param  array<string, mixed>  $endFacts */
    public static function because(string $reason, array $endFacts = [], ?Throwable $previous = null): self
    {
        return new self(in_array($reason, self::REASONS, true) ? $reason : throw new \LogicException('Unknown import fetch failure reason.'), $endFacts, $previous);
    }
}
