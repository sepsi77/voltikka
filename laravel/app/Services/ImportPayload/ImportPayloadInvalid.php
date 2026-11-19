<?php

namespace App\Services\ImportPayload;

use RuntimeException;

/**
 * A payload file the store step must not read. The reason is closed, so it can go into a JSON
 * result line, a log and a Sentry Issue as it is; the message never holds a path or file content.
 */
final class ImportPayloadInvalid extends RuntimeException
{
    public const REASONS = ['missing', 'sha256_mismatch', 'incomplete', 'wrong_source', 'wrong_date', 'options_mismatch', 'superseded'];

    private function __construct(public readonly string $reason)
    {
        parent::__construct("Import payload refused: {$reason}");
    }

    public static function because(string $reason): self
    {
        return new self(in_array($reason, self::REASONS, true) ? $reason : throw new \LogicException('Unknown import payload reason.'));
    }
}
