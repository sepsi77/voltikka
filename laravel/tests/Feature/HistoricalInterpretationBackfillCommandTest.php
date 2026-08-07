<?php

namespace Tests\Feature;

use App\Jobs\AnalyzeHistoricalContractEpisode;
use App\Models\Company;
use App\Models\ContractHistoricalInterpretation;
use App\Models\ContractHistoricalInterpretationEpisode;
use App\Models\ContractSourceSnapshot;
use App\Models\ElectricityContract;
use App\Services\ContractInterpretation\ContractInterpretationInputBuilder;
use App\Services\ContractInterpretation\HistoricalContractEpisodeBuilder;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class HistoricalInterpretationBackfillCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_migration_creates_isolated_tables_and_nullable_annual_provenance(): void
    {
        $this->assertTrue(Schema::hasTable('contract_historical_interpretation_episodes'));
        $this->assertTrue(Schema::hasTable('contract_historical_interpretations'));
        $this->assertTrue(Schema::hasColumn('contract_historical_interpretation_episodes', 'manifest_fingerprint'));
        $this->assertTrue(Schema::hasColumns('contract_price_annual_costs', [
            'historical_episode_id',
            'historical_interpretation_id',
            'historical_evidence_grade',
        ]));

        $migration = require database_path('migrations/2026_08_07_000001_create_historical_contract_interpretation_tables.php');
        $migration->down();
        $this->assertFalse(Schema::hasTable('contract_historical_interpretation_episodes'));
        $migration->up();
    }

    public function test_migration_retry_completes_after_one_table_and_one_annual_column_exist(): void
    {
        $migration = require database_path('migrations/2026_08_07_000001_create_historical_contract_interpretation_tables.php');
        $migration->down();

        Schema::create('contract_historical_interpretation_episodes', function (Blueprint $table): void {
            $table->id();
            $table->string('contract_id');
            $table->date('episode_start');
            $table->date('episode_end');
            $table->string('builder_version', 64);
            $table->char('episode_fingerprint', 64);
            $table->char('evidence_fingerprint', 64);
            $table->string('evidence_grade', 80);
            $table->json('analysis_input');
            $table->json('evidence_manifest');
            $table->timestamps();
        });
        Schema::table('contract_price_annual_costs', function (Blueprint $table): void {
            $table->unsignedBigInteger('historical_episode_id')->nullable();
        });

        $migration->up();
        $migration->up();

        $this->assertTrue(Schema::hasTable('contract_historical_interpretations'));
        $this->assertTrue(Schema::hasColumn('contract_historical_interpretation_episodes', 'manifest_fingerprint'));
        $this->assertTrue(Schema::hasColumns('contract_price_annual_costs', [
            'historical_episode_id',
            'historical_interpretation_id',
            'historical_evidence_grade',
        ]));
        $this->assertTrue(Schema::hasIndex(
            'contract_historical_interpretation_episodes',
            'historical_episodes_contract_dates_idx',
        ));
        $this->assertTrue(Schema::hasIndex(
            'contract_price_annual_costs',
            'contract_annual_costs_historical_episode_idx',
        ));
    }

    public function test_migration_down_tolerates_partial_empty_schema_state(): void
    {
        $migration = require database_path('migrations/2026_08_07_000001_create_historical_contract_interpretation_tables.php');
        $migration->down();
        Schema::table('contract_price_annual_costs', function (Blueprint $table): void {
            $table->string('historical_evidence_grade', 80)->nullable();
        });

        $migration->down();

        $this->assertFalse(Schema::hasColumn('contract_price_annual_costs', 'historical_evidence_grade'));
        $this->assertFalse(Schema::hasTable('contract_historical_interpretation_episodes'));
        $migration->up();
    }

    public function test_migration_rollback_refuses_to_drop_populated_audit_tables(): void
    {
        $contract = $this->contract();
        $this->snapshotAndComponent($contract, '2026-01-01');
        $hash = $this->planHash();
        $this->artisan('contracts:backfill-historical-interpretations', [
            '--apply' => true,
            '--plan-hash' => $hash,
        ])->assertSuccessful();

        $migration = require database_path('migrations/2026_08_07_000001_create_historical_contract_interpretation_tables.php');
        try {
            $migration->down();
            $this->fail('A populated historical audit table must block rollback.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('Rollback refused', $exception->getMessage());
            $this->assertStringContainsString('contains rows', $exception->getMessage());
            $this->assertTrue(Schema::hasTable('contract_historical_interpretation_episodes'));
            $this->assertTrue(Schema::hasTable('contract_historical_interpretations'));
        } finally {
            ContractHistoricalInterpretation::query()->delete();
            ContractHistoricalInterpretationEpisode::query()->delete();
        }
    }

    public function test_discovery_uses_bounded_contract_chunks_and_preserves_complete_chronology(): void
    {
        $company = Company::create(['name' => 'Chunk Energy', 'name_slug' => 'chunk-energy']);
        $snapshotRows = [];
        $componentRows = [];
        $dates = ['2026-01-01', '2026-01-02', '2026-01-03'];

        for ($index = 1; $index <= 205; $index++) {
            $contract = ElectricityContract::factory()->forCompany($company)->create([
                'id' => sprintf('chunk-contract-%03d', $index),
            ]);
            foreach ($dates as $date) {
                $snapshotRows[] = [
                    'snapshot_date' => $date,
                    'contract_id' => $contract->id,
                    'company_name' => $contract->company_name,
                    'contract_name' => $contract->name,
                    'pricing_model' => $contract->pricing_model,
                    'contract_type' => $contract->contract_type,
                    'fixed_time_range' => $contract->fixed_time_range,
                    'metering' => $contract->metering,
                    'segment_key' => 'fixed_open',
                    'pricing_basis' => 'observed_seller_data',
                    'has_discount' => false,
                    'includes_spot_price' => false,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
                $componentRows[] = $this->componentRow($contract, $date, $contract->id.'-'.$date);
            }
        }
        foreach (array_chunk($snapshotRows, 100) as $rows) {
            DB::table('contract_price_snapshots')->insert($rows);
        }
        foreach (array_chunk($componentRows, 100) as $rows) {
            DB::table('price_components')->insert($rows);
        }

        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = ['sql' => $query->sql, 'bindings' => $query->bindings];
        });
        $result = app(HistoricalContractEpisodeBuilder::class)->discover(
            \Carbon\CarbonImmutable::parse('2026-07-22'),
        );

        $this->assertSame(615, $result['scanned_contract_days']);
        $this->assertSame(615, $result['eligible_days']);
        $this->assertCount(205, $result['episodes']);
        $this->assertSame('chunk-contract-001', $result['episodes'][0]['contract_id']);
        $this->assertSame('2026-01-01', $result['episodes'][0]['episode_start']);
        $this->assertSame('2026-01-03', $result['episodes'][0]['episode_end']);
        $this->assertSame('chunk-contract-205', $result['episodes'][204]['contract_id']);

        $snapshotQueries = array_values(array_filter($queries, fn (array $query): bool => str_contains($query['sql'], 'contract_price_snapshots')
            && str_contains($query['sql'], 'contract_id" in')));
        $componentQueries = array_values(array_filter($queries, fn (array $query): bool => str_contains($query['sql'], 'price_components')
            && str_contains($query['sql'], 'electricity_contract_id" in')));
        $contractQueries = array_values(array_filter($queries, fn (array $query): bool => str_contains($query['sql'], 'from "electricity_contracts"')
            && str_contains($query['sql'], '"id" in')));
        $sourceQueries = array_values(array_filter($queries, fn (array $query): bool => str_contains($query['sql'], 'from "contract_source_snapshots"')
            && str_contains($query['sql'], '"contract_id" in')));
        $chunkCount = (int) ceil(205 / HistoricalContractEpisodeBuilder::DISCOVERY_CONTRACT_CHUNK_SIZE);
        $this->assertCount(1 + ($chunkCount * 4), $queries);
        $this->assertCount($chunkCount, $snapshotQueries);
        $this->assertCount($chunkCount, $componentQueries);
        $this->assertCount($chunkCount, $contractQueries);
        $this->assertCount($chunkCount, $sourceQueries);
        $dateQueryBindingCounts = array_fill(0, $chunkCount - 1, HistoricalContractEpisodeBuilder::DISCOVERY_CONTRACT_CHUNK_SIZE + 1);
        $dateQueryBindingCounts[] = 6;
        sort($dateQueryBindingCounts);
        $identityQueryBindingCounts = array_fill(0, $chunkCount - 1, HistoricalContractEpisodeBuilder::DISCOVERY_CONTRACT_CHUNK_SIZE);
        $identityQueryBindingCounts[] = 5;
        sort($identityQueryBindingCounts);
        $this->assertSame($dateQueryBindingCounts, collect($snapshotQueries)->map(fn (array $query): int => count($query['bindings']))->sort()->values()->all());
        $this->assertSame($dateQueryBindingCounts, collect($componentQueries)->map(fn (array $query): int => count($query['bindings']))->sort()->values()->all());
        $this->assertSame($identityQueryBindingCounts, collect($contractQueries)->map(fn (array $query): int => count($query['bindings']))->sort()->values()->all());
        $this->assertSame($identityQueryBindingCounts, collect($sourceQueries)->map(fn (array $query): int => count($query['bindings']))->sort()->values()->all());
        foreach (array_merge($snapshotQueries, $componentQueries, $contractQueries, $sourceQueries) as $query) {
            $this->assertStringNotContainsString('select *', strtolower($query['sql']));
        }
    }

    public function test_command_planning_retains_only_one_full_discovery_chunk(): void
    {
        $company = Company::create(['name' => 'Compact Energy', 'name_slug' => 'compact-energy']);
        $snapshotRows = [];
        $componentRows = [];

        for ($contractIndex = 1; $contractIndex <= 30; $contractIndex++) {
            $contract = ElectricityContract::factory()->forCompany($company)->create([
                'id' => sprintf('compact-contract-%02d', $contractIndex),
            ]);
            for ($day = 0; $day < 12; $day++) {
                $date = \Carbon\CarbonImmutable::parse('2026-01-01')->addDays($day)->toDateString();
                $snapshotRows[] = [
                    'snapshot_date' => $date,
                    'contract_id' => $contract->id,
                    'company_name' => $contract->company_name,
                    'contract_name' => $contract->name,
                    'pricing_model' => $contract->pricing_model,
                    'contract_type' => $contract->contract_type,
                    'fixed_time_range' => $contract->fixed_time_range,
                    'metering' => $contract->metering,
                    'segment_key' => 'fixed_open',
                    'pricing_basis' => 'observed_seller_data',
                    'has_discount' => false,
                    'includes_spot_price' => false,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
                $component = $this->componentRow($contract, $date, $contract->id.'-'.$date);
                $component['price'] = $day % 2 === 0 ? 7.2 : 8.2;
                $componentRows[] = $component;
            }
        }
        foreach (array_chunk($snapshotRows, 100) as $rows) {
            DB::table('contract_price_snapshots')->insert($rows);
        }
        foreach (array_chunk($componentRows, 100) as $rows) {
            DB::table('price_components')->insert($rows);
        }

        Artisan::call('contracts:backfill-historical-interpretations');
        $output = Artisan::output();

        $this->assertStringContainsString('Normalized selected episodes: 360', $output);
        $this->assertStringContainsString('Peak full episode payloads retained: 300', $output);
        $this->assertSame(0, ContractHistoricalInterpretationEpisode::count());
        $this->assertSame(0, ContractHistoricalInterpretation::count());
    }

    public function test_dry_run_is_read_only_and_reports_ineligible_days(): void
    {
        Queue::fake();
        $contract = $this->contract();
        $this->snapshotAndComponent($contract, '2026-01-01');
        DB::table('price_components')->insert($this->componentRow($contract, '2026-01-02', 'component-only'));

        $this->artisan('contracts:backfill-historical-interpretations')
            ->expectsOutputToContain('Mode: DRY RUN / READ ONLY')
            ->expectsOutputToContain('"component_only":1')
            ->expectsOutputToContain('Plan hash:')
            ->assertSuccessful();

        $this->assertSame(0, ContractHistoricalInterpretationEpisode::count());
        $this->assertSame(0, ContractHistoricalInterpretation::count());
        Queue::assertNothingPushed();
    }

    public function test_first_immutable_text_is_graded_as_backcast_and_exact_identity_overrides_it(): void
    {
        $contract = $this->contract();
        $this->snapshotAndComponent($contract, '2026-01-01');
        ContractSourceSnapshot::create([
            'contract_id' => $contract->id,
            'source_fingerprint' => str_repeat('a', 64),
            'source_payload' => [
                'Name' => 'Later source name',
                'Details' => [
                    'PricingModel' => 'Spot',
                    'ShortDescription' => '<p>Later immutable text</p>',
                    'Pricing' => ['PriceComponents' => []],
                ],
            ],
            'first_observed_at' => '2026-07-23 10:00:00',
            'last_observed_at' => '2026-07-23 10:00:00',
        ]);

        $hash = $this->planHash();
        $this->artisan('contracts:backfill-historical-interpretations', [
            '--apply' => true,
            '--plan-hash' => $hash,
        ])->assertSuccessful();

        $episode = ContractHistoricalInterpretationEpisode::sole();
        $this->assertSame('exact_components_first_immutable_text_backcast', $episode->evidence_grade->value);
        $this->assertSame($contract->name, $episode->analysis_input['contract_name']);
        $this->assertSame('FixedPrice', $episode->analysis_input['pricing_model']);
        $this->assertSame('Later immutable text', $episode->analysis_input['short_description']);
        $this->assertTrue($episode->analysis_input['_historical_provenance']['text_is_backcast']);
    }

    public function test_last_observed_input_retains_available_metadata_with_explicit_limits(): void
    {
        $contract = $this->contract();
        $contract->update([
            'target_group' => 'Household',
            'spot_price_selection' => 'Hourly',
            'pricing_name' => 'Retained pricing name',
            'time_period_definitions' => [['Name' => 'Day']],
            'billing_frequency' => ['Months' => 1],
            'consumption_limitation_min_x_kwh_per_y' => 1500,
            'consumption_limitation_max_x_kwh_per_y' => 10000,
            'short_description' => '<p>Retained description</p>',
        ]);
        $this->snapshotAndComponent($contract, '2026-01-01');

        $hash = $this->planHash();
        $this->artisan('contracts:backfill-historical-interpretations', [
            '--apply' => true,
            '--plan-hash' => $hash,
        ])->assertSuccessful();

        $episode = ContractHistoricalInterpretationEpisode::sole();
        $input = $episode->analysis_input;
        $this->assertSame(
            [...ContractInterpretationInputBuilder::TOP_LEVEL_KEYS, '_historical_provenance'],
            array_keys($input),
        );
        $this->assertSame($contract->api_id, $input['api_id']);
        $this->assertSame('Household', $input['target_group']);
        $this->assertSame('Hourly', $input['spot_price_selection']);
        $this->assertSame('Retained pricing name', $input['pricing_name']);
        $this->assertSame([['Name' => 'Day']], $input['time_period_definitions']);
        $this->assertSame(['Months' => 1], $input['billing_frequency']);
        $this->assertSame(['MinXKWhPerY' => 1500, 'MaxXKWhPerY' => 10000], $input['consumption_limitation']);
        $this->assertSame('Retained description', $input['short_description']);
        $this->assertSame('exact_components_last_observed_text_backcast', $episode->evidence_grade->value);
        $this->assertTrue($input['_historical_provenance']['text_is_backcast']);
        $this->assertStringContainsString('not proven contemporaneous', $input['_historical_provenance']['limitations']);
        $this->assertSame($contract->name, $input['contract_name']);
        $this->assertSame('FixedPrice', $input['pricing_model']);
    }

    public function test_historical_addendum_forbids_provenance_citations_as_seller_evidence(): void
    {
        $addendum = file_get_contents(resource_path('contract-interpretation/historical-system-prompt-addendum-v3.md'));

        $this->assertIsString($addendum);
        $this->assertStringContainsString('control metadata, not seller evidence', $addendum);
        $this->assertStringContainsString('Never cite `_historical_provenance`', $addendum);
        $this->assertStringContainsString('must be null. These episode inputs have no exact structured recurring-period date fields', $addendum);
        $this->assertStringContainsString('Never use `misleading_first_12_months=detected`', $addendum);
    }

    public function test_exact_row_id_change_changes_plan_hash(): void
    {
        $contract = $this->contract();
        $this->snapshotAndComponent($contract, '2026-01-01');

        $firstHash = $this->planHash();
        DB::table('price_components')
            ->where('id', $contract->id.'-2026-01-01')
            ->update(['id' => 'replacement-row-id']);
        $secondHash = $this->planHash();

        $this->assertNotSame($firstHash, $secondHash);
    }

    public function test_apply_second_pass_rejects_exact_manifest_drift_before_persistence(): void
    {
        $contract = $this->contract();
        $this->snapshotAndComponent($contract, '2026-01-01');
        $hash = $this->planHash();
        $realBuilder = app(HistoricalContractEpisodeBuilder::class);
        $chunks = iterator_to_array($realBuilder->discoverChunks(\Carbon\CarbonImmutable::parse('2026-07-22')));
        $changedChunks = $chunks;
        $changedChunks[0]['episodes'][0]['evidence_manifest']['target_days'][0]['snapshot_id']++;
        $changedChunks[0]['episodes'][0]['manifest_fingerprint'] = app(\App\Services\ContractInterpretation\HistoricalInterpretationFingerprint::class)
            ->manifest($changedChunks[0]['episodes'][0]['evidence_manifest']);
        $pass = 0;
        $builder = \Mockery::mock(HistoricalContractEpisodeBuilder::class);
        $builder->shouldReceive('discoverChunks')
            ->twice()
            ->andReturnUsing(function () use (&$pass, $chunks, $changedChunks): \Generator {
                $selected = $pass++ === 0 ? $chunks : $changedChunks;
                foreach ($selected as $index => $chunk) {
                    yield $index => $chunk;
                }
            });
        $this->instance(HistoricalContractEpisodeBuilder::class, $builder);

        $this->artisan('contracts:backfill-historical-interpretations', [
            '--apply' => true,
            '--plan-hash' => $hash,
        ])
            ->expectsOutputToContain('Historical evidence manifest changed after plan verification.')
            ->assertFailed();

        $this->assertSame(0, ContractHistoricalInterpretationEpisode::count());
        $this->assertSame(0, ContractHistoricalInterpretation::count());
    }

    public function test_apply_requires_exact_plan_hash_and_is_idempotent(): void
    {
        $contract = $this->contract();
        $this->snapshotAndComponent($contract, '2026-01-01');

        $this->artisan('contracts:backfill-historical-interpretations', [
            '--apply' => true,
            '--plan-hash' => str_repeat('0', 64),
        ])->assertFailed();
        $this->assertSame(0, ContractHistoricalInterpretationEpisode::count());

        $hash = $this->planHash();
        $this->artisan('contracts:backfill-historical-interpretations', [
            '--apply' => true,
            '--plan-hash' => $hash,
        ])->assertSuccessful();
        $this->assertSame(1, ContractHistoricalInterpretationEpisode::count());
        $this->assertSame(1, ContractHistoricalInterpretation::count());

        $hash = $this->planHash();
        $this->artisan('contracts:backfill-historical-interpretations', [
            '--apply' => true,
            '--plan-hash' => $hash,
        ])->assertSuccessful();
        $this->assertSame(1, ContractHistoricalInterpretationEpisode::count());
        $this->assertSame(1, ContractHistoricalInterpretation::count());
    }

    public function test_date_options_require_exact_valid_calendar_dates(): void
    {
        $this->artisan('contracts:backfill-historical-interpretations', [
            '--from' => 'January 1 2026',
        ])
            ->expectsOutputToContain('--from must be an exact valid date in YYYY-MM-DD format.')
            ->assertFailed();

        $this->artisan('contracts:backfill-historical-interpretations', [
            '--to' => '2026-02-30',
        ])
            ->expectsOutputToContain('--to must be an exact valid date in YYYY-MM-DD format.')
            ->assertFailed();
    }

    public function test_cost_estimate_uses_completed_aggregate_observed_mean_once_per_actionable_analysis(): void
    {
        $completed = $this->contract('contract-completed');
        $pending = $this->contract('contract-pending');
        $this->snapshotAndComponent($completed, '2026-01-01');
        $this->snapshotAndComponent($pending, '2026-01-01');
        $hash = $this->planHash();
        $this->artisan('contracts:backfill-historical-interpretations', [
            '--apply' => true,
            '--plan-hash' => $hash,
        ])->assertSuccessful();
        ContractHistoricalInterpretation::query()
            ->where('contract_id', $completed->id)
            ->sole()
            ->update([
                'status' => ContractHistoricalInterpretation::STATUS_VALIDATED,
                'usage' => ['cost' => 0.12, 'total_tokens' => 300],
                'completed_at' => now(),
            ]);

        $this->artisan('contracts:backfill-historical-interpretations')
            ->expectsOutputToContain('Minimum initial calls: 1')
            ->expectsOutputToContain('Maximum normal calls including repairs: 3')
            ->expectsOutputToContain('Transport retries can exceed the maximum normal call count.')
            ->expectsOutputToContain('ESTIMATED observed-mean total provider cost: $0.120000')
            ->assertSuccessful();
    }

    public function test_dispatch_limit_is_deterministic_and_api_key_blocks_dispatch_only(): void
    {
        Queue::fake();
        $first = $this->contract('contract-a');
        $second = $this->contract('contract-b');
        $this->snapshotAndComponent($first, '2026-01-01');
        $this->snapshotAndComponent($second, '2026-01-01');
        config()->set('services.openrouter.api_key', null);

        $hash = $this->planHash(['--dispatch-limit' => 1]);
        $this->artisan('contracts:backfill-historical-interpretations', [
            '--dispatch-limit' => 1,
            '--apply' => true,
            '--dispatch' => true,
            '--yes' => true,
            '--plan-hash' => $hash,
        ])->assertFailed();
        $this->assertSame(0, ContractHistoricalInterpretation::count());

        $hash = $this->planHash(['--dispatch-limit' => 1]);
        $this->artisan('contracts:backfill-historical-interpretations', [
            '--dispatch-limit' => 1,
            '--apply' => true,
            '--plan-hash' => $hash,
        ])->assertSuccessful();
        $this->assertSame(2, ContractHistoricalInterpretation::count());

        config()->set('services.openrouter.api_key', 'test-key');
        $hash = $this->planHash(['--dispatch-limit' => 1]);
        $this->artisan('contracts:backfill-historical-interpretations', [
            '--dispatch-limit' => 1,
            '--apply' => true,
            '--dispatch' => true,
            '--yes' => true,
            '--plan-hash' => $hash,
        ])->assertSuccessful();
        Queue::assertPushed(AnalyzeHistoricalContractEpisode::class, 1);
        Queue::assertPushed(fn (AnalyzeHistoricalContractEpisode $job): bool => $job->interpretationId === ContractHistoricalInterpretation::query()
            ->where('contract_id', 'contract-a')->value('id'));
    }

    public function test_failed_and_processing_work_need_explicit_retry_or_resume(): void
    {
        $contract = $this->contract();
        $this->snapshotAndComponent($contract, '2026-01-01');
        $hash = $this->planHash();
        $this->artisan('contracts:backfill-historical-interpretations', [
            '--apply' => true,
            '--plan-hash' => $hash,
        ])->assertSuccessful();
        $row = ContractHistoricalInterpretation::sole();

        $row->update(['status' => 'failed']);
        Artisan::call('contracts:backfill-historical-interpretations');
        $this->assertStringContainsString('Actionable analyses: 0', Artisan::output());
        $hash = $this->planHash(['--retry-failed' => true]);
        $this->artisan('contracts:backfill-historical-interpretations', [
            '--retry-failed' => true,
            '--apply' => true,
            '--plan-hash' => $hash,
        ])->assertSuccessful();
        $this->assertSame('pending', $row->fresh()->status);

        $row->update(['status' => 'processing', 'started_at' => now()->subHours(2)]);
        Artisan::call('contracts:backfill-historical-interpretations');
        $this->assertStringContainsString('Actionable analyses: 0', Artisan::output());
        $hash = $this->planHash(['--resume-stale-processing' => 60]);
        $this->artisan('contracts:backfill-historical-interpretations', [
            '--resume-stale-processing' => 60,
            '--apply' => true,
            '--plan-hash' => $hash,
        ])->assertSuccessful();
        $this->assertSame('pending', $row->fresh()->status);
    }

    public function test_model_uniqueness_and_relationships_have_no_current_side_effects(): void
    {
        $contract = $this->contract();
        $this->snapshotAndComponent($contract, '2026-01-01');
        $canonicalBefore = $contract->canonical_pricing;
        $hash = $this->planHash();
        $this->artisan('contracts:backfill-historical-interpretations', [
            '--apply' => true,
            '--plan-hash' => $hash,
        ])->assertSuccessful();

        $episode = $contract->fresh()->historicalInterpretationEpisodes()->sole();
        $this->assertSame(1, $contract->historicalInterpretations()->count());
        $this->assertNull($contract->fresh()->published_interpretation_id);
        $this->assertSame($canonicalBefore, $contract->fresh()->canonical_pricing);

        $this->expectException(QueryException::class);
        ContractHistoricalInterpretationEpisode::create($episode->only([
            'contract_id', 'episode_start', 'episode_end', 'builder_version',
            'episode_fingerprint', 'evidence_fingerprint', 'manifest_fingerprint', 'evidence_grade',
            'analysis_input', 'evidence_manifest',
        ]));
    }

    private function planHash(array $options = []): string
    {
        Artisan::call('contracts:backfill-historical-interpretations', $options);
        preg_match('/Plan hash: ([a-f0-9]{64})/', Artisan::output(), $matches);
        $this->assertArrayHasKey(1, $matches, Artisan::output());

        return $matches[1];
    }

    private function contract(string $id = 'contract-1'): ElectricityContract
    {
        $company = Company::firstOrCreate([
            'name' => 'Test Energy',
        ], [
            'name_slug' => 'test-energy',
        ]);

        return ElectricityContract::factory()->forCompany($company)->create(['id' => $id]);
    }

    private function snapshotAndComponent(ElectricityContract $contract, string $date): void
    {
        DB::table('contract_price_snapshots')->insert([
            'snapshot_date' => $date,
            'contract_id' => $contract->id,
            'company_name' => $contract->company_name,
            'contract_name' => $contract->name,
            'pricing_model' => $contract->pricing_model,
            'contract_type' => $contract->contract_type,
            'fixed_time_range' => $contract->fixed_time_range,
            'metering' => $contract->metering,
            'segment_key' => 'fixed_open',
            'pricing_basis' => 'observed_seller_data',
            'has_discount' => false,
            'includes_spot_price' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('price_components')->insert($this->componentRow($contract, $date, $contract->id.'-'.$date));
    }

    private function componentRow(ElectricityContract $contract, string $date, string $id): array
    {
        return [
            'id' => $id,
            'price_date' => $date,
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
        ];
    }
}
