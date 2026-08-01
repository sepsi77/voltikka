<?php

namespace App\Enums;

enum MeteringType: string
{
    case General = 'General';
    case Time = 'Time';
    case Season = 'Season';

    public static function fromSource(mixed $value): ?self
    {
        if (! is_string($value)) {
            return null;
        }

        return match (strtolower(trim($value))) {
            'general' => self::General,
            'time' => self::Time,
            'season', 'seasonal' => self::Season,
            default => null,
        };
    }

    public static function fromString(?string $value): self
    {
        return self::fromSource($value) ?? self::General;
    }
}
