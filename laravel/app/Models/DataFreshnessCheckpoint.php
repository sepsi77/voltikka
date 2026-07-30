<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DataFreshnessCheckpoint extends Model
{
    public const KEY_CONTRACT_IMPORT = 'contract_import';

    public const KEY_EEX_FUTURES = 'eex_futures';

    public const STATUS_READY = 'ready';

    public const STATUS_INCOMPLETE = 'incomplete';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'key',
        'effective_date',
        'status',
        'metadata',
        'recorded_at',
    ];

    protected function casts(): array
    {
        return [
            'effective_date' => 'date',
            'metadata' => 'array',
            'recorded_at' => 'datetime',
        ];
    }
}
