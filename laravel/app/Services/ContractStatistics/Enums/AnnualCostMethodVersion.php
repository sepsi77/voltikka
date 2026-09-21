<?php

namespace App\Services\ContractStatistics\Enums;

enum AnnualCostMethodVersion: string
{
    case Legacy = 'annual_cost_legacy_v1';
    case AsOf = 'annual_cost_as_of_v1';
    case AsOfV2 = 'annual_cost_as_of_v2';
    case AsOfV3 = 'annual_cost_as_of_v3';

    public function usesReconstructionSafety(): bool
    {
        return $this === self::AsOfV2 || $this === self::AsOfV3;
    }

    public function isAsOf(): bool
    {
        return $this === self::AsOf || $this->usesReconstructionSafety();
    }
}
