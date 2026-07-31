<?php

namespace App\Services\ContractInterpretation;

use App\Jobs\AnalyzeContractSourceSnapshot;
use App\Models\ContractInterpretation;
use App\Models\ContractSourceObservation;
use App\Models\ContractSourceSnapshot;
use App\Models\ElectricityContract;
use RuntimeException;

class ContractInterpretationDispatcher
{
    private const WAITING_FOR_API_KEY = 'Awaiting OPENROUTER_API_KEY before dispatch.';

    public function __construct(
        private readonly ContractAnalysisFingerprint $fingerprints,
        private readonly ContractInterpretationPublisher $publisher,
        private readonly ContractInterpretationInputBuilder $inputBuilder,
        private readonly ContractInterpretationValidator $validator,
    ) {}

    public function dispatch(
        ContractSourceObservation $observation,
        bool $runWhenDisabled = false,
        bool $retryFailed = false,
    ): ?ContractInterpretation {
        $contract = ElectricityContract::query()->findOrFail($observation->contract_id);

        if ($contract->current_source_observation_id !== $observation->id
            || $observation->contract_id !== $contract->id) {
            throw new RuntimeException('Only the exact pointed source observation can be dispatched.');
        }

        if (! config('contract_interpretation.enabled') && ! $runWhenDisabled) {
            return null;
        }

        $snapshot = $observation->relationLoaded('sourceSnapshot')
            ? $observation->sourceSnapshot
            : $observation->sourceSnapshot()->firstOrFail();

        if ($snapshot->contract_id !== $contract->id) {
            throw new RuntimeException('The pointed source observation snapshot must belong to the current contract.');
        }

        $analysisFingerprint = $this->fingerprints->forSnapshot($snapshot);
        $interpretation = $this->findByFingerprint($analysisFingerprint);
        $validatedForEpisode = false;
        $dateScopedFallback = false;

        if ($interpretation !== null
            && in_array($interpretation->status, [
                ContractInterpretation::STATUS_PUBLISHED,
                ContractInterpretation::STATUS_SUPERSEDED,
            ], true)
            && $this->hasReusableOutput($interpretation)) {
            $validatedForEpisode = $this->isValidForObservation($interpretation, $snapshot, $observation);

            if (! $validatedForEpisode) {
                $this->supersedeInvalidReuse($interpretation);
                $analysisFingerprint = $this->fingerprints->forObservation(
                    $snapshot,
                    $observation,
                );
                $interpretation = $this->findByFingerprint($analysisFingerprint);
                $dateScopedFallback = true;
                $validatedForEpisode = false;
            }
        }

        if ($interpretation !== null) {
            return $this->dispatchExisting(
                $interpretation,
                $contract,
                $snapshot,
                $observation,
                $retryFailed,
                $validatedForEpisode,
                $dateScopedFallback,
            );
        }

        $hasApiKey = (bool) config('services.openrouter.api_key');
        if (! $hasApiKey && ! $dateScopedFallback) {
            throw new RuntimeException('OPENROUTER_API_KEY is not configured.');
        }

        $interpretation = ContractInterpretation::firstOrCreate(
            ['analysis_fingerprint' => $analysisFingerprint],
            [
                'contract_id' => $snapshot->contract_id,
                'source_snapshot_id' => $snapshot->id,
                'analysis_source_observation_id' => $dateScopedFallback ? $observation->id : null,
                'status' => ContractInterpretation::STATUS_PENDING,
                'schema_version' => config('contract_interpretation.schema_version'),
                'prompt_version' => config('contract_interpretation.prompt_version'),
                'validator_version' => config('contract_interpretation.validator_version'),
                'provider' => config('contract_interpretation.provider'),
                'model' => config('contract_interpretation.model'),
            ],
        );

        if (! $interpretation->wasRecentlyCreated) {
            return $this->dispatchExisting(
                $interpretation,
                $contract,
                $snapshot,
                $observation,
                $retryFailed,
                dateScopedFallback: $dateScopedFallback,
            );
        }

        if (! $hasApiKey) {
            $interpretation->update(['error' => self::WAITING_FOR_API_KEY]);

            return $interpretation;
        }

        AnalyzeContractSourceSnapshot::dispatch($interpretation->id)->afterCommit();

        return $interpretation;
    }

