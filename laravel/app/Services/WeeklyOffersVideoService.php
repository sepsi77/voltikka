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

        // Fetch contracts with active discounts (max 3 for better per-card display time)
        $contracts = $this->getContractsWithActiveDiscounts(3);

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
     * Get contracts with active discounts, prioritizing company diversity.
     *
     * Selects at most one contract per company, preferring the best discount
     * from each company, then orders all by discount value.
     */
    private function getContractsWithActiveDiscounts(int $limit): Collection
    {
        $now = Carbon::now();

        // Fetch all contracts with active discounts
        $allContracts = ElectricityContract::query()
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
            ->get();

        // Group by company and select the best discount from each
        $bestByCompany = $allContracts
            ->groupBy('company_name')
            ->map(function ($companyContracts) {
                // For each company, pick the contract with the highest discount value
                return $companyContracts->sortByDesc(function ($contract) {
                    $discountInfo = $contract->getActiveDiscountInfo();
                    return $discountInfo ? abs($discountInfo['value']) : 0;
                })->first();
            })
            ->filter() // Remove nulls
            ->values();

        // Sort all selected contracts by discount value and take the limit
        return $bestByCompany
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

        // Never promote a contract flagged with deceptive/incomplete pricing or excluded from comparison.
        $canonicalPricing = app(\App\Services\CanonicalPricing\CanonicalContractPricingService::class);
        if ($canonicalPricing->enabled()) {
            $usage = new EnergyUsage(total: 5000, basicLiving: 5000);
            $evaluation = $canonicalPricing->evaluate($contract, $usage);
            if (! $evaluation['outcome']->isListed() || $evaluation['integrity']->detected) {
                return null;
            }
        }

        // Get latest price components with discount metadata
        $priceComponents = $contract->getLatestPriceComponentsForCalculation();

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

        $pricingResults = $this->calculateCostsForAllConsumptions(
            $priceComponents,
            $contractData,
            $spotPrices,
        );

        $costsWithDiscount = [
            'apartment' => round($pricingResults['apartment']['total_cost']),
            'townhouse' => round($pricingResults['townhouse']['total_cost']),
            'house' => round($pricingResults['house']['total_cost']),
        ];

        $savings = [
            'apartment' => round($pricingResults['apartment']['discount_savings_total']),
            'townhouse' => round($pricingResults['townhouse']['discount_savings_total']),
            'house' => round($pricingResults['house']['discount_savings_total']),
        ];

        return [
            'id' => $contract->id,
            'name' => $contract->name,
            'description' => $contract->short_description,
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
                'price_component_type' => $discountInfo['price_component_type'],
                'payment_unit' => $discountInfo['payment_unit'],
                'formatted' => $contract->formatActiveDiscountValue($discountInfo),
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

            $costs[$key] = $result->toArray();
        }

        return $costs;
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
