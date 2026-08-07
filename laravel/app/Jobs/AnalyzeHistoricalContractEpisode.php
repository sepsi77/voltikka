<?php

namespace App\Jobs;

use App\Models\ContractHistoricalInterpretation;
use App\Services\CanonicalPricing\CanonicalPricingParser;
use App\Services\ContractInterpretation\ContractInterpretationAttemptRunner;
use App\Services\ContractInterpretation\HistoricalContractEpisodeBuilder;
use App\Services\ContractInterpretation\HistoricalInterpretationBackcastValidator;
use App\Services\ContractInterpretation\HistoricalInterpretationFingerprint;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class AnalyzeHistoricalContractEpisode implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 400;

    public int $uniqueFor = 3600;

    public function __construct(public int $interpretationId)
    {
        $this->onQueue((string) config('contract_interpretation.historical.queue'));
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [30, 120, 300];
    }

    public function uniqueId(): string
    {
        return (string) $this->interpretationId;
    }

    public function handle(
        HistoricalInterpretationFingerprint $fingerprints,
        ContractInterpretationAttemptRunner $attemptRunner,
        HistoricalInterpretationBackcastValidator $backcastValidator,
        CanonicalPricingParser $parser,
    ): void {
        $interpretation = ContractHistoricalInterpretation::with('episode')->findOrFail($this->interpretationId);
        if ($interpretation->status === ContractHistoricalInterpretation::STATUS_VALIDATED) {
            return;
        }
        if (! in_array($interpretation->status, [
            ContractHistoricalInterpretation::STATUS_PENDING,
            ContractHistoricalInterpretation::STATUS_PROCESSING,
        ], true)) {
            return;
        }
        if ($interpretation->status === ContractHistoricalInterpretation::STATUS_PROCESSING
            && $this->attempts() <= 1) {
            // Only the queue's bounded retry can continue a processing claim.
            // An old processing row needs explicit command-side stale resume.
            return;
        }

        $episode = $interpretation->episode;
        $ownershipValid = $episode !== null
            && $episode->contract_id === $interpretation->contract_id
            && $episode->builder_version === HistoricalContractEpisodeBuilder::VERSION
            && $episode->manifest_fingerprint === $fingerprints->manifest($episode->evidence_manifest)
            && $episode->evidence_fingerprint === $fingerprints->evidence($episode->analysis_input, $episode->evidence_manifest)
            && $episode->episode_fingerprint === $fingerprints->episode(
                $episode->builder_version,
                $episode->contract_id,
                $episode->episode_start->toDateString(),
                $episode->episode_end->toDateString(),
                $episode->evidence_fingerprint,
            )
            && $interpretation->analysis_fingerprint === $fingerprints->analysis($episode->episode_fingerprint)
            && $this->versionsMatch($interpretation);

        if (! $ownershipValid) {
            $interpretation->update([
                'status' => ContractHistoricalInterpretation::STATUS_FAILED,
                'completed_at' => now(),
                'error' => 'Stored historical input, fingerprint, version, or ownership verification failed.',
            ]);

            return;
        }

        if ($interpretation->status === ContractHistoricalInterpretation::STATUS_PENDING) {
            $claimed = ContractHistoricalInterpretation::query()
                ->whereKey($interpretation->id)
                ->where('status', ContractHistoricalInterpretation::STATUS_PENDING)
                ->update([
                    'status' => ContractHistoricalInterpretation::STATUS_PROCESSING,
                    'started_at' => now(),
                    'completed_at' => null,
                    'error' => null,
                ]);
            if ($claimed !== 1) {
                return;
            }
            $interpretation->refresh();
        }

        $previousAttempts = is_array($interpretation->llm_attempts) ? $interpretation->llm_attempts : [];

        try {
            $result = $attemptRunner->run(
                $episode->analysis_input,
                (string) config('contract_interpretation.historical.addendum_path'),
                function (array $output) use ($backcastValidator, $episode, $parser): array {
                    $errors = $backcastValidator->validate($output, $episode->analysis_input);
                    if ($errors !== []) {
                        return $errors;
                    }

                    try {
                        $parser->parse(
                            $output['pricing'] ?? null,
                            $output['calculation'] ?? null,
                            $output['source_consistency'] ?? null,
                        );

                        return [];
                    } catch (Throwable $exception) {
                        return ['Canonical pricing parser rejected the output: '.$exception->getMessage()];
                    }
                },
                function (array $attempt, array $runAttempts) use ($interpretation, $previousAttempts): void {
                    $allAttempts = array_merge($previousAttempts, $runAttempts);
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

            $interpretation->update([
                'status' => $result['validated']
                    ? ContractHistoricalInterpretation::STATUS_VALIDATED
                    : ContractHistoricalInterpretation::STATUS_FAILED,
                'completed_at' => now(),
                'error' => $result['validated']
                    ? null
                    : 'Automatic validation failed after '.count($result['attempts']).' LLM attempts.',
            ]);
        } catch (Throwable $exception) {
            // Keep processing so a bounded queue retry can continue. The failed()
            // hook makes the terminal queue failure explicit and auditable.
            $interpretation->update([
                'status' => ContractHistoricalInterpretation::STATUS_PROCESSING,
                'error' => mb_substr('Transport/runtime failure: '.$exception->getMessage(), 0, 65000),
            ]);

            throw $exception;
        }
    }

    public function failed(Throwable $exception): void
    {
        ContractHistoricalInterpretation::whereKey($this->interpretationId)->update([
            'status' => ContractHistoricalInterpretation::STATUS_FAILED,
            'completed_at' => now(),
            'error' => mb_substr($exception->getMessage(), 0, 65000),
        ]);
    }

    private function versionsMatch(ContractHistoricalInterpretation $interpretation): bool
    {
        return $interpretation->schema_version === config('contract_interpretation.schema_version')
            && $interpretation->prompt_version === config('contract_interpretation.prompt_version')
            && $interpretation->historical_addendum_version === config('contract_interpretation.historical.addendum_version')
            && $interpretation->validator_version === config('contract_interpretation.validator_version')
            && $interpretation->parser_version === CanonicalPricingParser::VERSION
            && $interpretation->provider === config('contract_interpretation.provider')
            && $interpretation->model === config('contract_interpretation.model')
            && $interpretation->reasoning_effort === config('contract_interpretation.reasoning_effort');
    }
}
