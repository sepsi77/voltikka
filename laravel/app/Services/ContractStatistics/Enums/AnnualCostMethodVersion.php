<?php

namespace App\Services\ContractStatistics\Enums;

enum AnnualCostMethodVersion: string
{
    case Legacy = 'annual_cost_legacy_v1';
    case AsOf = 'annual_cost_as_of_v1';
}
