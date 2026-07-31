<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContractSourceObservation extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'contract_id',
        'source_snapshot_id',
        'first_observed_at',
        'last_observed_at',
    ];

    protected function casts(): array
    {
        return [
            'first_observed_at' => 'datetime',
            'last_observed_at' => 'datetime',
        ];
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(ElectricityContract::class, 'contract_id', 'id');
    }

    public function sourceSnapshot(): BelongsTo
    {
        return $this->belongsTo(ContractSourceSnapshot::class, 'source_snapshot_id');
    }
}
