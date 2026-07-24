<?php

namespace App\Services\CanonicalPricing\Support;

use App\Enums\MeteringType;
use App\Services\DTO\EnergyUsage;

/**
 * Builds the per-month kWh usage profile split into priced component buckets
 * (General, DayTime, NightTime, SeasonalWinterDay, SeasonalOther).
 *
 * This is the single source of truth for how annual consumption distributes across
 * the twelve calendar months and across day/night/seasonal buckets, including the
 * Finnish winter consumption weighting, cooling summer months, and per-month heating.
 *
 * It was extracted from ContractPriceCalculator so both the legacy source-component
 * calculator and the canonical phase-aware calculator share identical usage math.
 * The legacy calculator still exposes the constants below as backward-compatible aliases.
 */
class MonthlyUsageProfileBuilder
{
    /**
     * Winter price months: Jan, Feb, Mar, Nov, Dec (indices 0, 1, 2, 10, 11).
     * Seasonal rates apply from Nov 1 - Mar 31.
     */
    public const WINTER_PRICE_MONTHS = [
        true,   // January (0)
        true,   // February (1)
        true,   // March (2)
        false,  // April (3)
        false,  // May (4)
        false,  // June (5)
        false,  // July (6)
        false,  // August (7)
        false,  // September (8)
        false,  // October (9)
        true,   // November (10)
        true,   // December (11)
    ];

    /**
     * Winter months have 30% higher general electricity consumption than summer months.
     * This reflects reality in Finland: more lighting, indoor activities, etc.
     */
    public const WINTER_CONSUMPTION_MULTIPLIER = 1.30;

    /**
     * Night time shares for different usage components.
     * Nighttime hours: 22:00-07:00 (8 hours = 33% of day).
     */
    public const NIGHT_TIME_SHARES = [
        'sauna' => 0,
        'electricity_vehicle' => 0.9,
        'water' => 0.9,
        'room_heating' => 0.33,
        'cooling' => 0,
        'default' => 0.15,
    ];

    /**
     * Build a monthly kWh timeline per priced component bucket.
     *
     * @return array<int, array<string, float>> Twelve entries (calendar-month indexed 0-11),
     *                                           each with General/DayTime/NightTime/SeasonalWinterDay/SeasonalOther kWh.
     */
    public function build(MeteringType $metering, EnergyUsage $usage, bool $isSpotContract = false): array
    {
        $timeline = array_fill(0, 12, [
            'General' => 0.0,
            'DayTime' => 0.0,
            'NightTime' => 0.0,
            'SeasonalWinterDay' => 0.0,
            'SeasonalOther' => 0.0,
        ]);

        $this->addUsageTimeline($timeline, $metering, $isSpotContract, $usage->basicLiving / 12, self::NIGHT_TIME_SHARES['default']);

        if ($usage->bathroomUnderfloorHeating) {
            $this->addUsageTimeline($timeline, $metering, $isSpotContract, $usage->bathroomUnderfloorHeating / 12, self::NIGHT_TIME_SHARES['default']);
        }

        if ($usage->water) {
            $this->addUsageTimeline($timeline, $metering, $isSpotContract, $usage->water / 12, self::NIGHT_TIME_SHARES['water']);
        }

        if ($usage->sauna) {
            $this->addUsageTimeline($timeline, $metering, $isSpotContract, $usage->sauna / 12, self::NIGHT_TIME_SHARES['sauna']);
        }

        if ($usage->electricityVehicle) {
            $this->addUsageTimeline($timeline, $metering, $isSpotContract, $usage->electricityVehicle / 12, self::NIGHT_TIME_SHARES['electricity_vehicle']);
        }

        if ($usage->cooling) {
            $coolingPerMonth = $usage->cooling / 3;
            foreach ([5, 6, 7] as $monthIndex) {
                $this->addUsageForMonth($timeline, $metering, $isSpotContract, $monthIndex, $coolingPerMonth, 0.0);
            }
        }

        if ($usage->roomHeating) {
            if ($usage->heatingElectricityUseByMonth) {
                foreach ($usage->heatingElectricityUseByMonth as $monthIndex => $heatingUse) {
                    $this->addUsageForMonth($timeline, $metering, $isSpotContract, $monthIndex, (float) $heatingUse, self::NIGHT_TIME_SHARES['room_heating']);
                }
            } else {
                $this->addUsageTimeline($timeline, $metering, $isSpotContract, $usage->roomHeating / 12, self::NIGHT_TIME_SHARES['room_heating']);
            }
        }

        return $timeline;
    }

