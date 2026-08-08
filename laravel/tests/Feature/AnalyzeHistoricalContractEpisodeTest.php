<?php

namespace Tests\Feature;

use App\Jobs\AnalyzeHistoricalContractEpisode;
use App\Models\Company;
use App\Models\ContractHistoricalInterpretation;
use App\Models\ElectricityContract;
use App\Services\ContractInterpretation\ContractInterpretationPublisher;
use App\Services\ContractInterpretation\OpenRouterContractInterpretationClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class AnalyzeHistoricalContractEpisodeTest extends TestCase
{
    use RefreshDatabase;

    public function test_valid_output_is_validated_with_attempt_usage_and_no_publication_side_effects(): void
    {
        $analysis = $this->analysis();
        $contract = ElectricityContract::findOrFail($analysis->contract_id);
        $before = $contract->getAttributes();
        $componentBefore = DB::table('price_components')->get()->map(fn ($row) => (array) $row)->all();
        $this->mock(ContractInterpretationPublisher::class)->shouldNotReceive('publish');
        $this->mock(OpenRouterContractInterpretationClient::class)
            ->shouldReceive('interpret')
            ->once()
            ->withArgs(fn (array $input, string $addendum): bool => $input['_historical_provenance']['text_is_backcast'] === true
                && str_ends_with($addendum, 'historical-system-prompt-addendum-v3.md'))
            ->andReturn($this->llmResult($this->validOutput($analysis->contract_id), 15, 0.02));

        app()->call([new AnalyzeHistoricalContractEpisode($analysis->id), 'handle']);

        $analysis->refresh();
        $this->assertSame('validated', $analysis->status);
        $this->assertCount(1, $analysis->llm_attempts);
        $this->assertSame(0.02, $analysis->usage['cost']);
        $this->assertSame(15, $analysis->latency_ms);
        $this->assertSame($before, $contract->fresh()->getAttributes());
        $this->assertSame($componentBefore, DB::table('price_components')->get()->map(fn ($row) => (array) $row)->all());
        $this->assertSame(0, DB::table('contract_interpretations')->count());
        $this->assertSame(0, DB::table('contract_price_daily_statistics')->count());
        $this->assertSame(0, DB::table('contract_price_annual_costs')->count());
    }

    public function test_historical_job_and_http_policy_fit_the_worker_envelope(): void
    {
        $job = new AnalyzeHistoricalContractEpisode(1);
        $httpTimeout = config('contract_interpretation.historical.timeout');
        $modelCalls = 1 + config('contract_interpretation.max_repair_attempts');
        $retryAfter = config('queue.connections.database.retry_after');
        $supervisorConfig = file_get_contents(base_path('../supervisord.conf'));
        $retryAfterWasDefined = array_key_exists('DB_QUEUE_RETRY_AFTER', $_SERVER);
        $originalRetryAfter = $_SERVER['DB_QUEUE_RETRY_AFTER'] ?? null;

        try {
            $_SERVER['DB_QUEUE_RETRY_AFTER'] = '450';
            $queueConfigWithStaleOverride = require config_path('queue.php');
        } finally {
            if ($retryAfterWasDefined) {
                $_SERVER['DB_QUEUE_RETRY_AFTER'] = $originalRetryAfter;
            } else {
                unset($_SERVER['DB_QUEUE_RETRY_AFTER']);
            }
        }

        $this->assertIsString($supervisorConfig);
        $this->assertSame(1, preg_match('/\[program:queue-worker\]\Rcommand=.*\bqueue:work\b.*--timeout=(\d+)\b/', $supervisorConfig, $matches));
        $workerTimeout = (int) $matches[1];

        $this->assertSame(3, $modelCalls);
        $this->assertSame(300, $httpTimeout);
        $this->assertSame(1, config('contract_interpretation.historical.http_attempts'));
        $this->assertSame(1000, $job->timeout);
        $this->assertSame(1020, $workerTimeout);
        $this->assertSame(1050, $retryAfter);
        $this->assertSame(1050, $queueConfigWithStaleOverride['connections']['database']['retry_after']);
        $this->assertLessThan($job->timeout, $modelCalls * $httpTimeout);
        $this->assertLessThan($workerTimeout, $job->timeout);
        $this->assertLessThan($retryAfter, $workerTimeout);
    }

    public function test_invalid_output_uses_two_repairs_then_fails_and_retains_all_attempts(): void
    {
        config()->set('contract_interpretation.max_repair_attempts', 2);
        $analysis = $this->analysis();
        $invalid = ['schema_version' => 'wrong'];
        $client = $this->mock(OpenRouterContractInterpretationClient::class);
        $client->shouldReceive('interpret')->once()->andReturn($this->llmResult($invalid, 10, 0.01));
        $client->shouldReceive('repair')->twice()->andReturn(
            $this->llmResult($invalid, 20, 0.02),
            $this->llmResult($invalid, 30, 0.03),
        );
        $this->mock(ContractInterpretationPublisher::class)->shouldNotReceive('publish');

        app()->call([new AnalyzeHistoricalContractEpisode($analysis->id), 'handle']);

        $analysis->refresh();
        $this->assertSame('failed', $analysis->status);
        $this->assertCount(3, $analysis->llm_attempts);
        $this->assertSame(['initial', 'repair', 'repair'], collect($analysis->llm_attempts)->pluck('type')->all());
        $this->assertSame(0.06, $analysis->usage['cost']);
        $this->assertSame(60, $analysis->latency_ms);
        $this->assertNotEmpty($analysis->validation_errors);
    }

    public function test_stored_exact_manifest_fingerprint_mismatch_stops_before_client(): void
    {
        $analysis = $this->analysis();
        $manifest = $analysis->episode->evidence_manifest;
        $manifest['target_days'][0]['snapshot_id']++;
        $analysis->episode->update(['evidence_manifest' => $manifest]);
        $this->mock(OpenRouterContractInterpretationClient::class)->shouldNotReceive('interpret');
        $this->mock(ContractInterpretationPublisher::class)->shouldNotReceive('publish');

        app()->call([new AnalyzeHistoricalContractEpisode($analysis->id), 'handle']);

        $this->assertSame('failed', $analysis->fresh()->status);
        $this->assertStringContainsString('verification failed', $analysis->fresh()->error);
    }

    public function test_stored_input_or_fingerprint_mismatch_stops_before_client(): void
    {
        $analysis = $this->analysis();
        $input = $analysis->episode->analysis_input;
        $input['components'][0]['price'] = '999';
        $analysis->episode->update(['analysis_input' => $input]);
        $this->mock(OpenRouterContractInterpretationClient::class)->shouldNotReceive('interpret');
        $this->mock(ContractInterpretationPublisher::class)->shouldNotReceive('publish');

        app()->call([new AnalyzeHistoricalContractEpisode($analysis->id), 'handle']);

        $this->assertSame('failed', $analysis->fresh()->status);
        $this->assertStringContainsString('verification failed', $analysis->fresh()->error);
    }

    private function analysis(): ContractHistoricalInterpretation
    {
        Queue::fake();
        $company = Company::create(['name' => 'Test Energy', 'name_slug' => 'test-energy']);
        $contract = ElectricityContract::factory()->forCompany($company)->create([
            'id' => 'historical-contract',
            'short_description' => 'Retained source description.',
        ]);
        DB::table('contract_price_snapshots')->insert([
            'snapshot_date' => '2026-01-01',
            'contract_id' => $contract->id,
            'company_name' => $contract->company_name,
            'contract_name' => $contract->name,
            'pricing_model' => 'FixedPrice',
            'contract_type' => 'OpenEnded',
            'fixed_time_range' => null,
            'metering' => 'General',
            'segment_key' => 'fixed_open',
            'pricing_basis' => 'observed_seller_data',
            'has_discount' => false,
            'includes_spot_price' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('price_components')->insert([
            'id' => 'historical-component',
            'price_date' => '2026-01-01',
            'price_component_type' => 'General',
            'fuse_size' => null,
            'electricity_contract_id' => $contract->id,
            'has_discount' => false,
            'discount_value' => null,
            'discount_is_percentage' => null,
            'discount_type' => null,
            'discount_discount_n_first_kwh' => null,
            'discount_discount_n_first_months' => null,
            'discount_discount_until_date' => null,
            'price' => 7.2,
            'payment_unit' => 'CentPerKiwattHour',
        ]);

        Artisan::call('contracts:backfill-historical-interpretations');
        preg_match('/Plan hash: ([a-f0-9]{64})/', Artisan::output(), $matches);
        Artisan::call('contracts:backfill-historical-interpretations', [
            '--apply' => true,
            '--plan-hash' => $matches[1],
        ]);

        return ContractHistoricalInterpretation::with('episode')->sole();
    }

    private function llmResult(array $output, int $latency, float $cost): array
    {
        return [
            'output' => $output,
            'usage' => [
                'prompt_tokens' => 100,
                'completion_tokens' => 50,
                'total_tokens' => 150,
                'cost' => $cost,
            ],
            'provider' => 'test-provider',
            'response_id' => 'response-'.$latency,
            'latency_ms' => $latency,
        ];
    }

    private function validOutput(string $contractId): array
    {
        return [
            'schema_version' => '1.1',
            'contract_id' => $contractId,
            'classification' => [
                'term_type' => 'OpenEnded',
                'fixed_duration_months' => null,
                'primary_pricing_model' => 'FixedPrice',
                'pricing_mechanisms' => ['fixed'],
                'metering' => 'General',
                'spot_settlement_interval' => 'unknown',
                'periodic_reset_cadence' => 'none',
                'schedule_kinds' => ['standard'],
            ],
            'pricing' => [
                'phases' => [[
                    'label' => 'Exact historical structured price',
                    'phase_kind' => 'current_structured',
                    'starts' => ['kind' => 'contract_start', 'value' => null],
                    'ends' => ['kind' => 'none', 'value' => null],
                    'package' => null,
                    'components' => [[
                        'component_type' => 'energy_general',
                        'amount' => 7.2,
                        'normal_amount' => null,
                        'unit' => 'cents_per_kwh',
                        'vat_status' => 'unknown',
                        'price_role' => 'current',
                        'source_kind' => 'structured',
                        'evidence' => [[
                            'source' => 'components[0].price',
                            'quote' => 'components[0].price=7.2',
                        ]],
                    ]],
                    'evidence' => [],
                ]],
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
                'pricing_model_status' => 'match',
                'recommended_pricing_model' => 'FixedPrice',
                'contract_type_status' => 'match',
                'recommended_contract_type' => 'OpenEnded',
                'metering_status' => 'match',
                'recommended_metering' => 'General',
                'structured_pricing_status' => 'complete',
                'misleading_first_12_months' => 'not_detected',
                'issue_codes' => ['structured_matches_description'],
                'summary' => 'The exact structured price is complete.',
                'evidence' => [],
            ],
            'calculation' => [
                'status' => 'exact',
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
