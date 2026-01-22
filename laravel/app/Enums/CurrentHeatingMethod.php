<?php

namespace App\Enums;

enum CurrentHeatingMethod: string
{
    case Electricity = 'electricity';
    case Oil = 'oil';
    case DistrictHeating = 'district_heating';
}
