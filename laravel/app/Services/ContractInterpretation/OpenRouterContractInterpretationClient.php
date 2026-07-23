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
    public function interpret(array $input): array
    {
        $apiKey = config('services.openrouter.api_key');
        if (! $apiKey) {
            throw new RuntimeException('OPENROUTER_API_KEY is not configured.');
        }

        $schema = $this->readJsonFile((string) config('contract_interpretation.schema_path'));
        $prompt = $this->readFile((string) config('contract_interpretation.prompt_path'));
        $startedAt = hrtime(true);

        $response = Http::withToken($apiKey)
            ->acceptJson()
            ->withHeaders([
                'HTTP-Referer' => config('app.url'),
                'X-Title' => 'Voltikka contract interpretation',
            ])
            ->connectTimeout((int) config('contract_interpretation.connect_timeout'))
            ->timeout((int) config('contract_interpretation.timeout'))
            ->retry(2, 1000)
            ->post(rtrim((string) config('services.openrouter.base_url'), '/').'/chat/completions', [
                'model' => config('contract_interpretation.model'),
                'max_tokens' => config('contract_interpretation.max_tokens'),
                'reasoning' => [
                    'effort' => config('contract_interpretation.reasoning_effort'),
                    'exclude' => true,
                ],
                'messages' => [
                    ['role' => 'system', 'content' => $prompt],
                    [
                        'role' => 'user',
                        'content' => json_encode([
                            'analysis_date' => $input['analysis_date'] ?? now()->toDateString(),
                            'contract' => $input,
                        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                    ],
                ],
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
