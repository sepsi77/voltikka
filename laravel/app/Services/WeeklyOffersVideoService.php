<?php

namespace App\Services;

use App\Models\ElectricityContract;
use App\Models\SpotPriceAverage;
use App\Services\DTO\EnergyUsage;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Service for generating video-ready weekly offers data.
 *
 * Provides structured data for Remotion video generation featuring
 * electricity contracts with active discounts.
 */
class WeeklyOffersVideoService
{
    private const REGION = 'FI';
    private const TIMEZONE = 'Europe/Helsinki';
    private const VAT_RATE = 0.255; // 25.5% VAT

    // Consumption levels for different housing types (kWh/year)
    private const APARTMENT_CONSUMPTION = 2000;
    private const TOWNHOUSE_CONSUMPTION = 5000;
    private const HOUSE_CONSUMPTION = 10000;

    private const FINNISH_MONTHS = [
        1 => 'tammikuuta',
        2 => 'helmikuuta',
        3 => 'maaliskuuta',
        4 => 'huhtikuuta',
        5 => 'toukokuuta',
        6 => 'kesäkuuta',
        7 => 'heinäkuuta',
        8 => 'elokuuta',
        9 => 'syyskuuta',
        10 => 'lokakuuta',
        11 => 'marraskuuta',
        12 => 'joulukuuta',
    ];

    public function __construct(
        private readonly ContractPriceCalculator $priceCalculator,
    ) {
    }

    /**
     * Get weekly offers video data.
     *
     * @return array{
     *   generated_at: string,
     *   week: array{start: string, end: string, formatted: string},
     *   offers_count: int,
     *   offers: array<int, array>
     * }
     */
    public function getWeeklyOffersData(): array
    {
        $now = Carbon::now(self::TIMEZONE);

        // Get current week boundaries (Monday to Sunday)
        $weekStart = $now->copy()->startOfWeek();
        $weekEnd = $now->copy()->endOfWeek();

        // Fetch contracts with active discounts
        $contracts = $this->getContractsWithActiveDiscounts(5);

        // Get spot price averages for calculations
        $spotPrices = $this->getSpotPriceAverages();

        // Transform contracts to offers
        $offers = $contracts->map(fn($contract) => $this->transformContractToOffer($contract, $spotPrices))
            ->filter() // Remove nulls
            ->values()
            ->toArray();

        return [
            'generated_at' => Carbon::now()->toIso8601String(),
            'week' => [
                'start' => $weekStart->format('Y-m-d'),
                'end' => $weekEnd->format('Y-m-d'),
                'formatted' => $this->formatWeekPeriod($weekStart, $weekEnd),
            ],
            'offers_count' => count($offers),
            'offers' => $offers,
        ];
    }

    /**
     * Get contracts with active discounts, ordered by discount value.
     */
    private function getContractsWithActiveDiscounts(int $limit): Collection
    {
        $now = Carbon::now();

        return ElectricityContract::query()
            ->active()
            ->where('target_group', 'Household')
            ->whereHas('priceComponents', function ($query) use ($now) {
                $query->where('has_discount', true)
                    ->where(function ($q) use ($now) {
                        $q->whereNull('discount_discount_until_date')
                            ->orWhere('discount_discount_until_date', '>', $now);
                    });
            })
            ->with(['company', 'priceComponents', 'electricitySource'])
            ->get()
            ->sortByDesc(function ($contract) {
                $discountInfo = $contract->getActiveDiscountInfo();
                return $discountInfo ? abs($discountInfo['value']) : 0;
            })
            ->take($limit);
    }

    /**
     * Get spot price averages for cost calculations.
     */
    private function getSpotPriceAverages(): array
    {
        $rolling30d = SpotPriceAverage::latestRolling30Days(self::REGION);

        // Calculate day/night averages from the average
        // Assume flat rate for simplicity (day = night = average)
        $avgPrice = $rolling30d?->avg_price_without_tax ?? 5.0; // Default 5 c/kWh if no data

        return [
            'day' => $avgPrice,
            'night' => $avgPrice,
        ];
    }

