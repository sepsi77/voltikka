<?php

namespace App\Services\ContractStatistics;

use App\Services\ContractStatistics\Enums\AnnualCostCalculationBasis;
use App\Services\ContractStatistics\Enums\AnnualCostMethodVersion;

final class AnnualCostCompatibilityKey
{
    public static function make(
        AnnualCostMethodVersion $methodVersion,
        AnnualCostCalculationBasis $calculationBasis,
        ?string $estimateMethod,
        ?string $estimateBasis,
    ): string {
        $identity = implode('|', [
            $methodVersion->value,
            $calculationBasis->value,
            $estimateMethod ?? 'none',
            $estimateBasis ?? 'none',
        ]);

        return 'annual-cost-as-of:'.hash('sha256', $identity);
    }
}
