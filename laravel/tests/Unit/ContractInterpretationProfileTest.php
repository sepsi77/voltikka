<?php

namespace Tests\Unit;

use App\Models\ContractSourceSnapshot;
use App\Services\ContractInterpretation\ContractAnalysisFingerprint;
use App\Services\ContractInterpretation\ContractInterpretationInputBuilder;
use App\Services\ContractInterpretation\ContractInterpretationProfile;
use App\Services\ContractInterpretation\ContractInterpretationValidator;
use App\Services\ContractInterpretation\HistoricalInterpretationBackcastValidator;
use App\Services\ContractInterpretation\HistoricalInterpretationFingerprint;
use App\Services\ContractInterpretation\OpenRouterContractInterpretationClient;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ContractInterpretationProfileTest extends TestCase
{
    public function test_historical_hash_keeps_exact_original_bytes_when_current_profile_changes(): void
    {
        $hashes = new HistoricalInterpretationFingerprint;
        $original = $hashes->hash([
            'episode_fingerprint' => 'episode',
            'schema_version' => 'schema-v4',
            'prompt_version' => 'prompt-v19',
            'historical_addendum_version' => config('contract_interpretation.historical.addendum_version'),
            'historical_backcast_validator_version' => HistoricalInterpretationBackcastValidator::VERSION,
            'validator_version' => 'validator-v17',
            'parser_version' => 'canonical-pricing-parser-v1',
            'provider' => config('contract_interpretation.provider'),
            'model' => config('contract_interpretation.model'),
            'reasoning_effort' => config('contract_interpretation.reasoning_effort'),
        ]);
        $this->assertSame($original, $hashes->analysis('episode'));
        $snapshot = new ContractSourceSnapshot(['source_fingerprint' => 'source', 'source_payload' => [], 'contract_id' => 'test']);
        $fingerprint = new ContractAnalysisFingerprint;
        $current = $fingerprint->forSnapshot($snapshot);
        $input = (new ContractInterpretationInputBuilder)->build($snapshot, '2026-01-01');
        $profile = ContractInterpretationProfile::current();
        $this->changeCurrentProfile();
        $this->assertSame('schema-v4', $profile->schemaVersion);
        $this->assertSame($original, $hashes->analysis('episode'));
        $this->assertNotSame($current, $fingerprint->forSnapshot($snapshot));
        $this->assertSame($current, $fingerprint->forSnapshot($snapshot, $profile));
        $this->assertSame($input, (new ContractInterpretationInputBuilder)->build($snapshot, '2026-01-01', ContractInterpretationProfile::historical()));
    }

    public function test_stored_selection_is_pure_and_rejects_unsupported_tuples(): void
    {
        DB::enableQueryLog();
        $this->changeCurrentProfile();
        $before = config('contract_interpretation');
        $v4 = ContractInterpretationProfile::stored('schema-v4', 'prompt-v19', 'validator-v17');
        $v5 = ContractInterpretationProfile::stored('schema-v5', 'prompt-v20', 'validator-v18');
        $this->assertStringEndsWith('schema-v4.json', $v4->schemaPath);
        $this->assertStringEndsWith('schema-v5.json', $v5->schemaPath);
        $this->assertNotEmpty((new ContractInterpretationValidator)->validate([], [], $v5));
        $this->assertSame($before, config('contract_interpretation'));
        $this->assertSame([], DB::getQueryLog());
        $this->expectException(\InvalidArgumentException::class);
        ContractInterpretationProfile::stored('schema-v4', 'prompt-v20', 'validator-v18');
    }

    public function test_registry_uses_only_genuine_released_legacy_tuples_with_retained_assets(): void
    {
        $tuples = [[2, 5, 1], [3, 6, 1], [3, 6, 2], [3, 7, 3], [3, 8, 4], [3, 9, 5], [3, 10, 6], [3, 10, 7], [3, 11, 8], [3, 12, 9], [3, 13, 10], [3, 14, 10], [3, 15, 11], [3, 17, 13], [3, 17, 14], [4, 19, 16], [4, 19, 17], [5, 20, 18]];
        foreach ($tuples as [$schema, $prompt, $validator]) {
            $profile = ContractInterpretationProfile::stored('schema-v'.$schema, 'prompt-v'.$prompt, 'validator-v'.$validator);
            $this->assertFileExists($profile->schemaPath);
            $this->assertFileExists($profile->promptPath);
        }
        $this->expectException(\InvalidArgumentException::class);
        ContractInterpretationProfile::stored('schema-v4', 'prompt-v18', 'validator-v15');
    }

    public function test_custom_schema_constructor_remains_independent_of_asset_paths(): void
    {
        $profile = new ContractInterpretationProfile('schema-v4', 'prompt-v19', 'validator-v17', '/missing-schema', '/missing-prompt');
        $validator = new ContractInterpretationValidator(['type' => 'object', 'properties' => ['marker' => ['const' => 'expected']]]);
        $errors = $validator->validate(['marker' => 'wrong'], [], $profile);
        $this->assertContains('$.marker must equal the schema constant.', $errors);
    }

    public function test_historical_client_uses_pinned_assets_without_current_assets(): void
    {
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response(['choices' => [['message' => ['content' => '{}']]]])]);
        config()->set('services.openrouter.api_key', 'test-key');
        $this->changeCurrentProfile();
        (new OpenRouterContractInterpretationClient)->interpret([], config('contract_interpretation.historical.addendum_path'));
        Http::assertSent(function ($request): bool {
            return $request['response_format']['json_schema']['schema'] === json_decode(file_get_contents(resource_path('contract-interpretation/schema-v4.json')), true)
                && str_starts_with($request['messages'][0]['content'], file_get_contents(resource_path('contract-interpretation/system-prompt-v19.md')));
        });
    }

    private function changeCurrentProfile(): void
    {
        config()->set([
            'contract_interpretation.schema_version' => 'schema-v5',
            'contract_interpretation.prompt_version' => 'prompt-v20',
            'contract_interpretation.validator_version' => 'validator-v18',
            'contract_interpretation.parser_version' => 'future-parser',
            'contract_interpretation.schema_path' => '/missing-current-schema',
            'contract_interpretation.prompt_path' => '/missing-current-prompt',
        ]);
    }
}
