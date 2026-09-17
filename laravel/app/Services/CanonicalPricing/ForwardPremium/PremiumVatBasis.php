<?php

namespace App\Services\CanonicalPricing\ForwardPremium;

enum PremiumVatBasis: string
{
    case Included = 'included';
    case Excluded = 'excluded';
}
