<?php

namespace App\Services\CanonicalPricing\Enums;

/** Explicit compatibility boundary; dates must not select financial policy. */
enum ComparisonPolicy
{
    case Current;
    case Historical;
}
