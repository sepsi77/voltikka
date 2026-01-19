<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ActiveContract extends Model
{
    /**
     * The table associated with the model.
     */
    protected $table = 'active_contracts';

    /**
     * The primary key associated with the table.
     * The id is a foreign key to electricity_contracts.
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
    ];

    /**
     * Get the electricity contract that this active contract references.
     */
    public function electricityContract(): BelongsTo
    {
        return $this->belongsTo(ElectricityContract::class, 'id', 'id');
    }
}
