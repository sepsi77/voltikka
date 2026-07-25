<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RetailPremiumObservation extends Model
{
    protected $fillable = [
        'observation_key',
        'price_signature',
        'lineage_key',
        'lineage_contract_id',
        'contract_id',
        'published_interpretation_id',
        'source_snapshot_id',
        'company_name',
        'pricing_model',
        'cadence',
        'contract_type',
        'target_group',
        'metering',
        'phase_index',
        'phase_kind',
        'phase_label',
        'period_start',
        'period_end',
        'first_observed_date',
        'last_observed_date',
        'energy_components',
        'energy_component_type',
        'retail_energy_price_cents_per_kwh',
        'monthly_fee_eur',
        'vat_basis',
        'reference_consumption_kwh',
        'reference_kind',
        'reference_trade_date',
        'reference_price_cents_per_kwh',
        'retail_premium_cents_per_kwh',
        'retail_premium_with_fee_cents_per_kwh',
        'method_version',
        'quality',
        'quality_flags',
        'source_metadata',
    ];

    protected function casts(): array
    {
        return [
            'published_interpretation_id' => 'integer',
            'source_snapshot_id' => 'integer',
            'phase_index' => 'integer',
            'period_start' => 'date',
            'period_end' => 'date',
            'first_observed_date' => 'date',
            'last_observed_date' => 'date',
            'energy_components' => 'array',
            'retail_energy_price_cents_per_kwh' => 'float',
            'monthly_fee_eur' => 'float',
            'reference_consumption_kwh' => 'integer',
            'reference_trade_date' => 'date',
            'reference_price_cents_per_kwh' => 'float',
            'retail_premium_cents_per_kwh' => 'float',
            'retail_premium_with_fee_cents_per_kwh' => 'float',
            'quality_flags' => 'array',
            'source_metadata' => 'array',
        ];
    }
}
