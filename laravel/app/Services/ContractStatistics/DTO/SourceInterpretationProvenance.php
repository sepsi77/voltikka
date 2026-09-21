<?php

namespace App\Services\ContractStatistics\DTO;

use Carbon\CarbonImmutable;

readonly class SourceInterpretationProvenance
{
    /** @param list<int> $observationIds */
    public function __construct(
        public int $sourceSnapshotId,
        public array $observationIds,
        public int $interpretationId,
        public ?int $analysisObservationId,
        public CarbonImmutable $targetDate,
        public CarbonImmutable $completedAt,
        public bool $retrospective,
    ) {}

    public function toArray(): array
    {
        return [
            'source_snapshot_id' => $this->sourceSnapshotId,
            'observation_ids' => $this->observationIds,
            'interpretation_id' => $this->interpretationId,
            'analysis_observation_id' => $this->analysisObservationId,
            'target_date' => $this->targetDate->toDateString(),
            'completed_at' => $this->completedAt->utc()->toIso8601String(),
            'retrospective' => $this->retrospective,
            'legacy_null_observation_binding' => $this->analysisObservationId === null,
        ];
    }
}
