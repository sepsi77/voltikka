<?php

namespace App\Services\CanonicalPricing\MarketReset\DTO;

use App\Services\CanonicalPricing\MarketReset\Enums\ResetEstimateBasis;

/**
 * The per-month energy-price offsets that reprice a market-reset tail, plus the basis
 * evidence every UI surface and the comparison command need.
 *
 * `offsetsByMonthKey` holds `Y-m` => c/kWh additive offsets. A month absent from the map
 * is contractually known and must not be shifted. `hold_flat` returns an empty map, which
 * makes the estimate a no-op and keeps behaviour identical to the pre-shift calculator.
 */
readonly class ResetEstimate
{
    /**
     * @param  array<string, float>  $offsetsByMonthKey
     * @param  list<string>  $flags
     */
    public function __construct(
        public ResetEstimateBasis $basis,
        public array $offsetsByMonthKey,
        public float $beta,
        public string $cadence,
        public float $currentPeriodEnergyPriceCentsPerKwh,
        public ?float $annualEquivalentEnergyPriceCentsPerKwh = null,
        public ?string $referenceKind = null,
        public ?float $referencePriceCentsPerKwh = null,
        public ?string $curveTradeDate = null,
        public ?string $referenceTradeDate = null,
        public ?string $anchorPeriodLabel = null,
        public ?string $tailStartsMonthKey = null,
        public array $flags = [],
    ) {
    }

    public static function holdFlat(string $cadence, float $currentPrice, array $flags = []): self
    {
        return new self(
            basis: ResetEstimateBasis::HoldFlat,
            offsetsByMonthKey: [],
            beta: 1.0,
            cadence: $cadence,
            currentPeriodEnergyPriceCentsPerKwh: $currentPrice,
            flags: $flags,
        );
    }

    public function shiftsPrices(): bool
    {
        return $this->offsetsByMonthKey !== [] && $this->basis->shiftsPrices();
    }

    public function offsetForMonthKey(string $monthKey): float
    {
        return $this->offsetsByMonthKey[$monthKey] ?? 0.0;
    }

    /**
     * Typed payload for card/detail copy and for the comparison command. Never contains
     * free text from an interpretation summary.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'basis' => $this->basis->value,
            'beta' => $this->beta,
            'cadence' => $this->cadence,
            'current_period_energy_price' => $this->currentPeriodEnergyPriceCentsPerKwh,
            'annual_equivalent_energy_price' => $this->annualEquivalentEnergyPriceCentsPerKwh,
            'reference_kind' => $this->referenceKind,
            'reference_price' => $this->referencePriceCentsPerKwh,
            'curve_trade_date' => $this->curveTradeDate,
            'reference_trade_date' => $this->referenceTradeDate,
            'anchor_period' => $this->anchorPeriodLabel,
            'tail_starts' => $this->tailStartsMonthKey,
            'higher_confidence' => $this->basis->isHigherConfidence(),
            'flags' => $this->flags,
        ];
    }
}
