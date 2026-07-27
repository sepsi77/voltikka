<?php

namespace Tests\Feature;

use App\Jobs\AnalyzeContractSourceSnapshot;
use App\Models\Company;
use App\Models\ContractInterpretation;
use App\Models\ContractSourceSnapshot;
use App\Models\ElectricityContract;
use App\Models\PriceComponent;
use App\Services\ContractInterpretation\CanonicalPriceComponentWriter;
use App\Services\ContractInterpretation\ContractInterpretationDispatcher;
use App\Services\ContractInterpretation\ContractInterpretationInputBuilder;
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

    public function test_validator_version_changes_the_analysis_fingerprint(): void
    {
        Queue::fake();
        config()->set('contract_interpretation.enabled', true);
        config()->set('services.openrouter.api_key', 'test-key');
        $snapshot = $this->createSnapshot();
        $dispatcher = app(ContractInterpretationDispatcher::class);

        $first = $dispatcher->dispatch($snapshot);
        config()->set('contract_interpretation.validator_version', 'validator-next');
        $second = $dispatcher->dispatch($snapshot);

        $this->assertNotSame($first->id, $second->id);
        $this->assertSame(2, ContractInterpretation::count());
        Queue::assertPushed(AnalyzeContractSourceSnapshot::class, 2);
    }

    public function test_reasoning_effort_changes_the_analysis_fingerprint(): void
    {
        Queue::fake();
        config()->set('contract_interpretation.enabled', true);
        config()->set('contract_interpretation.reasoning_effort', 'low');
        config()->set('services.openrouter.api_key', 'test-key');
        $snapshot = $this->createSnapshot();
        $dispatcher = app(ContractInterpretationDispatcher::class);

        $first = $dispatcher->dispatch($snapshot);
        config()->set('contract_interpretation.reasoning_effort', 'medium');
        $second = $dispatcher->dispatch($snapshot);

        $this->assertNotSame($first->id, $second->id);
        $this->assertSame(2, ContractInterpretation::count());
        Queue::assertPushed(AnalyzeContractSourceSnapshot::class, 2);
    }

    public function test_client_requests_strict_structured_output(): void
    {
        config()->set('services.openrouter.api_key', 'test-key');
        config()->set('contract_interpretation.reasoning_effort', 'medium');
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

            $input = json_decode(data_get($body, 'messages.1.content'), true);

            return $request->hasHeader('Authorization', 'Bearer test-key')
                && data_get($body, 'response_format.type') === 'json_schema'
                && data_get($body, 'response_format.json_schema.strict') === true
                && data_get($body, 'reasoning.effort') === 'medium'
                && data_get($input, 'contract_id') === 'contract-1'
                && ! array_key_exists('contract', $input);
        });
    }

    public function test_client_sends_validation_errors_for_a_complete_repair(): void
    {
        config()->set('services.openrouter.api_key', 'test-key');
        Http::fake([
            '*/chat/completions' => Http::response([
                'choices' => [[
                    'message' => ['content' => json_encode(['schema_version' => '1.0'])],
                ]],
            ]),
        ]);

        app(OpenRouterContractInterpretationClient::class)->repair(
            ['analysis_date' => '2026-07-23', 'contract_id' => 'contract-1'],
            ['schema_version' => 'wrong'],
            ['$.schema_version must equal the schema constant.'],
        );

        Http::assertSent(function (Request $request): bool {
            $messages = $request->data()['messages'];
            $repair = json_decode($messages[3]['content'], true);

            return count($messages) === 4
                && $messages[2]['role'] === 'assistant'
                && data_get($repair, 'validation_errors.0') === '$.schema_version must equal the schema constant.'
                && str_contains(data_get($repair, 'requirements.0'), 'complete corrected JSON');
        });
    }

    public function test_input_builder_normalizes_description_html_without_changing_case(): void
    {
        $snapshot = $this->createSnapshot();
        $payload = $snapshot->source_payload;
        $payload['Details']['ExtraInformation']['FI'] = 'Hinta&nbsp;on <b>5,49</b> snt/kWh.<br>MARGINAALI';
        $snapshot->update(['source_payload' => $payload]);

        $input = app(ContractInterpretationInputBuilder::class)->build($snapshot->fresh());

        $this->assertSame('Hinta on 5,49 snt/kWh. MARGINAALI', $input['extra_information_fi']);
        $this->assertSame(
            'Hinta&nbsp;on <b>5,49</b> snt/kWh.<br>MARGINAALI',
            $snapshot->fresh()->source_payload['Details']['ExtraInformation']['FI'],
        );
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
            'package' => null,
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

    public function test_validator_accepts_a_deterministically_derived_structured_discount(): void
    {
        $output = $this->validOutput('contract-1');
        $output['pricing']['phases'] = [[
            'label' => 'Free first three months',
            'phase_kind' => 'introductory',
            'starts' => ['kind' => 'contract_start', 'value' => null],
            'ends' => ['kind' => 'after_months', 'value' => '3'],
            'package' => null,
            'components' => [[
                'component_type' => 'monthly_fee',
                'amount' => 2.45,
                'normal_amount' => 4.9,
                'unit' => 'eur_per_month',
                'vat_status' => 'unknown',
                'price_role' => 'introductory',
                'source_kind' => 'structured',
                'evidence' => collect([
                    'price' => 4.9,
                    'has_discount' => true,
                    'discount_value' => 50,
                    'discount_is_percentage' => true,
                    'discount_type' => 'NFirstMonth',
                    'discount_n_first_months' => 3,
                ])->map(fn (mixed $value, string $field): array => [
                    'source' => "components[0].{$field}",
                    'quote' => "components[0].{$field}=".json_encode($value),
                ])->values()->all(),
            ]],
            'evidence' => [],
        ], [
            'label' => 'Normal monthly fee',
            'phase_kind' => 'normal',
            'starts' => ['kind' => 'after_months', 'value' => '3'],
            'ends' => ['kind' => 'none', 'value' => null],
            'package' => null,
            'components' => [[
                'component_type' => 'monthly_fee',
                'amount' => 4.9,
                'normal_amount' => null,
                'unit' => 'eur_per_month',
                'vat_status' => 'unknown',
                'price_role' => 'normal',
                'source_kind' => 'structured',
                'evidence' => [[
                    'source' => 'components[0].price',
                    'quote' => 'components[0].price=4.9',
                ]],
            ]],
            'evidence' => [],
        ]];

        $input = [
            'contract_id' => 'contract-1',
            'pricing_model' => 'Spot',
            'contract_type' => 'OpenEnded',
            'metering' => 'General',
            'components' => [[
                'price_component_type' => 'Monthly',
                'price' => 4.9,
                'has_discount' => true,
                'discount_value' => 50,
                'discount_is_percentage' => true,
                'discount_type' => 'NFirstMonth',
                'discount_n_first_months' => 3,
            ]],
        ];
        $this->assertSame([], app(ContractInterpretationValidator::class)->validate($output, $input));

        $output['pricing']['phases'][0]['starts'] = ['kind' => 'after_months', 'value' => '0'];
        $this->assertSame([], app(ContractInterpretationValidator::class)->validate($output, $input));

        $missingDiscountPhase = $output;
        array_shift($missingDiscountPhase['pricing']['phases']);
        $this->assertContains(
            '$.pricing.phases must represent the active structured discount from components[0] for the first 3 months.',
            app(ContractInterpretationValidator::class)->validate($missingDiscountPhase, $input),
        );

        $input['components'][0]['has_discount'] = false;
        $this->assertContains(
            '$.pricing.phases[0] must not use inactive discount timing from components[0] when has_discount is false.',
            app(ContractInterpretationValidator::class)->validate($output, $input),
        );
    }

    public function test_surffari_active_until_date_margin_discount_cannot_disappear(): void
    {
        $fixture = json_decode(
            file_get_contents(base_path('tests/Fixtures/surffari-active-until-date-discount.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $validator = app(ContractInterpretationValidator::class);

        $faultyErrors = $validator->validate($fixture['faulty_output'], $fixture['input']);
        $this->assertContains(
            '$.pricing.phases must represent the active structured discount from components[1] through 2026-08-31.',
            $faultyErrors,
        );
        $this->assertSame([], $validator->validate($fixture['corrected_output'], $fixture['input']));
    }

    public function test_validator_rejects_wrong_discount_scope_amount_and_missing_normal_continuation(): void
    {
        $fixture = json_decode(
            file_get_contents(base_path('tests/Fixtures/surffari-active-until-date-discount.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $validator = app(ContractInterpretationValidator::class);

        $wrongScope = $fixture['corrected_output'];
        $wrongScope['pricing']['phases'][0]['components'][0]['component_type'] = 'monthly_fee';
        $this->assertContains(
            '$.pricing.phases must represent the active structured discount from components[1] on spot_margin; another component scope cannot satisfy it.',
            $validator->validate($wrongScope, $fixture['input']),
        );

        $wrongAmount = $fixture['corrected_output'];
        $wrongAmount['pricing']['phases'][0]['components'][0]['amount'] = 0.21;
        $wrongAmount['pricing']['phases'][0]['components'][0]['evidence'][] = [
            'source' => 'extra_information_fi',
            'quote' => 'Hinnat sisältävät ALV 25,5 %.',
        ];
        $this->assertContains(
            '$.pricing.phases must represent the active structured discount from components[1] with amount 0.2 and normal_amount 0.6.',
            $validator->validate($wrongAmount, $fixture['input']),
        );

        $missingContinuation = $fixture['corrected_output'];
        array_pop($missingContinuation['pricing']['phases']);
        $this->assertContains(
            '$.pricing.phases must continue components[1] as spot_margin at the known normal amount 0.6 after the structured discount ends.',
            $validator->validate($missingContinuation, $fixture['input']),
        );
    }

    public function test_validator_ignores_expired_and_inactive_structured_discount_metadata(): void
    {
        $input = [
            'analysis_date' => '2026-09-01',
            'contract_id' => 'contract-1',
            'pricing_model' => 'Spot',
            'contract_type' => 'OpenEnded',
            'metering' => 'General',
            'components' => [[
                'price_component_type' => 'General',
                'price' => 0.6,
                'has_discount' => true,
                'discount_value' => 0.4,
                'discount_is_percentage' => false,
                'discount_type' => 'UntilDate',
                'discount_until_date' => '2026-08-31T00:00:00',
            ]],
        ];
        $output = $this->validOutput('contract-1');
        $output['pricing']['phases'] = [$this->normalSpotMarginPhase()];
        $output['source_consistency']['structured_pricing_status'] = 'complete';
        $output['source_consistency']['misleading_first_12_months'] = 'not_detected';
        $output['source_consistency']['issue_codes'] = [];

        $validator = app(ContractInterpretationValidator::class);
        $this->assertSame([], $validator->validate($output, $input));

        // Stale expired metadata can be incomplete. It is historical and must be
        // ignored before active-discount amount validation.
        $input['components'][0]['discount_value'] = null;
        $this->assertSame([], $validator->validate($output, $input));

        $input['analysis_date'] = '2026-07-23';
        $input['components'][0]['discount_value'] = 0.4;
        $input['components'][0]['has_discount'] = false;
        $this->assertSame([], $validator->validate($output, $input));
    }

    public function test_validator_maps_spot_margin_discount_from_every_source_tariff_slot(): void
    {
        $validator = app(ContractInterpretationValidator::class);

        foreach (['General', 'DayTime', 'NightTime', 'SeasonalWinter', 'SeasonalWinterDay', 'SeasonalOther'] as $sourceType) {
            $input = [
                'analysis_date' => '2026-07-23',
                'contract_id' => 'contract-1',
                'pricing_model' => 'Spot',
                'contract_type' => 'OpenEnded',
                'metering' => 'General',
                'components' => [[
                    'price_component_type' => $sourceType,
                    'price' => 0.6,
                    'has_discount' => true,
                    'discount_value' => 0.4,
                    'discount_is_percentage' => false,
                    'discount_type' => 'UntilDate',
                    'discount_until_date' => '2026-08-31T00:00:00',
                ]],
            ];
            $output = $this->validOutput('contract-1');
            $output['pricing']['phases'] = $this->activeSpotMarginDiscountPhases();

            $this->assertSame(
                [],
                $validator->validate($output, $input),
                "Spot source slot {$sourceType} must map its active discount to spot_margin.",
            );
        }
    }

    public function test_validator_rejects_active_discount_when_amount_or_timing_is_unsafe(): void
    {
        $output = $this->validOutput('contract-1');
        $output['pricing']['phases'] = [$this->normalSpotMarginPhase()];
        $input = [
            'analysis_date' => '2026-07-23',
            'contract_id' => 'contract-1',
            'pricing_model' => 'Spot',
            'contract_type' => 'OpenEnded',
            'metering' => 'General',
            'components' => [[
                'price_component_type' => 'General',
                'price' => 0.6,
                'has_discount' => true,
                'discount_value' => null,
                'discount_is_percentage' => false,
                'discount_type' => 'UntilDate',
                'discount_until_date' => '2026-08-31T00:00:00',
            ]],
        ];
        $validator = app(ContractInterpretationValidator::class);

        $this->assertContains(
            'components[0] has an active structured discount whose amount cannot be represented safely.',
            $validator->validate($output, $input),
        );

        $input['components'][0]['discount_value'] = 0.4;
        $input['components'][0]['discount_until_date'] = null;
        $this->assertContains(
            'components[0] has an active UntilDate discount whose timing cannot be represented safely.',
            $validator->validate($output, $input),
        );
    }

    public function test_validator_rejects_spot_fixed_fee_as_a_fixed_or_package_mechanism(): void
    {
        $output = $this->validOutput('contract-1');
        $output['classification']['pricing_mechanisms'] = ['spot', 'fixed', 'flat_fee_or_package'];
        $output['pricing']['phases'] = [[
            'label' => 'Spot price',
            'phase_kind' => 'current_structured',
            'starts' => ['kind' => 'contract_start', 'value' => null],
            'ends' => ['kind' => 'none', 'value' => null],
            'package' => null,
            'components' => [[
                'component_type' => 'spot_margin',
                'amount' => 0.49,
                'normal_amount' => null,
                'unit' => 'cents_per_kwh',
                'vat_status' => 'unknown',
                'price_role' => 'current',
                'source_kind' => 'structured',
                'evidence' => [['source' => 'components[0].price', 'quote' => 'components[0].price=0.49']],
            ], [
                'component_type' => 'monthly_fee',
                'amount' => 4.9,
                'normal_amount' => null,
                'unit' => 'eur_per_month',
                'vat_status' => 'unknown',
                'price_role' => 'current',
                'source_kind' => 'structured',
                'evidence' => [['source' => 'components[1].price', 'quote' => 'components[1].price=4.9']],
            ]],
            'evidence' => [],
        ]];

        $errors = app(ContractInterpretationValidator::class)->validate($output, [
            'contract_id' => 'contract-1',
            'pricing_model' => 'Spot',
            'contract_type' => 'OpenEnded',
            'metering' => 'General',
            'components' => [
                ['price_component_type' => 'General', 'price' => 0.49],
                ['price_component_type' => 'Monthly', 'price' => 4.9],
            ],
        ]);

        $this->assertContains(
            '$.classification.pricing_mechanisms contains fixed without a fixed energy-price component.',
            $errors,
        );
        $this->assertContains(
            '$.classification.pricing_mechanisms contains flat_fee_or_package without a flat_fee component or package.',
            $errors,
        );
    }

    public function test_validator_requires_quarterly_reset_when_the_source_names_kvartaalisahko(): void
    {
        $output = $this->validOutput('contract-1');

        $errors = app(ContractInterpretationValidator::class)->validate($output, [
            'contract_id' => 'contract-1',
            'contract_name' => 'Kvartaalisähkö (aika)',
            'pricing_model' => 'Spot',
            'contract_type' => 'OpenEnded',
            'metering' => 'General',
            'components' => [],
        ]);

        $this->assertContains(
            '$.classification.pricing_mechanisms must contain periodic_market_reset because the source explicitly describes recurring price resets.',
            $errors,
        );
        $this->assertContains(
            '$.pricing.recurring_schedule must be present with quarterly cadence because the source explicitly describes that reset schedule.',
            $errors,
        );
        $this->assertContains(
            '$.classification.periodic_reset_cadence must be quarterly because the source explicitly describes that reset schedule.',
            $errors,
        );
    }

    public function test_validator_keeps_periodic_mechanism_schedule_cadence_and_calculation_consistent(): void
    {
        $output = $this->validOutput('contract-1');
        $output['classification']['pricing_mechanisms'] = ['spot', 'periodic_market_reset'];
        $output['classification']['periodic_reset_cadence'] = 'quarterly';
        $output['pricing']['recurring_schedule'] = [
            'present' => true,
            'cadence' => 'quarterly',
            'current_period_start' => null,
            'current_period_end' => null,
            'future_price_known' => false,
            'description' => 'Price resets each quarter.',
            'evidence' => [],
        ];
        $output['calculation']['status'] = 'exact';
        $input = [
            'contract_id' => 'contract-1',
            'contract_name' => 'Kvartaalisähkö',
            'pricing_model' => 'Spot',
            'contract_type' => 'OpenEnded',
            'metering' => 'General',
            'components' => [],
        ];

        $errors = app(ContractInterpretationValidator::class)->validate($output, $input);

        $this->assertContains(
            '$.calculation.status cannot be exact when a recurring future price is unknown.',
            $errors,
        );

        $output['calculation']['status'] = 'estimate_required';
        $this->assertSame([], app(ContractInterpretationValidator::class)->validate($output, $input));

        $output['source_consistency']['structured_pricing_status'] = 'incomplete';
        $output['source_consistency']['misleading_first_12_months'] = 'uncertain';
        $output['source_consistency']['issue_codes'] = ['recurring_reset_requires_estimate'];
        $errors = app(ContractInterpretationValidator::class)->validate($output, $input);
        $this->assertContains(
            '$.source_consistency.structured_pricing_status cannot be incomplete solely because recurring future market prices are unknown.',
            $errors,
        );

        $output['source_consistency']['structured_pricing_status'] = 'complete';
        $this->assertSame([], app(ContractInterpretationValidator::class)->validate($output, $input));
    }

    public function test_validator_rejects_pricing_phases_that_expired_before_analysis_date(): void
    {
        $output = $this->validOutput('contract-1');
        $output['pricing']['phases'] = [[
            'label' => 'Expired promotion',
            'phase_kind' => 'introductory',
            'starts' => ['kind' => 'unknown', 'value' => null],
            'ends' => ['kind' => 'date', 'value' => '2026-03-31'],
            'package' => null,
            'components' => [[
                'component_type' => 'monthly_fee',
                'amount' => 0,
                'normal_amount' => null,
                'unit' => 'eur_per_month',
                'vat_status' => 'unknown',
                'price_role' => 'introductory',
                'source_kind' => 'description',
                'evidence' => [[
                    'source' => 'extra_information_fi',
                    'quote' => 'Promotion 0 € until 31.3.2026.',
                ]],
            ]],
            'evidence' => [[
                'source' => 'extra_information_fi',
                'quote' => 'Promotion 0 € until 31.3.2026.',
            ]],
        ]];

        $errors = app(ContractInterpretationValidator::class)->validate($output, [
            'analysis_date' => '2026-07-23',
            'contract_id' => 'contract-1',
            'pricing_model' => 'Spot',
            'contract_type' => 'OpenEnded',
            'metering' => 'General',
            'extra_information_fi' => 'Promotion 0 € until 31.3.2026.',
            'components' => [],
        ]);

        $this->assertContains(
            '$.pricing.phases[0] ends before analysis_date and must not affect the current interpretation.',
            $errors,
        );
    }

    public function test_validator_rejects_uncertain_warning_without_a_directional_issue(): void
    {
        $output = $this->validOutput('contract-1');
        $output['source_consistency']['structured_pricing_status'] = 'complete';
        $output['source_consistency']['misleading_first_12_months'] = 'uncertain';
        $output['source_consistency']['issue_codes'] = ['structured_matches_description'];
        $output['calculation']['status'] = 'estimate_required';
        $input = [
            'contract_id' => 'contract-1',
            'pricing_model' => 'Spot',
            'contract_type' => 'OpenEnded',
            'metering' => 'General',
            'components' => [],
        ];

        $errors = app(ContractInterpretationValidator::class)->validate($output, $input);
        $this->assertContains(
            '$.source_consistency.misleading_first_12_months must be not_detected when pricing is complete with no directional issue.',
            $errors,
        );

        $output['source_consistency']['misleading_first_12_months'] = 'not_detected';
        $this->assertSame([], app(ContractInterpretationValidator::class)->validate($output, $input));
    }

    public function test_validator_treats_complete_structured_only_spot_pricing_as_assessable(): void
    {
        $output = $this->validOutput('contract-1');
        $output['pricing']['phases'] = [[
            'label' => 'Current pricing',
            'phase_kind' => 'current_structured',
            'starts' => ['kind' => 'contract_start', 'value' => null],
            'ends' => ['kind' => 'none', 'value' => null],
            'package' => null,
            'components' => [[
                'component_type' => 'spot_margin',
                'amount' => 0.36,
                'normal_amount' => null,
                'unit' => 'cents_per_kwh',
                'vat_status' => 'unknown',
                'price_role' => 'current',
                'source_kind' => 'structured',
                'evidence' => [['source' => 'components[0].price', 'quote' => 'components[0].price=0.36']],
            ], [
                'component_type' => 'monthly_fee',
                'amount' => 3.59,
                'normal_amount' => null,
                'unit' => 'eur_per_month',
                'vat_status' => 'unknown',
                'price_role' => 'current',
                'source_kind' => 'structured',
                'evidence' => [['source' => 'components[1].price', 'quote' => 'components[1].price=3.59']],
            ]],
            'evidence' => [],
        ]];
        $input = [
            'contract_id' => 'contract-1',
            'pricing_model' => 'Spot',
            'contract_type' => 'OpenEnded',
            'metering' => 'General',
            'components' => [
                ['price_component_type' => 'General', 'price' => 0.36, 'has_discount' => false],
                ['price_component_type' => 'Monthly', 'price' => 3.59, 'has_discount' => false],
            ],
        ];

        $errors = app(ContractInterpretationValidator::class)->validate($output, $input);
        $this->assertContains(
            '$.source_consistency.structured_pricing_status must be complete when recognized non-discounted structured components contain all available pricing facts.',
            $errors,
        );
        $this->assertContains(
            '$.source_consistency.misleading_first_12_months must be not_detected for complete structured-only pricing.',
            $errors,
        );
        $this->assertContains(
            '$.source_consistency.issue_codes must not contain insufficient_evidence only because descriptive pricing text is absent.',
            $errors,
        );

        $output['source_consistency']['structured_pricing_status'] = 'complete';
        $output['source_consistency']['misleading_first_12_months'] = 'not_detected';
        $output['source_consistency']['issue_codes'] = [];
        $this->assertSame([], app(ContractInterpretationValidator::class)->validate($output, $input));
    }

    public function test_validator_accepts_symmetric_numeric_evidence_for_both_bounds(): void
    {
        foreach (['+/-1,5', '±1.5', '+−1,5'] as $notation) {
            $quote = "Kulutusvaikutus on tyypillisesti {$notation} snt/kWh.";
            $output = $this->validOutput('contract-1');
            $output['classification']['pricing_mechanisms'] = ['spot', 'consumption_effect'];
            $output['pricing']['consumption_effect'] = [
                'present' => true,
                'applies_to' => 'base_contract',
                'cadence' => 'monthly',
                'expected_cents_per_kwh' => null,
                'typical_min_cents_per_kwh' => -1.5,
                'typical_max_cents_per_kwh' => 1.5,
                'hard_min_cents_per_kwh' => null,
                'hard_max_cents_per_kwh' => null,
                'uncapped' => false,
                'description' => $quote,
                'evidence' => [['source' => 'extra_information_fi', 'quote' => $quote]],
            ];

            $errors = app(ContractInterpretationValidator::class)->validate($output, [
                'contract_id' => 'contract-1',
                'pricing_model' => 'Spot',
                'contract_type' => 'OpenEnded',
                'metering' => 'General',
                'extra_information_fi' => $quote,
                'components' => [],
            ]);

            $this->assertNotContains(
                '$.pricing.consumption_effect.typical_min_cents_per_kwh lacks numeric evidence.',
                $errors,
            );
            $this->assertNotContains(
                '$.pricing.consumption_effect.typical_max_cents_per_kwh lacks numeric evidence.',
                $errors,
            );
        }
    }

    public function test_validator_does_not_treat_one_direction_as_symmetric_evidence(): void
    {
        $quote = 'Kulutusvaikutuksen alaraja on -1,5 snt/kWh.';
        $output = $this->validOutput('contract-1');
        $output['classification']['pricing_mechanisms'] = ['spot', 'consumption_effect'];
        $output['pricing']['consumption_effect'] = [
            'present' => true,
            'applies_to' => 'base_contract',
            'cadence' => 'monthly',
            'expected_cents_per_kwh' => null,
            'typical_min_cents_per_kwh' => -1.5,
            'typical_max_cents_per_kwh' => 1.5,
            'hard_min_cents_per_kwh' => null,
            'hard_max_cents_per_kwh' => null,
            'uncapped' => false,
            'description' => $quote,
            'evidence' => [['source' => 'extra_information_fi', 'quote' => $quote]],
        ];

        $errors = app(ContractInterpretationValidator::class)->validate($output, [
            'contract_id' => 'contract-1',
            'pricing_model' => 'Spot',
            'contract_type' => 'OpenEnded',
            'metering' => 'General',
            'extra_information_fi' => $quote,
            'components' => [],
        ]);

        $this->assertNotContains(
            '$.pricing.consumption_effect.typical_min_cents_per_kwh lacks numeric evidence.',
            $errors,
        );
        $this->assertContains(
            '$.pricing.consumption_effect.typical_max_cents_per_kwh lacks numeric evidence.',
            $errors,
        );
    }

    public function test_validator_requires_flat_package_taxonomy_for_package_source_pattern(): void
    {
        $output = $this->validOutput('contract-1', [
            'primary_pricing_model' => 'FixedPrice',
            'pricing_mechanisms' => ['fixed'],
        ]);
        $output['pricing']['phases'] = [[
            'label' => 'Package',
            'phase_kind' => 'current_structured',
            'starts' => ['kind' => 'contract_start', 'value' => null],
            'ends' => ['kind' => 'none', 'value' => null],
            'package' => null,
            'components' => [[
                'component_type' => 'monthly_fee',
                'amount' => 55.9,
                'normal_amount' => null,
                'unit' => 'eur_per_month',
                'vat_status' => 'unknown',
                'price_role' => 'current',
                'source_kind' => 'structured',
                'evidence' => [['source' => 'components[0].price', 'quote' => 'components[0].price=55.9']],
            ], [
                'component_type' => 'energy_general',
                'amount' => 0,
                'normal_amount' => null,
                'unit' => 'cents_per_kwh',
                'vat_status' => 'unknown',
                'price_role' => 'current',
                'source_kind' => 'structured',
                'evidence' => [['source' => 'components[1].price', 'quote' => 'components[1].price=0']],
            ]],
            'evidence' => [],
        ]];

        $errors = app(ContractInterpretationValidator::class)->validate($output, [
            'contract_id' => 'contract-1',
            'contract_name' => 'Helpposähkö L',
            'pricing_name' => 'Helpposähkö L',
            'pricing_model' => 'FixedPrice',
            'contract_type' => 'OpenEnded',
            'metering' => 'General',
            'extra_information_fi' => 'Tilaa Helpposähkö L-paketti tästä.',
            'consumption_limitation' => ['MaxXKWhPerY' => 3600],
            'components' => [
                ['price_component_type' => 'Monthly', 'price' => 55.9],
                ['price_component_type' => 'General', 'price' => 0],
            ],
        ]);

        $this->assertContains(
            '$.classification.pricing_mechanisms must contain flat_fee_or_package because the source explicitly describes a consumption package.',
            $errors,
        );
        $this->assertContains(
            '$.pricing.phases must map the package Monthly charge to a flat_fee component.',
            $errors,
        );
        $this->assertContains('$.pricing.phases is missing structured component type flat_fee.', $errors);
        $this->assertContains(
            '$.pricing.phases must not represent zero-price included package energy as energy_general.',
            $errors,
        );
        $this->assertContains(
            '$.classification.pricing_mechanisms must not contain fixed for a package without a positive fixed energy-price component.',
            $errors,
        );
        $this->assertNotContains('$.pricing.phases is missing structured component type energy_general.', $errors);

        $output['classification']['pricing_mechanisms'] = ['flat_fee_or_package'];
        $output['pricing']['phases'][0]['components'][0]['component_type'] = 'flat_fee';
        $output['pricing']['phases'][0]['components'][1] = $output['pricing']['phases'][0]['components'][0];
        $output['pricing']['phases'][0]['components'][1]['amount'] = null;
        $errors = app(ContractInterpretationValidator::class)->validate($output, [
            'contract_id' => 'contract-1',
            'contract_name' => 'Helpposähkö L',
            'pricing_name' => 'Helpposähkö L',
            'pricing_model' => 'FixedPrice',
            'contract_type' => 'OpenEnded',
            'metering' => 'General',
            'extra_information_fi' => 'Tilaa Helpposähkö L-paketti tästä.',
            'consumption_limitation' => ['MaxXKWhPerY' => 3600],
            'components' => [
                ['price_component_type' => 'Monthly', 'price' => 55.9],
                ['price_component_type' => 'General', 'price' => 0],
            ],
        ]);
        $this->assertContains(
            '$.pricing.phases must not create an unknown flat_fee placeholder from included package energy.',
            $errors,
        );
    }

    public function test_validator_requires_flat_package_taxonomy_for_explicit_excess_use_package(): void
    {
        $output = $this->validOutput('contract-1', [
            'primary_pricing_model' => 'FixedPrice',
            'pricing_mechanisms' => ['fixed', 'periodic_market_reset'],
            'periodic_reset_cadence' => 'other',
        ]);
        $output['pricing']['phases'] = [[
            'label' => 'Current package price',
            'phase_kind' => 'current_structured',
            'starts' => ['kind' => 'contract_start', 'value' => null],
            'ends' => ['kind' => 'none', 'value' => null],
            'package' => null,
            'components' => [[
                'component_type' => 'monthly_fee',
                'amount' => 9,
                'normal_amount' => null,
                'unit' => 'eur_per_month',
                'vat_status' => 'unknown',
                'price_role' => 'current',
                'source_kind' => 'structured',
                'evidence' => [['source' => 'components[0].price', 'quote' => 'components[0].price=9']],
            ], [
                'component_type' => 'energy_general',
                'amount' => 8.87,
                'normal_amount' => null,
                'unit' => 'cents_per_kwh',
                'vat_status' => 'unknown',
                'price_role' => 'current',
                'source_kind' => 'structured',
                'evidence' => [['source' => 'components[1].price', 'quote' => 'components[1].price=8.87']],
            ]],
            'evidence' => [],
        ]];
        $output['pricing']['recurring_schedule'] = [
            'present' => true,
            'cadence' => 'other',
            'current_period_start' => null,
            'current_period_end' => null,
            'future_price_known' => false,
            'description' => 'Price updates at least twice each year.',
            'evidence' => [],
        ];

        $input = [
            'contract_id' => 'contract-1',
            'contract_name' => 'Louna Helppo XS',
            'pricing_name' => 'Louna Helppo XS',
            'pricing_model' => 'FixedPrice',
            'contract_type' => 'OpenEnded',
            'metering' => 'General',
            'extra_information_fi' => 'Maksat kiinteän kuukausimaksun, joka sisältää valitsemasi paketin mukaisen määrän energiaa kuukaudessa. Kuukausirajan ylittävästä energiasta laskutamme yleissähkön hinnalla.',
            'consumption_limitation' => ['MaxXKWhPerY' => null],
            'components' => [
                ['price_component_type' => 'Monthly', 'price' => 9],
                ['price_component_type' => 'General', 'price' => 8.87],
            ],
        ];

        $errors = app(ContractInterpretationValidator::class)->validate($output, $input);
        $this->assertContains(
            '$.classification.pricing_mechanisms must contain flat_fee_or_package because the source explicitly describes a consumption package.',
            $errors,
        );
        $this->assertContains(
            '$.pricing.phases must map the package Monthly charge to a flat_fee component.',
            $errors,
        );
        $this->assertContains('$.pricing.phases is missing structured component type flat_fee.', $errors);
        $this->assertNotContains(
            '$.classification.pricing_mechanisms must not contain fixed for a package without a positive fixed energy-price component.',
            $errors,
        );
        $this->assertNotContains('$.pricing.phases is missing structured component type energy_general.', $errors);
    }

    public function test_validator_accepts_typed_monthly_package_and_rejects_missing_or_duplicate_charges(): void
    {
        $input = [
            'contract_id' => 'contract-1',
            'contract_name' => 'Kuukausipaketti S',
            'pricing_name' => 'Kuukausipaketti S',
            'pricing_model' => 'FixedPrice',
            'contract_type' => 'OpenEnded',
            'metering' => 'General',
            'extra_information_fi' => 'Kuukausipaketti S sisältää 150 kWh sähköenergiaa kuukaudessa. Kuukausirajan ylittävästä energiasta laskutamme lisäenergian hinnalla 16,60 c/kWh.',
            'components' => [
                ['price_component_type' => 'Monthly', 'price' => 21],
                ['price_component_type' => 'General', 'price' => 16.6, 'has_discount' => true, 'discount_type' => 'NFirstKwh', 'discount_n_first_kwh' => 1800],
            ],
        ];
        $output = $this->validOutput('contract-1', [
            'primary_pricing_model' => 'FixedPrice',
            'pricing_mechanisms' => ['flat_fee_or_package', 'fixed'],
        ]);
        $output['pricing']['phases'] = [[
            'label' => 'Current monthly package',
            'phase_kind' => 'current_structured',
            'starts' => ['kind' => 'contract_start', 'value' => null],
            'ends' => ['kind' => 'none', 'value' => null],
            'components' => [],
            'package' => [
                'monthly_fee_eur' => 21,
                'included_kwh' => 150,
                'allowance_cadence' => 'monthly',
                'excess_rate_cents_per_kwh' => 16.6,
                'evidence' => [
                    ['source' => 'components[0].price', 'quote' => 'components[0].price=21'],
                    ['source' => 'components[1].price', 'quote' => 'components[1].price=16.6'],
                    ['source' => 'extra_information_fi', 'quote' => 'Kuukausipaketti S sisältää 150 kWh sähköenergiaa kuukaudessa.'],
                ],
            ],
            'evidence' => [],
        ]];
        $output['source_consistency']['pricing_model_status'] = 'match';
        $output['source_consistency']['evidence'] = [];
        $output['source_consistency']['structured_pricing_status'] = 'complete';
        $output['source_consistency']['misleading_first_12_months'] = 'not_detected';
        $output['source_consistency']['issue_codes'] = ['structured_matches_description'];
        $output['calculation']['status'] = 'exact';

        $this->assertSame([], app(ContractInterpretationValidator::class)->validate($output, $input));

        $missingAllowance = $output;
        unset($missingAllowance['pricing']['phases'][0]['package']['included_kwh']);
        $this->assertContains(
            '$.pricing.phases[0].package.included_kwh is required.',
            app(ContractInterpretationValidator::class)->validate($missingAllowance, $input),
        );

        $missingExcessRate = $output;
        unset($missingExcessRate['pricing']['phases'][0]['package']['excess_rate_cents_per_kwh']);
        $this->assertContains(
            '$.pricing.phases[0].package.excess_rate_cents_per_kwh is required.',
            app(ContractInterpretationValidator::class)->validate($missingExcessRate, $input),
        );

        $unsupportedCadence = $output;
        $unsupportedCadence['pricing']['phases'][0]['package']['allowance_cadence'] = 'annual';
        $this->assertContains(
            '$.pricing.phases[0].package.allowance_cadence has an unsupported value.',
            app(ContractInterpretationValidator::class)->validate($unsupportedCadence, $input),
        );

        $duplicateFee = $output;
        $duplicateFee['pricing']['phases'][0]['components'][] = [
            'component_type' => 'monthly_fee',
            'amount' => 21,
            'normal_amount' => null,
            'unit' => 'eur_per_month',
            'vat_status' => 'unknown',
            'price_role' => 'current',
            'source_kind' => 'structured',
            'evidence' => [['source' => 'components[0].price', 'quote' => 'components[0].price=21']],
        ];
        $duplicateErrors = app(ContractInterpretationValidator::class)->validate($duplicateFee, $input);
        $this->assertContains('$.pricing.phases[0] must not duplicate package charges as components.', $duplicateErrors);
        $this->assertContains('$.pricing.phases must not duplicate a monthly package fee or excess rate as components.', $duplicateErrors);
    }

    public function test_validator_retains_source_hybrid_without_explicit_contrary_evidence(): void
    {
        $output = $this->validOutput('contract-1', [
            'primary_pricing_model' => 'FixedPrice',
            'pricing_mechanisms' => ['fixed'],
        ]);
        $input = [
            'contract_id' => 'contract-1',
            'pricing_model' => 'Hybrid',
            'contract_type' => 'OpenEnded',
            'metering' => 'General',
            'extra_information_fi' => 'Test contract source text.',
            'components' => [],
        ];

        $errors = app(ContractInterpretationValidator::class)->validate($output, $input);
        $this->assertContains(
            '$.classification.primary_pricing_model must retain source Hybrid when no explicit contrary evidence exists.',
            $errors,
        );

        $input['extra_information_fi'] = 'Test contract source text. Sopimus on ilman kulutusvaikutusta.';
        $this->assertNotContains(
            '$.classification.primary_pricing_model must retain source Hybrid when no explicit contrary evidence exists.',
            app(ContractInterpretationValidator::class)->validate($output, $input),
        );
    }

    public function test_price_component_writer_prefers_first_positive_row_for_a_storage_key_collision(): void
    {
        $snapshot = $this->createSnapshot();
        $payload = $snapshot->source_payload;
        $payload['Details']['Pricing'] = [
            'ElectricitySupplyProductId' => 'api-contract-1',
            'PriceComponents' => [
                [
                    'Id' => '00000000-0000-0000-0000-000000000000',
                    'PriceComponentType' => 'General',
                    'FuseSize' => null,
                    'OriginalPayment' => ['Price' => 0, 'PaymentUnit' => 'CentPerKiwattHour'],
                ],
                [
                    'Id' => '00000000-0000-0000-0000-000000000000',
                    'PriceComponentType' => 'General',
                    'FuseSize' => null,
                    'OriginalPayment' => ['Price' => 6.6, 'PaymentUnit' => 'CentPerKiwattHour'],
                ],
            ],
        ];

        $written = app(CanonicalPriceComponentWriter::class)->write([$payload], '2026-07-23');

        $this->assertSame(1, $written);
        $component = PriceComponent::sole();
        $this->assertSame(md5('contract-1:General:null'), $component->id);
        $this->assertSame('2026-07-23', $component->price_date->toDateString());
        $this->assertSame('contract-1', $component->electricity_contract_id);
        $this->assertSame('General', $component->price_component_type);
        $this->assertSame(6.6, $component->price);
    }

    public function test_price_component_writer_safely_replaces_a_date_with_colliding_rows(): void
    {
        $snapshot = $this->createSnapshot();
        $payload = $snapshot->source_payload;
        $payload['Details']['Pricing'] = [
            'ElectricitySupplyProductId' => 'api-contract-1',
            'PriceComponents' => [
                [
                    'Id' => '00000000-0000-0000-0000-000000000000',
                    'PriceComponentType' => 'General',
                    'FuseSize' => null,
                    'OriginalPayment' => ['Price' => 5.5, 'PaymentUnit' => 'CentPerKiwattHour'],
                ],
                [
                    'Id' => '00000000-0000-0000-0000-000000000000',
                    'PriceComponentType' => 'Monthly',
                    'FuseSize' => null,
                    'OriginalPayment' => ['Price' => 4.9, 'PaymentUnit' => 'EurPerMonth'],
                ],
            ],
        ];
        $writer = app(CanonicalPriceComponentWriter::class);
        $this->assertSame(2, $writer->write([$payload], '2026-07-23'));

        $payload['Details']['Pricing']['PriceComponents'] = [
            [
                'Id' => '00000000-0000-0000-0000-000000000000',
                'PriceComponentType' => 'General',
                'FuseSize' => null,
                'OriginalPayment' => ['Price' => 6.6, 'PaymentUnit' => 'CentPerKiwattHour'],
            ],
            [
                'Id' => '00000000-0000-0000-0000-000000000000',
                'PriceComponentType' => 'General',
                'FuseSize' => null,
                'OriginalPayment' => ['Price' => 0, 'PaymentUnit' => 'CentPerKiwattHour'],
            ],
        ];

        $written = $writer->write([$payload], '2026-07-23');

        $this->assertSame(1, $written);
        $rows = PriceComponent::query()
            ->where('electricity_contract_id', 'contract-1')
            ->whereDate('price_date', '2026-07-23')
            ->get();
        $this->assertCount(1, $rows);
        $this->assertSame('General', $rows->sole()->price_component_type);
        $this->assertSame(6.6, $rows->sole()->price);
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
            'package' => null,
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
        config()->set('contract_interpretation.max_repair_attempts', 0);
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

    public function test_job_can_publish_after_two_model_correction_calls(): void
    {
        config()->set('contract_interpretation.max_repair_attempts', 2);
        $snapshot = $this->createSnapshot();
        $interpretation = $this->createInterpretation($snapshot);
        $valid = $this->validOutput($snapshot->contract_id);
        $client = $this->mock(OpenRouterContractInterpretationClient::class);
        $client->shouldReceive('interpret')->once()->andReturn($this->llmResult(['schema_version' => 'wrong'], 10));
        $client->shouldReceive('repair')->twice()->andReturn(
            $this->llmResult(['schema_version' => 'still-wrong'], 20),
            $this->llmResult($valid, 30),
        );

        app()->call([new AnalyzeContractSourceSnapshot($interpretation->id), 'handle']);

        $interpretation->refresh();
        $this->assertSame(ContractInterpretation::STATUS_PUBLISHED, $interpretation->status);
        $this->assertCount(3, $interpretation->llm_attempts);
        $this->assertSame(['initial', 'repair', 'repair'], collect($interpretation->llm_attempts)->pluck('type')->all());
        $this->assertSame(3, $interpretation->usage['attempt_count']);
        $this->assertSame(60, $interpretation->latency_ms);
    }

    public function test_job_stops_after_two_failed_model_correction_calls(): void
    {
        config()->set('contract_interpretation.max_repair_attempts', 2);
        $snapshot = $this->createSnapshot();
        $interpretation = $this->createInterpretation($snapshot);
        $invalid = ['schema_version' => 'wrong'];
        $client = $this->mock(OpenRouterContractInterpretationClient::class);
        $client->shouldReceive('interpret')->once()->andReturn($this->llmResult($invalid, 10));
        $client->shouldReceive('repair')->twice()->andReturn(
            $this->llmResult($invalid, 20),
            $this->llmResult($invalid, 30),
        );

        app()->call([new AnalyzeContractSourceSnapshot($interpretation->id), 'handle']);

        $interpretation->refresh();
        $this->assertSame(ContractInterpretation::STATUS_FAILED, $interpretation->status);
        $this->assertCount(3, $interpretation->llm_attempts);
        $this->assertSame('Automatic validation failed after 3 LLM attempts.', $interpretation->error);
        $this->assertNull($snapshot->contract->fresh()->published_interpretation_id);
    }

    public function test_complete_current_prices_publish_when_future_recurring_prices_need_an_estimate(): void
    {
        $snapshot = $this->createSnapshot();
        $payload = $snapshot->source_payload;
        $payload['Name'] = 'Kvartaalisähkö';
        $payload['Details']['PricingModel'] = 'FixedPrice';
        $payload['Details']['Pricing'] = [
            'ElectricitySupplyProductId' => 'api-contract-1',
            'PriceComponents' => [[
                'Id' => 'current-quarter-component',
                'PriceComponentType' => 'General',
                'HasDiscount' => false,
                'OriginalPayment' => ['Price' => 7.25, 'PaymentUnit' => 'CentPerKiwattHour'],
            ]],
        ];
        $snapshot->update(['source_payload' => $payload]);
        $output = $this->validOutput($snapshot->contract_id, [
            'primary_pricing_model' => 'FixedPrice',
            'pricing_mechanisms' => ['fixed', 'periodic_market_reset'],
            'periodic_reset_cadence' => 'quarterly',
            'schedule_kinds' => ['recurring_market_reset'],
        ]);
        $output['pricing']['recurring_schedule'] = [
            'present' => true,
            'cadence' => 'quarterly',
            'current_period_start' => null,
            'current_period_end' => null,
            'future_price_known' => false,
            'description' => 'Future quarterly market prices are not known.',
            'evidence' => [],
        ];
        $output['source_consistency']['structured_pricing_status'] = 'complete';
        $output['source_consistency']['misleading_first_12_months'] = 'uncertain';
        $output['source_consistency']['issue_codes'] = ['recurring_reset_requires_estimate'];
        $output['calculation']['status'] = 'estimate_required';
        $interpretation = $this->createInterpretation($snapshot, $output);

        $published = app(ContractInterpretationPublisher::class)->publish($interpretation);

        $this->assertTrue($published);
        $this->assertTrue($interpretation->fresh()->relational_pricing_published);
        $this->assertSame(7.25, PriceComponent::sole()->price);
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

    public function test_hybrid_base_prices_publish_when_only_the_consumption_effect_is_unquantifiable(): void
    {
        $snapshot = $this->hybridSnapshot();
        $interpretation = $this->createInterpretation(
            $snapshot,
            $this->consumptionEffectOnlyOutput($snapshot->contract_id),
        );

        $published = app(ContractInterpretationPublisher::class)->publish($interpretation);

        $this->assertTrue($published);
        $this->assertTrue($interpretation->fresh()->relational_pricing_published);
        $this->assertSame(6.9, PriceComponent::where('price_component_type', 'General')->sole()->price);
        $this->assertDatabaseHas('active_contracts', ['id' => $snapshot->contract_id]);
    }

    public function test_a_component_mismatch_beside_the_consumption_effect_blocks_publication(): void
    {
        $snapshot = $this->hybridSnapshot();
        $output = $this->consumptionEffectOnlyOutput($snapshot->contract_id);
        $output['source_consistency']['issue_codes'][] = 'component_mismatch';
        $interpretation = $this->createInterpretation($snapshot, $output);

        app(ContractInterpretationPublisher::class)->publish($interpretation);

        $this->assertFalse($interpretation->fresh()->relational_pricing_published);
        $this->assertSame(0, PriceComponent::count());
    }

    public function test_a_detected_deception_beside_the_consumption_effect_still_blocks_publication(): void
    {
        $snapshot = $this->hybridSnapshot();
        $output = $this->consumptionEffectOnlyOutput($snapshot->contract_id);
        $output['source_consistency']['misleading_first_12_months'] = 'detected';
        $interpretation = $this->createInterpretation($snapshot, $output);

        app(ContractInterpretationPublisher::class)->publish($interpretation);

        $this->assertFalse($interpretation->fresh()->relational_pricing_published);
        $this->assertSame(0, PriceComponent::count());
    }

    public function test_calculation_status_alone_does_not_gate_the_source_components(): void
    {
        // Derivability is not trustworthiness. `unsupported`/`incomplete` say Voltikka
        // cannot total the year, not that the seller's published rate is wrong.
        $snapshot = $this->hybridSnapshot();
        $output = $this->consumptionEffectOnlyOutput($snapshot->contract_id);
        $output['calculation']['status'] = 'incomplete';
        $interpretation = $this->createInterpretation($snapshot, $output);

        app(ContractInterpretationPublisher::class)->publish($interpretation);

        $this->assertTrue($interpretation->fresh()->relational_pricing_published);
        $this->assertSame(6.9, PriceComponent::where('price_component_type', 'General')->sole()->price);
    }

    public function test_thin_source_documentation_does_not_block_complete_components(): void
    {
        // Lammaisten IISI-KULUTUSJOUSTO: the seller published no prose to check the
        // structured data against. That is thin documentation, not a defective price.
        $snapshot = $this->hybridSnapshot();
        $output = $this->consumptionEffectOnlyOutput($snapshot->contract_id);
        $output['source_consistency']['issue_codes'][] = 'insufficient_evidence';
        $interpretation = $this->createInterpretation($snapshot, $output);

        app(ContractInterpretationPublisher::class)->publish($interpretation);

        $this->assertTrue($interpretation->fresh()->relational_pricing_published);
    }

    public function test_a_periodic_reset_beside_the_consumption_effect_does_not_block(): void
    {
        // A product can be both a consumption-effect Hybrid and a quarterly market reset
        // (Korpela Kvartaali). Two expected reasons for an estimate are still not a defect.
        $snapshot = $this->hybridSnapshot();
        $output = $this->consumptionEffectOnlyOutput($snapshot->contract_id);
        $output['source_consistency']['issue_codes'][] = 'recurring_reset_requires_estimate';
        $interpretation = $this->createInterpretation($snapshot, $output);

        app(ContractInterpretationPublisher::class)->publish($interpretation);

        $this->assertTrue($interpretation->fresh()->relational_pricing_published);
    }

    public function test_a_published_pricing_model_correction_does_not_block_publication(): void
    {
        // Vattenfall Helppo Pörssisähkö: source says Spot, description discloses an
        // excess-use charge, interpretation corrects to Hybrid at high confidence. The
        // correction publishes, so the calculator reads the same components correctly.
        $snapshot = $this->hybridSnapshot();
        $output = $this->consumptionEffectOnlyOutput($snapshot->contract_id);
        $output['source_consistency']['issue_codes'][] = 'pricing_model_mismatch';
        $interpretation = $this->createInterpretation($snapshot, $output);

        app(ContractInterpretationPublisher::class)->publish($interpretation);

        $this->assertSame('mismatch', $output['source_consistency']['pricing_model_status']);
        $this->assertTrue($interpretation->fresh()->relational_pricing_published);
        $this->assertSame('Hybrid', ElectricityContract::findOrFail($snapshot->contract_id)->pricing_model);
    }

    public function test_an_unpublished_pricing_model_correction_blocks_publication(): void
    {
        // Below high confidence the correction does not publish, so the contract keeps a
        // model the interpretation believes is wrong — and pricing_model decides how a
        // component is read (a Spot 0.4 c/kWh General is a margin, not an energy price).
        $snapshot = $this->hybridSnapshot();
        $output = $this->consumptionEffectOnlyOutput($snapshot->contract_id);
        $output['source_consistency']['issue_codes'][] = 'pricing_model_mismatch';
        $output['confidence']['classification'] = 'medium';
        $interpretation = $this->createInterpretation($snapshot, $output);

        app(ContractInterpretationPublisher::class)->publish($interpretation);

        $this->assertFalse($interpretation->fresh()->relational_pricing_published);
        $this->assertSame(0, PriceComponent::count());
    }

    public function test_conflicting_structured_pricing_blocks_publication(): void
    {
        $snapshot = $this->hybridSnapshot();
        $output = $this->consumptionEffectOnlyOutput($snapshot->contract_id);
        $output['source_consistency']['structured_pricing_status'] = 'conflicting';
        $interpretation = $this->createInterpretation($snapshot, $output);

        app(ContractInterpretationPublisher::class)->publish($interpretation);

        $this->assertFalse($interpretation->fresh()->relational_pricing_published);
        $this->assertSame(0, PriceComponent::count());
    }

    public function test_an_unclassified_issue_code_blocks_publication(): void
    {
        // The schema's code list grows. A code nobody has classified in the publisher must
        // not be treated as harmless by default.
        $snapshot = $this->hybridSnapshot();
        $output = $this->consumptionEffectOnlyOutput($snapshot->contract_id);
        $output['source_consistency']['issue_codes'][] = 'other';
        $interpretation = $this->createInterpretation($snapshot, $output);

        app(ContractInterpretationPublisher::class)->publish($interpretation);

        $this->assertFalse($interpretation->fresh()->relational_pricing_published);
        $this->assertSame(0, PriceComponent::count());
    }

    public function test_an_intro_only_structured_price_blocks_publication(): void
    {
        // The classic deception shape: the structured components hold only the promo price
        // and the ongoing one lives in prose. Publishing these rows would state the promo
        // as the price.
        $snapshot = $this->hybridSnapshot();
        $output = $this->consumptionEffectOnlyOutput($snapshot->contract_id);
        $output['source_consistency']['issue_codes'][] = 'structured_matches_intro_only';
        $interpretation = $this->createInterpretation($snapshot, $output);

        app(ContractInterpretationPublisher::class)->publish($interpretation);

        $this->assertFalse($interpretation->fresh()->relational_pricing_published);
        $this->assertSame(0, PriceComponent::count());
    }

    public function test_republish_command_lifts_the_stale_flag_and_fills_the_days_it_lost(): void
    {
        // The gate is decided once at publication time and read by every later import, so a
        // contract published under the old rule stays blocked until this command re-asks.
        $snapshot = $this->hybridSnapshot();
        $snapshot->update([
            'first_observed_at' => '2026-07-25 06:00:00',
            'last_observed_at' => '2026-07-27 06:00:00',
        ]);
        $interpretation = $this->createInterpretation(
            $snapshot,
            $this->consumptionEffectOnlyOutput($snapshot->contract_id),
        );
        $interpretation->update([
            'status' => ContractInterpretation::STATUS_PUBLISHED,
            'relational_pricing_published' => false,
        ]);
        ElectricityContract::whereKey($snapshot->contract_id)
            ->update(['published_interpretation_id' => $interpretation->id]);

        $this->artisan('contracts:republish-gated-pricing', [
            '--from' => '2026-07-25',
            '--to' => '2026-07-27',
        ])->assertExitCode(0);

        $this->assertFalse($interpretation->fresh()->relational_pricing_published);
        $this->assertSame(0, PriceComponent::count(), 'A dry run must not write.');

        $this->artisan('contracts:republish-gated-pricing', [
            '--from' => '2026-07-25',
            '--to' => '2026-07-27',
            '--apply' => true,
        ])->assertExitCode(0);

        $this->assertTrue($interpretation->fresh()->relational_pricing_published);
        $this->assertEqualsCanonicalizing(
            ['2026-07-25', '2026-07-27', '2026-07-26'],
            PriceComponent::where('price_component_type', 'General')
                ->pluck('price_date')
                ->map(fn ($date) => $date->toDateString())
                ->all(),
        );
    }

    public function test_republish_command_leaves_days_that_already_have_rows_alone(): void
    {
        $snapshot = $this->hybridSnapshot();
        $snapshot->update([
            'first_observed_at' => '2026-07-25 06:00:00',
            'last_observed_at' => '2026-07-26 06:00:00',
        ]);
        $interpretation = $this->createInterpretation(
            $snapshot,
            $this->consumptionEffectOnlyOutput($snapshot->contract_id),
        );
        $interpretation->update([
            'status' => ContractInterpretation::STATUS_PUBLISHED,
            'relational_pricing_published' => false,
        ]);
        ElectricityContract::whereKey($snapshot->contract_id)
            ->update(['published_interpretation_id' => $interpretation->id]);

        // A day the import already wrote. Its stored price is what the API served then.
        PriceComponent::create([
            'id' => 'hybrid-energy',
            'price_date' => '2026-07-25',
            'price_component_type' => 'General',
            'electricity_contract_id' => $snapshot->contract_id,
            'has_discount' => false,
            'price' => 5.55,
            'payment_unit' => 'CentPerKiwattHour',
        ]);

        $this->artisan('contracts:republish-gated-pricing', [
            '--from' => '2026-07-25',
            '--to' => '2026-07-26',
            '--apply' => true,
        ])->assertExitCode(0);

        $this->assertSame(
            5.55,
            PriceComponent::whereDate('price_date', '2026-07-25')
                ->where('price_component_type', 'General')
                ->sole()
                ->price,
        );
        $this->assertSame(
            6.9,
            PriceComponent::whereDate('price_date', '2026-07-26')
                ->where('price_component_type', 'General')
                ->sole()
                ->price,
        );
    }

    public function test_republish_command_leaves_a_day_with_no_covering_snapshot_missing(): void
    {
        // Evidence, never inference: a gap outside the snapshot's observation window
        // stays a gap rather than borrowing a neighbouring day's price.
        $snapshot = $this->hybridSnapshot();
        $snapshot->update([
            'first_observed_at' => '2026-07-26 06:00:00',
            'last_observed_at' => '2026-07-27 06:00:00',
        ]);
        $interpretation = $this->createInterpretation(
            $snapshot,
            $this->consumptionEffectOnlyOutput($snapshot->contract_id),
        );
        $interpretation->update([
            'status' => ContractInterpretation::STATUS_PUBLISHED,
            'relational_pricing_published' => false,
        ]);
        ElectricityContract::whereKey($snapshot->contract_id)
            ->update(['published_interpretation_id' => $interpretation->id]);

        $this->artisan('contracts:republish-gated-pricing', [
            '--from' => '2026-07-24',
            '--to' => '2026-07-27',
            '--apply' => true,
        ])->assertExitCode(0);

        $this->assertEqualsCanonicalizing(
            ['2026-07-26', '2026-07-27'],
            PriceComponent::where('price_component_type', 'General')
                ->pluck('price_date')
                ->map(fn ($date) => $date->toDateString())
                ->all(),
        );
    }

    public function test_republish_command_does_not_reopen_a_genuinely_unsafe_interpretation(): void
    {
        $snapshot = $this->hybridSnapshot();
        $snapshot->update([
            'first_observed_at' => '2026-07-25 06:00:00',
            'last_observed_at' => '2026-07-27 06:00:00',
        ]);
        $output = $this->consumptionEffectOnlyOutput($snapshot->contract_id);
        $output['source_consistency']['structured_pricing_status'] = 'conflicting';
        $interpretation = $this->createInterpretation($snapshot, $output);
        $interpretation->update([
            'status' => ContractInterpretation::STATUS_PUBLISHED,
            'relational_pricing_published' => false,
        ]);
        ElectricityContract::whereKey($snapshot->contract_id)
            ->update(['published_interpretation_id' => $interpretation->id]);

        $this->artisan('contracts:republish-gated-pricing', [
            '--from' => '2026-07-25',
            '--to' => '2026-07-27',
            '--apply' => true,
        ])->assertExitCode(0);

        $this->assertFalse($interpretation->fresh()->relational_pricing_published);
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
     * A Hybrid ("joustosähkö") snapshot: a disclosed base energy rate and monthly fee,
     * plus a consumption effect the seller does not quantify.
     */
    private function hybridSnapshot(): ContractSourceSnapshot
    {
        $snapshot = $this->createSnapshot();
        ElectricityContract::whereKey($snapshot->contract_id)->update(['pricing_model' => 'Hybrid']);

        $payload = $snapshot->source_payload;
        $payload['Details']['PricingModel'] = 'Hybrid';
        $payload['Details']['ExtraInformation']['FI'] =
            'Energian hinta muodostuu kiinteästä hinnan osasta sekä asiakkaan kulutusvaikutuksesta.';
        $payload['Details']['Pricing'] = [
            'ElectricitySupplyProductId' => 'api-contract-1',
            'PriceComponents' => [
                [
                    'Id' => 'hybrid-energy',
                    'PriceComponentType' => 'General',
                    'HasDiscount' => false,
                    'OriginalPayment' => ['Price' => 6.9, 'PaymentUnit' => 'CentPerKiwattHour'],
                ],
                [
                    'Id' => 'hybrid-monthly',
                    'PriceComponentType' => 'Monthly',
                    'HasDiscount' => false,
                    'OriginalPayment' => ['Price' => 3.9, 'PaymentUnit' => 'EurPerMonth'],
                ],
            ],
        ];
        $snapshot->update(['source_payload' => $payload]);

        return $snapshot->fresh();
    }

    /**
     * The exact output shape prompt v19 requires for a Hybrid whose consumption effect
     * the source never prices: incomplete + unsupported, with that one issue code.
     *
     * @return array<string, mixed>
     */
    private function consumptionEffectOnlyOutput(string $contractId): array
    {
        $output = $this->validOutput($contractId, [
            'primary_pricing_model' => 'Hybrid',
            'pricing_mechanisms' => ['fixed', 'consumption_effect'],
        ]);
        $output['pricing']['consumption_effect'] = [
            'present' => true,
            'applies_to' => 'base_contract',
            'cadence' => 'monthly',
            'expected_cents_per_kwh' => null,
            'typical_min_cents_per_kwh' => null,
            'typical_max_cents_per_kwh' => null,
            'hard_min_cents_per_kwh' => null,
            'hard_max_cents_per_kwh' => null,
            'uncapped' => null,
            'description' => 'The customer-specific consumption effect is not priced in the source.',
            'evidence' => [],
        ];
        $output['source_consistency']['structured_pricing_status'] = 'incomplete';
        $output['source_consistency']['misleading_first_12_months'] = 'uncertain';
        $output['source_consistency']['issue_codes'] = ['unsupported_consumption_effect'];
        $output['calculation']['status'] = 'unsupported';
        $output['calculation']['missing_facts'] = ['The amount of the customer-specific consumption effect'];

        return $output;
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
            'schema_version' => config('contract_interpretation.schema_version'),
            'prompt_version' => config('contract_interpretation.prompt_version'),
            'validator_version' => config('contract_interpretation.validator_version'),
            'provider' => 'openrouter',
            'model' => 'test-model',
            'output' => $output,
        ]);
    }

    /**
     * @param  array<string, mixed>  $output
     * @return array{output: array<string, mixed>, usage: array<string, mixed>, provider: string, response_id: string, latency_ms: int}
     */
    private function llmResult(array $output, int $latency): array
    {
        return [
            'output' => $output,
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15, 'cost' => 0.01],
            'provider' => 'test-provider',
            'response_id' => 'response-'.$latency,
            'latency_ms' => $latency,
        ];
    }

    /**
     * @param  array<string, mixed>  $classificationOverrides
     * @return array<string, mixed>
     */
    public function test_validator_rejects_spot_day_night_margin_as_fixed_energy(): void
    {
        // Spot Valo: source DayTime/NightTime 0.33 are the spot margin, not fixed energy prices.
        $output = $this->validOutput('contract-1');
        $output['classification']['pricing_mechanisms'] = ['spot', 'time_of_use'];
        $output['classification']['metering'] = 'General';
        $output['pricing']['phases'] = [[
            'label' => 'Current',
            'phase_kind' => 'current_structured',
            'starts' => ['kind' => 'contract_start', 'value' => null],
            'ends' => ['kind' => 'none', 'value' => null],
            'package' => null,
            'components' => [
                ['component_type' => 'energy_day', 'amount' => 0.33, 'normal_amount' => null, 'unit' => 'cents_per_kwh', 'vat_status' => 'unknown', 'price_role' => 'current', 'source_kind' => 'structured', 'evidence' => [['source' => 'components[1].price', 'quote' => 'components[1].price=0.33']]],
                ['component_type' => 'energy_night', 'amount' => 0.33, 'normal_amount' => null, 'unit' => 'cents_per_kwh', 'vat_status' => 'unknown', 'price_role' => 'current', 'source_kind' => 'structured', 'evidence' => [['source' => 'components[2].price', 'quote' => 'components[2].price=0.33']]],
                ['component_type' => 'monthly_fee', 'amount' => 4.65, 'normal_amount' => null, 'unit' => 'eur_per_month', 'vat_status' => 'unknown', 'price_role' => 'current', 'source_kind' => 'structured', 'evidence' => [['source' => 'components[0].price', 'quote' => 'components[0].price=4.65']]],
            ],
            'evidence' => [],
        ]];

        $errors = app(ContractInterpretationValidator::class)->validate($output, [
            'contract_id' => 'contract-1',
            'pricing_model' => 'Spot',
            'contract_type' => 'OpenEnded',
            'metering' => 'Time',
            'components' => [
                ['price_component_type' => 'Monthly', 'price' => 4.65],
                ['price_component_type' => 'DayTime', 'price' => 0.33],
                ['price_component_type' => 'NightTime', 'price' => 0.33],
            ],
        ]);

        $this->assertContains(
            '$.pricing.phases: on a Spot contract a per-kWh energy adder at or below the margin ceiling must be spot_margin, not a fixed energy component.',
            $errors,
        );
        $this->assertContains('$.pricing.phases is missing structured component type spot_margin.', $errors);
    }

    public function test_validator_leaves_large_all_in_spot_energy_price_alone(): void
    {
        // Cheap Markkina: a 6.99 c/kWh all-in market price is above the margin ceiling and stays
        // energy_general; only the disclosed 1.29 margin is spot_margin.
        $output = $this->validOutput('contract-1');
        $output['classification']['pricing_mechanisms'] = ['spot'];
        $output['pricing']['phases'] = [[
            'label' => 'Current',
            'phase_kind' => 'current_structured',
            'starts' => ['kind' => 'contract_start', 'value' => null],
            'ends' => ['kind' => 'none', 'value' => null],
            'package' => null,
            'components' => [
                ['component_type' => 'energy_general', 'amount' => 6.99, 'normal_amount' => null, 'unit' => 'cents_per_kwh', 'vat_status' => 'unknown', 'price_role' => 'current', 'source_kind' => 'structured', 'evidence' => [['source' => 'components[0].price', 'quote' => 'components[0].price=6.99']]],
                ['component_type' => 'spot_margin', 'amount' => 1.29, 'normal_amount' => null, 'unit' => 'cents_per_kwh', 'vat_status' => 'unknown', 'price_role' => 'current', 'source_kind' => 'description', 'evidence' => [['source' => 'long_description', 'quote' => 'marginaali 1,29']]],
                ['component_type' => 'monthly_fee', 'amount' => 4.99, 'normal_amount' => null, 'unit' => 'eur_per_month', 'vat_status' => 'unknown', 'price_role' => 'current', 'source_kind' => 'structured', 'evidence' => [['source' => 'components[1].price', 'quote' => 'components[1].price=4.99']]],
            ],
            'evidence' => [],
        ]];

        $errors = app(ContractInterpretationValidator::class)->validate($output, [
            'contract_id' => 'contract-1',
            'pricing_model' => 'Spot',
            'contract_type' => 'OpenEnded',
            'metering' => 'General',
            'long_description' => 'Nord Pool spot -hinta ja marginaali 1,29 snt/kWh.',
            'components' => [
                ['price_component_type' => 'General', 'price' => 6.99],
                ['price_component_type' => 'Monthly', 'price' => 4.99],
            ],
        ]);

        $this->assertNotContains(
            '$.pricing.phases: on a Spot contract a per-kWh energy adder at or below the margin ceiling must be spot_margin, not a fixed energy component.',
            $errors,
        );
    }

    public function test_validator_accepts_flat_fee_in_place_of_expected_monthly_fee(): void
    {
        // A source Monthly charge may be read as flat_fee (a package-named product like
        // Kuukausipaketti); the validator must not reject it purely for "missing monthly_fee".
        $output = $this->validOutput('contract-1', [
            'primary_pricing_model' => 'FixedPrice',
            'pricing_mechanisms' => ['flat_fee_or_package', 'fixed'],
        ]);
        $output['pricing']['phases'] = [[
            'label' => 'Current',
            'phase_kind' => 'current_structured',
            'starts' => ['kind' => 'contract_start', 'value' => null],
            'ends' => ['kind' => 'none', 'value' => null],
            'package' => null,
            'components' => [
                ['component_type' => 'flat_fee', 'amount' => 35.0, 'normal_amount' => null, 'unit' => 'eur_per_month', 'vat_status' => 'unknown', 'price_role' => 'current', 'source_kind' => 'structured', 'evidence' => [['source' => 'components[0].price', 'quote' => 'components[0].price=35']]],
                ['component_type' => 'energy_general', 'amount' => 16.6, 'normal_amount' => null, 'unit' => 'cents_per_kwh', 'vat_status' => 'unknown', 'price_role' => 'current', 'source_kind' => 'structured', 'evidence' => [['source' => 'components[1].price', 'quote' => 'components[1].price=16.6']]],
            ],
            'evidence' => [],
        ]];

        $errors = app(ContractInterpretationValidator::class)->validate($output, [
            'contract_id' => 'contract-1',
            'pricing_model' => 'FixedPrice',
            'contract_type' => 'OpenEnded',
            'metering' => 'General',
            'components' => [
                ['price_component_type' => 'Monthly', 'price' => 35],
                ['price_component_type' => 'General', 'price' => 16.6],
            ],
        ]);

        $this->assertNotContains('$.pricing.phases is missing structured component type monthly_fee.', $errors);
    }

    public function test_validator_rejects_flat_and_monthly_fee_duplicate_from_one_source_charge(): void
    {
        $output = $this->validOutput('contract-1', [
            'primary_pricing_model' => 'FixedPrice',
            'pricing_mechanisms' => ['flat_fee_or_package', 'fixed'],
        ]);
        $fee = ['amount' => 49, 'normal_amount' => 49, 'unit' => 'eur_per_month', 'vat_status' => 'unknown', 'price_role' => 'current', 'source_kind' => 'structured', 'evidence' => [['source' => 'components[0].price', 'quote' => 'components[0].price=49']]];
        $output['pricing']['phases'] = [[
            'label' => 'Current',
            'phase_kind' => 'current_structured',
            'starts' => ['kind' => 'contract_start', 'value' => null],
            'ends' => ['kind' => 'none', 'value' => null],
            'components' => [
                array_merge($fee, ['component_type' => 'flat_fee']),
                array_merge($fee, ['component_type' => 'monthly_fee']),
                ['component_type' => 'energy_general', 'amount' => 16.6, 'normal_amount' => 16.6, 'unit' => 'cents_per_kwh', 'vat_status' => 'unknown', 'price_role' => 'current', 'source_kind' => 'structured', 'evidence' => [['source' => 'components[1].price', 'quote' => 'components[1].price=16.6']]],
            ],
            'package' => null,
            'evidence' => [],
        ]];

        $errors = app(ContractInterpretationValidator::class)->validate($output, [
            'contract_id' => 'contract-1',
            'pricing_model' => 'FixedPrice',
            'contract_type' => 'OpenEnded',
            'metering' => 'General',
            'components' => [
                ['price_component_type' => 'Monthly', 'price' => 49],
                ['price_component_type' => 'General', 'price' => 16.6],
            ],
        ]);

        $this->assertContains(
            '$.pricing.phases[0] has ambiguous duplicate monthly fees as flat_fee and monthly_fee.',
            $errors,
        );
    }

    public function test_validator_rejects_detected_on_a_reset_product_with_only_reset_path_codes(): void
    {
        // Cheap Kvartaali: a quarterly market reset is not deceptive for its own intro->market path.
        $output = $this->validOutput('contract-1', [
            'primary_pricing_model' => 'FixedPrice',
            'pricing_mechanisms' => ['fixed', 'periodic_market_reset'],
            'periodic_reset_cadence' => 'quarterly',
        ]);
        $output['pricing']['recurring_schedule']['present'] = true;
        $output['pricing']['recurring_schedule']['cadence'] = 'quarterly';
        $output['pricing']['recurring_schedule']['future_price_known'] = false;
        $output['source_consistency']['misleading_first_12_months'] = 'detected';
        $output['source_consistency']['issue_codes'] = ['promotion_metadata_missing', 'structured_matches_intro_only', 'future_price_omitted', 'recurring_reset_requires_estimate'];

        $errors = app(ContractInterpretationValidator::class)->validate($output, [
            'contract_id' => 'contract-1',
            'pricing_model' => 'FixedPrice',
            'contract_type' => 'OpenEnded',
            'metering' => 'General',
            'components' => [],
        ]);

        $this->assertContains(
            '$.source_consistency.misleading_first_12_months must not be detected for a periodic market-reset product whose only issues describe the reset/intro price path; use uncertain.',
            $errors,
        );
    }

    public function test_validator_allows_detected_on_a_reset_product_with_a_genuine_conflict(): void
    {
        // A reset product can still be detected for a genuine non-reset conflict.
        $output = $this->validOutput('contract-1', [
            'primary_pricing_model' => 'FixedPrice',
            'pricing_mechanisms' => ['fixed', 'periodic_market_reset'],
            'periodic_reset_cadence' => 'quarterly',
        ]);
        $output['pricing']['recurring_schedule']['present'] = true;
        $output['pricing']['recurring_schedule']['cadence'] = 'quarterly';
        $output['source_consistency']['misleading_first_12_months'] = 'detected';
        $output['source_consistency']['issue_codes'] = ['component_mismatch', 'recurring_reset_requires_estimate'];

        $errors = app(ContractInterpretationValidator::class)->validate($output, [
            'contract_id' => 'contract-1',
            'pricing_model' => 'FixedPrice',
            'contract_type' => 'OpenEnded',
            'metering' => 'General',
            'components' => [],
        ]);

        $this->assertNotContains(
            '$.source_consistency.misleading_first_12_months must not be detected for a periodic market-reset product whose only issues describe the reset/intro price path; use uncertain.',
            $errors,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function normalSpotMarginPhase(): array
    {
        return [
            'label' => 'Normal margin',
            'phase_kind' => 'normal',
            'starts' => ['kind' => 'contract_start', 'value' => null],
            'ends' => ['kind' => 'none', 'value' => null],
            'package' => null,
            'components' => [[
                'component_type' => 'spot_margin',
                'amount' => 0.6,
                'normal_amount' => null,
                'unit' => 'cents_per_kwh',
                'vat_status' => 'unknown',
                'price_role' => 'normal',
                'source_kind' => 'structured',
                'evidence' => [['source' => 'components[0].price', 'quote' => 'components[0].price=0.6']],
            ]],
            'evidence' => [],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function activeSpotMarginDiscountPhases(): array
    {
        $evidence = collect([
            'price' => 0.6,
            'has_discount' => true,
            'discount_value' => 0.4,
            'discount_is_percentage' => false,
            'discount_type' => 'UntilDate',
            'discount_until_date' => '2026-08-31T00:00:00',
        ])->map(fn (mixed $value, string $field): array => [
            'source' => "components[0].{$field}",
            'quote' => "components[0].{$field}=".json_encode($value),
        ])->values()->all();

        $normal = $this->normalSpotMarginPhase();
        $normal['starts'] = ['kind' => 'date', 'value' => '2026-09-01'];

        return [[
            'label' => 'Active margin campaign',
            'phase_kind' => 'introductory',
            'starts' => ['kind' => 'unknown', 'value' => null],
            'ends' => ['kind' => 'date', 'value' => '2026-08-31'],
            'package' => null,
            'components' => [[
                'component_type' => 'spot_margin',
                'amount' => 0.2,
                'normal_amount' => 0.6,
                'unit' => 'cents_per_kwh',
                'vat_status' => 'unknown',
                'price_role' => 'introductory',
                'source_kind' => 'structured',
                'evidence' => $evidence,
            ]],
            'evidence' => [],
        ], $normal];
    }

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
            'schema_version' => '1.1',
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
