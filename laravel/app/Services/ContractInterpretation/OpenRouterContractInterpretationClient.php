<?php

namespace App\Services\ContractInterpretation;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class OpenRouterContractInterpretationClient
{
    /**
     * @param  array<string, mixed>  $input
     * @return array{output: array<string, mixed>, usage: array<string, mixed>, provider: ?string, response_id: ?string, latency_ms: int}
     */
    public function interpret(array $input, ?string $historicalAddendumPath = null): array
    {
        return $this->request($input, historicalAddendumPath: $historicalAddendumPath);
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $previousOutput
     * @param  list<string>  $validationErrors
     * @return array{output: array<string, mixed>, usage: array<string, mixed>, provider: ?string, response_id: ?string, latency_ms: int}
     */
    public function repair(
        array $input,
        array $previousOutput,
        array $validationErrors,
        ?string $historicalAddendumPath = null,
    ): array {
        return $this->request($input, $previousOutput, $validationErrors, $historicalAddendumPath);
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>|null  $previousOutput
     * @param  list<string>  $validationErrors
     * @return array{output: array<string, mixed>, usage: array<string, mixed>, provider: ?string, response_id: ?string, latency_ms: int}
     */
    private function request(
        array $input,
        ?array $previousOutput = null,
        array $validationErrors = [],
        ?string $historicalAddendumPath = null,
    ): array {
        $apiKey = config('services.openrouter.api_key');
        if (! $apiKey) {
            throw new RuntimeException('OPENROUTER_API_KEY is not configured.');
        }

        $schema = $this->readJsonFile((string) config('contract_interpretation.schema_path'));
        $prompt = $this->readFile((string) config('contract_interpretation.prompt_path'));
        if ($historicalAddendumPath !== null) {
            $prompt .= "\n\n".$this->readFile($historicalAddendumPath);
        }
        $messages = [
            ['role' => 'system', 'content' => $prompt],
            [
                'role' => 'user',
                'content' => json_encode(
                    $input,
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
                ),
            ],
        ];

        if ($previousOutput !== null) {
            $messages[] = [
                'role' => 'assistant',
                'content' => json_encode(
                    $previousOutput,
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
                ),
            ];
            $messages[] = [
                'role' => 'user',
                'content' => json_encode([
                    'task' => 'Correct the previous complete JSON output.',
                    'validation_errors' => $validationErrors,
                    'requirements' => [
                        'Return the complete corrected JSON object, not a patch.',
                        'Correct every reported error.',
                        'Do not invent evidence or change source facts.',
                        'Remove unsupported facts or use null, Unknown, uncertain, or not_assessable as allowed by the schema.',
                    ],
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            ];
        }

        $historical = $historicalAddendumPath !== null;
        $connectTimeout = (int) config($historical
            ? 'contract_interpretation.historical.connect_timeout'
            : 'contract_interpretation.connect_timeout');
        $timeout = (int) config($historical
            ? 'contract_interpretation.historical.timeout'
            : 'contract_interpretation.timeout');
        $request = Http::withToken($apiKey)
            ->acceptJson()
            ->withHeaders([
                'HTTP-Referer' => config('app.url'),
                'X-Title' => 'Voltikka contract interpretation',
            ])
            ->connectTimeout($connectTimeout)
            ->timeout($timeout);
        if ($historical && (int) config('contract_interpretation.historical.http_attempts') !== 1) {
            throw new RuntimeException('Historical interpretation HTTP attempts must remain 1 to fit the queue timeout.');
        }
        if (! $historical) {
            $request->retry(2, 1000);
        }

        $startedAt = hrtime(true);
        $response = $request
            ->post(rtrim((string) config('services.openrouter.base_url'), '/').'/chat/completions', [
                'model' => config('contract_interpretation.model'),
                'max_tokens' => config('contract_interpretation.max_tokens'),
                'reasoning' => [
                    'effort' => config('contract_interpretation.reasoning_effort'),
                    'exclude' => true,
                ],
                'messages' => $messages,
                'response_format' => [
                    'type' => 'json_schema',
                    'json_schema' => [
                        'name' => 'voltikka_contract_interpretation',
                        'strict' => true,
                        'schema' => $schema,
                    ],
                ],
            ])
            ->throw()
            ->json();

        $content = data_get($response, 'choices.0.message.content');
        if (is_array($content)) {
            $content = implode('', array_map(
                fn (mixed $part): string => is_array($part) ? (string) ($part['text'] ?? '') : (string) $part,
                $content
            ));
        }
        if (! is_string($content) || $content === '') {
            throw new RuntimeException('OpenRouter returned no interpretation content.');
        }

        $output = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($output)) {
            throw new RuntimeException('OpenRouter interpretation content is not a JSON object.');
        }

        return [
            'output' => $output,
            'usage' => is_array($response['usage'] ?? null) ? $response['usage'] : [],
            'provider' => is_string($response['provider'] ?? null) ? $response['provider'] : null,
            'response_id' => is_string($response['id'] ?? null) ? $response['id'] : null,
            'latency_ms' => (int) round((hrtime(true) - $startedAt) / 1_000_000),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function readJsonFile(string $path): array
    {
        return json_decode($this->readFile($path), true, 512, JSON_THROW_ON_ERROR);
    }

    private function readFile(string $path): string
    {
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new RuntimeException("Cannot read contract interpretation asset: {$path}");
        }

        return $contents;
    }
}
