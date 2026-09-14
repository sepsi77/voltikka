<?php

namespace Tests\Feature;

use App\Jobs\AnalyzeContractSourceSnapshot;
use App\Jobs\WarmContractPriceStatisticsCache;
use App\Models\ActiveContract;
use App\Models\Company;
use App\Models\ContractInterpretation;
use App\Models\ContractPriceSnapshot;
use App\Models\ContractSourceObservation;
use App\Models\ContractSourceSnapshot;
use App\Models\DataFreshnessCheckpoint;
use App\Models\ElectricityContract;
use App\Models\ElectricityFuturesEodPrice;
use App\Services\Caching\ContractPriceCacheConflict;
use App\Services\Caching\ContractPriceCacheLifecycle;
use App\Services\CanonicalPricing\CanonicalContractPricingService;
use App\Services\CompanyListCacheService;
use App\Services\ContractImport\ContractImportCompletion;
use App\Services\ContractImport\ContractImportCompletionStopped;
use App\Services\ContractImport\ContractImportResult;
use App\Services\ContractInterpretation\ContractInterpretationPublisher;
use App\Services\ContractListCacheService;
use App\Services\ContractStatistics\ContractPriceStatisticsService;
use App\Services\MorningFreshness\MorningFreshnessResult;
use App\Services\MorningFreshness\MorningJobFreshnessService;
use App\Services\PriceForecasting\FixedTermPriceForecastService;
use Carbon\CarbonImmutable;
use Illuminate\Bus\UniqueLock;
use Illuminate\Database\Query\Grammars\MySqlGrammar;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Mockery;
use RuntimeException;
use Tests\Concerns\CapturesSentryIssues;
use Tests\TestCase;

class DeferredContractImportCompletionTest extends TestCase
{
    use CapturesSentryIssues;
    use RefreshDatabase;

