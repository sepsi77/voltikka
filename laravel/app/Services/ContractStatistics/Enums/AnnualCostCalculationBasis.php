<?php

namespace App\Services\ContractStatistics\Enums;

enum AnnualCostCalculationBasis: string
{
    case ObservedRelationalComponents = 'observed_relational_components';
    case CanonicalOutcome = 'canonical_outcome';
}
