<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContractPriceSnapshot extends Model
{
    protected $fillable = [
        'snapshot_date',
        'contract_id',
        'company_name',
        'contract_name',
        'pricing_model',
        'contract_type',
        'fixed_time_range',
        'metering',
        'segment_key',
        'pricing_basis',
        'energy_price_cents_per_kwh',
        'spot_margin_cents_per_kwh',
        'spot_total_energy_price_cents_per_kwh',
        'monthly_fee_eur',
        'annual_cost_2000_kwh',
        'annual_cost_5000_kwh',
        'annual_cost_18000_kwh',
        'has_discount',
        'includes_spot_price',
    ];

    protected function casts(): array
    {
        return [
            'snapshot_date' => 'date',
            'energy_price_cents_per_kwh' => 'float',
            'spot_margin_cents_per_kwh' => 'float',
            'spot_total_energy_price_cents_per_kwh' => 'float',
            'monthly_fee_eur' => 'float',
            'annual_cost_2000_kwh' => 'float',
            'annual_cost_5000_kwh' => 'float',
            'annual_cost_18000_kwh' => 'float',
            'has_discount' => 'boolean',
            'includes_spot_price' => 'boolean',
        ];
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(ElectricityContract::class, 'contract_id', 'id');
    }
}
