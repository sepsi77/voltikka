<?php

namespace App\Services;

use App\Enums\MeteringType;
use App\Services\CanonicalPricing\Support\MonthlyUsageProfileBuilder;
use App\Services\DTO\ContractPricingResult;
use App\Services\DTO\EnergyUsage;
use Carbon\Carbon;
use Carbon\CarbonInterface;

class ContractPriceCalculator
{
    /**
     * Winter price months: Jan, Feb, Mar, Nov, Dec (indices 0, 1, 2, 10, 11).
     *
     * Backward-compatible alias; the source of truth now lives on MonthlyUsageProfileBuilder.
     */
    public const WINTER_PRICE_MONTHS = MonthlyUsageProfileBuilder::WINTER_PRICE_MONTHS;

    /**
     * Winter months have 30% higher general electricity consumption than summer months.
     *
     * Backward-compatible alias; the source of truth now lives on MonthlyUsageProfileBuilder.
     */
    public const WINTER_CONSUMPTION_MULTIPLIER = MonthlyUsageProfileBuilder::WINTER_CONSUMPTION_MULTIPLIER;

    /**
     * Night time shares for different usage components.
     *
     * Backward-compatible alias; the source of truth now lives on MonthlyUsageProfileBuilder.
     */
    public const NIGHT_TIME_SHARES = MonthlyUsageProfileBuilder::NIGHT_TIME_SHARES;

    public function __construct(
        private readonly MonthlyUsageProfileBuilder $usageProfileBuilder = new MonthlyUsageProfileBuilder(),
    ) {
    }

