<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContractHistoricalInterpretation extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_VALIDATED = 'validated';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'episode_id',
        'contract_id',
        'analysis_fingerprint',
        'status',
        'schema_version',
        'prompt_version',
        'historical_addendum_version',
        'validator_version',
        'parser_version',
        'provider',
        'model',
        'reasoning_effort',
        'output',
        'validation_errors',
        'llm_attempts',
        'usage',
        'provider_response_id',
        'latency_ms',
        'error',
        'started_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'output' => 'array',
            'validation_errors' => 'array',
            'llm_attempts' => 'array',
            'usage' => 'array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function episode(): BelongsTo
    {
        return $this->belongsTo(ContractHistoricalInterpretationEpisode::class, 'episode_id');
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(ElectricityContract::class, 'contract_id', 'id');
    }
}
