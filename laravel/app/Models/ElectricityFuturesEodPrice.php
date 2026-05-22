<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ElectricityFuturesEodPrice extends Model
{
    /**
     * The table associated with the model.
     */
    protected $table = 'electricity_futures_eod_prices';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'exchange',
        'commodity',
        'pricing',
        'product',
        'market_region',
        'area',
        'area_name',
        'short_code',
        'maturity',
        'maturity_type',
        'display_year',
        'display_season',
        'display_quarter',
        'display_month',
        'display_week',
        'display_day',
        'trade_date',
        'settlement_price',
        'volume',
        'lot_size',
        'currency',
        'unit',
        'long_name',
        'last_update',
    ];

    /**
     * The attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'display_year' => 'integer',
            'display_season' => 'integer',
            'display_quarter' => 'integer',
            'display_month' => 'integer',
            'display_week' => 'integer',
            'display_day' => 'date',
            'trade_date' => 'date',
            'settlement_price' => 'float',
            'volume' => 'float',
            'lot_size' => 'float',
            'last_update' => 'date',
        ];
    }
}