    private function dispatchExisting(
        ContractInterpretation $interpretation,
        ElectricityContract $contract,
        ContractSourceSnapshot $snapshot,
        ContractSourceObservation $observation,
        bool $retryFailed,
        bool $validatedForEpisode = false,
        bool $dateScopedFallback = false,
    ): ContractInterpretation {
        if ($dateScopedFallback
            && ($interpretation->analysis_source_observation_id === null
                || (int) $interpretation->analysis_source_observation_id !== (int) $observation->id)) {
            throw new RuntimeException('The date-scoped interpretation belongs to a different source observation.');
        }

        if ($interpretation->status === ContractInterpretation::STATUS_PENDING) {
            if ($interpretation->error === self::WAITING_FOR_API_KEY
                && config('services.openrouter.api_key')) {
                $interpretation->update(['error' => null]);
                AnalyzeContractSourceSnapshot::dispatch($interpretation->id)->afterCommit();
            }

            return $interpretation;
        }

        if ($interpretation->status === ContractInterpretation::STATUS_PROCESSING) {
            return $interpretation;
        }

        if (in_array($interpretation->status, [
            ContractInterpretation::STATUS_PUBLISHED,
            ContractInterpretation::STATUS_SUPERSEDED,
        ], true)) {
            if (! $this->hasReusableOutput($interpretation)) {
                return $interpretation;
            }

            if (! $validatedForEpisode
                && ! $this->isValidForObservation($interpretation, $snapshot, $observation)) {
                $this->supersedeInvalidReuse($interpretation);

                return $interpretation->fresh();
            }

            if ($contract->published_interpretation_id === $interpretation->id
                && $interpretation->status === ContractInterpretation::STATUS_PUBLISHED) {
                return $interpretation;
            }

            $this->publisher->publish($interpretation);

            return $interpretation->fresh();
        }

        if (! $retryFailed || $interpretation->status !== ContractInterpretation::STATUS_FAILED) {
            return $interpretation;
        }

        if (! config('services.openrouter.api_key')) {
            throw new RuntimeException('OPENROUTER_API_KEY is not configured.');
        }

        $interpretation->update([
            'status' => ContractInterpretation::STATUS_PENDING,
            'validation_errors' => null,
            'llm_attempts' => null,
            'error' => null,
            'started_at' => null,
            'completed_at' => null,
        ]);
        AnalyzeContractSourceSnapshot::dispatch($interpretation->id)->afterCommit();

        return $interpretation;
    }

    private function findByFingerprint(string $analysisFingerprint): ?ContractInterpretation
    {
        return ContractInterpretation::query()
            ->where('analysis_fingerprint', $analysisFingerprint)
            ->first();
    }

    private function isValidForObservation(
        ContractInterpretation $interpretation,
        ContractSourceSnapshot $snapshot,
        ContractSourceObservation $observation,
    ): bool {
        $input = $this->inputBuilder->build($snapshot, $observation->first_observed_at);

        return $this->validator->validate($interpretation->output ?? [], $input) === [];
    }

    private function supersedeInvalidReuse(ContractInterpretation $interpretation): void
    {
        if ($interpretation->status !== ContractInterpretation::STATUS_PUBLISHED) {
            return;
        }

        $interpretation->update([
            'status' => ContractInterpretation::STATUS_SUPERSEDED,
            'completed_at' => now(),
            'error' => 'The stored output is not valid for the pointed observation date.',
        ]);
    }

    private function hasReusableOutput(ContractInterpretation $interpretation): bool
    {
        return is_array($interpretation->output)
            && $interpretation->output !== []
            && empty($interpretation->validation_errors);
    }
}