    /**
     * Calculate monthly and annual costs for a given electricity contract and usage.
     *
     * @param array $priceComponents Array of price component data with 'price_component_type' and 'price' keys.
     *                               Discount metadata is optional but used when available.
     * @param array $contractData Contract data with 'contract_type', 'pricing_model' and 'metering' keys
     * @param EnergyUsage $usage Energy usage data
     * @param float|null $spotPriceDay Average spot price for day hours (for spot contracts)
     * @param float|null $spotPriceNight Average spot price for night hours (for spot contracts)
     * @param CarbonInterface|null $calculationStartDate Start date for promo-aware first-year estimates. Defaults to now.
     */
    public function calculate(
        array $priceComponents,
        array $contractData,
        EnergyUsage $usage,
        ?float $spotPriceDay = null,
        ?float $spotPriceNight = null,
        ?CarbonInterface $calculationStartDate = null,
    ): ContractPricingResult {
        $calculationStartDate ??= Carbon::now('Europe/Helsinki');

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
        if (! $isSpotContract && $generalRate > 0 && $generalRate < 0.8) {
            $isSpotContract = true;
        }

        // If no per-kWh rates are set AND it's not a spot contract, return fixed monthly fee only
        $hasPerKwhRates = $seasonalOtherRate || $seasonalWinterDayRate || $generalRate || $nightTimeRate || $dayTimeRate;
        if (! $hasPerKwhRates && ! $isSpotContract) {
            $baseMonthlyCosts = array_fill(0, 12, $fixedMonthlyFee);
            $monthlyDiscountSavings = $this->calculateMonthlyDiscountSavings(
                $priceComponents,
                $this->usageProfileBuilder->build(MeteringType::General, $usage, false),
                $fixedMonthlyFee,
                $calculationStartDate,
            );
            $monthlyCosts = $this->applyDiscountSavingsToMonthlyCosts($baseMonthlyCosts, $monthlyDiscountSavings);
            $annualCost = array_sum($monthlyCosts);
            $annualBaseCost = array_sum($baseMonthlyCosts);

            return new ContractPricingResult(
                totalCost: $annualCost,
                avgMonthlyCost: $annualCost / 12,
                monthlyCosts: $monthlyCosts,
                monthlyFixedFee: $fixedMonthlyFee,
                baseTotalCost: $annualBaseCost,
                baseAvgMonthlyCost: $annualBaseCost / 12,
                baseMonthlyCosts: $baseMonthlyCosts,
                discountSavingsTotal: array_sum($monthlyDiscountSavings),
                monthlyDiscountSavings: $monthlyDiscountSavings,
                includesDiscounts: array_sum($monthlyDiscountSavings) > 0,
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

        // Calculate base energy costs for each usage component (in cents before fixed fee)
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

            if ($isSpotContract) {
                $regularPriceCooling = ($spotPriceDay ?? 0) + $spotPriceMargin;
            }

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

        // Combine all energy costs
        $costs = [
            $basicLivingCost,
            $bathroomUnderfloorHeatingCost,
            $waterCost,
            $saunaCost,
            $electricityVehicleCost,
            $coolingCost,
            $heatingCost,
        ];

        $baseMonthlyCosts = [];
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

            $baseMonthlyCosts[] = $totalCostForMonth;
        }

        $componentUsageTimeline = $this->usageProfileBuilder->build($metering, $usage, $isSpotContract);
        $monthlyDiscountSavings = $this->calculateMonthlyDiscountSavings(
            $priceComponents,
            $componentUsageTimeline,
            $fixedMonthlyFee,
            $calculationStartDate,
        );

        $totalMonthlyCosts = $this->applyDiscountSavingsToMonthlyCosts($baseMonthlyCosts, $monthlyDiscountSavings);

        $annualBaseCost = array_sum($baseMonthlyCosts);
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
            baseTotalCost: $annualBaseCost,
            baseAvgMonthlyCost: $annualBaseCost / 12,
            baseMonthlyCosts: $baseMonthlyCosts,
            discountSavingsTotal: array_sum($monthlyDiscountSavings),
            monthlyDiscountSavings: $monthlyDiscountSavings,
            includesDiscounts: array_sum($monthlyDiscountSavings) > 0,
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

        [$summerConsumptionFactor, $winterConsumptionFactor] = $this->usageProfileBuilder->seasonalConsumptionFactors();

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

    /**
     * Calculate monthly discount savings in euros for the first 12 months.
     *
     * @param array<int, array<string, mixed>> $priceComponents
     * @param array<int, array<string, float>> $componentUsageTimeline
     * @return array<int, float>
     */
    private function calculateMonthlyDiscountSavings(
        array $priceComponents,
        array $componentUsageTimeline,
        float $fixedMonthlyFee,
        CarbonInterface $calculationStartDate,
    ): array {
        $monthlySavings = array_fill(0, 12, 0.0);

        foreach ($priceComponents as $component) {
            if (! ($component['has_discount'] ?? false)) {
                continue;
            }

            $discountValue = (float) ($component['discount_value'] ?? 0);
            if ($discountValue <= 0) {
                continue;
            }

            $componentType = $component['price_component_type'] ?? null;
            $paymentUnit = $component['payment_unit'] ?? null;
            $componentPrice = (float) ($component['price'] ?? 0);
            $isPercentage = (bool) ($component['discount_is_percentage'] ?? false);
            $discountType = $component['discount_type'] ?? null;
            $nFirstMonths = (int) ($component['discount_discount_n_first_months'] ?? 0);
            $remainingFirstKwh = (float) ($component['discount_discount_n_first_kwh'] ?? 0);
            $untilDate = $component['discount_discount_until_date'] ?? null;

            $componentDiscountAmount = $this->resolveComponentDiscountAmount($componentPrice, $discountValue, $isPercentage);
            if ($componentDiscountAmount <= 0) {
                continue;
            }

            if ($paymentUnit === 'EurPerMonth' || $componentType === 'Monthly') {
                for ($monthIndex = 0; $monthIndex < 12; $monthIndex++) {
                    $coverage = $this->resolveMonthlyDiscountCoverage(
                        $monthIndex,
                        $discountType,
                        $nFirstMonths,
                        $calculationStartDate,
                        $untilDate,
                    );

                    if ($coverage <= 0) {
                        continue;
                    }

                    $monthlySavings[$monthIndex] += $componentDiscountAmount * $coverage;
                }

                continue;
            }

            if (($paymentUnit !== 'CentPerKiwattHour' && $paymentUnit !== null) || ! is_string($componentType)) {
                continue;
            }

            for ($monthIndex = 0; $monthIndex < 12; $monthIndex++) {
                $coverage = $this->resolveMonthlyDiscountCoverage(
                    $monthIndex,
                    $discountType,
                    $nFirstMonths,
                    $calculationStartDate,
                    $untilDate,
                );

                if ($coverage <= 0) {
                    continue;
                }

                $monthlyUsage = (float) ($componentUsageTimeline[$monthIndex][$componentType] ?? 0.0);
                if ($monthlyUsage <= 0) {
                    continue;
                }

                $coveredUsage = match ($discountType) {
                    'NFirstKwh' => min($monthlyUsage * $coverage, $remainingFirstKwh),
                    default => $monthlyUsage * $coverage,
                };

                if ($coveredUsage <= 0) {
                    continue;
                }

                $monthlySavings[$monthIndex] += ($coveredUsage * $componentDiscountAmount) / 100;

                if ($discountType === 'NFirstKwh') {
                    $remainingFirstKwh -= $coveredUsage;
                    if ($remainingFirstKwh <= 0) {
                        break;
                    }
                }
            }
        }

        return $monthlySavings;
    }

    private function resolveComponentDiscountAmount(float $componentPrice, float $discountValue, bool $isPercentage): float
    {
        if ($isPercentage) {
            return max(0.0, min($componentPrice, $componentPrice * ($discountValue / 100)));
        }

        return max(0.0, min($componentPrice, $discountValue));
    }

    private function resolveMonthlyDiscountCoverage(
        int $monthIndex,
        ?string $discountType,
        int $nFirstMonths,
        CarbonInterface $calculationStartDate,
        mixed $untilDate,
    ): float {
        return match ($discountType) {
            'NFirstMonth' => $monthIndex < $nFirstMonths ? 1.0 : 0.0,
            'UntilDate' => $this->resolveUntilDateCoverage($monthIndex, $calculationStartDate, $untilDate),
            'NFirstKwh' => 1.0,
            default => $nFirstMonths > 0 ? ($monthIndex < $nFirstMonths ? 1.0 : 0.0) : 1.0,
        };
    }

    private function resolveUntilDateCoverage(int $monthIndex, CarbonInterface $calculationStartDate, mixed $untilDate): float
    {
        if (! $untilDate) {
            return 0.0;
        }

        $monthStart = $calculationStartDate->copy()->startOfDay()->addMonths($monthIndex);
        $monthEnd = $monthStart->copy()->addMonth();
        $until = $untilDate instanceof CarbonInterface
            ? $untilDate->copy()->timezone($monthStart->getTimezone())
            : Carbon::parse($untilDate, $monthStart->getTimezone());

        if ($until <= $monthStart) {
            return 0.0;
        }

        if ($until >= $monthEnd) {
            return 1.0;
        }

        $coveredSeconds = $until->diffInSeconds($monthStart, false);
        $monthSeconds = max(1, $monthEnd->diffInSeconds($monthStart, false));

        return max(0.0, min(1.0, $coveredSeconds / $monthSeconds));
    }

    /**
     * @param array<int, float> $baseMonthlyCosts
     * @param array<int, float> $monthlyDiscountSavings
     * @return array<int, float>
     */
    private function applyDiscountSavingsToMonthlyCosts(array $baseMonthlyCosts, array $monthlyDiscountSavings): array
    {
        $monthlyCosts = [];

        foreach ($baseMonthlyCosts as $monthIndex => $baseCost) {
            $monthlyCosts[] = max(0, $baseCost - ($monthlyDiscountSavings[$monthIndex] ?? 0.0));
        }

        return $monthlyCosts;
    }
}
