<?php

namespace App\Enums;

enum BuildingType: string
{
    case DetachedHouse = 'detached_house';
    case Apartment = 'apartment';
    case RowHouse = 'row_house';
}
