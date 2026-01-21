<?php

namespace App\Services;

use App\Enums\MeteringType;
use App\Services\DTO\ContractPricingResult;
use App\Services\DTO\EnergyUsage;

class ContractPriceCalculator
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
     * Calculate monthly and annual costs for a given electricity contract and usage.
     *
     * @param array $priceComponents Array of price component data with 'price_component_type' and 'price' keys
     * @param array $contractData Contract data with 'contract_type' and 'metering' keys
     * @param EnergyUsage $usage Energy usage data
     * @param float|null $spotPriceDay Average spot price for day hours (for spot contracts)
     * @param float|null $spotPriceNight Average spot price for night hours (for spot contracts)
     */
    public function calculate(
        array $priceComponents,
        array $contractData,
        EnergyUsage $usage,
        ?float $spotPriceDay = null,
        ?float $spotPriceNight = null,
    ): ContractPricingResult {
        // Extract pricing from components
        $fixedMonthlyFee = 0.0;
        $seasonalOtherRate = 0.0;
        $seasonalWinterDayRate = 0.0;
        $generalRate = 0.0;
        $nightTimeRate = 0.0;
        $dayTimeRate = 0.0;
        $spotPriceMargin = 0.0;

        foreach ($priceComponents as $comp) {
            $type = $comp['price_component_type'] ?? '';
            $price = (float) ($comp['price'] ?? 0);

            match ($type) {
                'Monthly' => $fixedMonthlyFee = $price,
                'SeasonalOther' => $seasonalOtherRate = $price,
                'SeasonalWinterDay' => $seasonalWinterDayRate = $price,
                'NightTime' => $nightTimeRate = $price,
                'DayTime' => $dayTimeRate = $price,
                'General' => $generalRate = $price,
                default => null,
            };
        }

        // Detect spot contracts FIRST - check pricing_model
        $pricingModel = $contractData['pricing_model'] ?? '';
        $isSpotContract = $pricingModel === 'Spot';

        // Also detect spot contracts by abnormally low prices (< 0.8 c/kWh)
        // These are margins added on top of spot prices
        if (!$isSpotContract && $generalRate > 0 && $generalRate < 0.8) {
            $isSpotContract = true;
        }

        // If no per-kWh rates are set AND it's not a spot contract, return fixed monthly fee only
        $hasPerKwhRates = $seasonalOtherRate || $seasonalWinterDayRate || $generalRate || $nightTimeRate || $dayTimeRate;
        if (!$hasPerKwhRates && !$isSpotContract) {
            $monthlyCosts = array_fill(0, 12, $fixedMonthlyFee);
            $annualCost = array_sum($monthlyCosts);

            return new ContractPricingResult(
                totalCost: $annualCost,
                avgMonthlyCost: $fixedMonthlyFee,
                monthlyCosts: $monthlyCosts,
                monthlyFixedFee: $fixedMonthlyFee,
            );
        }

        // Determine metering type and pricing rates
        $metering = MeteringType::General;
        $regularPrice = 0.0;
        $discountPrice = 0.0;

        if ($generalRate > 0) {
            $metering = MeteringType::General;
            $regularPrice = $generalRate;
            $discountPrice = 0.0;
        } elseif ($nightTimeRate > 0) {
            $metering = MeteringType::Time;
            $regularPrice = $dayTimeRate;
            $discountPrice = $nightTimeRate;
        } elseif ($seasonalWinterDayRate > 0) {
            $metering = MeteringType::Season;
            $regularPrice = $seasonalWinterDayRate;
            $discountPrice = $seasonalOtherRate;
        }

        // Handle spot price contracts - use spot price + margin
        if ($isSpotContract) {
            // Find the margin from the first non-monthly price component
            foreach ($priceComponents as $comp) {
                if (($comp['price_component_type'] ?? '') !== 'Monthly') {
                    $spotPriceMargin = (float) ($comp['price'] ?? 0);
                    break;
                }
            }

            $metering = MeteringType::Time;
            $regularPrice = ($spotPriceDay ?? 0) + $spotPriceMargin;
            $discountPrice = ($spotPriceNight ?? 0) + $spotPriceMargin;
            $generalRate = 0; // Reset since info is in spot_price_margin
        }

        // Calculate costs for each usage component
        $basicLivingCost = $this->calculateMonthlyCosts(
            metering: $metering,
            electricityUse: $usage->basicLiving / 12,
            normalPrice: $regularPrice,
            discountPrice: $discountPrice,
            nightTimeUsageShare: self::NIGHT_TIME_SHARES['default'],
        );

        $bathroomUnderfloorHeatingCost = 0;
        if ($usage->bathroomUnderfloorHeating) {
            $bathroomUnderfloorHeatingCost = $this->calculateMonthlyCosts(
                metering: $metering,
                electricityUse: $usage->bathroomUnderfloorHeating / 12,
                normalPrice: $regularPrice,
                discountPrice: $discountPrice,
                nightTimeUsageShare: self::NIGHT_TIME_SHARES['default'],
            );
        }

        $waterCost = 0;
        if ($usage->water) {
            $waterCost = $this->calculateMonthlyCosts(
                metering: $metering,
                electricityUse: $usage->water / 12,
                normalPrice: $regularPrice,
                discountPrice: $discountPrice,
                nightTimeUsageShare: self::NIGHT_TIME_SHARES['water'],
            );
        }

        $saunaCost = 0;
        if ($usage->sauna) {
            $saunaCost = $this->calculateMonthlyCosts(
                metering: $metering,
                electricityUse: $usage->sauna / 12,
                normalPrice: $regularPrice,
                discountPrice: $discountPrice,
                nightTimeUsageShare: self::NIGHT_TIME_SHARES['sauna'],
            );
        }

        $electricityVehicleCost = 0;
        if ($usage->electricityVehicle) {
            $electricityVehicleCost = $this->calculateMonthlyCosts(
                metering: $metering,
                electricityUse: $usage->electricityVehicle / 12,
                normalPrice: $regularPrice,
                discountPrice: $discountPrice,
                nightTimeUsageShare: self::NIGHT_TIME_SHARES['electricity_vehicle'],
            );
        }

        // Handle cooling separately (only June-August, daytime only)
        $coolingCost = array_fill(0, 12, 0.0);
        if ($usage->cooling) {
            // Determine the rate for cooling (summer, daytime)
            $regularPriceCooling = match ($metering) {
                MeteringType::Season => $seasonalOtherRate,
                MeteringType::Time => $dayTimeRate,
                default => $generalRate,
            };

            // Calculate per-month cost (only 3 months)
            $coolingCostPerMonth = $this->calculateMonthlyCosts(
                metering: MeteringType::General,
                electricityUse: $usage->cooling / 3,
                normalPrice: $regularPriceCooling,
                discountPrice: $discountPrice,
                nightTimeUsageShare: 0,
            );

            // Cooling only in June (5), July (6), August (7)
            $coolingCost = [0, 0, 0, 0, 0, $coolingCostPerMonth, $coolingCostPerMonth, $coolingCostPerMonth, 0, 0, 0, 0];
        }

        // Handle room heating
        $heatingCost = 0;
        if ($usage->roomHeating) {
            if ($usage->heatingElectricityUseByMonth) {
                // Calculate heating cost month by month
                $heatingCost = [];
                foreach ($usage->heatingElectricityUseByMonth as $index => $heatingUse) {
                    $cost = match ($metering) {
                        MeteringType::General => $heatingUse * $regularPrice,
                        MeteringType::Time => $this->calculateTimeBasedCost(
                            $heatingUse,
                            $regularPrice,
                            $discountPrice,
                            self::NIGHT_TIME_SHARES['room_heating'],
                        ),
                        MeteringType::Season => $this->calculateSeasonalHeatingCost(
                            $heatingUse,
                            $regularPrice,
                            $discountPrice,
                            self::NIGHT_TIME_SHARES['room_heating'],
                            self::WINTER_PRICE_MONTHS[$index],
                        ),
                    };
                    $heatingCost[] = $cost;
                }
            } else {
                $heatingCost = $this->calculateMonthlyCosts(
                    metering: $metering,
                    electricityUse: $usage->roomHeating / 12,
                    normalPrice: $regularPrice,
                    discountPrice: $discountPrice,
                    nightTimeUsageShare: self::NIGHT_TIME_SHARES['room_heating'],
                );
            }
        }

        // Combine all costs
        $costs = [
            $basicLivingCost,
            $bathroomUnderfloorHeatingCost,
            $waterCost,
            $saunaCost,
            $electricityVehicleCost,
            $coolingCost,
            $heatingCost,
        ];

        $totalMonthlyCosts = [];
        for ($month = 0; $month < 12; $month++) {
            $totalCostForMonth = 0.0;

            foreach ($costs as $costType) {
                if (is_array($costType)) {
                    $totalCostForMonth += $costType[$month];
                } else {
                    $totalCostForMonth += (float) $costType;
                }
            }

            // Convert from cents to EUR
            $totalCostForMonth = $totalCostForMonth / 100;

            // Add fixed monthly fee
            $totalCostForMonth += $fixedMonthlyFee;

            $totalMonthlyCosts[] = $totalCostForMonth;
        }

        $annualCost = array_sum($totalMonthlyCosts);
        $avgMonthlyCost = $annualCost / 12;

        return new ContractPricingResult(
            totalCost: $annualCost,
            avgMonthlyCost: $avgMonthlyCost,
            monthlyCosts: $totalMonthlyCosts,
            monthlyFixedFee: $fixedMonthlyFee,
            spotPriceMargin: $spotPriceMargin > 0 ? $spotPriceMargin : null,
            generalKwhPrice: $generalRate > 0 ? $generalRate : null,
            seasonalWinterDayKwhPrice: $seasonalWinterDayRate > 0 ? $seasonalWinterDayRate : null,
            seasonalOtherKwhPrice: $seasonalOtherRate > 0 ? $seasonalOtherRate : null,
            daytimeKwhPrice: $dayTimeRate > 0 ? $dayTimeRate : null,
            nighttimeKwhPrice: $nightTimeRate > 0 ? $nightTimeRate : null,
            spotPriceDayAvg: $isSpotContract ? $spotPriceDay : null,
            spotPriceNightAvg: $isSpotContract ? $spotPriceNight : null,
            isSpotContract: $isSpotContract,
        );
    }

    /**
     * Calculate monthly costs based on metering type.
     *
     * @return float|array Returns a single monthly cost (float) for general/time metering,
     *                     or an array of 12 monthly costs for seasonal metering
     */
    private function calculateMonthlyCosts(
        MeteringType $metering,
        float $electricityUse,
        float $normalPrice,
        float $discountPrice,
        float $nightTimeUsageShare,
    ): float|array {
        return match ($metering) {
            MeteringType::General => $electricityUse * $normalPrice,
            MeteringType::Time => $this->calculateTimeBasedCost(
                $electricityUse,
                $normalPrice,
                $discountPrice,
                $nightTimeUsageShare,
            ),
            MeteringType::Season => $this->calculateSeasonalCosts(
                $electricityUse,
                $normalPrice,
                $discountPrice,
                $nightTimeUsageShare,
            ),
        };
    }

    private function calculateTimeBasedCost(
        float $electricityUse,
        float $normalPrice,
        float $discountPrice,
        float $nightTimeUsageShare,
    ): float {
        $normalRateCost = $electricityUse * (1 - $nightTimeUsageShare) * $normalPrice;
        $discountRateCost = $electricityUse * $nightTimeUsageShare * $discountPrice;

        return $normalRateCost + $discountRateCost;
    }

    /**
     * Calculate seasonal costs for each month.
     *
     * Applies both seasonal pricing AND seasonal consumption weighting.
     * Winter months have 30% higher consumption than summer months.
     *
     * @param float $electricityUse Monthly average electricity use (annual / 12)
     * @return array Array of 12 monthly costs in cents
     */
    private function calculateSeasonalCosts(
        float $electricityUse,
        float $normalPrice,
        float $discountPrice,
        float $nightTimeUsageShare,
    ): array {
        $monthlyCosts = [];

        // Calculate consumption adjustment factors to maintain annual total
        // 5 winter months × winterFactor + 7 summer months × summerFactor = 12 × monthlyAvg
        // With winter = 1.3 × summer: 7s + 5(1.3s) = 12 → s = 12/13.5
        $winterMonths = 5;
        $summerMonths = 7;
        $totalWeightedMonths = $summerMonths + ($winterMonths * self::WINTER_CONSUMPTION_MULTIPLIER);
        $summerConsumptionFactor = 12 / $totalWeightedMonths;  // ≈ 0.889
        $winterConsumptionFactor = $summerConsumptionFactor * self::WINTER_CONSUMPTION_MULTIPLIER;  // ≈ 1.156

        foreach (self::WINTER_PRICE_MONTHS as $isWinterMonth) {
            if ($isWinterMonth) {
                // Winter month: higher consumption + winter pricing
                $monthlyUse = $electricityUse * $winterConsumptionFactor;
                $normalRateCost = $monthlyUse * (1 - $nightTimeUsageShare) * $normalPrice;
                $discountRateCost = $monthlyUse * $nightTimeUsageShare * $discountPrice;
                $monthlyCosts[] = $normalRateCost + $discountRateCost;
            } else {
                // Summer month: lower consumption + discount rate
                $monthlyUse = $electricityUse * $summerConsumptionFactor;
                $monthlyCosts[] = $monthlyUse * $discountPrice;
            }
        }

        return $monthlyCosts;
    }

    /**
     * Calculate heating cost for a specific month with seasonal pricing.
     */
    private function calculateSeasonalHeatingCost(
        float $heatingUse,
        float $normalPrice,
        float $discountPrice,
        float $nightTimeUsageShare,
        bool $isWinterMonth,
    ): float {
        if ($isWinterMonth) {
            $normalRateCost = $heatingUse * (1 - $nightTimeUsageShare) * $normalPrice;
            $discountRateCost = $heatingUse * $nightTimeUsageShare * $discountPrice;

            return $normalRateCost + $discountRateCost;
        }

        // Summer months use only discount rate
        return $heatingUse * $discountPrice;
    }
}
