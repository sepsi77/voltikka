<?php

namespace App\Services\Caching;

use RuntimeException;

final class ContractPriceCacheStorageException extends RuntimeException
{
    private function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }

    public static function writeFailed(): self
    {
        return new self('cache_write_failed', 'Price cache write failed.');
    }

    public static function readbackFailed(): self
    {
        return new self('cache_readback_failed', 'Candidate price cache readback failed.');
    }
}
