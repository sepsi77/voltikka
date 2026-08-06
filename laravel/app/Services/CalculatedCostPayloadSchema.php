<?php

namespace App\Services;

final class CalculatedCostPayloadSchema
{
    public const VERSION = 14;

    public static function cacheMarker(): string
    {
        return 'cs'.self::VERSION;
    }
}
