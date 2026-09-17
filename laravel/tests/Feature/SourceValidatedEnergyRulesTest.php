<?php

namespace Tests\Feature;

use App\Jobs\AnalyzeContractSourceSnapshot;
use App\Models\Company;
use App\Models\ContractInterpretation;
use App\Models\ContractSourceObservation;
use App\Models\ContractSourceSnapshot;
use App\Models\ElectricityContract;
use App\Services\CanonicalPricing\CurrentSourcePromotionEvidence;
use App\Services\ContractInterpretation\ContractAnalysisFingerprint;
use App\Services\ContractInterpretation\ContractInterpretationDispatcher;
use App\Services\ContractInterpretation\ContractInterpretationProfile;
use App\Services\ContractInterpretation\ContractInterpretationPublisher;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\EnergyRulesFixture as F;
use Tests\TestCase;

class SourceValidatedEnergyRulesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-09-15 10:00:00');
        Http::preventStrayRequests();
        Queue::fake();
    }

    public function test_v5_job_uses_stored_assets_and_full_proof_under_v4_defaults(): void
    {
        [$contract, $snapshot, $observation, $analysis] = $this->fixture();
        config()->set('services.openrouter.api_key', 'test-key');
        Http::fake(['*' => Http::response(['choices' => [['message' => ['content' => json_encode($analysis->output)]]]])]);
        app()->call([new AnalyzeContractSourceSnapshot($analysis->id), 'handle']);
        $this->assertSame('published', $analysis->fresh()->status);
        $this->assertSame('schema-v5', $analysis->fresh()->schema_version);
        $this->assertSame('schema-v4', config('contract_interpretation.schema_version'));
        Http::assertSent(fn ($request) => $request['response_format']['json_schema']['schema']['properties']['schema_version']['const'] === '1.2'
            && str_contains($request['messages'][0]['content'], 'SOURCE-BACKED ENERGY RULES'));
        $this->assertSame($analysis->id, $contract->fresh()->published_interpretation_id);
    }

    public function test_v5_repair_can_remove_unproven_facts_without_changing_the_stored_profile(): void
    {
        [$contract, $snapshot, $observation, $analysis] = $this->fixture();
        $invalid = $analysis->output;
        $invalid['pricing']['phases'][0]['components'][0]['energy_rule']['discount_value'] = 4;
        $repaired = $analysis->output;
        $repaired['pricing']['phases'][0]['components'][0]['energy_rule'] = F::unknown();
        config()->set('services.openrouter.api_key', 'test-key');
        Http::fake(['*' => Http::sequence()
            ->push(['choices' => [['message' => ['content' => json_encode($invalid)]]]])
            ->push(['choices' => [['message' => ['content' => json_encode($repaired)]]]])]);
        app()->call([new AnalyzeContractSourceSnapshot($analysis->id), 'handle']);
        $stored = $analysis->fresh();
        $this->assertSame('published', $stored->status);
        $this->assertSame('schema-v5', $stored->schema_version);
        $this->assertCount(2, $stored->llm_attempts);
        $this->assertNotEmpty($stored->llm_attempts[0]['validation_errors']);
        $this->assertSame('unknown', $stored->output['pricing']['phases'][0]['components'][0]['energy_rule']['kind']);
        Http::assertSentCount(2);
    }

    public function test_forged_rule_or_changed_locked_input_cannot_change_any_publication_rows(): void
    {
        [$contract, $snapshot, $observation, $analysis] = $this->fixture();
        $publisher = app(ContractInterpretationPublisher::class);
        $output = $analysis->output;
        $originalSource = $snapshot->source_payload;
        foreach (['operand', 'missing', 'input', 'observation'] as $case) {
            $snapshot->update(['source_payload' => $originalSource]);
            $analysis->analysis_source_observation_id = null;
            $changed = $output;
            if ($case === 'operand') {
                $changed['pricing']['phases'][0]['components'][0]['energy_rule']['discount_value'] = 4;
            } elseif ($case === 'missing') {
                unset($changed['pricing']['phases'][0]['components'][0]['energy_rule']);
            } elseif ($case === 'input') {
                $source = $snapshot->source_payload;
                $source['Details']['ShortDescription'] = 'Not '.$source['Details']['ShortDescription'];
                $snapshot->update(['source_payload' => $source]); // Local forged-source fixture only.
            } else {
                $analysis->analysis_source_observation_id = $observation->id + 1;
            }
            $analysis->output = $changed;
            $analysis->save();
            $before = $this->rows();
            $this->assertFalse($publisher->publish($analysis), $case);
            $this->assertSame($before, $this->rows(), $case);
        }
    }

    public function test_default_v4_cannot_replace_valid_v5_on_the_unchanged_pointed_observation(): void
    {
        [$contract, $snapshot, $observation, $v5] = $this->fixture();
        $v4 = $this->legacy($snapshot, 'validator-v17');
        $v4->update(['status' => 'superseded']);
        $publisher = app(ContractInterpretationPublisher::class);
        $this->assertTrue($publisher->publish($v5));
        $before = $this->rows();
        config()->set('contract_interpretation.enabled', true);
        config()->set('services.openrouter.api_key', null);
        $selected = app(ContractInterpretationDispatcher::class)->dispatch($observation);
        $this->assertSame($v5->id, $selected->id);
        $this->assertFalse($publisher->publish($v4));
        $this->assertSame($before, $this->rows());
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_changed_source_does_not_gain_automatic_v5_selection(): void
    {
        [$contract, $snapshot, $observation, $v5] = $this->fixture();
        $this->assertTrue(app(ContractInterpretationPublisher::class)->publish($v5));
        $changed = $snapshot->replicate();
        $changed->source_fingerprint = str_repeat('b', 64);
        $changed->save();
        $next = ContractSourceObservation::create(['contract_id' => $contract->id, 'source_snapshot_id' => $changed->id, 'first_observed_at' => '2026-09-16', 'last_observed_at' => '2026-09-16']);
        $contract->update(['current_source_observation_id' => $next->id]);
        config()->set('contract_interpretation.enabled', true);
        config()->set('services.openrouter.api_key', 'test-key');
        $analysis = app(ContractInterpretationDispatcher::class)->dispatch($next);
        $this->assertSame('schema-v4', $analysis->schema_version);
        Queue::assertPushed(AnalyzeContractSourceSnapshot::class, 1);
    }

    public function test_v5_recurrence_uses_v5_full_validation_without_a_model_call(): void
    {
        [$contract, $snapshot, $observation, $v5] = $this->fixture();
        $this->assertTrue(app(ContractInterpretationPublisher::class)->publish($v5));
        $next = ContractSourceObservation::create(['contract_id' => $contract->id, 'source_snapshot_id' => $snapshot->id, 'first_observed_at' => '2026-09-15 11:00:00', 'last_observed_at' => '2026-09-15 11:00:00']);
        $contract->update(['current_source_observation_id' => $next->id, 'published_interpretation_id' => null]);
        $v5->update(['status' => 'superseded']);
        $this->selectV5();
        $selected = app(ContractInterpretationDispatcher::class)->dispatch($next, true);
        $this->assertSame($v5->id, $selected->id);
        $this->assertSame('published', $selected->status);
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_real_prior_v4_profile_can_publish_and_recur_without_new_facts(): void
    {
        [$contract, $snapshot, $observation, $v5] = $this->fixture();
        $legacy = $this->legacy($snapshot, 'validator-v16');
        $publisher = app(ContractInterpretationPublisher::class);
        $this->assertTrue($publisher->publish($legacy));
        $legacy->update(['status' => 'superseded']);
        config()->set('contract_interpretation.validator_version', 'validator-v16');
        config()->set('contract_interpretation.schema_path', '/missing-global-schema');
        $selected = app(ContractInterpretationDispatcher::class)->dispatch($observation, true);
        $this->assertSame($legacy->id, $selected->id);
        $this->assertSame('published', $selected->status);
        $this->assertArrayNotHasKey('energy_rule', $selected->output['pricing']['phases'][0]['components'][0]);
        Queue::assertNothingPushed();
    }

    public function test_current_energy_proof_uses_one_read_and_exact_published_source_output(): void
    {
        [$contract, , $observation, $analysis] = $this->fixture();
        $analysis->update(['completed_at' => now()]);
        $this->assertTrue(app(ContractInterpretationPublisher::class)->publish($analysis));
        $contract->refresh();
        $proof = new CurrentSourcePromotionEvidence;
        DB::enableQueryLog();
        DB::flushQueryLog();
        $result = $proof->forContracts(collect([$contract, $contract]), CarbonImmutable::parse('2026-09-15', 'Europe/Helsinki'));
        $this->assertCount(1, DB::getQueryLog());
        DB::disableQueryLog();
        $this->assertTrue($result[$contract->id]['valid']);
        $this->assertTrue($result[$contract->id]['energy_rules_valid']);
        $this->assertFalse($proof->forContracts(collect([$contract]), CarbonImmutable::parse('2026-09-14'))[$contract->id]['energy_rules_valid']);
        $this->assertTrue($proof->forContracts(collect([$contract]), CarbonImmutable::parse('2026-09-16'))[$contract->id]['energy_rules_valid']);

        // A date-scoped result must name this exact episode, not another recurrence.
        $analysis->update(['analysis_source_observation_id' => $observation->id + 1]);
        $this->assertFalse($proof->forContracts(collect([$contract]))[$contract->id]['energy_rules_valid']);
        Http::assertNothingSent();
    }

    public function test_current_energy_proof_rejects_forged_rules_and_stale_calculation_copies(): void
    {
        [$contract, $snapshot, , $analysis] = $this->fixture();
        $analysis->update(['completed_at' => now()]);
        $this->assertTrue(app(ContractInterpretationPublisher::class)->publish($analysis));
        $contract->refresh();
        $proof = new CurrentSourcePromotionEvidence;
        $original = $analysis->output;
        foreach (['canonical_pricing', 'canonical_calculation', 'canonical_source_consistency'] as $column) {
            $copy = clone $contract;
            $copy->{$column} = [];
            $this->assertFalse($proof->forContracts(collect([$copy]))[$contract->id]['energy_rules_valid'], $column);
        }
        $forged = $original;
        $forged['pricing']['phases'][0]['components'][0]['energy_rule']['discount_value'] = 4;
        $analysis->update(['output' => $forged]);
        $contract->update(['canonical_pricing' => $forged['pricing']]);
        $this->assertFalse($proof->forContracts(collect([$contract]))[$contract->id]['energy_rules_valid']);
        $analysis->update(['output' => $original]);
        $contract->update(['canonical_pricing' => $original['pricing']]);
        $source = $snapshot->source_payload;
        $source['Details']['ShortDescription'] = 'Unsupported energy price conditions.';
        $snapshot->update(['source_payload' => $source]);
        $this->assertFalse($proof->forContracts(collect([$contract]))[$contract->id]['energy_rules_valid']);
        $source['Details']['Pricing']['PriceComponents'] = 'malformed';
        $snapshot->update(['source_payload' => $source]);
        $this->assertFalse($proof->forContracts(collect([$contract]))[$contract->id]['energy_rules_valid']);
        Http::assertNothingSent();
    }

    #[DataProvider('invalidCanonicalAmounts')]
    public function test_current_batch_proof_rejects_changed_numeric_types_in_caller_and_database(float $normalAmount, mixed $invalid): void
    {
        [$contract, $snapshot, , $analysis] = $this->fixture($normalAmount);
        $analysis->update(['completed_at' => now()]);
        $this->assertTrue(app(ContractInterpretationPublisher::class)->publish($analysis));
        $contract->refresh();
        $originalOutput = $analysis->output;
        $originalSource = $snapshot->source_payload;
        $originalPricing = $contract->canonical_pricing;
        $changed = $originalPricing;
        $changed['phases'][0]['components'][0]['amount'] = $invalid;
        $proof = new CurrentSourcePromotionEvidence;
        foreach (['caller', 'database'] as $target) {
            $copy = clone $contract;
            if ($target === 'caller') {
                $copy->canonical_pricing = $changed;
            } else {
                DB::table('electricity_contracts')->where('id', $contract->id)->update(['canonical_pricing' => json_encode($changed)]);
            }
            DB::enableQueryLog();
            DB::flushQueryLog();
            $result = $proof->forContracts(collect([$copy, $copy]))[$contract->id];
            $this->assertCount(1, DB::getQueryLog());
            DB::disableQueryLog();
            $this->assertTrue($result['valid'], $target);
            $this->assertFalse($result['energy_rules_valid'], $target);
        }
        DB::table('electricity_contracts')->where('id', $contract->id)->update(['canonical_pricing' => json_encode($originalPricing)]);
        $this->assertTrue($proof->forContracts(collect([$contract]))[$contract->id]['energy_rules_valid']);
        $this->assertSame($originalOutput, $analysis->fresh()->output);
        $this->assertSame($originalSource, $snapshot->fresh()->source_payload);
        Http::assertNothingSent();
    }

    public static function invalidCanonicalAmounts(): array
    {
        return [
            'true instead of four' => [9, true],
            'null instead of zero' => [5, null],
            'string instead of four' => [9, '4'],
            'string instead of zero' => [5, '0'],
        ];
    }

    public function test_current_batch_proof_accepts_object_key_order_and_numeric_representation_changes(): void
    {
        [$contract, , , $analysis] = $this->fixture();
        $analysis->update(['completed_at' => now()]);
        $this->assertTrue(app(ContractInterpretationPublisher::class)->publish($analysis));
        $contract->refresh();
        $reorder = function (mixed $value) use (&$reorder): mixed {
            if (! is_array($value)) {
                return is_int($value) ? (float) $value : $value;
            }
            $value = array_map($reorder, $value);

            return array_is_list($value) ? $value : array_reverse($value, true);
        };
        $proof = new CurrentSourcePromotionEvidence;
        foreach (['caller', 'database'] as $target) {
            $copy = clone $contract;
            foreach (['canonical_pricing', 'canonical_calculation', 'canonical_source_consistency'] as $column) {
                $changed = $reorder($contract->{$column});
                if ($target === 'caller') {
                    $copy->{$column} = $changed;
                } else {
                    DB::table('electricity_contracts')->where('id', $contract->id)->update([$column => json_encode($changed, JSON_PRESERVE_ZERO_FRACTION)]);
                }
            }
            DB::enableQueryLog();
            DB::flushQueryLog();
            $result = $proof->forContracts(collect([$copy, $copy]))[$contract->id];
            $this->assertCount(1, DB::getQueryLog());
            DB::disableQueryLog();
            $this->assertTrue($result['energy_rules_valid'], $target);
        }
        Http::assertNothingSent();
    }

    public function test_current_energy_proof_checks_dates_status_errors_and_exact_tuple(): void
    {
        [$contract, , $observation, $analysis] = $this->fixture();
        $analysis->update(['completed_at' => now()]);
        $this->assertTrue(app(ContractInterpretationPublisher::class)->publish($analysis));
        $contract->refresh();
        $analysis->refresh();
        $proof = new CurrentSourcePromotionEvidence;
        foreach ([
            ['schema_version', 'schema-v4'], ['prompt_version', 'prompt-v19'], ['validator_version', 'validator-v17'],
            ['status', 'superseded'], ['validation_errors', ['unsafe']],
            ['completed_at', null], ['published_at', null],
            ['completed_at', '2026-09-16 00:00:00'], ['published_at', '2026-09-16 00:00:00'],
            ['published_at', '2026-09-14 00:00:00'],
        ] as [$field, $invalid]) {
            $original = $analysis->{$field};
            $analysis->update([$field => $invalid]);
            $this->assertFalse($proof->forContracts(collect([$contract]))[$contract->id]['energy_rules_valid'], $field);
            $analysis->update([$field => $original]);
        }
        $observation->update(['first_observed_at' => '2026-09-16 00:00:00']);
        $this->assertFalse($proof->forContracts(collect([$contract]))[$contract->id]['energy_rules_valid']);
        Http::assertNothingSent();
    }

    public function test_legacy_and_unpointed_contracts_never_authorize_energy_rules(): void
    {
        [$contract, $snapshot] = $this->fixture();
        $legacy = $this->legacy($snapshot, 'validator-v17');
        $this->assertTrue(app(ContractInterpretationPublisher::class)->publish($legacy));
        $contract->refresh();
        $proof = new CurrentSourcePromotionEvidence;
        $source = $snapshot->source_payload;
        $source['Name'] = 'Kampanjahinta 4 snt/kWh';
        $snapshot->update(['source_payload' => $source]);
        DB::enableQueryLog();
        DB::flushQueryLog();
        $result = $proof->forContracts(collect([$contract, $contract]))[$contract->id];
        $queries = DB::getQueryLog();
        $this->assertCount(1, $queries);
        DB::disableQueryLog();
        $this->assertTrue($result['valid']);
        $this->assertSame([4.0], $result['rates']);
        $this->assertFalse($result['energy_rules_valid']);
        $row = DB::selectOne($queries[0]['query'], $queries[0]['bindings']);
        foreach (['output', 'canonical_pricing', 'canonical_calculation', 'canonical_source_consistency'] as $column) {
            $this->assertNull($row->{$column}, $column);
        }
        $contract->published_interpretation_id = null;
        $contract->current_source_observation_id = null;
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->assertSame([], $proof->forContracts(collect([$contract])));
        $this->assertCount(0, DB::getQueryLog());
        DB::disableQueryLog();
        Http::assertNothingSent();
    }

    private function fixture(float $normalAmount = 9): array
    {
        [, $output, $source] = F::example('absolute_discount', $normalAmount);
        Company::create(['name' => 'Energy test company', 'name_slug' => 'energy-test-company']);
        $contract = ElectricityContract::factory()->forCompany('Energy test company')->create(['id' => 'energy-test', 'name' => 'Energy test', 'pricing_model' => 'FixedPrice', 'contract_type' => 'OpenEnded', 'metering' => 'General', 'target_group' => 'Household']);
        $snapshot = ContractSourceSnapshot::create(['contract_id' => $contract->id, 'source_fingerprint' => str_repeat('a', 64), 'source_payload' => $source, 'first_observed_at' => '2026-09-15', 'last_observed_at' => '2026-09-15']);
        $observation = ContractSourceObservation::create(['contract_id' => $contract->id, 'source_snapshot_id' => $snapshot->id, 'first_observed_at' => '2026-09-15', 'last_observed_at' => '2026-09-15']);
        $contract->update(['current_source_observation_id' => $observation->id]);
        $analysis = ContractInterpretation::create(['contract_id' => $contract->id, 'source_snapshot_id' => $snapshot->id,
            'analysis_fingerprint' => (new ContractAnalysisFingerprint)->forSnapshot($snapshot, F::profile()), 'status' => 'pending',
            'schema_version' => 'schema-v5', 'prompt_version' => 'prompt-v20', 'validator_version' => 'validator-v18', 'provider' => config('contract_interpretation.provider'), 'model' => config('contract_interpretation.model'), 'output' => $output, 'validation_errors' => []]);

        return [$contract, $snapshot, $observation, $analysis];
    }

    private function legacy(ContractSourceSnapshot $snapshot, string $validator): ContractInterpretation
    {
        [, $output] = F::example('absolute_discount');
        $output['schema_version'] = '1.1';
        foreach ($output['pricing']['phases'] as &$phase) {
            foreach ($phase['components'] as &$component) {
                unset($component['energy_rule']);
            }
        }
        unset($phase, $component);
        $profile = ContractInterpretationProfile::stored('schema-v4', 'prompt-v19', $validator);

        return ContractInterpretation::create(['contract_id' => $snapshot->contract_id, 'source_snapshot_id' => $snapshot->id,
            'analysis_fingerprint' => (new ContractAnalysisFingerprint)->forSnapshot($snapshot, $profile), 'status' => 'pending',
            'schema_version' => 'schema-v4', 'prompt_version' => 'prompt-v19', 'validator_version' => $validator, 'provider' => config('contract_interpretation.provider'), 'model' => config('contract_interpretation.model'), 'output' => $output, 'validation_errors' => []]);
    }

    private function selectV5(): void
    {
        $profile = F::profile();
        config()->set(['contract_interpretation.schema_version' => $profile->schemaVersion, 'contract_interpretation.prompt_version' => $profile->promptVersion, 'contract_interpretation.validator_version' => $profile->validatorVersion, 'contract_interpretation.schema_path' => $profile->schemaPath, 'contract_interpretation.prompt_path' => $profile->promptPath]);
    }

    private function rows(): array
    {
        $rows = [];
        foreach (['electricity_contracts', 'contract_interpretations', 'price_components', 'active_contracts'] as $table) {
            $rows[$table] = DB::table($table)->get()->map(fn ($row) => (array) $row)->all();
        }

        return $rows;
    }
}
