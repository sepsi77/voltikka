<?php

namespace Tests\Feature;

use App\Jobs\AnalyzeContractSourceSnapshot;
use App\Models\Company;
use App\Models\ContractInterpretation;
use App\Models\ContractSourceSnapshot;
use App\Models\ElectricityContract;
use App\Models\PriceComponent;
use App\Services\ContractInterpretation\ContractInterpretationDispatcher;
use App\Services\ContractInterpretation\ContractInterpretationPublisher;
use App\Services\ContractInterpretation\ContractInterpretationValidator;
use App\Services\ContractInterpretation\OpenRouterContractInterpretationClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ContractInterpretationPipelineTest extends TestCase
{
    use RefreshDatabase;

    public function test_dispatch_is_idempotent_for_the_same_analysis_fingerprint(): void
    {
        Queue::fake();
        config()->set('contract_interpretation.enabled', true);
        config()->set('services.openrouter.api_key', 'test-key');
        $snapshot = $this->createSnapshot();
        $dispatcher = app(ContractInterpretationDispatcher::class);

        $first = $dispatcher->dispatch($snapshot);
        $second = $dispatcher->dispatch($snapshot);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, ContractInterpretation::count());
        Queue::assertPushed(AnalyzeContractSourceSnapshot::class, 1);
    }

    public function test_client_requests_strict_structured_output(): void
    {
        config()->set('services.openrouter.api_key', 'test-key');
        Http::fake([
            '*/chat/completions' => Http::response([
                'id' => 'response-1',
                'provider' => 'test-provider',
                'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5],
                'choices' => [[
                    'message' => ['content' => json_encode(['ok' => true])],
                ]],
            ]),
        ]);

        $result = app(OpenRouterContractInterpretationClient::class)->interpret([
            'analysis_date' => '2026-07-23',
            'contract_id' => 'contract-1',
        ]);

        $this->assertSame(['ok' => true], $result['output']);
        $this->assertSame('response-1', $result['response_id']);
        Http::assertSent(function (Request $request): bool {
            $body = $request->data();

            return $request->hasHeader('Authorization', 'Bearer test-key')
                && data_get($body, 'response_format.type') === 'json_schema'
                && data_get($body, 'response_format.json_schema.strict') === true
                && data_get($body, 'reasoning.effort') === 'low';
        });
    }

    public function test_validator_rejects_wrong_contract_and_invalid_schema(): void
    {
        $errors = app(ContractInterpretationValidator::class)->validate(
            ['schema_version' => 'wrong', 'contract_id' => 'wrong-contract'],
            ['contract_id' => 'contract-1']
        );

        $this->assertNotEmpty($errors);
        $this->assertContains('$.contract_id must match the source contract ID.', $errors);
    }

    public function test_validator_requires_text_evidence_for_corrections_and_numbers(): void
    {
        $output = $this->validOutput('contract-1', ['primary_pricing_model' => 'Hybrid']);
        $output['source_consistency']['evidence'] = [[
            'source' => 'pricing_model',
            'quote' => 'pricing_model=Spot',
        ]];
        $output['pricing']['phases'] = [[
            'label' => 'Unsupported number',
            'phase_kind' => 'current_structured',
            'starts' => ['kind' => 'contract_start', 'value' => null],
            'ends' => ['kind' => 'none', 'value' => null],
            'components' => [[
                'component_type' => 'energy_general',
                'amount' => 9.99,
                'normal_amount' => null,
                'unit' => 'cents_per_kwh',
                'vat_status' => 'unknown',
                'price_role' => 'current',
                'source_kind' => 'description',
                'evidence' => [[
                    'source' => 'extra_information_fi',
                    'quote' => 'Test contract source text.',
                ]],
            ]],
            'evidence' => [],
        ]];

        $errors = app(ContractInterpretationValidator::class)->validate($output, [
            'contract_id' => 'contract-1',
            'pricing_model' => 'Spot',
            'contract_type' => 'OpenEnded',
            'metering' => 'General',
            'extra_information_fi' => 'Test contract source text.',
            'components' => [],
        ]);

        $this->assertContains(
            '$.source_consistency.evidence must cite source text for a classification correction.',
            $errors,
        );
        $this->assertContains('$.pricing.phases[0].components[0].amount lacks numeric evidence.', $errors);
    }

    public function test_successful_job_automatically_publishes_canonical_classification(): void
    {
        $snapshot = $this->createSnapshot();
        $payload = $snapshot->source_payload;
        $payload['Details']['Pricing'] = [
            'ElectricitySupplyProductId' => 'api-contract-1',
            'PriceComponents' => [[
                'Id' => 'component-1',
                'PriceComponentType' => 'General',
                'HasDiscount' => false,
                'OriginalPayment' => ['Price' => 7.25, 'PaymentUnit' => 'CentPerKiwattHour'],
            ]],
        ];
        $snapshot->update(['source_payload' => $payload]);
        $interpretation = $this->createInterpretation($snapshot);
        $output = $this->validOutput($snapshot->contract_id, [
            'term_type' => 'FixedTerm',
            'fixed_duration_months' => 12,
            'primary_pricing_model' => 'Hybrid',
            'metering' => 'Time',
        ]);
        $output['pricing']['phases'] = [[
            'label' => 'Current price',
            'phase_kind' => 'current_structured',
            'starts' => ['kind' => 'contract_start', 'value' => null],
            'ends' => ['kind' => 'none', 'value' => null],
            'components' => [[
                'component_type' => 'energy_general',
                'amount' => 7.25,
                'normal_amount' => null,
                'unit' => 'cents_per_kwh',
                'vat_status' => 'unknown',
                'price_role' => 'current',
                'source_kind' => 'structured',
                'evidence' => [[
                    'source' => 'components[0].price',
                    'quote' => 'components[0].price=7.25',
                ]],
            ]],
            'evidence' => [],
        ]];

        $this->mock(OpenRouterContractInterpretationClient::class)
            ->shouldReceive('interpret')
            ->once()
            ->andReturn([
                'output' => $output,
                'usage' => ['prompt_tokens' => 100],
                'provider' => 'test-provider',
                'response_id' => 'response-1',
                'latency_ms' => 123,
            ]);

        app()->call([new AnalyzeContractSourceSnapshot($interpretation->id), 'handle']);

        $contract = ElectricityContract::findOrFail($snapshot->contract_id);
        $interpretation->refresh();
        $this->assertSame('Hybrid', $contract->pricing_model);
        $this->assertSame('FixedTerm', $contract->contract_type);
        $this->assertSame('Time', $contract->metering);
        $this->assertSame('Fixed12', $contract->fixed_time_range);
        $this->assertSame($interpretation->id, $contract->published_interpretation_id);
        $this->assertSame($output['pricing'], $contract->canonical_pricing);
        $this->assertSame($output['source_consistency'], $contract->canonical_source_consistency);
        $this->assertSame($output['calculation'], $contract->canonical_calculation);
        $this->assertSame(ContractInterpretation::STATUS_PUBLISHED, $interpretation->status);
        $this->assertTrue($interpretation->relational_pricing_published);
        $this->assertSame($output, $interpretation->output);
        $this->assertContains('canonical_pricing', $interpretation->published_fields);
        $this->assertContains('pricing_model', $interpretation->published_fields);
        $this->assertSame('test-provider', $interpretation->usage['provider']);
        $this->assertSame(7.25, PriceComponent::sole()->price);
        $this->assertDatabaseHas('active_contracts', ['id' => $contract->id]);
    }

    public function test_invalid_output_is_stored_but_not_published(): void
    {
        $snapshot = $this->createSnapshot();
        $interpretation = $this->createInterpretation($snapshot);

        $this->mock(OpenRouterContractInterpretationClient::class)
            ->shouldReceive('interpret')
            ->once()
            ->andReturn([
                'output' => ['schema_version' => 'wrong'],
                'usage' => [],
                'provider' => null,
                'response_id' => null,
                'latency_ms' => 10,
            ]);

        app()->call([new AnalyzeContractSourceSnapshot($interpretation->id), 'handle']);

        $interpretation->refresh();
        $contract = ElectricityContract::findOrFail($snapshot->contract_id);
        $this->assertSame(ContractInterpretation::STATUS_FAILED, $interpretation->status);
        $this->assertNotEmpty($interpretation->validation_errors);
        $this->assertNull($contract->published_interpretation_id);
        $this->assertSame('Spot', $contract->pricing_model);
    }

    public function test_unsafe_structured_pricing_is_stored_but_not_activated(): void
    {
        $snapshot = $this->createSnapshot();
        $payload = $snapshot->source_payload;
        $payload['Details']['Pricing'] = [
            'ElectricitySupplyProductId' => 'api-contract-1',
            'PriceComponents' => [[
                'Id' => 'unsafe-component-1',
                'PriceComponentType' => 'General',
                'HasDiscount' => false,
                'OriginalPayment' => ['Price' => 1.25, 'PaymentUnit' => 'CentPerKiwattHour'],
            ]],
        ];
        $snapshot->update(['source_payload' => $payload]);
        $output = $this->validOutput($snapshot->contract_id);
        $output['source_consistency']['structured_pricing_status'] = 'incomplete';
        $output['source_consistency']['misleading_first_12_months'] = 'detected';
        $output['calculation']['status'] = 'incomplete';
        $interpretation = $this->createInterpretation($snapshot, $output);

        $published = app(ContractInterpretationPublisher::class)->publish($interpretation);

        $contract = ElectricityContract::findOrFail($snapshot->contract_id);
        $this->assertTrue($published);
        $this->assertSame($output['pricing'], $contract->canonical_pricing);
        $this->assertDatabaseMissing('active_contracts', ['id' => $contract->id]);
        $interpretation->refresh();
        $this->assertFalse($interpretation->relational_pricing_published);
        $this->assertSame(ContractInterpretation::STATUS_PUBLISHED, $interpretation->status);

        Queue::fake();
        config()->set('contract_interpretation.enabled', true);
        config()->set('services.openrouter.api_key', 'test-key');
        Http::fake([
            'ev-shv-prod-app-wa-consumerapi1.azurewebsites.net/api/productlist/*' => Http::response(
                [$snapshot->source_payload],
                200,
            ),
        ]);
        $this->artisan('contracts:fetch', ['--postcodes' => '00100', '--skip-logos' => true])
            ->assertExitCode(0);

        $this->assertDatabaseMissing('active_contracts', ['id' => $contract->id]);
        $this->assertSame(0, PriceComponent::count());
    }

    public function test_older_result_cannot_replace_a_newer_source_version(): void
    {
        $snapshot = $this->createSnapshot();
        $interpretation = $this->createInterpretation($snapshot, $this->validOutput($snapshot->contract_id));
        ContractSourceSnapshot::create([
            'contract_id' => $snapshot->contract_id,
            'source_fingerprint' => str_repeat('b', 64),
            'source_payload' => $snapshot->source_payload,
            'first_observed_at' => '2026-07-24 10:00:00',
            'last_observed_at' => '2026-07-24 10:00:00',
        ]);

        $published = app(ContractInterpretationPublisher::class)->publish($interpretation);

        $this->assertFalse($published);
        $this->assertSame(ContractInterpretation::STATUS_SUPERSEDED, $interpretation->fresh()->status);
        $this->assertNull(ElectricityContract::findOrFail($snapshot->contract_id)->published_interpretation_id);
    }

    private function createSnapshot(): ContractSourceSnapshot
    {
        Company::create(['name' => 'Test Energy', 'name_slug' => 'test-energy']);
        ElectricityContract::create([
            'id' => 'contract-1',
            'api_id' => 'api-contract-1',
            'company_name' => 'Test Energy',
            'name' => 'Test contract',
            'contract_type' => 'OpenEnded',
            'metering' => 'General',
            'pricing_model' => 'Spot',
            'availability_is_national' => true,
        ]);

        return ContractSourceSnapshot::create([
            'contract_id' => 'contract-1',
            'source_fingerprint' => str_repeat('a', 64),
            'source_payload' => [
                'Id' => 'api-contract-1',
                'Name' => 'Test contract',
                'Company' => ['Name' => 'Test Energy'],
                'Details' => [
                    'ContractType' => 'OpenEnded',
                    'PricingModel' => 'Spot',
                    'Metering' => 'General',
                    'Pricing' => ['PriceComponents' => []],
                    'ExtraInformation' => ['FI' => 'Test contract source text.'],
                ],
            ],
            'first_observed_at' => '2026-07-23 10:00:00',
            'last_observed_at' => '2026-07-23 10:00:00',
        ]);
    }

    /**
     * @param  array<string, mixed>|null  $output
     */
    private function createInterpretation(
        ContractSourceSnapshot $snapshot,
        ?array $output = null,
    ): ContractInterpretation {
        return ContractInterpretation::create([
            'contract_id' => $snapshot->contract_id,
            'source_snapshot_id' => $snapshot->id,
            'analysis_fingerprint' => hash('sha256', $snapshot->source_fingerprint.microtime(true)),
            'status' => ContractInterpretation::STATUS_PENDING,
            'schema_version' => 'schema-v2',
            'prompt_version' => 'prompt-v5',
            'provider' => 'openrouter',
            'model' => 'test-model',
            'output' => $output,
        ]);
    }

    /**
     * @param  array<string, mixed>  $classificationOverrides
     * @return array<string, mixed>
     */
    private function validOutput(string $contractId, array $classificationOverrides = []): array
    {
        $classification = array_merge([
            'term_type' => 'OpenEnded',
            'fixed_duration_months' => null,
            'primary_pricing_model' => 'Spot',
            'pricing_mechanisms' => ['spot'],
            'metering' => 'General',
            'spot_settlement_interval' => 'unknown',
            'periodic_reset_cadence' => 'none',
            'schedule_kinds' => ['standard'],
        ], $classificationOverrides);
        $hasCorrection = $classification['primary_pricing_model'] !== 'Spot'
            || $classification['term_type'] !== 'OpenEnded'
            || $classification['metering'] !== 'General';

        return [
            'schema_version' => '1.0',
            'contract_id' => $contractId,
            'classification' => $classification,
            'pricing' => [
                'phases' => [],
                'recurring_schedule' => [
                    'present' => false,
                    'cadence' => 'none',
                    'current_period_start' => null,
                    'current_period_end' => null,
                    'future_price_known' => null,
                    'description' => null,
                    'evidence' => [],
                ],
                'consumption_effect' => [
                    'present' => false,
                    'applies_to' => 'unknown',
                    'cadence' => 'none',
                    'expected_cents_per_kwh' => null,
                    'typical_min_cents_per_kwh' => null,
                    'typical_max_cents_per_kwh' => null,
                    'hard_min_cents_per_kwh' => null,
                    'hard_max_cents_per_kwh' => null,
                    'uncapped' => null,
                    'description' => null,
                    'evidence' => [],
                ],
            ],
            'source_consistency' => [
                'pricing_model_status' => $classification['primary_pricing_model'] === 'Spot' ? 'match' : 'mismatch',
                'recommended_pricing_model' => $classification['primary_pricing_model'],
                'contract_type_status' => $classification['term_type'] === 'OpenEnded' ? 'match' : 'mismatch',
                'recommended_contract_type' => $classification['term_type'],
                'metering_status' => $classification['metering'] === 'General' ? 'match' : 'mismatch',
                'recommended_metering' => $classification['metering'],
                'structured_pricing_status' => 'not_assessable',
                'misleading_first_12_months' => 'not_assessable',
                'issue_codes' => ['insufficient_evidence'],
                'summary' => 'No independent description evidence.',
                'evidence' => $hasCorrection ? [[
                    'source' => 'extra_information_fi',
                    'quote' => 'Test contract source text.',
                ]] : [],
            ],
            'calculation' => [
                'status' => 'estimate_required',
                'missing_facts' => [],
                'required_assumptions' => [],
            ],
            'confidence' => [
                'classification' => 'high',
                'pricing' => 'high',
                'integrity' => 'high',
            ],
        ];
    }
}