    /**
     * @return array{0: float, 1: float} [summerConsumptionFactor, winterConsumptionFactor]
     */
    public function seasonalConsumptionFactors(): array
    {
        $winterMonths = 5;
        $summerMonths = 7;
        $totalWeightedMonths = $summerMonths + ($winterMonths * self::WINTER_CONSUMPTION_MULTIPLIER);
        $summerConsumptionFactor = 12 / $totalWeightedMonths;  // ≈ 0.889
        $winterConsumptionFactor = $summerConsumptionFactor * self::WINTER_CONSUMPTION_MULTIPLIER;  // ≈ 1.156

        return [$summerConsumptionFactor, $winterConsumptionFactor];
    }

    /**
     * @param array<int, array<string, float>> $timeline
     */
    private function addUsageTimeline(array &$timeline, MeteringType $metering, bool $isSpotContract, float $monthlyAverageUse, float $nightTimeUsageShare): void
    {
        if ($metering === MeteringType::Season && ! $isSpotContract) {
            [$summerConsumptionFactor, $winterConsumptionFactor] = $this->seasonalConsumptionFactors();

            foreach (self::WINTER_PRICE_MONTHS as $monthIndex => $isWinterMonth) {
                $monthlyUse = $monthlyAverageUse * ($isWinterMonth ? $winterConsumptionFactor : $summerConsumptionFactor);
                $this->addUsageForMonth($timeline, $metering, $isSpotContract, $monthIndex, $monthlyUse, $nightTimeUsageShare);
            }

            return;
        }

        foreach (array_keys(self::WINTER_PRICE_MONTHS) as $monthIndex) {
            $this->addUsageForMonth($timeline, $metering, $isSpotContract, $monthIndex, $monthlyAverageUse, $nightTimeUsageShare);
        }
    }

    /**
     * @param array<int, array<string, float>> $timeline
     */
    private function addUsageForMonth(array &$timeline, MeteringType $metering, bool $isSpotContract, int $monthIndex, float $monthlyUse, float $nightTimeUsageShare): void
    {
        if ($monthlyUse <= 0) {
            return;
        }

        if ($isSpotContract) {
            $timeline[$monthIndex]['General'] += $monthlyUse;

            return;
        }

        match ($metering) {
            MeteringType::General => $timeline[$monthIndex]['General'] += $monthlyUse,
            MeteringType::Time => $this->addTimeBasedUsageForMonth($timeline, $monthIndex, $monthlyUse, $nightTimeUsageShare),
            MeteringType::Season => $this->addSeasonalUsageForMonth($timeline, $monthIndex, $monthlyUse, $nightTimeUsageShare),
        };
    }

    /**
     * @param array<int, array<string, float>> $timeline
     */
    private function addTimeBasedUsageForMonth(array &$timeline, int $monthIndex, float $monthlyUse, float $nightTimeUsageShare): void
    {
        $timeline[$monthIndex]['DayTime'] += $monthlyUse * (1 - $nightTimeUsageShare);
        $timeline[$monthIndex]['NightTime'] += $monthlyUse * $nightTimeUsageShare;
    }

    /**
     * @param array<int, array<string, float>> $timeline
     */
    private function addSeasonalUsageForMonth(array &$timeline, int $monthIndex, float $monthlyUse, float $nightTimeUsageShare): void
    {
        if (self::WINTER_PRICE_MONTHS[$monthIndex]) {
            $timeline[$monthIndex]['SeasonalWinterDay'] += $monthlyUse * (1 - $nightTimeUsageShare);
            $timeline[$monthIndex]['SeasonalOther'] += $monthlyUse * $nightTimeUsageShare;

            return;
        }

        $timeline[$monthIndex]['SeasonalOther'] += $monthlyUse;
    }
}
