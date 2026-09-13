<?php

namespace App\Services\Caching;

use Illuminate\Support\Facades\DB;

class ContractPriceCacheEvidence
{
    public function current(): array
    {
        return DB::table('electricity_contracts as contracts')
            ->join('active_contracts as active', 'active.id', '=', 'contracts.id')
            ->leftJoin('contract_source_observations as observations', 'observations.id', '=', 'contracts.current_source_observation_id')
            ->leftJoin('contract_interpretations as interpretations', 'interpretations.id', '=', 'contracts.published_interpretation_id')
            ->orderBy('contracts.id')
            ->get([
                'contracts.id', 'contracts.current_source_observation_id', 'contracts.published_interpretation_id',
                'observations.contract_id as observed_contract', 'observations.source_snapshot_id as observed_snapshot',
                'interpretations.contract_id as published_contract', 'interpretations.source_snapshot_id as published_snapshot',
                'interpretations.status',
            ])->mapWithKeys(fn ($row) => [$row->id => (array) $row])->all();
    }

    public function fingerprint(): string
    {
        return hash('sha256', serialize($this->current()));
    }

    public function isCurrent(array $row): bool
    {
        // Pointer-free local/legacy fixtures retain their cold-build behavior.
        if ($row['current_source_observation_id'] === null && $row['published_interpretation_id'] === null) {
            return true;
        }

        return $row['observed_contract'] === $row['id']
            && $row['published_contract'] === $row['id']
            && $row['observed_snapshot'] !== null
            && $row['observed_snapshot'] === $row['published_snapshot']
            && $row['status'] === 'published';
    }
}
