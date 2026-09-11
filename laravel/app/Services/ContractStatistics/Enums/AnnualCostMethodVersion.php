<?php

namespace App\Services\ContractStatistics\Enums;

enum AnnualCostMethodVersion: string
{
    case Legacy = 'annual_cost_legacy_v1';
    case AsOf = 'annual_cost_as_of_v1';
    case AsOfV2 = 'annual_cost_as_of_v2';

    public function isAsOf(): bool
    {
        return $this === self::AsOf || $this === self::AsOfV2;
    }
}
