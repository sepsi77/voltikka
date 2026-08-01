<?php

namespace App\Services\DTO;

class ContractPeriodPricingResult
{
    public function __construct(
        public readonly bool $available,
        public readonly ?string $unavailableReason,
        public readonly ?float $periodTotal,
        public readonly ?float $basePeriodTotal,
        public readonly float $discountSavings,
        public readonly bool $hasPromotion,
        public readonly bool $isSpotContract,
        public readonly ?float $spotPriceMargin,
        public readonly ?float $spotPriceAverage,
        public readonly ?float $monthlyFixedFee,
        public readonly ?float $generalKwhPrice,
        public readonly ?float $daytimeKwhPrice,
        public readonly ?float $nighttimeKwhPrice,
        public readonly ?float $seasonalWinterDayKwhPrice,
        public readonly ?float $seasonalOtherKwhPrice,
    ) {}

    public static function unavailable(string $reason, bool $isSpotContract = false): self
    {
        return new self(
            available: false,
            unavailableReason: $reason,
            periodTotal: null,
            basePeriodTotal: null,
            discountSavings: 0.0,
            hasPromotion: false,
            isSpotContract: $isSpotContract,
            spotPriceMargin: null,
            spotPriceAverage: null,
            monthlyFixedFee: null,
            generalKwhPrice: null,
            daytimeKwhPrice: null,
            nighttimeKwhPrice: null,
            seasonalWinterDayKwhPrice: null,
            seasonalOtherKwhPrice: null,
        );
    }
}
