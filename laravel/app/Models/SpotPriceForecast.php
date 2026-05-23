<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;

class SpotPriceForecast extends Model
{
    public const SOURCE_NORDPOOL_PREDICT_FI = 'nordpool_predict_fi';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'source',
        'region',
        'timestamp',
        'utc_datetime',
        'price_with_tax',
        'vat_rate',
        'source_url',
        'fetched_at',
        'metadata',
    ];

    /**
     * The attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'timestamp' => 'integer',
            'utc_datetime' => 'datetime',
            'price_with_tax' => 'float',
            'vat_rate' => 'float',
            'fetched_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    /**
     * Get the VAT0 price derived from the upstream VAT-included forecast.
     */
    protected function priceWithoutTax(): Attribute
    {
        return Attribute::make(
            get: function () {
                $priceWithTax = $this->price_with_tax ?? 0.0;
                $rate = $this->vat_rate ?? 0.0;

                return $rate > -1 ? $priceWithTax / (1 + $rate) : $priceWithTax;
            }
        );
    }

    /**
     * Scope to filter by region.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string $region
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeForRegion($query, string $region)
    {
        return $query->where('region', $region);
    }

    /**
     * Scope to filter by source.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string $source
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeForSource($query, string $source)
    {
        return $query->where('source', $source);
    }
}
