<?php

namespace App\Services\CanonicalPricing\Enums;

/**
 * Supported reset cadence for an included-energy package allowance.
 *
 * Only monthly allowances are costable. A future schema can add another case only when
 * the calculator has a reviewed reset and carry-over rule for it.
 */
enum AllowanceCadence: string
{
    case Monthly = 'monthly';
}
