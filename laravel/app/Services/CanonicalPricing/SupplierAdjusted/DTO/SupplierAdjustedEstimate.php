<?php

namespace App\Services\CanonicalPricing\SupplierAdjusted\DTO;

use App\Services\CanonicalPricing\ForwardPremium\PremiumEstimate;
use App\Services\CanonicalPricing\SupplierAdjusted\Enums\SupplierAdjustedEstimateBasis;

readonly class SupplierAdjustedEstimate
{
    /**
     * @param  array<string, float>  $offsetsByMonthKey
     * @param  list<string>  $flags
     */
    public function __construct(
        public SupplierAdjustedEstimateBasis $basis,
        public array $offsetsByMonthKey,
        public float $beta,
        public float $currentEnergyPriceCentsPerKwh,
        public float $monthlyFeeEur,
        public ?float $annualEquivalentEnergyPriceCentsPerKwh,
        public ?string $referenceKind,
        public ?float $referencePriceCentsPerKwh,
        public ?string $curveTradeDate,
        public ?string $referenceTradeDate,
        public ?string $tailStartsMonthKey,
        public PriceEpisodeAnchor $priceEpisodeAnchor,
        public array $flags = [],
        public array $bucketOffsetsByMonthKey = [],
        public ?PremiumEstimate $premium = null,
    ) {}

    public static function holdFlat(SupplierAdjustedEstimateRequest $request, float $beta, array $flags = []): self
    {
        return new self(
            basis: SupplierAdjustedEstimateBasis::HoldFlat,
            offsetsByMonthKey: [],
            beta: $beta,
            currentEnergyPriceCentsPerKwh: $request->currentEnergyPriceCentsPerKwh,
            monthlyFeeEur: $request->monthlyFeeEur,
            annualEquivalentEnergyPriceCentsPerKwh: $request->currentEnergyPriceCentsPerKwh,
            referenceKind: null,
            referencePriceCentsPerKwh: null,
            curveTradeDate: null,
            referenceTradeDate: null,
            tailStartsMonthKey: $request->tailMonthKeys[0] ?? null,
            priceEpisodeAnchor: $request->priceEpisodeAnchor,
            flags: array_values(array_unique(array_merge($request->priceEpisodeAnchor->flags, $flags))),
        );
    }

    public static function energyBucket(string $bucket): string
    {
        return match ($bucket) {
            'General' => 'energy_general',
            'DayTime' => 'energy_day',
            'NightTime' => 'energy_night',
            'SeasonalWinterDay' => 'energy_seasonal_winter',
            'SeasonalOther' => 'energy_seasonal_other',
            default => $bucket,
        };
    }

    public function offsetForMonthKey(string $monthKey, ?string $bucket = null): float
    {
        if ($bucket !== null && $this->bucketOffsetsByMonthKey !== []) {
            return $this->bucketOffsetsByMonthKey[$monthKey][self::energyBucket($bucket)] ?? 0.0;
        }

        return $this->offsetsByMonthKey[$monthKey] ?? 0.0;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            ...($this->premium === null ? [] : [
                'premium' => $this->premium->toArray(),
                'current_policy' => 'supplier_adjusted_forward_premium_v1',
                'fallback_reason' => 'missing_own_reference_using_comparable_premium',
            ]),
            'basis' => $this->basis->value,
            'beta' => $this->beta,
            'current_energy_price' => $this->currentEnergyPriceCentsPerKwh,
            'monthly_fee' => $this->monthlyFeeEur,
            'annual_equivalent_energy_price' => $this->annualEquivalentEnergyPriceCentsPerKwh,
            'reference_kind' => $this->referenceKind,
            'reference_price' => $this->referencePriceCentsPerKwh,
            'curve_trade_date' => $this->curveTradeDate,
            'reference_trade_date' => $this->referenceTradeDate,
            'price_episode_started_at' => $this->priceEpisodeAnchor->startedAt?->toDateString(),
            'price_episode_evidence_basis' => $this->priceEpisodeAnchor->evidenceBasis->value,
            'tail_starts' => $this->tailStartsMonthKey,
            'monthly_fee_assumption' => 'held_flat',
            'higher_confidence' => $this->basis->isHigherConfidence(),
            'flags' => array_values(array_unique(array_merge($this->priceEpisodeAnchor->flags, $this->flags))),
        ];
    }
}