    /**
     * Transform a contract to the offer format.
     */
    private function transformContractToOffer(ElectricityContract $contract, array $spotPrices): ?array
    {
        $discountInfo = $contract->getActiveDiscountInfo();
        if (!$discountInfo) {
            return null;
        }

        // Get price components
        $priceComponents = $contract->priceComponents->map(fn($pc) => [
            'price_component_type' => $pc->price_component_type,
            'price' => $pc->price,
            'has_discount' => $pc->has_discount,
            'discount_value' => $pc->discount_value,
            'discount_is_percentage' => $pc->discount_is_percentage,
        ])->toArray();

        // Extract pricing info
        $monthlyFee = 0;
        $energyPrice = null;

        foreach ($priceComponents as $comp) {
            if ($comp['price_component_type'] === 'Monthly') {
                $monthlyFee = $comp['price'];
            } elseif (in_array($comp['price_component_type'], ['General', 'DayTime'])) {
                $energyPrice = $comp['price'];
            }
        }

        // Contract data for calculator
        $contractData = [
            'pricing_model' => $contract->pricing_model,
            'metering' => $contract->metering,
        ];

        // Calculate costs WITH discount
        $costsWithDiscount = $this->calculateCostsForAllConsumptions(
            $priceComponents,
            $contractData,
            $spotPrices,
        );

        // Calculate costs WITHOUT discount for savings comparison
        $priceComponentsNoDiscount = $this->removePriceComponentDiscounts($priceComponents);
        $costsWithoutDiscount = $this->calculateCostsForAllConsumptions(
            $priceComponentsNoDiscount,
            $contractData,
            $spotPrices,
        );

        // Calculate savings (difference between no-discount and with-discount)
        $savings = [
            'apartment' => round($costsWithoutDiscount['apartment'] - $costsWithDiscount['apartment']),
            'townhouse' => round($costsWithoutDiscount['townhouse'] - $costsWithDiscount['townhouse']),
            'house' => round($costsWithoutDiscount['house'] - $costsWithDiscount['house']),
        ];

        return [
            'id' => $contract->id,
            'name' => $contract->name,
            'company' => [
                'name' => $contract->company_name,
                'logo_url' => $contract->company?->getLogoUrl(),
            ],
            'pricing_model' => $contract->pricing_model,
            'discount' => [
                'value' => $discountInfo['value'],
                'is_percentage' => $discountInfo['is_percentage'],
                'n_first_months' => $discountInfo['n_first_months'],
                'until_date' => $discountInfo['until_date']?->format('Y-m-d'),
            ],
            'pricing' => [
                'monthly_fee' => $monthlyFee,
                'energy_price' => $energyPrice,
            ],
            'costs' => [
                'apartment' => round($costsWithDiscount['apartment']),
                'townhouse' => round($costsWithDiscount['townhouse']),
                'house' => round($costsWithDiscount['house']),
            ],
            'savings' => $savings,
        ];
    }

    /**
     * Calculate costs for all consumption levels.
     */
    private function calculateCostsForAllConsumptions(
        array $priceComponents,
        array $contractData,
        array $spotPrices,
    ): array {
        $consumptions = [
            'apartment' => self::APARTMENT_CONSUMPTION,
            'townhouse' => self::TOWNHOUSE_CONSUMPTION,
            'house' => self::HOUSE_CONSUMPTION,
        ];

        $costs = [];
        foreach ($consumptions as $key => $consumption) {
            $usage = new EnergyUsage(total: $consumption, basicLiving: $consumption);

            $result = $this->priceCalculator->calculate(
                priceComponents: $priceComponents,
                contractData: $contractData,
                usage: $usage,
                spotPriceDay: $spotPrices['day'],
                spotPriceNight: $spotPrices['night'],
            );

            $costs[$key] = $result->totalCost;
        }

        return $costs;
    }

    /**
     * Remove discounts from price components for comparison.
     *
     * For percentage discounts, we need to calculate the original price.
     * For absolute discounts, we add back the discount value.
     */
    private function removePriceComponentDiscounts(array $priceComponents): array
    {
        return array_map(function ($comp) {
            if (!($comp['has_discount'] ?? false)) {
                return $comp;
            }

            $discountValue = $comp['discount_value'] ?? 0;
            $isPercentage = $comp['discount_is_percentage'] ?? false;
            $currentPrice = $comp['price'] ?? 0;

            if ($isPercentage && $discountValue > 0 && $discountValue < 100) {
                // For percentage discounts: original = current / (1 - discount%)
                // e.g., if current is 8.5 c/kWh with 15% off, original was 10 c/kWh
                $comp['price'] = $currentPrice / (1 - ($discountValue / 100));
            } else {
                // For absolute discounts: original = current + discount
                $comp['price'] = $currentPrice + abs($discountValue);
            }

            $comp['has_discount'] = false;
            $comp['discount_value'] = null;

            return $comp;
        }, $priceComponents);
    }

    /**
     * Format week period in Finnish.
     *
     * Examples:
     * - Same month: "20.–26. tammikuuta 2026"
     * - Different months: "27.1.–2.2.2026"
     */
    private function formatWeekPeriod(Carbon $start, Carbon $end): string
    {
        if ($start->month === $end->month) {
            // Same month: "20.–26. tammikuuta 2026"
            return sprintf(
                '%d.–%d. %s %d',
                $start->day,
                $end->day,
                self::FINNISH_MONTHS[$start->month],
                $start->year
            );
        }

        // Different months: "27.1.–2.2.2026"
        return sprintf(
            '%d.%d.–%d.%d.%d',
            $start->day,
            $start->month,
            $end->day,
            $end->month,
            $end->year
        );
    }
}
