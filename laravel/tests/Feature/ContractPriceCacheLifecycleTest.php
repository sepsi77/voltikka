<?php

namespace Tests\Feature;

use App\Services\Caching\ContractPriceCacheConflict;
use App\Services\Caching\ContractPriceCacheLifecycle;
use App\Services\Caching\ContractPriceCacheStorageException;
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

    public function test_immediate_invalidation_rejects_an_in_progress_candidate(): void
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
        $this->assertSame($starting['version'] + 2, $active['version']);
        $this->assertNotSame($starting['generation'], $active['generation']);
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
        $lifecycle->invalidate();
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
        $lifecycle->invalidate();
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
        $this->assertSame($starting['version'] + 2, $lifecycle->active()['version']);
        $this->assertCount(3, Cache::get(ContractPriceCacheLifecycle::RETIRED_KEY));
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
