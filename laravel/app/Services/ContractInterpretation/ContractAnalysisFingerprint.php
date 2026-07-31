<?php

namespace App\Services\ContractInterpretation;

use App\Models\ContractSourceObservation;
use App\Models\ContractSourceSnapshot;

class ContractAnalysisFingerprint
{
    public function forSnapshot(ContractSourceSnapshot $snapshot): string
    {
        return hash('sha256', implode('|', [
            $snapshot->source_fingerprint,
            (string) config('contract_interpretation.schema_version'),
            (string) config('contract_interpretation.prompt_version'),
            (string) config('contract_interpretation.validator_version'),
            (string) config('contract_interpretation.provider'),
            (string) config('contract_interpretation.model'),
            (string) config('contract_interpretation.reasoning_effort'),
        ]));
    }

    public function forObservation(
        ContractSourceSnapshot $snapshot,
        ContractSourceObservation $observation,
    ): string {
        return hash('sha256', implode('|', [
            $this->forSnapshot($snapshot),
            'analysis_date',
            $observation->first_observed_at->toDateString(),
            'source_observation_id',
            (string) $observation->id,
        ]));
    }
}
