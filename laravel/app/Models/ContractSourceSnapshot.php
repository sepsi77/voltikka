<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ContractSourceSnapshot extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'contract_id',
        'source_fingerprint',
        'source_payload',
        'first_observed_at',
        'last_observed_at',
    ];

    protected function casts(): array
    {
        return [
            'source_payload' => 'array',
            'first_observed_at' => 'datetime',
            'last_observed_at' => 'datetime',
        ];
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(ElectricityContract::class, 'contract_id', 'id');
    }

    public function observations(): HasMany
    {
        return $this->hasMany(ContractSourceObservation::class, 'source_snapshot_id');
    }

    public function interpretations(): HasMany
    {
        return $this->hasMany(ContractInterpretation::class, 'source_snapshot_id');
    }
}
