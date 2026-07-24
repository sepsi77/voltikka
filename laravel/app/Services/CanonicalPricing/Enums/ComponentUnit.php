<?php

namespace App\Services\CanonicalPricing\Enums;

/**
 * Canonical pricing component units (schema-v3 `$defs.component.unit`).
 */
enum ComponentUnit: string
{
    case CentsPerKwh = 'cents_per_kwh';
    case EurPerMonth = 'eur_per_month';
    case EurFlat = 'eur_flat';
    case Percent = 'percent';
    case Unknown = 'unknown';

    /**
     * Units the calculator cannot cost into a 12-month total. A window-relevant
     * component carrying one of these forces the contract out of comparison.
     */
    public function isCostable(): bool
    {
        return match ($this) {
            self::CentsPerKwh, self::EurPerMonth, self::EurFlat => true,
            self::Percent, self::Unknown => false,
        };
    }
}