    private const DATE = '2026-09-16';

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse(self::DATE.' 06:00:00', 'Europe/Helsinki'));
        config(['canonical_pricing.enabled' => true, 'contract_interpretation.enabled' => true, 'cache.default' => 'array']);
        app()->forgetScopedInstances();
        Queue::fake();
        Http::preventStrayRequests();
        $this->captureSentryIssues();
    }

    public function test_pending_then_real_publication_activates_new_contract_and_rebuilds_statistics_and_all_nine_payloads(): void
    {
        $first = $this->fixture('old', true);
        $new = $this->fixture('new', false);
        $this->begin([$first, $new]);
        $this->assertSame('waiting', app(ContractImportCompletion::class)->tick());
        $this->assertSame(1, $this->checkpoint()->metadata['checks']);
        $this->assertNull(Cache::get(ContractPriceCacheLifecycle::ACTIVE_KEY));
        $this->assertSame([], $this->sentryIssues);
        $this->travel(10)->minutes();
        $this->publish($first);
        $this->publish($new);
        $this->assertDatabaseHas('active_contracts', ['id' => 'new']);
        $this->assertSame('ready', app(ContractImportCompletion::class)->tick());
        $row = $this->checkpoint();
        $this->assertSame(['new', 'old'], $row->metadata['active_contract_ids']);
        $this->assertSame(2, $row->metadata['checks']);
        $this->assertSame('2026-09-16T06:10:00+03:00', $row->metadata['statistics_started_at']);
        $this->assertDatabaseHas('contract_price_snapshots', ['contract_id' => 'new', 'snapshot_date' => self::DATE.' 00:00:00']);
        $list = app(ContractListCacheService::class);
        foreach (ContractListCacheService::PRESET_CONSUMPTIONS as $consumption) {
            $payload = Cache::get($list->getCacheKey($consumption));
            $this->assertNotNull($payload);
            $this->assertArrayHasKey('new', $payload['contracts']);
        }
        $this->assertNotNull(Cache::get(app(CompanyListCacheService::class)->getCacheKey(5000)));
        $this->assertTrue(app(ContractImportCompletion::class)->readyCurrent($row->metadata));
        $before = $row->getRawOriginal();
        $this->assertSame('no_pending_completion', app(ContractImportCompletion::class)->tick());
        $this->assertSame($before, $this->checkpoint()->getRawOriginal());
        Queue::assertNotPushed(AnalyzeContractSourceSnapshot::class);
        Http::assertNothingSent();
    }

    public function test_already_published_import_is_immediately_ready_including_relational_blocked_publication(): void
    {
        $fixture = $this->fixture('ready', true);
        $this->publish($fixture);
        $fixture[2]->refresh()->update(['relational_pricing_published' => false]);
        $this->begin([$fixture], false);
        $this->assertSame('ready', $this->checkpoint()->status);
        $this->assertTrue(app(ContractImportCompletion::class)->readyCurrent($this->checkpoint()->metadata));
    }

    public function test_typed_refresh_conflict_is_deferred_but_storage_failure_is_terminal_and_never_retried(): void
    {
        $fixture = $this->fixture('typed', true);
        $this->publish($fixture);
        $real = app(ContractListCacheService::class);
        $proxy = Mockery::mock($real)->makePartial();
        $proxy->shouldReceive('refresh')->once()->andThrow(ContractPriceCacheConflict::evidenceChanged());
        app()->instance(ContractListCacheService::class, $proxy);
        $this->begin([$fixture]);
        $this->assertSame([], $this->sentryIssues);
        app()->instance(ContractListCacheService::class, $real);
        $this->assertSame('ready', app(ContractImportCompletion::class)->tick());

        $this->checkpoint()->update(['status' => ContractImportCompletion::PENDING]);
        $proxy = Mockery::mock($real)->makePartial();
        $proxy->shouldReceive('refresh')->once()->andThrow(new RuntimeException('private token=https://secret.invalid'));
        app()->instance(ContractListCacheService::class, $proxy);
        $this->assertSame('failed:price_cache_refresh', app(ContractImportCompletion::class)->tick());
        $this->assertSame('no_pending_completion', app(ContractImportCompletion::class)->tick());
        $this->assertImportIssue('contracts', 'error', ['price_cache_refresh' => 1]);
        $this->assertStringNotContainsString('secret', json_encode($this->sentryIssues[0]->getContexts()));
        $this->assertStringNotContainsString('secret', json_encode($this->checkpoint()->metadata));
    }

    public function test_dry_run_is_read_only_for_waiting_eligible_and_expired_claims(): void
    {
        $fixture = $this->fixture('dry', false);
        $this->begin([$fixture]);
        foreach (['waiting', 'eligible', 'interrupted_execution'] as $expected) {
            if ($expected === 'eligible') {
                $this->publish($fixture);
            }
            if ($expected === 'interrupted_execution') {
                $this->metadata(['claim_token' => (string) Str::uuid(), 'claim_until' => now()->subSecond()->toIso8601String()]);
            }
            $queries = [];
            DB::listen(function ($query) use (&$queries): void {
                $queries[] = $query->sql;
            });
            $before = $this->checkpoint()->getRawOriginal();
            $cacheBefore = serialize(Cache::getStore());
            $this->artisan('contracts:complete-import', ['--dry-run' => true])
                ->expectsOutput('Contract import completion: '.$expected)->assertSuccessful();
            $this->assertSame($before, $this->checkpoint()->getRawOriginal());
            $this->assertSame($cacheBefore, serialize(Cache::getStore()));
            $this->assertSame([], array_values(array_filter($queries, fn ($sql) => preg_match('/^\s*(insert|update|delete|replace|create|begin)/i', $sql))));
        }
        $this->assertSame([], $this->sentryIssues);
    }

    public function test_deadline_check_budget_and_interrupted_claim_fail_once_without_statistics(): void
    {
        $fixture = $this->fixture('bounded', true);
        foreach ([
            ['checks' => 60, 'reason' => 'checks_exhausted'],
            ['deadline' => now()->subSecond()->toIso8601String(), 'reason' => 'deadline_exhausted'],
            ['claim_token' => (string) Str::uuid(), 'claim_until' => now()->subSecond()->toIso8601String(), 'reason' => 'interrupted_execution'],
        ] as $case) {
            $this->begin([$fixture]);
            $reason = $case['reason'];
            unset($case['reason']);
            $this->metadata($case);
            $this->assertSame('failed:'.$reason, app(ContractImportCompletion::class)->tick());
            $this->assertSame('no_pending_completion', app(ContractImportCompletion::class)->tick());
        }
        $this->assertCount(3, $this->sentryIssues);
    }

    public function test_same_day_only_and_old_failed_stage_only_records_are_not_healed(): void
    {
        $fixture = $this->fixture('date', false);
        $this->begin([$fixture]);
        $this->travel(1)->days();
        $this->assertSame('failed:date_expired', app(ContractImportCompletion::class)->tick());
        $this->checkpoint()->update(['status' => 'failed', 'metadata' => ['stage' => 'post_import']]);
        $before = $this->checkpoint()->getRawOriginal();
        $this->assertSame('no_pending_completion', app(ContractImportCompletion::class)->tick());
        $this->assertSame($before, $this->checkpoint()->getRawOriginal());
        $this->checkpoint()->update(['status' => ContractImportCompletion::PENDING]);
        $this->assertSame('invalid_manifest', app(ContractImportCompletion::class)->tick());
    }

    public function test_transport_failed_waits_but_validation_rejection_settles_without_counting_as_published(): void
    {
        $fixture = $this->fixture('rejected', false);
        $retained = $this->fixture('retained', true);
        $this->publish($retained);
        $this->begin([$fixture, $retained]);
        $fixture[2]->update(['status' => 'failed', 'error' => 'transport']);
        $this->assertSame('waiting', app(ContractImportCompletion::class)->tick());
        $fixture[2]->update(['validation_errors' => ['deterministic rejection']]);
        $this->assertSame('ready', app(ContractImportCompletion::class)->tick());
        $this->assertSame(['retained'], $this->checkpoint()->metadata['active_contract_ids']);
        ActiveContract::create(['id' => 'rejected']);
        $this->assertFalse(app(ContractImportCompletion::class)->readyCurrent($this->checkpoint()->metadata));
        $this->checkpoint()->update(['status' => ContractImportCompletion::PENDING]);
        $this->assertSame('ready', app(ContractImportCompletion::class)->tick());
        $this->assertSame('failed', $fixture[2]->fresh()->status);
        $this->assertNull($fixture[0]->fresh()->published_interpretation_id);
    }

    public function test_active_business_spot_rejection_completes_without_old_prices_or_blocking_fixed_forecasts(): void
    {
        $this->assertRejectedImportSafety(true);
    }

    public function test_active_fixed_rejection_completes_but_forecast_publication_gate_stays_closed(): void
    {
        $this->assertRejectedImportSafety(false);
    }

    private function assertRejectedImportSafety(bool $business): void
    {
        $normal = $this->fixture('normal-fixed', true);
        $rejected = $this->fixture('rejected-active', true);
        if ($business) {
            $rejected[0]->update(['target_group' => 'Company', 'pricing_model' => 'Spot', 'contract_type' => 'OpenEnded']);
        }
        $this->publish($normal);
        $this->publish($rejected);
        $this->begin([$normal, $rejected], false);
        if (! $business) {
            $this->assertDatabaseHas('contract_price_snapshots', ['contract_id' => 'rejected-active', 'energy_price_cents_per_kwh' => 10]);
        }
        $old = $rejected[2]->fresh();
        $snapshot = $rejected[1]->sourceSnapshot->replicate();
        $snapshot->source_fingerprint = hash('sha256', 'changed-rejected');
        $snapshot->source_payload = ['Id' => $rejected[0]->id, 'changed' => true];
        $snapshot->save();
        $observation = ContractSourceObservation::create([
            'contract_id' => $rejected[0]->id, 'source_snapshot_id' => $snapshot->id,
            'first_observed_at' => now(), 'last_observed_at' => now(),
        ]);
        $rejected[0]->update(['current_source_observation_id' => $observation->id]);
        $target = $old->replicate();
        $target->fill([
            'source_snapshot_id' => $snapshot->id, 'analysis_source_observation_id' => $observation->id,
            'analysis_fingerprint' => hash('sha256', 'failed-rejected'), 'status' => 'failed',
            'validation_errors' => ['deterministic rejection'], 'published_at' => null,
        ])->save();
        $rejected = [$rejected[0], $observation, $target];
        $tables = ['electricity_contracts', 'active_contracts', 'contract_source_snapshots',
            'contract_source_observations', 'contract_interpretations', 'price_components'];
        $before = [];
        foreach ($tables as $table) {
            $before[$table] = json_encode(DB::table($table)->orderBy('id')->get());
        }
        $this->begin([$normal, $rejected], false);
        $facts = $this->checkpoint()->metadata;
        $this->assertTrue(app(ContractImportCompletion::class)->readyCurrent($facts));
        $episode = collect($facts['episodes'])->firstWhere('contract_id', $rejected[0]->id);
        $this->assertSame($target->id, $episode['interpretation_id']);
        $proof = collect($facts['ready_evidence'][1])->first(fn ($row) => $row[0] === $rejected[0]->id);
        $this->assertSame([$rejected[0]->id, $observation->id, $snapshot->id, $old->id, 'failed', null,
            $target->updated_at->toIso8601String()], $proof);
        foreach (ContractListCacheService::PRESET_CONSUMPTIONS as $consumption) {
            $payload = Cache::get(app(ContractListCacheService::class)->getCacheKey($consumption));
            $unsafe = $payload['contracts'][$rejected[0]->id];
            $this->assertFalse($unsafe['is_listed']);
            foreach (['total_cost', 'base_total_cost', 'monthly_fixed_fee', 'spot_price_margin', 'general_kwh_price'] as $key) {
                $this->assertNull($unsafe['calculated_cost'][$key], $key);
            }
            $this->assertSame(['normal-fixed'], $payload['sorted_ids']);
        }
        $this->assertDatabaseMissing('contract_price_snapshots', ['contract_id' => $rejected[0]->id]);
        $this->assertDatabaseHas('contract_price_snapshots', ['contract_id' => 'normal-fixed', 'energy_price_cents_per_kwh' => 10]);
        $this->assertSame(0, DB::table('contract_price_annual_costs')->where('contract_id', $rejected[0]->id)->whereNotNull('total_cost')->count());
        foreach ($tables as $table) {
            $this->assertSame($before[$table], json_encode(DB::table($table)->orderBy('id')->get()), $table);
        }
        $this->readyEexInputs();
        $this->assertSame($business ? [] : ['contract_interpretations'], array_keys($this->forecastFreshness()->failures));
        $this->assertArrayHasKey('contract_interpretations', app(MorningJobFreshnessService::class)
            ->checkRetailPremium(CarbonImmutable::parse(self::DATE))->failures);
    }

    public function test_active_unsettled_targets_wait_to_the_bound_and_unknown_or_missing_targets_fail_closed(): void
    {
        $fixture = $this->fixture('unsettled-active', true);
        $this->begin([$fixture]);
        $completion = app(ContractImportCompletion::class);
        foreach (['pending', 'processing', 'failed'] as $status) {
            $fixture[2]->update(['status' => $status, 'validation_errors' => []]);
            $before = $fixture[2]->fresh()->getRawOriginal();
            $this->assertSame('waiting', $completion->tick());
            $this->assertSame($before, $fixture[2]->fresh()->getRawOriginal());
        }
        $facts = $this->checkpoint()->metadata;
        $fixture[2]->update(['status' => 'validated']);
        $this->assertSame('publication_missing', $completion->inspect($facts)['reason']);
        $facts['episodes'][0]['interpretation_id'] = null;
        $this->assertSame('publication_missing', $completion->inspect($facts)['reason']);
        $fixture[2]->update(['status' => 'failed']);
        $this->travel(121)->minutes();
        $this->assertSame('failed:deadline_exhausted', $completion->tick());
        $this->assertSame('failed', $fixture[2]->fresh()->status);
        $this->assertNull($fixture[0]->fresh()->published_interpretation_id);
    }

    public function test_pointer_change_or_date_scoped_target_mismatch_supersedes_manifest(): void
    {
        $fixture = $this->fixture('pointer', false);
        $this->begin([$fixture]);
        $next = ContractSourceObservation::create([
            'contract_id' => 'pointer', 'source_snapshot_id' => $fixture[1]->source_snapshot_id,
            'first_observed_at' => now(), 'last_observed_at' => now(),
        ]);
        $fixture[0]->update(['current_source_observation_id' => $next->id]);
        $this->assertSame('failed:superseded', app(ContractImportCompletion::class)->tick());
        $fixture[0]->update(['current_source_observation_id' => $fixture[1]->id]);
        $this->begin([$fixture]);
        $fixture[2]->update(['analysis_source_observation_id' => $next->id]);
        $this->assertSame('failed:superseded', app(ContractImportCompletion::class)->tick());
    }

    public function test_completion_write_guard_rejects_drift_without_becoming_a_permanent_reader_gate(): void
    {
        $fixture = $this->fixture('gate', true);
        $this->publish($fixture);
        $this->begin([$fixture], false);
        $facts = $this->checkpoint()->metadata;
        $this->assertTrue(app(ContractImportCompletion::class)->readyCurrent($facts));
        $other = $this->fixture('outside', false);
        $this->publish($other);
        $result = app(MorningJobFreshnessService::class)->checkRetailPremium(CarbonImmutable::parse(self::DATE));
        $this->assertArrayNotHasKey('contract_completion', $result->failures);
        ActiveContract::where('id', 'outside')->delete();
        $this->travel(1)->seconds();
        $fixture[2]->update(['published_at' => now()]);
        $this->assertFalse(app(ContractImportCompletion::class)->readyCurrent($facts));
        $this->checkpoint()->update(['metadata' => array_diff_key($facts, ['completion_version' => true])]);
        $result = app(MorningJobFreshnessService::class)->checkRetailPremium(CarbonImmutable::parse(self::DATE));
        $this->assertArrayNotHasKey('contract_completion', $result->failures);
    }

    public function test_duplicate_tick_does_not_claim_again_while_first_claim_builds(): void
    {
        $fixture = $this->fixture('duplicate', true);
        $this->begin([$fixture]);
        $this->publish($fixture);
        $real = app(ContractListCacheService::class);
        $proxy = Mockery::mock($real)->makePartial();
        $proxy->shouldReceive('refresh')->once()->andReturnUsing(function ($company, $guard) use ($real): int {
            $this->assertSame('claimed', app(ContractImportCompletion::class)->tick());
            $this->assertSame(1, $this->checkpoint()->metadata['checks']);

            return $real->refresh($company, $guard);
        });
        app()->instance(ContractListCacheService::class, $proxy);
        $this->assertSame('ready', app(ContractImportCompletion::class)->tick());
    }

    public function test_transaction_fence_runs_before_deletion_and_rolls_back_on_rejected_owner(): void
    {
        $fixture = $this->fixture('fenced', true);
        $this->publish($fixture);
        $this->begin([$fixture], false);
        $completion = app(ContractImportCompletion::class);
        $old = $this->checkpoint()->metadata['run_uuid'];
        $fence = $completion->statisticsFence(self::DATE, $old);
        $new = $completion->start(self::DATE);
        $this->assertFalse($completion->record(self::DATE, $old, 'failed', ['stage' => 'old']));
        $this->assertTrue($completion->record(self::DATE, $new, 'ready', ['stage' => 'new']));
        $before = ContractPriceSnapshot::all()->toArray();
        try {
            app(ContractPriceStatisticsService::class)->calculateForDate(self::DATE, ['fenced'], true, transactionFence: $fence);
            $this->fail('Stale ownership must fail before deleting statistics.');
        } catch (ContractImportCompletionStopped $exception) {
            $this->assertSame('ownership_changed', $exception->reason);
        }
        $this->assertSame($before, ContractPriceSnapshot::all()->toArray());
        $this->assertSame($new, $this->checkpoint()->metadata['run_uuid']);
        $this->assertSame('ready', $this->checkpoint()->status);
        $level = DB::transactionLevel();
        try {
            app(ContractPriceStatisticsService::class)->calculateForDate(self::DATE, ['fenced'], true, transactionFence: function () use ($level): void {
                $this->assertGreaterThan($level, DB::transactionLevel());
                $this->checkpoint()->update(['status' => 'must_rollback']);
                throw new RuntimeException('reject');
            });
        } catch (RuntimeException) {
            $this->assertSame('ready', $this->checkpoint()->status);
        }
        $this->assertSame($before, ContractPriceSnapshot::all()->toArray());
    }

    public function test_stale_same_run_claim_is_fenced_before_any_statistics_write(): void
    {
        $fixture = $this->fixture('claim', true);
        $this->begin([$fixture]);
        $token = (string) Str::uuid();
        $this->metadata(['claim_token' => $token, 'claim_until' => now()->addMinutes(30)->toIso8601String()]);
        $fence = app(ContractImportCompletion::class)->statisticsFence(self::DATE, $this->checkpoint()->metadata['run_uuid'], $token);
        $this->metadata(['claim_token' => (string) Str::uuid()]);
        $this->expectException(ContractImportCompletionStopped::class);
        app(ContractPriceStatisticsService::class)->calculateForDate(self::DATE, ['claim'], true, transactionFence: $fence);
    }

    public function test_new_full_run_before_old_statistics_fence_keeps_new_ready_statistics_and_checkpoint(): void
    {
        $fixture = $this->fixture('ordered', true);
        $this->begin([$fixture]);
        $this->publish($fixture);
        $real = app(ContractPriceStatisticsService::class);
        $oldCompletion = app(ContractImportCompletion::class);
        $proxy = Mockery::mock($real)->makePartial();
        $proxy->shouldReceive('calculateForDate')->once()->andReturnUsing(function ($date, $ids, $overwrite, $useCanonical, $fence) use ($real, $fixture, $oldCompletion): array {
            // A has passed its outer check but has not entered its statistics transaction.
            $new = $oldCompletion->start(self::DATE);
            // The outer lock is only an optimization; release it to model its expiry.
            Cache::lock(ContractImportCompletion::LOCK)->forceRelease();
            $result = $oldCompletion->initial($this->import([$fixture]), self::DATE, $new, [$fixture[1]->id => $fixture[2]->id]);
            $this->assertTrue($result->succeeded());
            $this->assertFalse($result->deferred);
            $oldCompletion->record(self::DATE, $new, 'ready', ['stage' => 'new_run'] + $result->completionMetadata);

            return $real->calculateForDate($date, $ids, $overwrite, $useCanonical, $fence);
        });
        app()->instance(ContractPriceStatisticsService::class, $proxy);
        $this->assertSame('ownership_changed', app(ContractImportCompletion::class)->tick());
        $this->assertSame('ready', $this->checkpoint()->status);
        $this->assertSame('new_run', $this->checkpoint()->metadata['stage']);
        $this->assertSame([], $this->sentryIssues);
    }

    public function test_scoped_statistics_take_the_same_transaction_fence_without_overwriting_pending_facts(): void
    {
        $fixture = $this->fixture('scoped', true);
        $this->begin([$fixture]);
        $before = $this->checkpoint()->getRawOriginal();
        $next = ContractSourceObservation::create([
            'contract_id' => 'scoped', 'source_snapshot_id' => $fixture[1]->source_snapshot_id,
            'first_observed_at' => now(), 'last_observed_at' => now(),
        ]);
        $fixture[0]->update(['current_source_observation_id' => $next->id]);
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });
        $result = app(ContractImportCompletion::class)->initial($this->import([$fixture]), self::DATE, null, []);
        $this->assertTrue($result->succeeded());
        $this->assertFalse($result->deferred);
        $this->assertSame($before, $this->checkpoint()->getRawOriginal());
        $firstDelete = collect($queries)->search(fn ($sql) => str_starts_with($sql, 'delete from "contract_price_snapshots"'));
        $this->assertNotFalse($firstDelete);
        $this->assertNotEmpty(array_filter(array_slice($queries, 0, $firstDelete), fn ($sql) => str_contains($sql, 'data_freshness_checkpoints') && str_starts_with($sql, 'select')));
        $this->assertSame('failed:superseded', app(ContractImportCompletion::class)->tick());
    }

    public function test_publication_during_statistics_defers_without_building_a_cache_candidate(): void
    {
        $fixture = $this->fixture('during-statistics', true);
        $this->begin([$fixture]);
        $this->publish($fixture);
        $real = app(ContractPriceStatisticsService::class);
        $proxy = Mockery::mock($real)->makePartial();
        $proxy->shouldReceive('calculateForDate')->once()->andReturnUsing(function ($date, $ids, $overwrite, $canonical, $fence) use ($real, $fixture): array {
            $result = $real->calculateForDate($date, $ids, $overwrite, $canonical, $fence);
            $this->travel(1)->seconds();
            $this->publish($fixture);

            return $result;
        });
        app()->instance(ContractPriceStatisticsService::class, $proxy);
        $cache = Mockery::mock(app(ContractListCacheService::class))->makePartial();
        $cache->shouldNotReceive('refresh');
        app()->instance(ContractListCacheService::class, $cache);
        $this->assertSame('waiting', app(ContractImportCompletion::class)->tick());
        $this->assertSame(ContractImportCompletion::PENDING, $this->checkpoint()->status);
        $this->assertSame([], $this->sentryIssues);
    }

    public function test_publication_during_candidate_build_retires_both_candidates_and_waits_for_new_statistics(): void
    {
        $fixture = $this->fixture('during-build', true);
        $this->begin([$fixture]);
        $this->publish($fixture);
        $real = app(CanonicalContractPricingService::class);
        $proxy = Mockery::mock($real)->makePartial();
        $calls = 0;
        $proxy->shouldReceive('metricsForContracts')->andReturnUsing(function ($contracts, $usage) use ($real, $fixture, &$calls) {
            $result = $real->metricsForContracts($contracts, $usage);
            if (++$calls === 1) {
                $this->travel(1)->seconds();
                $this->publish($fixture);
            }

            return $result;
        });
        app()->instance(CanonicalContractPricingService::class, $proxy);
        app()->forgetScopedInstances();
        $this->assertSame('waiting', app(ContractImportCompletion::class)->tick());
        $this->assertSame(16, $calls);
        $this->assertCount(4, Cache::get(ContractPriceCacheLifecycle::RETIRED_KEY));
        $this->assertSame(ContractImportCompletion::PENDING, $this->checkpoint()->status);
        $this->assertSame([], $this->sentryIssues);
        app()->instance(CanonicalContractPricingService::class, $real);
        app()->forgetScopedInstances();
        $this->assertSame('ready', app(ContractImportCompletion::class)->tick());
    }

    public function test_publication_in_the_last_ready_write_window_is_rejected_by_the_morning_gate(): void
    {
        $fixture = $this->fixture('finalize', true);
        $this->begin([$fixture]);
        $this->publish($fixture);
        DataFreshnessCheckpoint::updating(function (DataFreshnessCheckpoint $row) use ($fixture): void {
            if ($row->isDirty('status') && $row->status === 'ready') {
                $this->travel(1)->seconds();
                $this->publish($fixture);
            }
        });
        // A relevant publication in the last write window keeps the existing recoverable order failure.
        $this->assertSame('ready', app(ContractImportCompletion::class)->tick());
        $this->assertFalse(app(ContractImportCompletion::class)->readyCurrent($this->checkpoint()->metadata));
        $this->readyEexInputs();
        $this->assertSame(['statistics_publication_order'], array_keys(app(MorningJobFreshnessService::class)
            ->checkFixedTermForecast(CarbonImmutable::parse(self::DATE))->failures));
    }

    public function test_statistics_failure_after_claim_is_terminal_and_does_not_retry_or_leak_details(): void
    {
        $fixture = $this->fixture('statistics-error', true);
        $this->begin([$fixture]);
        $this->publish($fixture);
        $statistics = Mockery::mock(ContractPriceStatisticsService::class);
        $statistics->shouldReceive('calculateForDate')->once()->andThrow(new RuntimeException('private body token'));
        app()->instance(ContractPriceStatisticsService::class, $statistics);
        $this->assertSame('failed:daily_statistics', app(ContractImportCompletion::class)->tick());
        $this->assertSame('no_pending_completion', app(ContractImportCompletion::class)->tick());
        $this->assertImportIssue('contracts', 'error', ['daily_statistics' => 1]);
        $this->assertStringNotContainsString('private', json_encode($this->sentryIssues[0]->getContexts()));
    }

    public function test_generation_invalidation_before_or_after_refresh_cannot_bless_unbuilt_cache_facts(): void
    {
        $fixture = $this->fixture('generation-window', true);
        $this->begin([$fixture]);
        $this->publish($fixture);
        $real = app(ContractListCacheService::class);
        foreach (['before', 'after'] as $window) {
            $this->checkpoint()->update(['status' => ContractImportCompletion::PENDING]);
            $proxy = Mockery::mock($real)->makePartial();
            $proxy->shouldReceive('refresh')->once()->andReturnUsing(function ($company, $guard) use ($real, $window): int {
                if ($window === 'before') {
                    app(ContractPriceCacheLifecycle::class)->invalidate();
                }
                $version = $real->refresh($company, $guard);
                if ($window === 'after') {
                    app(ContractPriceCacheLifecycle::class)->invalidate();
                }

                return $version;
            });
            app()->instance(ContractListCacheService::class, $proxy);
            $this->assertSame('waiting', app(ContractImportCompletion::class)->tick());
            $this->assertSame(ContractImportCompletion::PENDING, $this->checkpoint()->status);
            app()->instance(ContractListCacheService::class, $real);
            $this->assertSame('ready', app(ContractImportCompletion::class)->tick());
        }
        $this->assertSame([], $this->sentryIssues);
    }

    public function test_checkpoint_json_key_order_does_not_change_claim_or_generation_identity(): void
    {
        $fixture = $this->fixture('json-order', true);
        $this->begin([$fixture]);
        $this->publish($fixture);
        DataFreshnessCheckpoint::updated(function (DataFreshnessCheckpoint $row): void {
            // MySQL stores object keys in its own order, unlike SQLite's JSON text.
            $facts = $row->metadata;
            ksort($facts);
            if (isset($facts['cache_generation'])) {
                ksort($facts['cache_generation']);
            }
            DB::table('data_freshness_checkpoints')->where('id', $row->id)->update(['metadata' => json_encode($facts)]);
        });
        $this->assertSame('ready', app(ContractImportCompletion::class)->tick());
        $this->assertTrue(app(ContractImportCompletion::class)->readyCurrent($this->checkpoint()->metadata));
    }

    public function test_statistics_fence_requests_mysql_for_update_before_any_date_write(): void
    {
        $fixture = $this->fixture('mysql-fence', true);
        $this->begin([$fixture]);
        $fence = app(ContractImportCompletion::class)->statisticsFence(self::DATE, $this->checkpoint()->metadata['run_uuid']);
        $connection = DB::connection();
        $grammar = $connection->getQueryGrammar();
        $connection->setQueryGrammar(new MySqlGrammar);
        try {
            app(ContractPriceStatisticsService::class)->calculateForDate(self::DATE, ['mysql-fence'], true, transactionFence: $fence);
            $this->fail('SQLite must reject the MySQL lock statement.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('data_freshness_checkpoints', $exception->getSql());
            $this->assertStringEndsWith('for update', $exception->getSql());
        } finally {
            $connection->setQueryGrammar($grammar);
        }
        $this->assertSame(ContractImportCompletion::PENDING, $this->checkpoint()->status);
    }

    public function test_completed_manifest_does_not_pin_forecast_readers_to_the_old_eex_cache_generation(): void
    {
        $fixture = $this->fixture('eex-reader', true);
        $this->publish($fixture);
        $this->begin([$fixture], false);
        $this->readyEexInputs();
        $facts = $this->checkpoint()->metadata;
        $this->assertTrue($this->forecastFreshness()->ready());

        app(ContractPriceCacheLifecycle::class)->invalidate();

        $this->assertFalse(app(ContractImportCompletion::class)->readyCurrent($facts));
        $this->assertSame([], $this->forecastFreshness()->failures);
        $this->assertSame($facts, $this->checkpoint()->metadata);
        $this->assertSame('no_pending_completion', app(ContractImportCompletion::class)->tick());
    }

    public function test_nonfixed_and_business_publication_and_new_inactive_source_do_not_block_completed_fixed_forecast(): void
    {
        $fixed = $this->fixture('reader-fixed', true);
        $spot = $this->fixture('reader-spot', true);
        $spot[0]->update(['pricing_model' => 'Spot', 'contract_type' => 'OpenEnded']);
        $business = $this->fixture('reader-business', true);
        $business[0]->update(['target_group' => 'Company']);
        foreach ([$fixed, $spot, $business] as $fixture) {
            $this->publish($fixture);
        }
        $this->begin([$fixed, $spot, $business], false);
        $this->readyEexInputs();
        $this->assertTrue($this->forecastFreshness()->ready());
        $this->travel(5)->minutes();
        $this->publish($spot);
        $this->publish($business);
        $this->fixture('reader-new-inactive', false);

        $this->assertFalse(app(ContractImportCompletion::class)->readyCurrent($this->checkpoint()->metadata));
        $this->assertSame([], $this->forecastFreshness()->failures);
    }

    public function test_completed_manifest_keeps_relevant_publication_only_recovery_and_real_statistics_recalculation(): void
    {
        $fixture = $this->fixture('reader-recovery', true);
        $this->publish($fixture);
        $this->begin([$fixture], false);
        $this->readyEexInputs();
        $facts = $this->checkpoint()->metadata;
        $snapshotIds = ContractPriceSnapshot::pluck('id')->all();
        $this->assertTrue($this->forecastFreshness()->ready());
        $this->travel(5)->minutes();
        $this->publish($fixture);
        $this->assertSame(['statistics_publication_order'], array_keys($this->forecastFreshness()->failures));
        $this->travel(1)->minutes();
        $warmCount = Queue::pushed(WarmContractPriceStatisticsCache::class)->count();
        // Model completion of the initial unique warm job before the later recovery.
        app(UniqueLock::class)->release(new WarmContractPriceStatisticsCache);
        $builder = $this->createMock(FixedTermPriceForecastService::class);
        $builder->expects($this->once())->method('buildForecasts')->willReturn(collect([[
            'forecast_date' => self::DATE, 'target_date' => '2026-10-16', 'horizon_days' => 30,
            'duration_months' => 12, 'target_quantile' => 'median', 'current_price_cents_per_kwh' => 10,
            'forecast_price_cents_per_kwh' => 10.1, 'expected_change_cents_per_kwh' => 0.1,
            'hedge_cost_cents_per_kwh' => 7, 'retail_premium_cents_per_kwh' => 3,
            'normal_retail_premium_cents_per_kwh' => 3, 'fair_price_cents_per_kwh' => 10,
            'gap_cents_per_kwh' => 0, 'futures_trade_date' => '2026-09-15', 'coverage_quality' => 'all_monthly',
            'confidence' => 'low', 'direction' => 'stable', 'consumer_signal' => 'neutral',
            'contract_count' => 1, 'model_version' => 'test-model',
            'source_metadata' => ['current_retail_pricing_basis' => 'canonical_calculation'],
        ]]));
        app()->instance(FixedTermPriceForecastService::class, $builder);

        $this->artisan('forecasting:run-fixed-contracts', ['--as-of' => self::DATE, '--require-freshness' => true])->assertSuccessful();

        $this->assertNotSame($snapshotIds, ContractPriceSnapshot::pluck('id')->all());
        $this->assertDatabaseCount('fixed_contract_price_forecasts', 1);
        Queue::assertPushed(WarmContractPriceStatisticsCache::class, $warmCount + 1);
        $this->assertSame($facts, $this->checkpoint()->metadata);
        $this->assertTrue(app(MorningJobFreshnessService::class)->checkFixedTermForecast(
            CarbonImmutable::parse(self::DATE), CarbonImmutable::now('Europe/Helsinki'),
        )->ready());
    }

    public function test_completed_manifest_still_requires_the_exact_pointed_fixed_episode_and_ready_status(): void
    {
        $fixture = $this->fixture('reader-pointer', true);
        $this->publish($fixture);
        $this->begin([$fixture], false);
        $this->readyEexInputs();
        $this->assertTrue($this->forecastFreshness()->ready());
        $next = ContractSourceObservation::create([
            'contract_id' => $fixture[0]->id, 'source_snapshot_id' => $fixture[1]->source_snapshot_id,
            'first_observed_at' => now(), 'last_observed_at' => now(),
        ]);
        $fixture[0]->update(['current_source_observation_id' => $next->id]);
        $this->assertSame(['contract_interpretations'], array_keys($this->forecastFreshness()->failures));
        $this->assertArrayHasKey('contract_interpretations', app(MorningJobFreshnessService::class)
            ->checkRetailPremium(CarbonImmutable::parse(self::DATE))->failures);
        $fixture[0]->update(['current_source_observation_id' => $fixture[1]->id]);
        $this->checkpoint()->update(['status' => ContractImportCompletion::PENDING]);
        $this->assertSame(['contract_checkpoint'], array_keys($this->forecastFreshness()->failures));
    }

    public function test_scoped_statistics_do_not_insert_a_global_checkpoint_when_none_exists(): void
    {
        $fixture = $this->fixture('scope-no-checkpoint', true);
        $this->publish($fixture);
        $this->assertDatabaseCount('data_freshness_checkpoints', 0);
        $result = app(ContractImportCompletion::class)->initial($this->import([$fixture]), self::DATE, null, []);
        $this->assertTrue($result->succeeded());
        $this->assertFalse($result->deferred);
        $this->assertDatabaseCount('data_freshness_checkpoints', 0);
        $this->assertDatabaseHas('contract_price_snapshots', ['contract_id' => $fixture[0]->id]);
    }

    public function test_full_start_between_scoped_fence_preparation_and_execution_preserves_new_ready_rows(): void
    {
        $first = $this->fixture('scope-first', true);
        $this->publish($first);
        $completion = app(ContractImportCompletion::class);
        $fence = $completion->statisticsFence(self::DATE, null, activeIds: ['scope-first']);
        $this->assertDatabaseCount('data_freshness_checkpoints', 0);
        $new = $this->fixture('scope-new', true);
        $this->publish($new);
        $this->begin([$first, $new], false);
        $facts = $this->checkpoint()->getRawOriginal();
        $snapshots = ContractPriceSnapshot::all()->toArray();
        try {
            app(ContractPriceStatisticsService::class)->calculateForDate(self::DATE, ['scope-first'], true, transactionFence: $fence);
            $this->fail('The old scoped active set must fail before deletion.');
        } catch (ContractPriceCacheConflict $exception) {
            $this->assertSame('evidence_changed', $exception->reason);
        }
        $this->assertSame($facts, $this->checkpoint()->getRawOriginal());
        $this->assertSame($snapshots, ContractPriceSnapshot::all()->toArray());
    }

    private function forecastFreshness(): MorningFreshnessResult
    {
        return app(MorningJobFreshnessService::class)->checkFixedTermForecast(CarbonImmutable::parse(self::DATE, 'Europe/Helsinki'));
    }

    private function readyEexInputs(): void
    {
        DataFreshnessCheckpoint::create([
            'key' => DataFreshnessCheckpoint::KEY_EEX_FUTURES, 'effective_date' => self::DATE,
            'status' => 'ready', 'metadata' => ['current_run_latest_prior_fi_trade_date' => '2026-09-15'],
            'recorded_at' => now(),
        ]);
        ElectricityFuturesEodPrice::create([
            'area' => 'FI', 'short_code' => 'FNBM', 'maturity' => '202610', 'maturity_type' => 'month',
            'trade_date' => '2026-09-15', 'settlement_price' => 50,
        ]);
    }

    private function begin(array $fixtures, bool $pending = true): void
    {
        $service = app(ContractImportCompletion::class);
        $uuid = $service->start(self::DATE);
        $targets = [];
        foreach ($fixtures as $fixture) {
            $targets[$fixture[1]->id] = $fixture[2]->id;
        }
        $result = $service->initial($this->import($fixtures), self::DATE, $uuid, $targets);
        $this->assertTrue($result->succeeded(), json_encode($result->requiredFailures));
        $this->assertSame($pending, $result->deferred);
        $this->assertTrue($service->record(self::DATE, $uuid, $result->deferred ? ContractImportCompletion::PENDING : 'ready', $result->completionMetadata));
    }

    private function import(array $fixtures): ContractImportResult
    {
        $ids = array_map(fn ($fixture) => $fixture[1]->id, $fixtures);
        $active = ActiveContract::orderBy('id')->pluck('id')->all();

        return new ContractImportResult(true, count($fixtures), count($active), 0, [], $ids, $ids, $active, []);
    }

    private function checkpoint(): DataFreshnessCheckpoint
    {
        return DataFreshnessCheckpoint::where('key', DataFreshnessCheckpoint::KEY_CONTRACT_IMPORT)->sole();
    }

    private function metadata(array $changes): void
    {
        $row = $this->checkpoint();
        $row->update(['metadata' => array_replace($row->metadata, $changes)]);
    }

    private function fixture(string $id, bool $active): array
    {
        Company::firstOrCreate(['name' => 'Completion Oy'], ['name_slug' => 'completion']);
        $contract = ElectricityContract::create([
            'id' => $id, 'api_id' => $id, 'company_name' => 'Completion Oy', 'name' => $id,
            'availability_is_national' => true, 'contract_type' => 'FixedTerm', 'fixed_time_range' => 'Fixed12',
            'pricing_model' => 'FixedPrice', 'metering' => 'General', 'target_group' => 'Household',
        ]);
        $snapshot = ContractSourceSnapshot::create([
            'contract_id' => $id, 'source_fingerprint' => hash('sha256', $id), 'source_payload' => ['Id' => $id],
            'first_observed_at' => now(), 'last_observed_at' => now(),
        ]);
        $observation = ContractSourceObservation::create([
            'contract_id' => $id, 'source_snapshot_id' => $snapshot->id, 'first_observed_at' => now(), 'last_observed_at' => now(),
        ]);
        $contract->update(['current_source_observation_id' => $observation->id]);
        $target = ContractInterpretation::create([
            'contract_id' => $id, 'source_snapshot_id' => $snapshot->id, 'analysis_fingerprint' => hash('sha256', 'target'.$id),
            'status' => 'pending', 'schema_version' => 'test', 'prompt_version' => 'test', 'validator_version' => 'test',
            'provider' => 'test', 'model' => 'test',
        ]);
        if ($active) {
            ActiveContract::create(['id' => $id]);
        }

        return [$contract, $observation, $target];
    }

    private function publish(array $fixture): void
    {
        $fixture[2]->update(['output' => [
            'source_consistency' => ['misleading_first_12_months' => 'not_detected', 'structured_pricing_status' => 'complete', 'issue_codes' => []],
            'calculation' => ['status' => 'exact', 'missing_facts' => [], 'required_assumptions' => []],
            'pricing' => [
                'recurring_schedule' => ['present' => false, 'cadence' => 'none'],
                'consumption_effect' => ['present' => false, 'applies_to' => 'unknown'],
                'phases' => [[
                    'label' => 'current', 'phase_kind' => 'current_structured',
                    'starts' => ['kind' => 'contract_start', 'value' => null], 'ends' => ['kind' => 'none', 'value' => null],
                    'components' => [[
                        'component_type' => $fixture[0]->pricing_model === 'Spot' ? 'spot_margin' : 'energy_general', 'amount' => 10, 'normal_amount' => null,
                        'unit' => 'cents_per_kwh', 'vat_status' => 'included', 'price_role' => 'current', 'source_kind' => 'both', 'evidence' => [],
                    ]], 'evidence' => [],
                ]],
            ],
        ], 'validation_errors' => []]);
        $this->assertTrue(app(ContractInterpretationPublisher::class)->publish($fixture[2]));
    }
}
