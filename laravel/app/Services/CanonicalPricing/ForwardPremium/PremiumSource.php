<?php

namespace App\Services\CanonicalPricing\ForwardPremium;

enum PremiumSource: string
{
    case OwnLineage = 'own_lineage';
    case SameCompany = 'same_company';
    case Market = 'market';
}
