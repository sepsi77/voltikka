<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SpotFutures extends Model
{
    /**
     * The table associated with the model.
     */
    protected $table = 'spot_futures';

    /**
     * The primary key associated with the table.
     * Note: This model has a composite primary key (date, price).
     * Laravel doesn't natively support composite keys, so we treat 'date' as primary
     * and use setKeysForSaveQuery() for proper update/delete operations.
     */
    protected $primaryKey = 'date';

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
        'date',
        'price',
    ];

    /**
     * The attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date' => 'date',
            'price' => 'float',
        ];
    }

    /**
     * Set the keys for a save update query.
     * This handles the composite primary key (date, price) for updates.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    protected function setKeysForSaveQuery($query)
    {
        $query->where('date', $this->getAttribute('date'))
              ->where('price', $this->getAttribute('price'));

        return $query;
    }
}
