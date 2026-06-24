<?php

namespace App\Services\BillComparison;

use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * Seasonal consumption profiles for annualizing a single billing period's kWh.
 *
 * A one-month bill cannot be multiplied by 12 because Finnish household
 * consumption is strongly seasonal — especially when electric heating is
 * included. This class builds a 12-month share vector (summing to 1.0) and
 * computes what fraction of annual consumption a given billing period
 * represents, so `annual_kWh = period_kWh / periodShare`.
 *
 * Two profiles:
 *  - "no heating": mild winter bump (winter months ×1.156, summer ×0.889),
 *    matching `ContractPriceCalculator::getSeasonalConsumptionFactors()`.
 *  - "heating": flat base load + Finnish heating-degree-day shape
 *    (`EnergyCalculator::HEATING_NEED_PER_MONTH`, Jyväskylä 2021) with a
 *    default 55 % heating / 45 % base split for a direct-electric home.
 *
 * Both shapes are normalized so `sum(monthlyShares) === 1.0`.
 */
class ConsumptionProfile
{
    /**
     * Default share of annual consumption attributable to heating for a
     * direct-electric / heat-pump detached house. Tunable; the shape (not the
     * exact ratio) is what matters most for annualization.
     */
    public const DEFAULT_HEATING_SHARE = 0.55;

    /**
     * Finnish monthly heating-degree-day reference (Jyväskylä 2021),
     * indexed by calendar month 0..11. Sourced from
     * `App\Services\EnergyCalculator::HEATING_NEED_PER_MONTH`.
     */
    public const HEATING_NEED_PER_MONTH = [754, 768, 623, 426, 202, 0, 0, 36, 277, 336, 537, 812];

    /**
     * Winter consumption multiplier matching ContractPriceCalculator.
     */
    private const WINTER_CONSUMPTION_MULTIPLIER = 1.30;

    /**
     * Winter months: Jan, Feb, Mar, Nov, Dec (indices 0,1,2,10,11).
     */
    private const WINTER_MONTHS = [0, 1, 2, 10, 11];

    /**
     * @return list<float> 12 monthly shares, summing to 1.0
     */
    public function monthlyShares(bool $includesHeating): array
    {
        return $includesHeating
            ? $this->heatingMonthlyShares(self::DEFAULT_HEATING_SHARE)
            : $this->noHeatingMonthlyShares();
    }

    /**
     * Fraction of annual consumption occurring within [start, end] inclusive
     * (Europe/Helsinki). Invert to scale a period kWh up to annual kWh.
     *
     * @return float value in (0, 1]
     */
    public function periodShare(CarbonInterface $start, CarbonInterface $end, bool $includesHeating): float
    {
        $shares = $this->monthlyShares($includesHeating);

        $cursor = Carbon::parse($start, 'Europe/Helsinki')->startOfDay();
        $last = Carbon::parse($end, 'Europe/Helsinki')->startOfDay();

        $share = 0.0;
        while ($cursor <= $last) {
            $monthIndex = (int) $cursor->format('n') - 1;
            $daysInMonth = (int) $cursor->format('t');
            $share += $shares[$monthIndex] / $daysInMonth;
            $cursor = $cursor->copy()->addDay();
        }

        // Guard against pathological inputs.
        return $share > 0 ? min(1.0, $share) : 1.0;
    }

    /**
     * @return list<float>
     */
    private function noHeatingMonthlyShares(): array
    {
        $winterMonths = count(self::WINTER_MONTHS);
        $summerMonths = 12 - $winterMonths;
        $totalWeighted = $summerMonths + ($winterMonths * self::WINTER_CONSUMPTION_MULTIPLIER);
        $summerFactor = 12 / $totalWeighted; // ≈ 0.889
        $winterFactor = $summerFactor * self::WINTER_CONSUMPTION_MULTIPLIER; // ≈ 1.156

        $shares = [];
        for ($i = 0; $i < 12; $i++) {
            $factor = in_array($i, self::WINTER_MONTHS, true) ? $winterFactor : $summerFactor;
            $shares[] = $factor / 12;
        }

        return $shares;
    }

    /**
     * @return list<float>
     */
    private function heatingMonthlyShares(float $heatingShare): array
    {
        $baseShare = 1.0 - $heatingShare;
        $totalHeating = array_sum(self::HEATING_NEED_PER_MONTH) ?: 1.0;

        $shares = [];
        foreach (self::HEATING_NEED_PER_MONTH as $need) {
            $monthShare = $baseShare * (1 / 12) + $heatingShare * ($need / $totalHeating);
            $shares[] = $monthShare;
        }

        return $shares;
    }
}
