<?php

namespace App\Services\ContractInterpretation;

use App\Jobs\AnalyzeContractSourceSnapshot;
use App\Models\ContractInterpretation;
use App\Models\ContractSourceSnapshot;
use RuntimeException;

class ContractInterpretationDispatcher
{
    public function __construct(private readonly ContractAnalysisFingerprint $fingerprints) {}

    public function dispatch(
        ContractSourceSnapshot $snapshot,
        bool $runWhenDisabled = false,
        bool $retryFailed = false,
    ): ?ContractInterpretation {
        if (! config('contract_interpretation.enabled') && ! $runWhenDisabled) {
            return null;
        }
        if (! config('services.openrouter.api_key')) {
            throw new RuntimeException('OPENROUTER_API_KEY is not configured.');
        }

        $interpretation = ContractInterpretation::firstOrCreate(
            ['analysis_fingerprint' => $this->fingerprints->forSnapshot($snapshot)],
            [
                'contract_id' => $snapshot->contract_id,
                'source_snapshot_id' => $snapshot->id,
                'status' => ContractInterpretation::STATUS_PENDING,
                'schema_version' => config('contract_interpretation.schema_version'),
                'prompt_version' => config('contract_interpretation.prompt_version'),
                'provider' => config('contract_interpretation.provider'),
                'model' => config('contract_interpretation.model'),
            ]
        );

        if (! $interpretation->wasRecentlyCreated) {
            if (! $retryFailed || $interpretation->status !== ContractInterpretation::STATUS_FAILED) {
                return $interpretation;
            }

            $interpretation->update([
                'status' => ContractInterpretation::STATUS_PENDING,
                'validation_errors' => null,
                'error' => null,
                'started_at' => null,
                'completed_at' => null,
            ]);
        }

        AnalyzeContractSourceSnapshot::dispatch($interpretation->id)->afterCommit();

        return $interpretation;
    }
}
