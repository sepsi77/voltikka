<?php

namespace App\Models;

use App\Services\ContractInterpretation\Enums\HistoricalEvidenceGrade;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ContractHistoricalInterpretationEpisode extends Model
{
    protected $fillable = [
        'contract_id',
        'episode_start',
        'episode_end',
        'builder_version',
        'episode_fingerprint',
        'evidence_fingerprint',
        'manifest_fingerprint',
        'evidence_grade',
        'analysis_input',
        'evidence_manifest',
    ];

    protected function casts(): array
    {
        return [
            'episode_start' => 'date',
            'episode_end' => 'date',
            'evidence_grade' => HistoricalEvidenceGrade::class,
            'analysis_input' => 'array',
            'evidence_manifest' => 'array',
        ];
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(ElectricityContract::class, 'contract_id', 'id');
    }

    public function interpretations(): HasMany
    {
        return $this->hasMany(ContractHistoricalInterpretation::class, 'episode_id');
    }
}
