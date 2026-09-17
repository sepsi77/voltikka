<?php

namespace App\Services\CanonicalPricing\DTO;

use App\Services\CanonicalPricing\MarketReset\DTO\ResetEstimate;
use App\Services\CanonicalPricing\SupplierAdjusted\DTO\SupplierAdjustedEstimate;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/** Internal only. Rates and offsets must already use the calculation VAT basis. */
final readonly class NormalEnergyProjection
{
    /** @param array<string, float> $currentRates Exact canonical energy buckets, not promotional prices. */
    public function __construct(
        public array $currentRates,
        public SupplierAdjustedEstimate|ResetEstimate $estimate,
    ) {
        if ($currentRates === []) {
            throw new InvalidArgumentException('Missing normal energy rates.');
        }
        foreach ($currentRates as $key => $rate) {
            if (! in_array($key, ['energy_general', 'energy_day', 'energy_night', 'energy_seasonal_winter', 'energy_seasonal_other'], true)
                || (! is_int($rate) && ! is_float($rate)) || ! is_finite((float) $rate) || $rate < 0) {
                throw new InvalidArgumentException('Invalid normal energy rate.');
            }
        }
        $offsets = $estimate->offsetsByMonthKey;
        foreach ($estimate->bucketOffsetsByMonthKey as $buckets) {
            if (! is_array($buckets)) {
                throw new InvalidArgumentException('Invalid bucket offsets.');
            }
            $offsets = array_merge($offsets, array_values($buckets));
        }
        foreach ($offsets as $offset) {
            if ((! is_int($offset) && ! is_float($offset)) || ! is_finite((float) $offset)
                || ($estimate->basis->value === 'hold_flat' && (float) $offset !== 0.0)) {
                throw new InvalidArgumentException('Invalid normal energy offset.');
            }
        }
        $current = $estimate instanceof ResetEstimate ? $estimate->currentPeriodEnergyPriceCentsPerKwh : $estimate->currentEnergyPriceCentsPerKwh;
        if (! is_finite($current) || $current < 0) {
            throw new InvalidArgumentException('Invalid estimate current price.');
        }
        $representative = count($currentRates) === 1 ? reset($currentRates) : null;
        if ($estimate instanceof SupplierAdjustedEstimate) {
            if (isset($currentRates['energy_day'], $currentRates['energy_night']) && count($currentRates) === 2) {
                $representative = ($currentRates['energy_day'] * 15 + $currentRates['energy_night'] * 9) / 24;
            } elseif (isset($currentRates['energy_seasonal_winter'], $currentRates['energy_seasonal_other']) && count($currentRates) === 2) {
                $representative = ($currentRates['energy_seasonal_winter'] * 5 + $currentRates['energy_seasonal_other'] * 7) / 12;
            }
        }
        if ($representative !== null && abs($representative - $current) > 0.0001) {
            throw new InvalidArgumentException('Estimate uses a different current price.');
        }
    }

    public function tail(): ?CarbonImmutable
    {
        $date = $this->estimate instanceof ResetEstimate ? $this->estimate->tailStartsOn : null;
        $date ??= $this->estimate->tailStartsMonthKey === null ? null : $this->estimate->tailStartsMonthKey.'-01';

        return $date === null ? null : CarbonImmutable::parse($date, 'Europe/Helsinki')->startOfDay();
    }

    public function offset(CarbonImmutable $date, string $bucket): float
    {
        if ($this->tail() !== null && $date->lt($this->tail())) {
            return 0.0;
        }

        return $this->estimate->offsetForMonthKey($date->format('Y-m'), $bucket);
    }
}
