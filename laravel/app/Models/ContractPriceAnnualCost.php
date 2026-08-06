<?php

namespace App\Models;

use App\Services\ContractStatistics\Enums\AnnualCostCalculationBasis;
use App\Services\ContractStatistics\Enums\AnnualCostMethodVersion;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContractPriceAnnualCost extends Model
{
    protected $fillable = [
        'snapshot_date',
        'contract_id',
        'segment_key',
        'pricing_basis',
        'consumption_kwh',
        'annual_cost',
        'method_version',
        'calculation_basis',
        'estimate_method',
        'estimate_basis',
        'compatibility_key',
        'source_observation_id',
        'source_snapshot_id',
        'source_interpretation_id',
        'price_episode_started_at',
        'provenance',
    ];

    protected function casts(): array
    {
        return [
            'snapshot_date' => 'date',
            'consumption_kwh' => 'integer',
            'annual_cost' => 'float',
            'method_version' => AnnualCostMethodVersion::class,
            'calculation_basis' => AnnualCostCalculationBasis::class,
            'source_observation_id' => 'integer',
            'source_snapshot_id' => 'integer',
            'source_interpretation_id' => 'integer',
            'price_episode_started_at' => 'datetime',
            'provenance' => 'array',
        ];
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(ElectricityContract::class, 'contract_id', 'id');
    }
}
