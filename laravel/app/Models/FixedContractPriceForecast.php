<?php

namespace App\Models;

use App\Services\PriceForecasting\FixedTermPriceForecastService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class FixedContractPriceForecast extends Model
{
    protected $fillable = [
        'forecast_date',
        'target_date',
        'horizon_days',
        'duration_months',
        'target_quantile',
        'current_price_cents_per_kwh',
        'forecast_price_cents_per_kwh',
        'expected_change_cents_per_kwh',
        'interval_low_cents_per_kwh',
        'interval_high_cents_per_kwh',
        'hedge_cost_cents_per_kwh',
        'retail_premium_cents_per_kwh',
        'normal_retail_premium_cents_per_kwh',
        'fair_price_cents_per_kwh',
        'gap_cents_per_kwh',
        'futures_trade_date',
        'coverage_quality',
        'confidence',
        'direction',
        'consumer_signal',
        'contract_count',
        'model_version',
        'source_metadata',
        'actual_price_cents_per_kwh',
        'actual_change_cents_per_kwh',
        'forecast_error_cents_per_kwh',
        'absolute_error_cents_per_kwh',
        'actual_direction',
        'direction_correct',
        'evaluated_at',
    ];

    /**
     * Keep public forecast surfaces on one current model and an explicit current
     * retail-input basis. Old rows remain stored for audit and evaluation.
     */
    public function scopeEligibleForPublicDisplay(Builder $query): Builder
    {
        $pricingBasis = (bool) config('canonical_pricing.enabled', false)
            ? FixedTermPriceForecastService::CANONICAL_PRICING_BASIS
            : FixedTermPriceForecastService::OBSERVED_PRICING_BASIS;

        return $query
            ->where('model_version', (string) config('price_forecasting.fixed_term.model_version', 'fixed_term_ewma_gap_v2'))
            ->where('source_metadata->current_retail_pricing_basis', $pricingBasis);
    }

    protected function casts(): array
    {
        return [
            'forecast_date' => 'date',
            'target_date' => 'date',
            'horizon_days' => 'integer',
            'duration_months' => 'integer',
            'current_price_cents_per_kwh' => 'float',
            'forecast_price_cents_per_kwh' => 'float',
            'expected_change_cents_per_kwh' => 'float',
            'interval_low_cents_per_kwh' => 'float',
            'interval_high_cents_per_kwh' => 'float',
            'hedge_cost_cents_per_kwh' => 'float',
            'retail_premium_cents_per_kwh' => 'float',
            'normal_retail_premium_cents_per_kwh' => 'float',
            'fair_price_cents_per_kwh' => 'float',
            'gap_cents_per_kwh' => 'float',
            'futures_trade_date' => 'date',
            'contract_count' => 'integer',
            'source_metadata' => 'array',
            'actual_price_cents_per_kwh' => 'float',
            'actual_change_cents_per_kwh' => 'float',
            'forecast_error_cents_per_kwh' => 'float',
            'absolute_error_cents_per_kwh' => 'float',
            'direction_correct' => 'boolean',
            'evaluated_at' => 'datetime',
        ];
    }
}
