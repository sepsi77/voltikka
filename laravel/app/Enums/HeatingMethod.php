<?php

namespace App\Enums;

enum HeatingMethod: string
{
    case Electricity = 'electricity';
    case AirToWaterHeatPump = 'air_to_water_heat_pump';
    case GroundHeatPump = 'ground_heat_pump';
    case DistrictHeating = 'district_heating';
    case Oil = 'oil';
    case Fireplace = 'fireplace';
    case Pellets = 'pellets';
    case Other = 'other';
}
