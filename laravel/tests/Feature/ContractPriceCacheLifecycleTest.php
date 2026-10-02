<?php

namespace Tests\Feature;

use App\Services\Caching\ContractPriceCacheConflict;
use App\Services\Caching\ContractPriceCacheLifecycle;
use App\Services\Caching\ContractPriceCacheStorageException;
use App\Services\Caching\ContractPriceCacheUnavailable;
use App\Services\CompanyListCacheService;
use App\Services\ContractListCacheService;
use App\Services\ContractPricing\ContractMetricSet;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class ContractPriceCacheLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Direct cold reads below model the CLI producer boundary.
        request()->server->remove('REQUEST_METHOD');
    }

    public function test_last_preset_failure_preserves_active_generation_and_payloads(): void
    {
        $list = $this->builder();
        $starting = app(ContractPriceCacheLifecycle::class)->active();
        $oldKey = $list->getCacheKey(5000);
        $old = $list->getCachedMetrics(5000)->toArray();
        $list->shouldReceive('buildCachedMetrics')->with(20000)->andThrow(new RuntimeException('last preset'));
        try {
            $list->refresh(app(CompanyListCacheService::class));
            $this->fail('Refresh must fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame('last preset', $exception->getMessage());
        }
        $this->assertSame($starting, app(ContractPriceCacheLifecycle::class)->active());
        $this->assertSame($old, Cache::get($oldKey));
    }

    public function test_company_failure_preserves_active_generation(): void
    {
        $starting = app(ContractPriceCacheLifecycle::class)->active();
        $companies = Mockery::mock(CompanyListCacheService::class);
        $companies->shouldReceive('buildCachedCompanies')->once()->andThrow(new RuntimeException('company'));
        try {
            $this->builder()->refresh($companies);
            $this->fail('Refresh must fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame('company', $exception->getMessage());
        }
        $this->assertSame($starting, app(ContractPriceCacheLifecycle::class)->active());
    }

    public function test_activation_waits_for_all_presets_and_old_keys_have_grace(): void
    {
        $lifecycle = app(ContractPriceCacheLifecycle::class);
        $list = $this->builder();
        $companies = app(CompanyListCacheService::class);
        $list->getCachedMetrics(5000);
        $oldKey = $list->getCacheKey(5000);
        $starting = $lifecycle->active();
        $built = [];
        $list->shouldReceive('buildCachedMetrics')->andReturnUsing(function (int $consumption) use (&$built, $starting, $lifecycle) {
            $this->assertSame($starting, $lifecycle->active());
            $competitor = Cache::lock(ContractPriceCacheLifecycle::PRODUCER_LOCK_KEY, 30);
            $this->assertFalse($competitor->get());
            $transition = Cache::lock(ContractPriceCacheLifecycle::LOCK_KEY, 30);
            $this->assertTrue($transition->get());
            $transition->release();
            $built[] = $consumption;

            return $this->emptyMetrics($consumption);
        });
        Cache::forever('unrelated-refresh-payload', 'keep');
        $list->refresh($companies);
        $this->assertSame(ContractListCacheService::PRESET_CONSUMPTIONS, $built);
        $this->assertSame($starting['version'] + 1, $list->getVersion());
        $this->assertNotNull(Cache::get($oldKey));
        $this->assertSame('keep', Cache::get('unrelated-refresh-payload'));
        $this->travel(ContractPriceCacheLifecycle::GRACE_SECONDS + 1)->seconds();
        $lifecycle->cleanupRetired();
        $this->assertNull(Cache::get($oldKey));
        $this->assertNotNull(Cache::get($list->getCacheKey(5000)));
    }

    public function test_refresh_demand_rejects_an_in_progress_candidate(): void
    {
        $list = $this->builder();
        $starting = app(ContractPriceCacheLifecycle::class)->active();
        $list->shouldReceive('buildCachedMetrics')->with(20000)->twice()->andReturnUsing(function () use ($list) {
            $list->bumpVersion();

            return $this->emptyMetrics(20000);
        });
        try {
            $list->refresh(app(CompanyListCacheService::class));
            $this->fail('Refresh must fail.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('changed while replacement', $exception->getMessage());
        }
        $active = app(ContractPriceCacheLifecycle::class)->active();
        $this->assertSame($starting, $active);
        $this->assertTrue(app(ContractPriceCacheLifecycle::class)->pending());
        $this->assertNull(Cache::get($list->getCacheKey(5000)));
    }

    public function test_false_candidate_write_is_a_failure_without_activation(): void
    {
        $lifecycle = app(ContractPriceCacheLifecycle::class);
        $starting = $lifecycle->active();
        $candidate = $lifecycle->candidate($starting);
        $repository = Cache::getFacadeRoot()->store();
        $proxy = Mockery::mock($repository)->makePartial();
        $proxy->shouldReceive('forever')->with('failed-candidate', ['value' => 1])->andReturn(false);
        Cache::swap($proxy);
        try {
            $lifecycle->write($candidate, 'failed-candidate', ['value' => 1], true);
            $this->fail('Write must fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Price cache write failed.', $exception->getMessage());
        }
        $this->assertSame($starting, $lifecycle->active());
    }

    public function test_corrupt_or_missing_readback_rejects_candidate(): void
    {
        $lifecycle = app(ContractPriceCacheLifecycle::class);
        $starting = $lifecycle->active();
        foreach ([null, ['corrupt' => true]] as $readback) {
            $candidate = $lifecycle->candidate($starting);
            Cache::forever('candidate-readback', $readback);
            try {
                $lifecycle->promote($starting, $candidate, ['candidate-readback' => hash('sha256', serialize(['valid' => true]))]);
                $this->fail('Readback must fail.');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('readback failed', $exception->getMessage());
            }
            $this->assertSame($starting, $lifecycle->active());
        }
    }

    public function test_database_store_uses_the_same_verified_generation_without_flushing_other_rows(): void
    {
        config(['cache.default' => 'database']);
        $list = $this->builder();
        Cache::forever('database-collateral', 'keep');
        $starting = $list->getVersion();
        $list->refresh(app(CompanyListCacheService::class));
        $this->assertSame($starting + 1, $list->getVersion());
        $this->travel(3)->days();
        $this->assertSame('keep', Cache::get('database-collateral'));
        $this->assertNotNull(Cache::get($list->getCacheKey(5000)));
    }

    public function test_physical_cleanup_failure_preserves_retry_state_and_does_not_undo_activation(): void
    {
        config(['cache.default' => 'database']);
        $lifecycle = app(ContractPriceCacheLifecycle::class);
        $starting = $lifecycle->active();
        $lifecycle->write($starting, 'retirement-failure', ['old' => true]);
        $candidate = $lifecycle->candidate($starting);
        $lifecycle->write($candidate, 'replacement-success', ['new' => true], true);
        $lifecycle->promote($starting, $candidate, ['replacement-success' => hash('sha256', serialize(['new' => true]))]);
        $this->travel(61)->minutes();
        $repository = Cache::getFacadeRoot()->store();
        $proxy = Mockery::mock($repository)->makePartial();
        $proxy->shouldReceive('forget')->with('retirement-failure')->once()
            ->andThrow(new RuntimeException('deletion failed'));
        Cache::swap($proxy);
        $this->assertSame(['deleted' => 0, 'failures' => 1], $lifecycle->cleanupRetired());
        Cache::swap($repository);
        $this->assertTrue($this->physicalKeyExists('retirement-failure'));
        $this->assertTrue($this->physicalKeyExists('contract_price_manifest:'.$starting['generation']));
        $this->assertArrayHasKey($starting['generation'], Cache::get(ContractPriceCacheLifecycle::RETIRED_KEY));
        $this->assertSame($candidate, $lifecycle->active());
        $this->assertSame(['deleted' => 1, 'failures' => 0], $lifecycle->cleanupRetired());
        $this->assertFalse($this->physicalKeyExists('retirement-failure'));
        $this->assertFalse($this->physicalKeyExists('contract_price_manifest:'.$starting['generation']));
        $this->assertArrayNotHasKey($starting['generation'], Cache::get(ContractPriceCacheLifecycle::RETIRED_KEY));
        $this->assertTrue($this->physicalKeyExists('replacement-success'));
        $this->assertSame($candidate, $lifecycle->active());
    }

    public function test_scheduled_cleanup_physically_deletes_only_due_tracked_rows(): void
    {
        config(['cache.default' => 'database']);
        $lifecycle = app(ContractPriceCacheLifecycle::class);
        $old = $lifecycle->active();
        $lifecycle->write($old, 'owned-retired-payload', ['old' => true]);
        $lifecycle->promote($old, $lifecycle->candidate($old), []);
        $active = $lifecycle->active();
        $lifecycle->write($active, 'owned-active-payload', ['active' => true]);
        Cache::forever('unrelated-cleanup-payload', 'keep');
        $retired = Cache::get(ContractPriceCacheLifecycle::RETIRED_KEY);
        // This can occur when recording retirement succeeds but the pointer write fails.
        $retired[$active['generation']] = ['after' => 0];
        Cache::forever(ContractPriceCacheLifecycle::RETIRED_KEY, $retired);
        $this->travel(59)->minutes();
        $this->artisan('contracts:cleanup-price-cache')->assertSuccessful();
        $this->assertTrue($this->physicalKeyExists('owned-retired-payload'));
        $this->travel(2)->minutes();
        $this->assertTrue($this->physicalKeyExists('owned-retired-payload'));
        $this->artisan('contracts:cleanup-price-cache')->assertSuccessful();
        $this->assertFalse($this->physicalKeyExists('owned-retired-payload'));
        $this->assertFalse($this->physicalKeyExists('contract_price_manifest:'.$old['generation']));
        $this->assertTrue($this->physicalKeyExists('owned-active-payload'));
        $this->assertTrue($this->physicalKeyExists('unrelated-cleanup-payload'));
        $this->assertSame($active, $lifecycle->active());
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($event) => str_contains($event->command ?? '', 'contracts:cleanup-price-cache'));
        $this->assertNotNull($event);
        $this->assertSame('*/5 * * * *', $event->expression);
        $this->assertTrue($event->onOneServer);
        $this->assertTrue($event->withoutOverlapping);
    }

    public function test_cleanup_is_bounded_and_late_cold_writes_are_rejected(): void
    {
        config(['cache.default' => 'database']);
        $lifecycle = app(ContractPriceCacheLifecycle::class);
        $old = $lifecycle->active();
        foreach (range(1, 101) as $number) {
            $lifecycle->write($old, 'bounded-retired-'.$number, ['old' => true]);
        }
        $lifecycle->promote($old, $lifecycle->candidate($old), []);
        $this->travel(59)->minutes();
        try {
            $lifecycle->write($old, 'late-retired-write', ['late' => true]);
            $this->fail('An invalidated generation must not receive a cold result.');
        } catch (ContractPriceCacheConflict $exception) {
            $this->assertSame('generation_changed', $exception->reason);
        }
        $this->assertFalse($this->physicalKeyExists('late-retired-write'));
        $this->travel(2)->minutes();
        $this->assertSame(['deleted' => 100, 'failures' => 0], $lifecycle->cleanupRetired());
        $this->assertTrue($this->physicalKeyExists('bounded-retired-101'));
        $this->assertSame(['deleted' => 1, 'failures' => 0], $lifecycle->cleanupRetired());
        $this->assertFalse($this->physicalKeyExists('bounded-retired-101'));
        $this->assertFalse($this->physicalKeyExists('late-retired-write'));
    }

    private function physicalKeyExists(string $key): bool
    {
        return DB::table('cache')->where('key', Cache::getStore()->getPrefix().$key)->exists();
    }

    public function test_failed_cleanup_state_write_prevents_activation_without_retiring_the_active_payload(): void
    {
        $lifecycle = app(ContractPriceCacheLifecycle::class);
        $starting = $lifecycle->active();
        $lifecycle->write($starting, 'active-before-failed-transition', ['old' => true]);
        $candidate = $lifecycle->candidate($starting);
        $repository = Cache::getFacadeRoot()->store();
        $proxy = Mockery::mock($repository)->makePartial();
        $proxy->shouldReceive('forever')->with(ContractPriceCacheLifecycle::RETIRED_KEY, Mockery::type('array'))->andReturn(false);
        Cache::swap($proxy);
        try {
            $lifecycle->promote($starting, $candidate, []);
            $this->fail('Activation requires durable cleanup ownership.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Price cache write failed.', $exception->getMessage());
        }
        $this->assertSame($starting, $lifecycle->active());
        $this->assertSame(['old' => true], Cache::get('active-before-failed-transition'));
    }

    public function test_cleanup_command_reports_failures_for_scheduler_retry(): void
    {
        $lifecycle = Mockery::mock(ContractPriceCacheLifecycle::class);
        $lifecycle->shouldReceive('cleanupRetired')->once()->andReturn(['deleted' => 0, 'failures' => 1]);
        $this->app->instance(ContractPriceCacheLifecycle::class, $lifecycle);
        $this->artisan('contracts:cleanup-price-cache')->assertFailed();
    }

    public function test_company_builder_receives_the_candidate_5000_metrics(): void
    {
        $list = $this->builder();
        $candidateMetrics = $this->emptyMetrics(5000);
        $list->shouldReceive('buildCachedMetrics')->with(5000)->andReturn($candidateMetrics);
        $companies = Mockery::mock(CompanyListCacheService::class);
        $companies->shouldReceive('buildCachedCompanies')->once()->with(5000, $candidateMetrics)->andReturn(collect());
        $companies->shouldReceive('getCacheKey')->once()->with(5000, Mockery::type('array'))->andReturn('exact-candidate-company');
        $companies->shouldNotReceive('getCachedCompanies');
        $list->refresh($companies);
        $this->assertEquals(collect(), Cache::get('exact-candidate-company'));
    }

    public function test_generation_conflict_rebuilds_all_presets_in_a_new_candidate(): void
    {
        $list = $this->builder();
        $lifecycle = app(ContractPriceCacheLifecycle::class);
        $starting = $lifecycle->active();
        $built = [];
        $list->shouldReceive('buildCachedMetrics')->andReturnUsing(function (int $consumption) use (&$built, $lifecycle) {
            $built[] = $consumption;
            if (count($built) === 8) {
                $lifecycle->invalidate();
            }

            return $this->emptyMetrics($consumption);
        });
        $list->refresh(app(CompanyListCacheService::class));
        $this->assertSame([...ContractListCacheService::PRESET_CONSUMPTIONS, ...ContractListCacheService::PRESET_CONSUMPTIONS], $built);
        $this->assertSame($starting['version'] + 1, $lifecycle->active()['version']);
        $this->assertCount(2, Cache::get(ContractPriceCacheLifecycle::RETIRED_KEY));
        $this->assertFalse($lifecycle->pending());
    }

    public function test_failed_candidate_retirement_stops_retry_and_exposes_storage_failure(): void
    {
        $list = $this->builder();
        $list->shouldReceive('buildCachedMetrics')->with(2000)->once()
            ->andThrow(ContractPriceCacheConflict::evidenceChanged());
        $starting = app(ContractPriceCacheLifecycle::class)->active();
        $proxy = Mockery::mock(Cache::getFacadeRoot()->store())->makePartial();
        $proxy->shouldReceive('forever')->with(ContractPriceCacheLifecycle::RETIRED_KEY, Mockery::type('array'))->andReturn(false);
        Cache::swap($proxy);
        try {
            $list->refresh(app(CompanyListCacheService::class));
            $this->fail('Retirement must succeed before retry.');
        } catch (ContractPriceCacheStorageException $exception) {
            $this->assertSame('cache_write_failed', $exception->reason);
        }
        $this->assertSame($starting, app(ContractPriceCacheLifecycle::class)->active());
    }

    public function test_invalidation_retains_exact_pointer_and_payloads_and_repair_demand_coalesces(): void
    {
        $lifecycle = app(ContractPriceCacheLifecycle::class);
        $active = $lifecycle->active();
        $lifecycle->write($active, 'retained-demand-payload', ['old' => true]);
        $lifecycle->invalidate();
        $lifecycle->invalidate();
        $this->assertSame(2, $lifecycle->demandRevision());
        $lifecycle->requestRepair();
        $lifecycle->requestRepair();
        $this->assertSame(2, $lifecycle->demandRevision());
        $this->assertSame($active, $lifecycle->active());
        $this->assertSame(['old' => true], Cache::get('retained-demand-payload'));
        $this->assertNull(Cache::get(ContractPriceCacheLifecycle::RETIRED_KEY));
        $lifecycle->promote($active, $lifecycle->candidate($active), []);
        $this->assertFalse($lifecycle->pending());
        $lifecycle->requestRepair();
        $lifecycle->requestRepair();
        $this->assertSame(3, $lifecycle->demandRevision());
    }

    public function test_lost_demand_key_keeps_the_satisfied_floor_and_next_invalidation_is_warmed(): void
    {
        $list = $this->builder();
        $this->app->instance(ContractListCacheService::class, $list);
        $lifecycle = app(ContractPriceCacheLifecycle::class);
        foreach (range(1, 3) as $revision) {
            $this->assertSame($revision, $lifecycle->invalidate());
        }
        $list->refresh(app(CompanyListCacheService::class));
        $active = $lifecycle->active();
        $this->assertSame(3, $active['demand_revision']);
        $this->assertSame(3, Cache::get(ContractPriceCacheLifecycle::DEMAND_KEY));
        Cache::forget(ContractPriceCacheLifecycle::DEMAND_KEY);
        $this->assertSame(3, $lifecycle->demandRevision());
        $this->assertFalse($lifecycle->pending());
        $this->assertSame($active, $lifecycle->active());
        $list->shouldNotReceive('getCachedMetrics');
        $this->artisan('contracts:warm-cache --pending')->doesntExpectOutput()->assertSuccessful();
        $this->assertSame($active, $lifecycle->active());
        $this->assertNull(Cache::get(ContractPriceCacheLifecycle::DEMAND_KEY));

        $this->assertSame(4, $lifecycle->invalidate());
        $this->assertTrue($lifecycle->pending());
        $this->assertSame($active, $lifecycle->active());
        $this->artisan('contracts:warm-cache --pending')->doesntExpectOutput()->assertSuccessful();
        $replacement = $lifecycle->active();
        $this->assertNotSame($active['generation'], $replacement['generation']);
        $this->assertSame($active['version'] + 1, $replacement['version']);
        $this->assertSame(4, $replacement['demand_revision']);
        $this->assertSame(4, Cache::get(ContractPriceCacheLifecycle::DEMAND_KEY));
        $this->assertSame(4, $lifecycle->demandRevision());
        $this->assertFalse($lifecycle->pending());
        foreach (ContractListCacheService::PRESET_CONSUMPTIONS as $consumption) {
            $this->assertNotNull(Cache::get($list->getCacheKey($consumption)));
        }
        $this->assertNotNull(Cache::get(app(CompanyListCacheService::class)->getCacheKey(5000)));
    }

    public function test_repair_after_demand_metadata_loss_advances_above_the_satisfied_floor_once(): void
    {
        $lifecycle = app(ContractPriceCacheLifecycle::class);
        $starting = $lifecycle->active();
        $lifecycle->invalidate();
        $lifecycle->invalidate();
        $lifecycle->promote($starting, $lifecycle->candidate($starting), []);
        $active = $lifecycle->active();
        Cache::forget(ContractPriceCacheLifecycle::DEMAND_KEY);
        $this->assertFalse($lifecycle->pending());
        $lifecycle->requestRepair();
        $lifecycle->requestRepair();
        $this->assertSame(3, $lifecycle->demandRevision());
        $this->assertSame(3, Cache::get(ContractPriceCacheLifecycle::DEMAND_KEY));
        $this->assertTrue($lifecycle->pending());
        $this->assertSame($active, $lifecycle->active());
    }

    public function test_demand_key_loss_during_an_unmet_candidate_rejects_promotion(): void
    {
        $lifecycle = app(ContractPriceCacheLifecycle::class);
        $starting = $lifecycle->active();
        $lifecycle->invalidate();
        $lifecycle->invalidate();
        $lifecycle->promote($starting, $lifecycle->candidate($starting), []);
        $active = $lifecycle->active();
        $lifecycle->invalidate();
        $candidate = $lifecycle->candidate($active);
        $this->assertSame(3, $candidate['demand_revision']);
        $payload = ['verified' => true];
        $lifecycle->write($candidate, 'candidate-before-demand-loss', $payload, true);
        Cache::forget(ContractPriceCacheLifecycle::DEMAND_KEY);
        $this->assertSame(2, $lifecycle->demandRevision());
        try {
            $lifecycle->promote($active, $candidate, ['candidate-before-demand-loss' => hash('sha256', serialize($payload))]);
            $this->fail('Lost unmet demand proof must reject candidate promotion.');
        } catch (ContractPriceCacheConflict $exception) {
            $this->assertSame('generation_changed', $exception->reason);
        }
        $this->assertSame($active, $lifecycle->active());
        $this->assertSame($payload, Cache::get('candidate-before-demand-loss'));
    }

    public function test_expired_producer_cannot_promote_and_does_not_release_new_owner(): void
    {
        $lifecycle = app(ContractPriceCacheLifecycle::class);
        $active = $lifecycle->active();
        $lifecycle->requestRepair();
        $candidate = $lifecycle->candidate($active);
        $producer = Cache::lock(ContractPriceCacheLifecycle::PRODUCER_LOCK_KEY, 1);
        $this->assertTrue($producer->get());
        $this->travel(2)->seconds();
        $replacement = Cache::lock(ContractPriceCacheLifecycle::PRODUCER_LOCK_KEY, 30);
        $this->assertTrue($replacement->get());
        try {
            $lifecycle->promote($active, $candidate, [], $producer);
            $this->fail('Expired producers must not publish.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Price cache producer lease lost.', $exception->getMessage());
        }
        $producer->release();
        $this->assertTrue($replacement->isOwnedByCurrentProcess());
        $this->assertSame($active, $lifecycle->active());
        $this->assertTrue($lifecycle->pending());
        $replacement->release();
    }

    public function test_pending_warmer_builds_missing_payloads_then_is_a_quiet_noop(): void
    {
        $list = $this->builder();
        $this->app->instance(ContractListCacheService::class, $list);
        $list->shouldNotReceive('getCachedMetrics');
        $this->artisan('contracts:warm-cache --pending')->doesntExpectOutput()->assertSuccessful();
        $active = app(ContractPriceCacheLifecycle::class)->active();
        $list->shouldNotReceive('buildCachedMetrics');
        $this->artisan('contracts:warm-cache --pending')->doesntExpectOutput()->assertSuccessful();
        $this->assertSame($active, app(ContractPriceCacheLifecycle::class)->active());
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($event) => str_contains($event->command ?? '', 'contracts:warm-cache --pending'));
        $this->assertSame('* * * * *', $event->expression);
        $this->assertFalse($event->onOneServer);
        $this->assertTrue($event->runInBackground);
        $this->assertTrue($event->withoutOverlapping);
    }

    public function test_pending_warmer_failure_keeps_demand_and_active_pointer(): void
    {
        $list = $this->builder();
        $this->app->instance(ContractListCacheService::class, $list);
        $active = app(ContractPriceCacheLifecycle::class)->active();
        $list->shouldReceive('buildCachedMetrics')->with(20000)->andThrow(new RuntimeException('failure'));
        $this->artisan('contracts:warm-cache --pending')->assertFailed();
        $this->assertSame($active, app(ContractPriceCacheLifecycle::class)->active());
        $this->assertTrue(app(ContractPriceCacheLifecycle::class)->pending());
    }

    public function test_comparison_domain_is_checked_before_pricing_or_cache_writes(): void
    {
        Cache::flush();
        $list = $this->builder();
        $list->shouldNotReceive('buildCachedMetrics');
        foreach ([0, -1, 150001, PHP_INT_MAX] as $consumption) {
            try {
                $list->prepareComparisonForConsumption($consumption);
                $this->fail('Invalid input must fail before cache bootstrap.');
            } catch (\InvalidArgumentException) {
                $this->assertNull(Cache::get(ContractPriceCacheLifecycle::ACTIVE_KEY));
            }
        }
        $this->assertSame(150000, ContractListCacheService::MAX_COMPARISON_CONSUMPTION);
    }

    public function test_custom_cap_prevents_calculation_and_expiry_reclaims_only_owned_custom_keys(): void
    {
        $list = $this->builder();
        $lifecycle = app(ContractPriceCacheLifecycle::class);
        $list->refresh(app(CompanyListCacheService::class));
        $active = $lifecycle->active();
        $presetKeys = array_map(fn ($consumption) => $list->getCacheKey($consumption), ContractListCacheService::PRESET_CONSUMPTIONS);
        $companyKey = app(CompanyListCacheService::class)->getCacheKey(5000);
        $before = array_map(fn ($key) => Cache::get($key), [...$presetKeys, $companyKey]);
        Cache::forever('unrelated-profile', 'keep');
        foreach (range(7001, 7064) as $consumption) {
            $this->assertSame($consumption, $list->prepareComparisonForConsumption($consumption)->consumption());
        }
        $manifestKey = 'contract_price_manifest:'.$active['generation'];
        $manifest = Cache::get($manifestKey);
        $this->assertCount(64, $manifest['custom_expirations']);
        $this->assertCount(74, $manifest);
        $this->assertSame(now()->timestamp + 1800, $manifest['custom_expirations'][$list->getCacheKey(7001)]);
        $list->shouldNotReceive('buildCachedMetrics')->with(7312);
        try {
            $list->prepareComparisonForConsumption(7312);
            $this->fail('Live profiles must not be evicted at capacity.');
        } catch (ContractPriceCacheUnavailable) {
            $this->assertSame($manifest, Cache::get($manifestKey));
        }
        $this->travel(1799)->seconds();
        $this->assertNotNull(Cache::get($list->getCacheKey(7001)));
        $this->travel(2)->seconds();
        $this->assertNull(Cache::get($list->getCacheKey(7001)));
        $list->resetCalculationState();
        $this->assertNull($list->getCachedMetrics(7312));
        $this->assertSame(150000, $list->prepareComparisonForConsumption(150000)->consumption());
        $manifest = Cache::get($manifestKey);
        $this->assertCount(1, $manifest['custom_expirations']);
        $this->assertCount(11, $manifest);
        $this->assertNotContains($list->getCacheKey(7001), $manifest);
        $this->assertSame($before, array_map(fn ($key) => Cache::get($key), [...$presetKeys, $companyKey]));
        $this->assertSame('keep', Cache::get('unrelated-profile'));
        $this->assertSame($active, $lifecycle->active());
    }

    public function test_database_custom_ttl_and_physical_pruning_preserve_forever_presets(): void
    {
        config(['cache.default' => 'database']);
        $lifecycle = app(ContractPriceCacheLifecycle::class);
        $active = $lifecycle->active();
        $lifecycle->write($active, 'forever-preset', ['preset' => true]);
        $lifecycle->write($active, 'database-expired-custom', [], custom: true);
        $prefix = Cache::getStore()->getPrefix();
        $this->assertSame(now()->timestamp + 1800, (int) DB::table('cache')->where('key', $prefix.'database-expired-custom')->value('expiration'));
        $this->travel(1801)->seconds();
        $this->assertSame(1, DB::table('cache')->where('key', $prefix.'database-expired-custom')->count());
        $lifecycle->write($active, 'database-next-custom', [], custom: true);
        $this->assertSame(0, DB::table('cache')->where('key', $prefix.'database-expired-custom')->count());
        $this->assertSame(['preset' => true], Cache::get('forever-preset'));
        $this->assertCount(1, Cache::get('contract_price_manifest:'.$active['generation'])['custom_expirations']);
        $this->assertSame($active, $lifecycle->active());
    }

    public function test_custom_prune_manifest_write_failure_keeps_retry_ownership(): void
    {
        $lifecycle = app(ContractPriceCacheLifecycle::class);
        $active = $lifecycle->active();
        $lifecycle->write($active, 'expired-before-manifest-failure', [], custom: true);
        $manifestKey = 'contract_price_manifest:'.$active['generation'];
        $manifest = Cache::get($manifestKey);
        $this->travel(1801)->seconds();
        $repository = Cache::getFacadeRoot()->store();
        $proxy = Mockery::mock($repository)->makePartial();
        $proxy->shouldReceive('forever')->with($manifestKey, Mockery::type('array'))->andReturn(false);
        Cache::swap($proxy);
        try {
            $lifecycle->checkCustomCapacity($active, 'after-manifest-failure');
            $this->fail('Manifest failure must retain retry ownership.');
        } catch (ContractPriceCacheStorageException) {
            $this->assertSame($manifest, Cache::get($manifestKey));
        } finally {
            Cache::swap($repository);
        }
        $lifecycle->write($active, 'after-manifest-failure', [], custom: true);
        $this->assertNotContains('expired-before-manifest-failure', Cache::get($manifestKey));
        $this->assertCount(1, Cache::get($manifestKey)['custom_expirations']);
    }

    public function test_custom_write_rechecks_capacity_after_preflight_race(): void
    {
        $lifecycle = app(ContractPriceCacheLifecycle::class);
        $active = $lifecycle->active();
        $lifecycle->checkCustomCapacity($active, 'racing-custom');
        foreach (range(1, 64) as $profile) {
            $lifecycle->write($active, 'owned-custom-'.$profile, [], custom: true);
        }
        try {
            $lifecycle->write($active, 'racing-custom', [], custom: true);
            $this->fail('Atomic write must reject a full manifest.');
        } catch (ContractPriceCacheUnavailable) {
            $this->assertNull(Cache::get('racing-custom'));
            $this->assertCount(64, Cache::get('contract_price_manifest:'.$active['generation'])['custom_expirations']);
        }
    }

    public function test_custom_expiry_deletion_failure_keeps_manifest_ownership_for_retry(): void
    {
        $lifecycle = app(ContractPriceCacheLifecycle::class);
        $active = $lifecycle->active();
        $lifecycle->write($active, 'expired-owned-custom', [], custom: true);
        $manifestKey = 'contract_price_manifest:'.$active['generation'];
        $manifest = Cache::get($manifestKey);
        $this->travel(1801)->seconds();
        $repository = Cache::getFacadeRoot()->store();
        $proxy = Mockery::mock($repository)->makePartial();
        $proxy->shouldReceive('forget')->with('expired-owned-custom')->andReturn(false);
        Cache::swap($proxy);
        try {
            $lifecycle->checkCustomCapacity($active, 'next-owned-custom');
            $this->fail('Deletion failure must not lose cleanup ownership.');
        } catch (ContractPriceCacheStorageException) {
            $this->assertSame($manifest, Cache::get($manifestKey));
            $this->assertSame($active, $lifecycle->active());
            $this->assertNull(Cache::get('next-owned-custom'));
        } finally {
            Cache::swap($repository);
        }
        $lifecycle->write($active, 'next-owned-custom', [], custom: true);
        $this->assertSame(['next-owned-custom'], array_values(array_filter(Cache::get($manifestKey), 'is_string')));
        $this->assertCount(1, Cache::get($manifestKey)['custom_expirations']);
    }

    private function builder(): ContractListCacheService
    {
        $parameters = (new \ReflectionClass(ContractListCacheService::class))->getConstructor()->getParameters();
        $arguments = array_map(fn ($parameter) => app($parameter->getType()->getName()), $parameters);
        $list = Mockery::mock(ContractListCacheService::class, $arguments)->makePartial()->shouldAllowMockingProtectedMethods();
        $list->shouldReceive('buildCachedMetrics')->byDefault()->andReturnUsing(fn (int $consumption) => $this->emptyMetrics($consumption));

        return $list;
    }

    private function emptyMetrics(int $consumption): ContractMetricSet
    {
        return ContractMetricSet::fromArray([
            'contracts' => [], 'sorted_ids' => [], 'excluded_ids' => [], 'consumption' => $consumption,
        ]);
    }
}
