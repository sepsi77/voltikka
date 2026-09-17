<?php

namespace App\Services\CanonicalPricing\SupplierAdjusted;

use App\Services\CanonicalPricing\Enums\ComparisonPolicy;
use App\Services\CanonicalPricing\ForwardPremium\PremiumFamily;
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
        private readonly ResetEstimatorSettings $settings = new ResetEstimatorSettings,
    ) {}

    public function estimate(SupplierAdjustedEstimateRequest $request): SupplierAdjustedEstimate
    {
        $holdBeta = $request->policy === ComparisonPolicy::Current ? 0.0 : $this->settings->beta;
        if ($request->tailMonthKeys === []) {
            return SupplierAdjustedEstimate::holdFlat($request, $holdBeta, ['no_estimated_tail']);
        }

        $forward = $this->forwardShift($request);
        if ($forward !== null && $this->isPlausible($forward)) {
            return $forward;
        }

        $premium = $forward === null ? $this->forwardPremium($request) : null;
        if ($premium !== null && $this->isPlausible($premium)) {
            return $premium;
        }

        $seasonal = $this->seasonalIndexShift(
            $request,
            $forward === null
                ? ($request->energyRates !== [] ? ['no_usable_own_reference_and_curve_pair', 'no_usable_comparable_premium_and_curve_pair'] : [])
                : ['forward_shift_outside_plausibility_band'],
        );
        if ($seasonal !== null && $this->isPlausible($seasonal)) {
            return $seasonal;
        }

        $flags = ['no_usable_market_shape'];
        if ($request->energyRates !== []) {
            $flags[] = $request->premium === null ? 'no_defensible_comparable_premium' : 'comparable_premium_curve_unavailable_or_implausible';
        }
        if ($forward !== null) {
            $flags[] = 'forward_shift_outside_plausibility_band';
        }
        if ($seasonal !== null) {
            $flags[] = 'seasonal_index_outside_plausibility_band';
        }

        return SupplierAdjustedEstimate::holdFlat($request, $holdBeta, $flags);
    }

    private function forwardShift(SupplierAdjustedEstimateRequest $request): ?SupplierAdjustedEstimate
    {
        $episodeStart = $request->priceEpisodeAnchor->startedAt;
        if ($episodeStart === null) {
            return null;
        }

        $tradeDate = $this->curve->tradeDate($request->asOfDate);
        if ($tradeDate === null || ($request->policy === ComparisonPolicy::Current && ! $tradeDate->lt($request->asOfDate)) || $tradeDate->diffInDays($request->asOfDate) > $this->settings->maxCurveAgeDays) {
            return null;
        }

        $reference = $this->curve->referencePrice(
            $episodeStart->min($request->asOfDate),
            $episodeStart->startOfMonth(),
            ['month'],
        );
        if ($request->policy === ComparisonPolicy::Current && $reference !== null
            && (! is_finite((float) $reference['price_cents_per_kwh'])
                || ! $this->validReferenceDate($reference['trade_date'] ?? null, $episodeStart->min($request->asOfDate)))) {
            return null;
        }
        if ($reference === null) {
            return null;
        }

        $offsets = [];
        $flags = $episodeStart->greaterThan($request->asOfDate)
            ? ['reference_vintage_bounded_by_as_of'] : [];
        foreach ($request->tailMonthKeys as $monthKey) {
            $forward = $this->curve->forwardPriceForMonth($request->asOfDate, $this->monthFromKey($monthKey));
            if ($forward === null || ($request->policy === ComparisonPolicy::Current && ! is_finite((float) $forward['price_cents_per_kwh']))) {
                return null;
            }
            if ($forward['kind'] !== 'month') {
                $flags[] = 'forward_month_from_'.$forward['kind'].'_contract';
            }
            $offsets[$monthKey] = $this->settings->beta
                * ($forward['price_cents_per_kwh'] * $request->marketPriceMultiplier
                    - $reference['price_cents_per_kwh'] * $request->marketPriceMultiplier);
            if ($request->policy === ComparisonPolicy::Current && ! is_finite($offsets[$monthKey])) {
                return null;
            }
        }

        return new SupplierAdjustedEstimate(
            basis: SupplierAdjustedEstimateBasis::ForwardCurveShift,
            offsetsByMonthKey: $offsets,
            beta: $this->settings->beta,
            currentEnergyPriceCentsPerKwh: $request->currentEnergyPriceCentsPerKwh,
            monthlyFeeEur: $request->monthlyFeeEur,
            annualEquivalentEnergyPriceCentsPerKwh: $this->annualEquivalent($request, $offsets),
            referenceKind: $reference['kind'],
            referencePriceCentsPerKwh: $reference['price_cents_per_kwh'] * $request->marketPriceMultiplier,
            curveTradeDate: $tradeDate->toDateString(),
            referenceTradeDate: ($reference['trade_date'] ?? '') !== '' ? $reference['trade_date'] : null,
            tailStartsMonthKey: $request->tailMonthKeys[0],
            priceEpisodeAnchor: $request->priceEpisodeAnchor,
            flags: array_values(array_unique($flags)),
        );
    }

    private function forwardPremium(SupplierAdjustedEstimateRequest $request): ?SupplierAdjustedEstimate
    {
        $premium = $request->premium;
        if ($premium === null || $request->energyRates === []
            || array_diff_key($request->energyRates, $premium->premiumsByBucket) !== []
            || array_diff_key($premium->premiumsByBucket, $request->energyRates) !== []) {
            return null;
        }
        foreach ($premium->observations as $observation) {
            if ($observation->compatibility->family !== ($request->pricingMechanism === 'Hybrid' ? PremiumFamily::SupplierAdjustedHybridBase : PremiumFamily::SupplierAdjusted)
                || $observation->observedAt->gt($request->asOfDate)
                || ! $observation->referenceTradeDate->lt($request->asOfDate)) {
                return null;
            }
        }
        $trade = $this->curve->tradeDate($request->asOfDate);
        if ($trade === null || ! $trade->lt($request->asOfDate) || $trade->diffInDays($request->asOfDate) > $this->settings->maxCurveAgeDays) {
            return null;
        }
        $offsets = [];
        foreach ($request->tailMonthKeys as $monthKey) {
            $point = $this->curve->forwardPriceForMonth($request->asOfDate, $this->monthFromKey($monthKey));
            if ($point === null || ! is_finite((float) $point['price_cents_per_kwh'])) {
                return null;
            }
            foreach ($request->energyRates as $bucket => $rate) {
                $offsets[$monthKey][$bucket] = $this->settings->beta
                    * ($point['price_cents_per_kwh'] * $request->marketPriceMultiplier + $premium->premiumsByBucket[$bucket] - $rate);
            }
        }
        $weighted = $weight = 0.0;
        foreach ($request->bucketMonthWeights as $monthKey => $buckets) {
            foreach ($buckets as $bucket => $kwh) {
                $bucket = SupplierAdjustedEstimate::energyBucket($bucket);
                if (! array_key_exists($bucket, $request->energyRates)) {
                    continue;
                }
                $weighted += $kwh * max(0.0, $request->energyRates[$bucket] + ($offsets[$monthKey][$bucket] ?? 0.0));
                $weight += $kwh;
            }
        }

        return new SupplierAdjustedEstimate(
            basis: SupplierAdjustedEstimateBasis::ForwardPremium,
            offsetsByMonthKey: [], beta: $this->settings->beta,
            currentEnergyPriceCentsPerKwh: $request->currentEnergyPriceCentsPerKwh,
            monthlyFeeEur: $request->monthlyFeeEur,
            annualEquivalentEnergyPriceCentsPerKwh: $weight > 0 ? $weighted / $weight : null,
            referenceKind: 'comparable_energy_episode_month_proxy', referencePriceCentsPerKwh: null,
            curveTradeDate: $trade->toDateString(), referenceTradeDate: null,
            tailStartsMonthKey: $request->tailMonthKeys[0], priceEpisodeAnchor: $request->priceEpisodeAnchor,
            flags: ['missing_own_reference_using_comparable_premium', 'lower_confidence_energy_episode_proxy'],
            bucketOffsetsByMonthKey: $offsets, premium: $premium,
        );
    }

    /** @param list<string> $carriedFlags */
    private function seasonalIndexShift(SupplierAdjustedEstimateRequest $request, array $carriedFlags): ?SupplierAdjustedEstimate
    {
        $episodeStart = $request->priceEpisodeAnchor->startedAt;
        if ($episodeStart === null || ! $this->settings->seasonalIndexEnabled) {
            return null;
        }

        $index = $this->curve->spotSeasonalIndex($request->asOfDate);
        if ($index === null) {
            return null;
        }

        $referenceIndex = $index[(int) $episodeStart->month] ?? null;
        if ($referenceIndex === null || $referenceIndex <= 0 || ($request->policy === ComparisonPolicy::Current && ! is_finite($referenceIndex))) {
            return null;
        }

        $anchor = $request->policy === ComparisonPolicy::Current
            ? ($request->seasonalAnchorEnergyPriceCentsPerKwh ?? $request->currentEnergyPriceCentsPerKwh)
            : $request->currentEnergyPriceCentsPerKwh;
        $offsets = [];
        foreach ($request->tailMonthKeys as $monthKey) {
            $monthIndex = $index[(int) $this->monthFromKey($monthKey)->month] ?? null;
            if ($monthIndex === null || ($request->policy === ComparisonPolicy::Current && (! is_finite($monthIndex) || $monthIndex <= 0))) {
                return null;
            }
            $offsets[$monthKey] = $this->settings->beta
                * ($anchor * ($monthIndex / $referenceIndex) - $anchor);
        }

        return new SupplierAdjustedEstimate(
            basis: SupplierAdjustedEstimateBasis::SpotSeasonalIndex,
            offsetsByMonthKey: $offsets,
            beta: $this->settings->beta,
            currentEnergyPriceCentsPerKwh: $request->currentEnergyPriceCentsPerKwh,
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

    private function validReferenceDate(mixed $date, CarbonImmutable $bound): bool
    {
        return is_string($date)
            && preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date) === 1
            && checkdate((int) substr($date, 5, 2), (int) substr($date, 8, 2), (int) substr($date, 0, 4))
            && $date < $bound->toDateString();
    }

    private function monthFromKey(string $monthKey): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('Y-m-d', $monthKey.'-01', 'Europe/Helsinki')->startOfMonth();
    }
}
