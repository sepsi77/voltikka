<?php

namespace App\Enums;

enum MeteringType: string
{
    case General = 'General';
    case Time = 'Time';
    case Season = 'Season';

    public static function fromString(?string $value): self
    {
        return match (strtolower($value ?? '')) {
            'general' => self::General,
            'time' => self::Time,
            'season', 'seasonal' => self::Season,
            default => self::General,
        };
    }
}
