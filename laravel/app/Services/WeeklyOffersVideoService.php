<?php

namespace App\Services;

use App\Enums\TargetGroup;
use App\Models\ElectricityContract;
use App\Models\SpotPriceAverage;
use App\Services\CanonicalPricing\CanonicalContractPricingService;
use App\Services\CanonicalPricing\CanonicalOfferFacts;
use App\Services\CanonicalPricing\Enums\ContractComparability;
use App\Services\ContractPricing\CanonicalContractMetric;
use App\Services\ContractPricing\ContractPricingViewData;
use App\Services\DTO\EnergyUsage;
use Carbon\Carbon;
use Carbon\CarbonInterface;
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

    private const SELECTION_PROFILE = 'townhouse';

    private const CONSUMPTIONS = [
        'apartment' => self::APARTMENT_CONSUMPTION,
        'townhouse' => self::TOWNHOUSE_CONSUMPTION,
        'house' => self::HOUSE_CONSUMPTION,
    ];

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
        private readonly CanonicalContractPricingService $canonicalPricing,
    ) {}

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

        $offers = $this->canonicalPricing->enabled()
            ? $this->getCanonicalOffers(3, $now->copy()->startOfDay())
            : $this->getLegacyOffers(3);

        return [
            'generated_at' => Carbon::now()->toIso8601String(),
            'pricing_basis' => $this->canonicalPricing->enabled() ? 'canonical' : 'legacy_relational',
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
     * Canonical offer membership and order use the measured customer benefit at
     * 5,000 kWh. The canonical comparison total is the first deterministic tie-break.
     * One contract per company is kept after that truthful global order is applied.
     *
     * @return list<array<string, mixed>>
     */
    private function getCanonicalOffers(int $limit, CarbonInterface $startDate): array
    {
        $contracts = ElectricityContract::query()
            ->active()
            ->where('target_group', TargetGroup::Household->value)
            ->with(['company', 'electricitySource'])
            ->get();

        $metricsByProfile = [];
        foreach (self::CONSUMPTIONS as $profile => $consumption) {
            $metricsByProfile[$profile] = $this->canonicalPricing->metricsForContracts(
                $contracts,
                new EnergyUsage(total: $consumption, basicLiving: $consumption),
                $startDate,
            );
        }

        return $contracts
            ->map(fn (ElectricityContract $contract): ?array => $this->transformCanonicalContractToOffer(
                $contract,
                $metricsByProfile,
            ))
            ->filter()
            ->sort(function (array $left, array $right): int {
                $benefitOrder = $right['selection']['measured_customer_benefit_eur']
                    <=> $left['selection']['measured_customer_benefit_eur'];
                if ($benefitOrder !== 0) {
                    return $benefitOrder;
                }

                $totalOrder = $left['selection']['canonical_total_cost']
                    <=> $right['selection']['canonical_total_cost'];

                return $totalOrder !== 0
                    ? $totalOrder
                    : strcmp((string) $left['id'], (string) $right['id']);
            })
            ->unique('company.name')
            ->take($limit)
            ->values()
            ->all();
    }

    /**
     * @param  array<string, array<string, CanonicalContractMetric>>  $metricsByProfile
     * @return array<string, mixed>|null
     */
    private function transformCanonicalContractToOffer(
        ElectricityContract $contract,
        array $metricsByProfile,
    ): ?array {
        $selectionMetric = $metricsByProfile[self::SELECTION_PROFILE][$contract->id] ?? null;
        if (! $selectionMetric instanceof CanonicalContractMetric) {
            return null;
        }
        $selectionPricing = $selectionMetric->pricing();
        $offerFacts = CanonicalOfferFacts::fromPricing($selectionPricing);

        if ($offerFacts === null
            || ! $selectionMetric->isListed()
            || $selectionMetric->integrity()->detected
            || $selectionMetric->sortKey() === null) {
            return null;
        }

        $consumptions = [];
        foreach (self::CONSUMPTIONS as $profile => $consumption) {
            $metric = $metricsByProfile[$profile][$contract->id] ?? null;
            if (! $metric instanceof CanonicalContractMetric
                || ! $metric->isListed()
                || $metric->integrity()->detected) {
                return null;
            }

            $consumptions[$profile] = $this->canonicalConsumptionOutput($metric, $consumption);
        }

        return [
            'id' => $contract->id,
            'name' => $contract->name,
            'description' => $contract->short_description,
            'company' => [
                'name' => $contract->company_name,
                'logo_url' => $contract->company?->getLogoUrl(),
            ],
            'pricing_model' => $contract->pricing_model,
            'pricing_basis' => 'canonical',
            'comparability' => $selectionMetric->comparability()->value,
            'offer' => [
                ...$offerFacts,
                'benefit_eur' => $this->money($offerFacts['benefit_eur']),
            ],
            'pricing_integrity' => [
                'detected' => false,
                'reason_family' => $selectionMetric->integrity()->reasonFamily->value,
                'issue_codes' => $selectionMetric->integrity()->issueCodes,
            ],
            'pricing' => $this->canonicalCurrentPricing($selectionPricing),
            'consumptions' => $consumptions,
            'selection' => [
                'metric' => 'measured_customer_benefit',
                'consumption_kwh' => self::TOWNHOUSE_CONSUMPTION,
                'measured_customer_benefit_eur' => $this->money($offerFacts['benefit_eur']),
                'benefit_basis_months' => $offerFacts['basis_months'],
                'canonical_total_cost' => $selectionMetric->sortKey(),
                'tie_break' => 'canonical_total_cost_ascending_then_contract_id',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function canonicalConsumptionOutput(CanonicalContractMetric $metric, int $consumption): array
    {
        $pricing = $metric->pricing();
        $offer = CanonicalOfferFacts::fromPricing($pricing);
        $term = $pricing->contractTerm();
        $isShortTerm = $pricing->comparability() === ContractComparability::TermPriceOnly;

        return [
            'annual_consumption_kwh' => $consumption,
            'pricing_basis' => 'canonical',
            'availability' => $metric->isListed() ? 'available' : 'unavailable',
            'comparability' => $metric->comparability()->value,
            'total_cost' => $this->money($pricing->total()),
            'normal_total_cost' => $this->money($pricing->baseTotal()),
            'avg_monthly_cost' => $this->money($pricing->averageMonthlyCost()),
            'normal_avg_monthly_cost' => $this->money($pricing->baseAverageMonthlyCost()),
            'comparison_measured_saving' => $this->money($pricing->discountSaving()),
            'total_basis' => $isShortTerm ? 'annualized_contract_term' : 'first_12_months',
            'total_basis_label' => $isShortTerm
                ? 'Vuositasolle muunnettu vertailuhinta'
                : 'Ensimmäisen 12 kuukauden hinta',
            'is_estimate' => $pricing->isEstimate(),
            'estimate_method' => $pricing->estimateMethod()?->value,
            'customer_benefit_eur' => $offer !== null ? $this->money($offer['benefit_eur']) : null,
            'customer_benefit_basis_months' => $offer['basis_months'] ?? null,
            'customer_benefit_basis_label' => $offer['basis_label'] ?? null,
            'contract_term' => $term === null ? null : [
                'months' => $term->integer('months'),
                'total_cost' => $this->money($term->number('total_cost')),
                'normal_total_cost' => $this->money($term->number('base_total_cost')),
                'measured_saving' => $this->money($term->number('discount_savings_total')),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function canonicalCurrentPricing(ContractPricingViewData $pricing): array
    {
        return [
            'monthly_fee' => $this->number($pricing->monthlyFixedFee()),
            'general_kwh_price' => $this->number($pricing->generalKwhPrice()),
            'daytime_kwh_price' => $this->number($pricing->daytimeKwhPrice()),
            'nighttime_kwh_price' => $this->number($pricing->nighttimeKwhPrice()),
            'seasonal_winter_day_kwh_price' => $this->number($pricing->seasonalWinterDayKwhPrice()),
            'seasonal_other_kwh_price' => $this->number($pricing->seasonalOtherKwhPrice()),
            'spot_price_margin' => $this->number($pricing->spotPriceMargin()),
            'energy_package' => $pricing->energyPackage()?->toArray(),
        ];
    }

    private function money(mixed $value): ?float
    {
        return is_numeric($value) ? round((float) $value, 2) : null;
    }

    private function number(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    /**
     * Keep the feature-off relational offer path unchanged.
     *
     * @return list<array<string, mixed>>
     */
    private function getLegacyOffers(int $limit): array
    {
        $contracts = $this->getContractsWithActiveDiscounts($limit);
        $spotPrices = $this->getSpotPriceAverages();

        return $contracts
            ->map(fn (ElectricityContract $contract) => $this->transformContractToOffer($contract, $spotPrices))
            ->filter()
            ->values()
            ->all();
    }

    /**
     * Get contracts with active relational discounts for the feature-off path.
     */
    private function getContractsWithActiveDiscounts(int $limit): Collection
    {
        $now = Carbon::now();

        // Fetch all contracts with active discounts
        $allContracts = ElectricityContract::query()
            ->active()
            ->where('target_group', TargetGroup::Household->value)
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
        if (! $discountInfo) {
            return null;
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
        $costs = [];
        foreach (self::CONSUMPTIONS as $key => $consumption) {
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
