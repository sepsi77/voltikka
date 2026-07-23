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

    public int $timeout = 260;

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
            $errors = $validator->validate($result['output'], $input);
            $usage = $result['usage'];
            if ($result['provider'] !== null) {
                $usage['provider'] = $result['provider'];
            }

            $interpretation->update([
                'output' => $result['output'],
                'validation_errors' => $errors === [] ? null : $errors,
                'usage' => $usage,
                'provider_response_id' => $result['response_id'],
                'latency_ms' => $result['latency_ms'],
            ]);

            if ($errors !== []) {
                $interpretation->update([
                    'status' => ContractInterpretation::STATUS_FAILED,
                    'completed_at' => now(),
                    'error' => 'Automatic validation failed.',
                ]);

                return;
            }

            $publisher->publish($interpretation->fresh());
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
