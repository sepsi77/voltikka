<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContractInterpretation extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_PUBLISHED = 'published';

    public const STATUS_FAILED = 'failed';

    public const STATUS_SUPERSEDED = 'superseded';

    protected $fillable = [
        'contract_id',
        'source_snapshot_id',
        'analysis_fingerprint',
        'status',
        'schema_version',
        'prompt_version',
        'provider',
        'model',
        'output',
        'validation_errors',
        'published_fields',
        'relational_pricing_published',
        'usage',
        'provider_response_id',
        'latency_ms',
        'error',
        'started_at',
        'completed_at',
        'published_at',
    ];

    protected function casts(): array
    {
        return [
            'output' => 'array',
            'validation_errors' => 'array',
            'published_fields' => 'array',
            'relational_pricing_published' => 'boolean',
            'usage' => 'array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'published_at' => 'datetime',
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
