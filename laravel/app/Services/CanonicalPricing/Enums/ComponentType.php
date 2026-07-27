<?php

namespace App\Services\CanonicalPricing\Enums;

/**
 * Canonical pricing component types (schema-v4 `$defs.component.component_type`).
 */
enum ComponentType: string
{
    case EnergyGeneral = 'energy_general';
    case EnergyDay = 'energy_day';
    case EnergyNight = 'energy_night';
    case EnergySeasonalWinter = 'energy_seasonal_winter';
    case EnergySeasonalOther = 'energy_seasonal_other';
    case SpotMargin = 'spot_margin';
    case MonthlyFee = 'monthly_fee';
    case ConsumptionEffect = 'consumption_effect';
    case FlatFee = 'flat_fee';
    case Other = 'other';

    /**
     * The relational usage-profile bucket this energy component is priced against,
     * matching MonthlyUsageProfileBuilder output keys. Null for non-per-kWh components.
     */
    public function usageBucket(): ?string
    {
        return match ($this) {
            self::EnergyGeneral => 'General',
            self::EnergyDay => 'DayTime',
            self::EnergyNight => 'NightTime',
            self::EnergySeasonalWinter => 'SeasonalWinterDay',
            self::EnergySeasonalOther => 'SeasonalOther',
            default => null,
        };
    }

    public function isPerKwhEnergy(): bool
    {
        return $this->usageBucket() !== null;
    }

    public function isMonthlyFee(): bool
    {
        return $this === self::MonthlyFee;
    }

    public function isFlatFee(): bool
    {
        return $this === self::FlatFee;
    }
}
