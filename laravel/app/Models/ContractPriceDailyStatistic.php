<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ContractPriceDailyStatistic extends Model
{
    protected $fillable = [
        'stat_date',
        'segment_key',
        'metric_key',
        'consumption_kwh',
        'min_value',
        'p20_value',
        'avg_value',
        'median_value',
        'p80_value',
        'max_value',
        'contract_count',
    ];

    protected function casts(): array
    {
        return [
            'stat_date' => 'date',
            'consumption_kwh' => 'integer',
            'min_value' => 'float',
            'p20_value' => 'float',
            'avg_value' => 'float',
            'median_value' => 'float',
            'p80_value' => 'float',
            'max_value' => 'float',
            'contract_count' => 'integer',
        ];
    }
}
