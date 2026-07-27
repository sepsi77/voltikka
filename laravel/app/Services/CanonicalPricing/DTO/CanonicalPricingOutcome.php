<?php

namespace App\Services\CanonicalPricing\DTO;

use App\Services\CanonicalPricing\Enums\ContractComparability;
use App\Services\CanonicalPricing\Enums\EstimateMethod;

/**
 * The result of costing one contract's canonical pricing across the 12-month comparison
 * window. `toCalculatedCostArray()` is a superset of the legacy ContractPricingResult
 * array shape so existing cards/detail views keep working unchanged.
 *
 * For excluded contracts (`comparability` not listed) `totalCost` is null and the
 * contract must not be ranked or shown an annual total.
 */
readonly class CanonicalPricingOutcome
{
    /**
     * @param  array<int, float>  $monthlyCosts
     * @param  array<int, float>  $baseMonthlyCosts
     * @param  array<int, float>  $monthlyDiscountSavings
     * @param  list<array<string, mixed>>  $phaseBreakdown
     * @param  list<OfferTermData>  $offerTerms
     * @param  list<string>  $assumptions
     * @param  array<string, mixed>|null  $resetEstimate  Typed evidence for a market-reset tail
     *                                                    estimate (basis, reference kind, curve vintage, current-period price, estimated
     *                                                    12-month equivalent). Null when no shift was applied.
     */
    public function __construct(
        public ContractComparability $comparability,
        public EstimateMethod $estimateMethod,
        public ?float $totalCost,
        public array $monthlyCosts,
        public ?float $baseTotalCost,
        public array $baseMonthlyCosts,
        public float $measuredDiscountSavingsTotal,
        public array $monthlyDiscountSavings,
        public ?float $structuredOnlyTotal,
        public bool $isSpotContract,
        public ?float $monthlyFixedFee = null,
        public ?float $spotPriceMargin = null,
        public ?float $generalKwhPrice = null,
        public ?float $daytimeKwhPrice = null,
        public ?float $nighttimeKwhPrice = null,
        public ?float $seasonalWinterDayKwhPrice = null,
        public ?float $seasonalOtherKwhPrice = null,
        public ?float $spotPriceDayAvg = null,
        public ?float $spotPriceNightAvg = null,
        public ?int $termMonths = null,
        public ?IncludedEnergyPackageData $energyPackage = null,
        public ?float $contractTermTotalCost = null,
        public ?float $contractTermBaseTotalCost = null,
        public ?float $contractTermDiscountSavingsTotal = null,
        public array $phaseBreakdown = [],
        public array $offerTerms = [],
        public ?ConsumptionEffectData $consumptionEffect = null,
        public array $assumptions = [],
        public ?array $resetEstimate = null,
    ) {}

    public function isListed(): bool
    {
        return $this->comparability->isListed();
    }

    public function isEstimate(): bool
    {
        return $this->comparability->isEstimate();
    }

    /**
     * Discount savings vs the disclosed normal price, when a later normal price is known.
     */
    public function discountSavingsTotal(): float
    {
        return $this->measuredDiscountSavingsTotal;
    }

    /**
     * Superset of the legacy `ContractPricingResult::toArray()` shape plus canonical fields.
     *
     * @return array<string, mixed>
     */
    public function toCalculatedCostArray(): array
    {
        $savings = $this->discountSavingsTotal();

        return [
            // Legacy keys consumed by cards/detail/rankings.
            'total_cost' => $this->totalCost,
            'avg_monthly_cost' => $this->totalCost !== null ? $this->totalCost / 12 : null,
            'monthly_costs' => $this->monthlyCosts,
            'monthly_fixed_fee' => $this->monthlyFixedFee,
            'spot_price_margin' => $this->spotPriceMargin,
            'general_kwh_price' => $this->generalKwhPrice,
            'nighttime_kwh_price' => $this->nighttimeKwhPrice,
            'daytime_kwh_price' => $this->daytimeKwhPrice,
            'seasonal_winter_day_kwh_price' => $this->seasonalWinterDayKwhPrice,
            'seasonal_other_kwh_price' => $this->seasonalOtherKwhPrice,
            'spot_price_day_avg' => $this->spotPriceDayAvg,
            'spot_price_night_avg' => $this->spotPriceNightAvg,
            'is_spot_contract' => $this->isSpotContract,
            'base_total_cost' => $this->baseTotalCost,
            'base_avg_monthly_cost' => $this->baseTotalCost !== null ? $this->baseTotalCost / 12 : null,
            'base_monthly_costs' => $this->baseMonthlyCosts,
            'discount_savings_total' => $savings,
            'monthly_discount_savings' => $this->monthlyDiscountSavings,
            'includes_discounts' => $savings > 0,

            // Canonical additions.
            'pricing_basis' => 'canonical',
            'comparability' => $this->comparability->value,
            'is_estimate' => $this->isEstimate(),
            'estimate_method' => $this->estimateMethod->value,
            'term_months' => $this->termMonths,
            'energy_package' => $this->energyPackage?->toArray(),
            'contract_term' => $this->termMonths !== null
                && $this->contractTermTotalCost !== null
                && $this->contractTermBaseTotalCost !== null
                && $this->contractTermDiscountSavingsTotal !== null
                    ? [
                        'months' => $this->termMonths,
                        'total_cost' => $this->contractTermTotalCost,
                        'base_total_cost' => $this->contractTermBaseTotalCost,
                        'discount_savings_total' => $this->contractTermDiscountSavingsTotal,
                    ]
                    : null,
            'phase_breakdown' => $this->phaseBreakdown,
            'offer_terms' => array_map(
                static fn (OfferTermData $term): array => $term->toArray(),
                $this->offerTerms,
            ),
            'structured_only_total' => $this->structuredOnlyTotal,
            'consumption_effect' => $this->consumptionEffect?->toArray(),
            'assumptions' => $this->assumptions,
            'reset_estimate' => $this->resetEstimate,
        ];
    }
}
