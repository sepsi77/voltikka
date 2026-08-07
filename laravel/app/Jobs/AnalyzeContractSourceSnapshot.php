<?php

namespace App\Jobs;

use App\Models\ContractInterpretation;
use App\Models\ContractSourceObservation;
use App\Models\ElectricityContract;
use App\Services\ContractInterpretation\ContractInterpretationAttemptRunner;
use App\Services\ContractInterpretation\ContractInterpretationInputBuilder;
use App\Services\ContractInterpretation\ContractInterpretationPublisher;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class AnalyzeContractSourceSnapshot implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 400;

    public int $uniqueFor = 3600;

    public function __construct(public int $interpretationId)
    {
        $this->onQueue((string) config('contract_interpretation.queue'));
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [30, 120, 300];
    }

    public function uniqueId(): string
    {
        return (string) $this->interpretationId;
    }

    public function handle(
        ContractInterpretationInputBuilder $inputBuilder,
        ContractInterpretationAttemptRunner $attemptRunner,
        ContractInterpretationPublisher $publisher,
    ): void {
        $interpretation = ContractInterpretation::with('sourceSnapshot')->findOrFail($this->interpretationId);
        if (in_array($interpretation->status, [
            ContractInterpretation::STATUS_PUBLISHED,
            ContractInterpretation::STATUS_SUPERSEDED,
        ], true)) {
            return;
        }

        $contract = ElectricityContract::query()->find($interpretation->contract_id);
        $observation = $contract?->current_source_observation_id === null
            ? null
            : ContractSourceObservation::query()->find($contract->current_source_observation_id);
        $matchesPointedObservation = $contract !== null
            && $observation !== null
            && $observation->contract_id === $contract->id
            && $interpretation->sourceSnapshot !== null
            && $interpretation->sourceSnapshot->contract_id === $contract->id
            && $observation->source_snapshot_id === $interpretation->source_snapshot_id
            && ($interpretation->analysis_source_observation_id === null
                || (int) $interpretation->analysis_source_observation_id === (int) $observation->id);

        if (! $matchesPointedObservation) {
            $interpretation->update([
                'status' => ContractInterpretation::STATUS_SUPERSEDED,
                'completed_at' => now(),
                'error' => 'The interpretation does not match the pointed source observation.',
            ]);

            return;
        }

        $input = $inputBuilder->build(
            $interpretation->sourceSnapshot,
            $observation->first_observed_at,
        );

        $interpretation->update([
            'status' => ContractInterpretation::STATUS_PROCESSING,
            'started_at' => now(),
            'completed_at' => null,
            'error' => null,
        ]);
        $previousAttempts = is_array($interpretation->llm_attempts) ? $interpretation->llm_attempts : [];

        try {
            $result = $attemptRunner->run(
                $input,
                afterAttempt: function (array $attempt, array $attempts) use ($interpretation, $previousAttempts): void {
                    $allAttempts = array_merge($previousAttempts, $attempts);
                    foreach ($allAttempts as $index => &$storedAttempt) {
                        $storedAttempt['attempt'] = $index + 1;
                    }
                    unset($storedAttempt);
                    $errors = $attempt['validation_errors'];
                    $interpretation->update([
                        'output' => $attempt['output'],
                        'validation_errors' => $errors === [] ? null : $errors,
                        'llm_attempts' => $allAttempts,
                        'usage' => ContractInterpretationAttemptRunner::aggregateUsage($allAttempts),
                        'provider_response_id' => $attempt['provider_response_id'],
                        'latency_ms' => collect($allAttempts)->sum('latency_ms'),
                    ]);
                },
            );

            if ($result['validated']) {
                $publisher->publish($interpretation->fresh());

                return;
            }

            $interpretation->update([
                'status' => ContractInterpretation::STATUS_FAILED,
                'completed_at' => now(),
                'error' => 'Automatic validation failed after '.count($result['attempts']).' LLM attempts.',
            ]);
        } catch (Throwable $exception) {
            $interpretation->update([
                'status' => ContractInterpretation::STATUS_FAILED,
                'completed_at' => now(),
                'error' => mb_substr($exception->getMessage(), 0, 65000),
            ]);

            throw $exception;
        }
    }

    public function failed(Throwable $exception): void
    {
        ContractInterpretation::whereKey($this->interpretationId)->update([
            'status' => ContractInterpretation::STATUS_FAILED,
            'completed_at' => now(),
            'error' => mb_substr($exception->getMessage(), 0, 65000),
        ]);
    }
}
