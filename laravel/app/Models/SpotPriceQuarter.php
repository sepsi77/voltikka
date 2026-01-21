<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;

class SpotPriceQuarter extends Model
{
    /**
     * The table associated with the model.
     */
    protected $table = 'spot_prices_quarter';

    /**
     * The primary key associated with the table.
     * Note: This model has a composite primary key (region, timestamp).
     * Laravel doesn't natively support composite keys, so we treat 'region' as primary
     * and use setKeysForSaveQuery() for proper update/delete operations.
     */
    protected $primaryKey = 'region';

    /**
     * Indicates if the model's ID is auto-incrementing.
     */
    public $incrementing = false;

    /**
     * The data type of the primary key.
     */
    protected $keyType = 'string';

    /**
     * Indicates if the model should be timestamped.
     */
    public $timestamps = false;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'region',
        'timestamp',
        'utc_datetime',
        'price_without_tax',
        'vat_rate',
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
            'price_without_tax' => 'float',
            'vat_rate' => 'float',
        ];
    }

    /**
     * The accessors to append to the model's array form.
     *
     * @var list<string>
     */
    protected $appends = ['vat', 'price_with_tax'];

    /**
     * Get the VAT amount.
     * Computed column: vat = price_without_tax * vat_rate
     */
    protected function vat(): Attribute
    {
        return Attribute::make(
            get: function () {
                $price = $this->price_without_tax ?? 0.0;
                $rate = $this->vat_rate ?? 0.0;
                return $price * $rate;
            }
        );
    }

    /**
     * Get the price with tax.
     * Computed column: price_with_tax = price_without_tax * (1 + vat_rate)
     */
    protected function priceWithTax(): Attribute
    {
        return Attribute::make(
            get: function () {
                $price = $this->price_without_tax ?? 0.0;
                $rate = $this->vat_rate ?? 0.0;
                return $price * (1 + $rate);
            }
        );
    }

    /**
     * Set the keys for a save update query.
     * This handles the composite primary key (region, timestamp) for updates.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    protected function setKeysForSaveQuery($query)
    {
        $query->where('region', $this->getAttribute('region'))
              ->where('timestamp', $this->getAttribute('timestamp'));

        return $query;
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
     * Scope to filter by date (filters on utc_datetime).
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param \DateTimeInterface|string $date
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeForDate($query, $date)
    {
        return $query->whereDate('utc_datetime', $date);
    }

    /**
     * Scope to filter by hour (4 quarter-hour records per hour).
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param \DateTimeInterface $hourStart Start of the hour (UTC)
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeForHour($query, $hourStart)
    {
        $hourEnd = $hourStart->copy()->addHour();
        return $query->where('utc_datetime', '>=', $hourStart)
                     ->where('utc_datetime', '<', $hourEnd);
    }
}
