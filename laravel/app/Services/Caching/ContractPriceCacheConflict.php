<?php

namespace App\Services\Caching;

use RuntimeException;

final class ContractPriceCacheConflict extends RuntimeException
{
    public const EVIDENCE_CHANGED = 'evidence_changed';

    public const GENERATION_CHANGED = 'generation_changed';

    private function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }

    public static function evidenceChanged(): self
    {
        return new self(self::EVIDENCE_CHANGED, 'Contract evidence changed during price calculation.');
    }

    public static function generationChanged(): self
    {
        return new self(self::GENERATION_CHANGED, 'Price cache changed while replacement was built.');
    }
}
