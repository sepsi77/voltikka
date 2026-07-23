<?php

namespace App\Jobs;

use App\Models\ContractInterpretation;
use App\Services\ContractInterpretation\ContractInterpretationInputBuilder;
use App\Services\ContractInterpretation\ContractInterpretationPublisher;
use App\Services\ContractInterpretation\ContractInterpretationValidator;
use App\Services\ContractInterpretation\OpenRouterContractInterpretationClient;
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
        OpenRouterContractInterpretationClient $client,
        ContractInterpretationValidator $validator,
        ContractInterpretationPublisher $publisher,
    ): void {
        $interpretation = ContractInterpretation::with('sourceSnapshot')->findOrFail($this->interpretationId);
        if (in_array($interpretation->status, [
            ContractInterpretation::STATUS_PUBLISHED,
            ContractInterpretation::STATUS_SUPERSEDED,
        ], true)) {
            return;
        }

        $interpretation->update([
            'status' => ContractInterpretation::STATUS_PROCESSING,
            'started_at' => now(),
            'completed_at' => null,
            'error' => null,
        ]);

        try {
            $input = $inputBuilder->build($interpretation->sourceSnapshot);
            $result = $client->interpret($input);
            $attempts = [];
            $maxRepairAttempts = min(2, max(0, (int) config('contract_interpretation.max_repair_attempts')));

            for ($attemptNumber = 0; $attemptNumber <= $maxRepairAttempts; $attemptNumber++) {
                $errors = $validator->validate($result['output'], $input);
                $attempts[] = $this->attemptRecord($attemptNumber, $result, $errors);
                $usage = $this->aggregateUsage($attempts);

                $interpretation->update([
                    'output' => $result['output'],
                    'validation_errors' => $errors === [] ? null : $errors,
                    'llm_attempts' => $attempts,
                    'usage' => $usage,
                    'provider_response_id' => $result['response_id'],
                    'latency_ms' => collect($attempts)->sum('latency_ms'),
                ]);

                if ($errors === []) {
                    $publisher->publish($interpretation->fresh());

                    return;
                }

                if ($attemptNumber === $maxRepairAttempts) {
                    $interpretation->update([
                        'status' => ContractInterpretation::STATUS_FAILED,
                        'completed_at' => now(),
                        'error' => 'Automatic validation failed after '.count($attempts).' LLM attempts.',
                    ]);

                    return;
                }

                $result = $client->repair($input, $result['output'], $errors);
            }
        } catch (Throwable $exception) {
            $interpretation->update([
                'status' => ContractInterpretation::STATUS_FAILED,
                'completed_at' => now(),
                'error' => mb_substr($exception->getMessage(), 0, 65000),
            ]);

            throw $exception;
        }
    }

    /**
     * @param  array{output: array<string, mixed>, usage: array<string, mixed>, provider: ?string, response_id: ?string, latency_ms: int}  $result
     * @param  list<string>  $errors
     * @return array<string, mixed>
     */
    private function attemptRecord(int $attemptNumber, array $result, array $errors): array
    {
        return [
            'attempt' => $attemptNumber + 1,
            'type' => $attemptNumber === 0 ? 'initial' : 'repair',
            'output' => $result['output'],
            'validation_errors' => $errors,
            'usage' => $result['usage'],
            'provider' => $result['provider'],
            'provider_response_id' => $result['response_id'],
            'latency_ms' => $result['latency_ms'],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $attempts
     * @return array<string, mixed>
     */
    private function aggregateUsage(array $attempts): array
    {
        $usage = [
            'attempt_count' => count($attempts),
            'prompt_tokens' => 0,
            'completion_tokens' => 0,
            'total_tokens' => 0,
            'cost' => 0.0,
        ];

        foreach ($attempts as $attempt) {
            $attemptUsage = $attempt['usage'] ?? [];
            foreach (['prompt_tokens', 'completion_tokens', 'total_tokens'] as $field) {
                $usage[$field] += (int) ($attemptUsage[$field] ?? 0);
            }
            $usage['cost'] += (float) ($attemptUsage['cost'] ?? 0);
            if (is_string($attempt['provider'] ?? null)) {
                $usage['provider'] = $attempt['provider'];
            }
        }

        return $usage;
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
