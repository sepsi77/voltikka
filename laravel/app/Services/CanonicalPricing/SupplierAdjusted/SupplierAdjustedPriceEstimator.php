<?php

namespace App\Services\CanonicalPricing\SupplierAdjusted;

use App\Services\CanonicalPricing\MarketReset\DTO\ResetEstimatorSettings;
use App\Services\CanonicalPricing\MarketReset\MarketReferenceCurveProvider;
use App\Services\CanonicalPricing\SupplierAdjusted\DTO\SupplierAdjustedEstimate;
use App\Services\CanonicalPricing\SupplierAdjusted\DTO\SupplierAdjustedEstimateRequest;
use App\Services\CanonicalPricing\SupplierAdjusted\Enums\SupplierAdjustedEstimateBasis;
use Carbon\CarbonImmutable;

/**
 * Estimates later comparison months for an adjustable supplier price. This is not a
 * recurring-reset model: it uses the observed start of the current supplier-price episode.
 */
class SupplierAdjustedPriceEstimator
{
    public function __construct(
        private readonly MarketReferenceCurveProvider $curve,
        private readonly ResetEstimatorSettings $settings = new ResetEstimatorSettings(),
    ) {}

    public function estimate(SupplierAdjustedEstimateRequest $request): SupplierAdjustedEstimate
    {
        if ($request->tailMonthKeys === []) {
            return SupplierAdjustedEstimate::holdFlat($request, $this->settings->beta, ['no_estimated_tail']);
        }

        $forward = $this->forwardShift($request);
        if ($forward !== null && $this->isPlausible($forward)) {
            return $forward;
        }

        $seasonal = $this->seasonalIndexShift(
            $request,
            $forward === null ? [] : ['forward_shift_outside_plausibility_band'],
        );
        if ($seasonal !== null && $this->isPlausible($seasonal)) {
            return $seasonal;
        }

        $flags = ['no_usable_market_shape'];
        if ($forward !== null) {
            $flags[] = 'forward_shift_outside_plausibility_band';
        }
        if ($seasonal !== null) {
            $flags[] = 'seasonal_index_outside_plausibility_band';
        }

        return SupplierAdjustedEstimate::holdFlat($request, $this->settings->beta, $flags);
    }

    private function forwardShift(SupplierAdjustedEstimateRequest $request): ?SupplierAdjustedEstimate
    {
        $episodeStart = $request->priceEpisodeAnchor->startedAt;
        if ($episodeStart === null) {
            return null;
        }

        $tradeDate = $this->curve->tradeDate($request->asOfDate);
        if ($tradeDate === null || $tradeDate->diffInDays($request->asOfDate) > $this->settings->maxCurveAgeDays) {
            return null;
        }

        $reference = $this->curve->referencePrice(
            $episodeStart,
            $episodeStart->startOfMonth(),
            ['month'],
        );
        if ($reference === null) {
            return null;
        }

        $offsets = [];
        $flags = [];
        foreach ($request->tailMonthKeys as $monthKey) {
            $forward = $this->curve->forwardPriceForMonth($request->asOfDate, $this->monthFromKey($monthKey));
            if ($forward === null) {
                return null;
            }
            if ($forward['kind'] !== 'month') {
                $flags[] = 'forward_month_from_'.$forward['kind'].'_contract';
            }
            $offsets[$monthKey] = $this->settings->beta
                * ($forward['price_cents_per_kwh'] - $reference['price_cents_per_kwh']);
        }

        return new SupplierAdjustedEstimate(
            basis: SupplierAdjustedEstimateBasis::ForwardCurveShift,
            offsetsByMonthKey: $offsets,
            beta: $this->settings->beta,
            currentEnergyPriceCentsPerKwh: $request->currentEnergyPriceCentsPerKwh,
            monthlyFeeEur: $request->monthlyFeeEur,
            annualEquivalentEnergyPriceCentsPerKwh: $this->annualEquivalent($request, $offsets),
            referenceKind: $reference['kind'],
            referencePriceCentsPerKwh: $reference['price_cents_per_kwh'],
            curveTradeDate: $tradeDate->toDateString(),
            referenceTradeDate: ($reference['trade_date'] ?? '') !== '' ? $reference['trade_date'] : null,
            tailStartsMonthKey: $request->tailMonthKeys[0],
            priceEpisodeAnchor: $request->priceEpisodeAnchor,
            flags: array_values(array_unique($flags)),
        );
    }

    /** @param list<string> $carriedFlags */
    private function seasonalIndexShift(SupplierAdjustedEstimateRequest $request, array $carriedFlags): ?SupplierAdjustedEstimate
    {
        $episodeStart = $request->priceEpisodeAnchor->startedAt;
        if ($episodeStart === null || ! $this->settings->seasonalIndexEnabled) {
            return null;
        }

        $index = $this->curve->spotSeasonalIndex();
        if ($index === null) {
            return null;
        }

        $referenceIndex = $index[(int) $episodeStart->month] ?? null;
        if ($referenceIndex === null || $referenceIndex <= 0) {
            return null;
        }

        $anchor = $request->currentEnergyPriceCentsPerKwh;
        $offsets = [];
        foreach ($request->tailMonthKeys as $monthKey) {
            $monthIndex = $index[(int) $this->monthFromKey($monthKey)->month] ?? null;
            if ($monthIndex === null) {
                return null;
            }
            $offsets[$monthKey] = $this->settings->beta
                * ($anchor * ($monthIndex / $referenceIndex) - $anchor);
        }

        return new SupplierAdjustedEstimate(
            basis: SupplierAdjustedEstimateBasis::SpotSeasonalIndex,
            offsetsByMonthKey: $offsets,
            beta: $this->settings->beta,
            currentEnergyPriceCentsPerKwh: $anchor,
            monthlyFeeEur: $request->monthlyFeeEur,
            annualEquivalentEnergyPriceCentsPerKwh: $this->annualEquivalent($request, $offsets),
            referenceKind: 'spot_seasonal_index',
            referencePriceCentsPerKwh: null,
            curveTradeDate: null,
            referenceTradeDate: null,
            tailStartsMonthKey: $request->tailMonthKeys[0],
            priceEpisodeAnchor: $request->priceEpisodeAnchor,
            flags: array_values(array_unique(array_merge($carriedFlags, ['lower_confidence_seasonal_index']))),
        );
    }

    /** @param array<string, float> $offsets */
    private function annualEquivalent(SupplierAdjustedEstimateRequest $request, array $offsets): ?float
    {
        $weighted = 0.0;
        $weights = 0.0;
        foreach ($request->monthWeights as $monthKey => $weight) {
            if ($weight <= 0) {
                continue;
            }
            $weighted += max(0.0, $request->currentEnergyPriceCentsPerKwh + ($offsets[$monthKey] ?? 0.0)) * $weight;
            $weights += $weight;
        }

        return $weights > 0 ? $weighted / $weights : null;
    }

    private function isPlausible(SupplierAdjustedEstimate $estimate): bool
    {
        $annual = $estimate->annualEquivalentEnergyPriceCentsPerKwh;

        return $annual !== null
            && $annual >= $this->settings->absurdityFloorCentsPerKwh
            && $annual <= $this->settings->absurdityCeilingCentsPerKwh;
    }

    private function monthFromKey(string $monthKey): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('Y-m-d', $monthKey.'-01', 'Europe/Helsinki')->startOfMonth();
    }
}
