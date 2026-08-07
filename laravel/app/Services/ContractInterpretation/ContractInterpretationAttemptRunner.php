<?php

namespace App\Services\ContractInterpretation;

class ContractInterpretationAttemptRunner
{
    public function __construct(
        private readonly OpenRouterContractInterpretationClient $client,
        private readonly ContractInterpretationValidator $validator,
    ) {}

    /**
     * Run one initial call and no more than two deterministic repair calls.
     * This service has no publisher or persistence dependency.
     *
     * @param  array<string, mixed>  $input
     * @param  callable(array<string, mixed>): list<string>|null  $additionalValidation
     * @param  callable(array<string, mixed>, list<array<string, mixed>>): void|null  $afterAttempt
     * @return array{validated: bool, output: array<string, mixed>, errors: list<string>, attempts: list<array<string, mixed>>, usage: array<string, mixed>}
     */
    public function run(
        array $input,
        ?string $historicalAddendumPath = null,
        ?callable $additionalValidation = null,
        ?callable $afterAttempt = null,
    ): array {
        $result = $historicalAddendumPath === null
            ? $this->client->interpret($input)
            : $this->client->interpret($input, $historicalAddendumPath);
        $attempts = [];
        $maxRepairAttempts = min(2, max(0, (int) config('contract_interpretation.max_repair_attempts')));

        for ($attemptNumber = 0; $attemptNumber <= $maxRepairAttempts; $attemptNumber++) {
            $errors = $this->validator->validate($result['output'], $input);
            if ($errors === [] && $additionalValidation !== null) {
                $errors = $additionalValidation($result['output']);
            }
            $attempts[] = $this->attemptRecord($attemptNumber, $result, $errors);
            if ($afterAttempt !== null) {
                $afterAttempt(end($attempts), $attempts);
            }

            if ($errors === [] || $attemptNumber === $maxRepairAttempts) {
                return [
                    'validated' => $errors === [],
                    'output' => $result['output'],
                    'errors' => $errors,
                    'attempts' => $attempts,
                    'usage' => self::aggregateUsage($attempts),
                ];
            }

            $result = $historicalAddendumPath === null
                ? $this->client->repair($input, $result['output'], $errors)
                : $this->client->repair($input, $result['output'], $errors, $historicalAddendumPath);
        }

        throw new \LogicException('The bounded interpretation attempt loop did not return.');
    }

    /** @param list<array<string, mixed>> $attempts */
    public static function aggregateUsage(array $attempts): array
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
}
