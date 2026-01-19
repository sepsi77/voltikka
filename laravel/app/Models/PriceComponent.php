<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PriceComponent extends Model
{
    /**
     * The table associated with the model.
     */
    protected $table = 'price_components';

    /**
     * The primary key associated with the table.
     * Note: This model has a composite primary key (id, price_date).
     * Laravel doesn't natively support composite keys, so we treat 'id' as primary
     * and use getKeyForSaveQuery() for proper update/delete operations.
     */
    protected $primaryKey = 'id';

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
     * The legacy table doesn't have timestamps.
     */
    public $timestamps = false;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'id',
        'price_date',
        'price_component_type',
        'fuse_size',
        'electricity_contract_id',
        'has_discount',
        'discount_value',
        'discount_is_percentage',
        'discount_type',
        'discount_discount_n_first_kwh',
        'discount_discount_n_first_months',
        'discount_discount_until_date',
        'price',
        'payment_unit',
    ];

    /**
     * The attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'price_date' => 'date',
            'has_discount' => 'boolean',
            'discount_value' => 'float',
            'discount_is_percentage' => 'boolean',
            'discount_discount_n_first_kwh' => 'float',
            'discount_discount_n_first_months' => 'integer',
            'discount_discount_until_date' => 'datetime',
            'price' => 'float',
        ];
    }

    /**
     * Set the keys for a save update query.
     * This handles the composite primary key (id, price_date) for updates.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    protected function setKeysForSaveQuery($query)
    {
        $query->where('id', $this->getAttribute('id'))
              ->where('price_date', $this->getAttribute('price_date'));

        return $query;
    }

    /**
     * Get the electricity contract that owns this price component.
     */
    public function electricityContract(): BelongsTo
    {
        return $this->belongsTo(ElectricityContract::class, 'electricity_contract_id', 'id');
    }

    /**
     * Scope to filter by price component type.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string $type
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeOfType($query, string $type)
    {
        return $query->where('price_component_type', $type);
    }

    /**
     * Scope to filter by date.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param \DateTimeInterface|string $date
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeForDate($query, $date)
    {
        return $query->where('price_date', $date);
    }

    /**
     * Scope to get the latest price components (most recent price_date).
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeLatest($query)
    {
        return $query->orderByDesc('price_date');
    }

    /**
     * Scope to filter by having discounts.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeWithDiscount($query)
    {
        return $query->where('has_discount', true);
    }
}
