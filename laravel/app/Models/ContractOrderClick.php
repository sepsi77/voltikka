<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ContractOrderClick extends Model
{
    use HasFactory;

    public const UPDATED_AT = null;

    protected $fillable = [
        'event_uuid',
        'occurred_at',
        'contract_id',
        'contract_name',
        'company_name',
        'annual_price_eur',
        'consumption_kwh',
        'price_rank',
        'rank_total',
        'rank_consumption_kwh',
        'is_estimate',
        'pricing_basis',
        'cta_location',
        'session_source',
        'session_medium',
        'session_campaign',
        'landing_path',
        'page_path',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'occurred_at' => 'immutable_datetime',
            'annual_price_eur' => 'decimal:2',
            'consumption_kwh' => 'integer',
            'price_rank' => 'integer',
            'rank_total' => 'integer',
            'rank_consumption_kwh' => 'integer',
            'is_estimate' => 'boolean',
            'created_at' => 'immutable_datetime',
        ];
    }
}
