<?php

namespace App\Services\CanonicalPricing\Enums;

/**
 * Canonical pricing component price roles (schema-v3 `$defs.component.price_role`).
 */
enum PriceRole: string
{
    case Current = 'current';
    case Introductory = 'introductory';
    case Normal = 'normal';
    case Future = 'future';
    case Estimated = 'estimated';
    case Typical = 'typical';
    case Bound = 'bound';
    case Unknown = 'unknown';

    /**
     * Roles that describe non-billed disclosure figures (consumption-effect ranges),
     * not an amount that participates in the 12-month cost timeline.
     */
    public function isDisclosureOnly(): bool
    {
        return match ($this) {
            self::Typical, self::Bound, self::Estimated => true,
            default => false,
        };
    }
}
