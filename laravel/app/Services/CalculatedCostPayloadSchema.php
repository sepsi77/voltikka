<?php

namespace App\Services;

final class CalculatedCostPayloadSchema
{
    public const VERSION = 13;

    public static function cacheMarker(): string
    {
        return 'cs'.self::VERSION;
    }
}
