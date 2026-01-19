<?php

namespace App\Enums;

enum SupplementaryHeatingMethod: string
{
    case HeatPump = 'heat_pump';
    case ExhaustAirHeatPump = 'exhaust_air_heat_pump';
    case Fireplace = 'fireplace';
}
