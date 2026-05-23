<?php

namespace App\Models;

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
