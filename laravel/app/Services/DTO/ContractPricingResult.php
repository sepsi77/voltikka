<?php

namespace App\Services\DTO;

class ContractPricingResult
{
    public function __construct(
        public readonly float $totalCost,
        public readonly float $avgMonthlyCost,
        public readonly array $monthlyCosts,
        public readonly ?float $monthlyFixedFee = null,
        public readonly ?float $spotPriceMargin = null,
        public readonly ?float $generalKwhPrice = null,
        public readonly ?float $nighttimeKwhPrice = null,
        public readonly ?float $daytimeKwhPrice = null,
        public readonly ?float $seasonalWinterDayKwhPrice = null,
        public readonly ?float $seasonalOtherKwhPrice = null,
        public readonly ?float $spotPriceDayAvg = null,
        public readonly ?float $spotPriceNightAvg = null,
        public readonly bool $isSpotContract = false,
    ) {
    }

    public function toArray(): array
    {
        return [
            'total_cost' => $this->totalCost,
            'avg_monthly_cost' => $this->avgMonthlyCost,
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
        ];
    }
}
