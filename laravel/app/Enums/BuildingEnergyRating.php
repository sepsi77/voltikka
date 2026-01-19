<?php

namespace App\Enums;

enum BuildingEnergyRating: string
{
    case Passive = 'passive';
    case LowEnergy = 'low_energy';
    case Year2010 = '2010';
    case Year2000 = '2000';
    case Year1990 = '1990';
    case Year1980 = '1980';
    case Year1970 = '1970';
    case Year1960 = '1960';
    case Older = 'older';

    public function index(): int
    {
        return match ($this) {
            self::Passive => 0,
            self::LowEnergy => 1,
            self::Year2010 => 2,
            self::Year2000 => 3,
            self::Year1990 => 4,
            self::Year1980 => 5,
            self::Year1970 => 6,
            self::Year1960 => 7,
            self::Older => 8,
        };
    }
}
