<?php

namespace Tests\Feature;

use App\Models\ActiveContract;
use App\Models\Company;
use App\Models\ContractInterpretation;
use App\Models\ContractSourceObservation;
use App\Models\ContractSourceSnapshot;
use App\Models\ElectricityContract;
use App\Services\Caching\ContractPriceCacheConflict;
use App\Services\Caching\ContractPriceCacheLifecycle;
use App\Services\CanonicalPricing\CanonicalContractPricingService;
use App\Services\CompanyListCacheService;
use App\Services\ContractListCacheService;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Mockery;
use RuntimeException;
use Tests\Concerns\CapturesSentryIssues;
use Tests\TestCase;

class PriceCacheConflictRecoveryTest extends TestCase
{
    use CapturesSentryIssues;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['canonical_pricing.enabled' => true]);
        app()->forgetScopedInstances();
    }

    public function test_cold_conflict_reloads_published_prices_and_sort_order_without_partial_write(): void
    {
        $first = $this->contract('first', 5);
        $this->contract('second', 10);
        $calls = $this->duringCalculation(function (int $call) use ($first) {
            if ($call === 1) {
                $this->publish($first, 20);
                $this->assertNull(Cache::get(app(ContractListCacheService::class)->getCacheKey(5000)));
            }
        });
        $list = app(ContractListCacheService::class);
        $metrics = $list->getCachedMetrics(5000);
        $this->assertSame(2, $calls->count);
        $this->assertEqualsWithDelta(1000, $metrics->metric('first')->pricing()->total(), 0.01);
        $this->assertSame(['second', 'first'], $metrics->toArray()['sorted_ids']);
        $this->assertSame($metrics->toArray(), Cache::get($list->getCacheKey(5000)));
        $this->assertSame($metrics, $list->getCachedMetrics(5000));
        $this->assertSame(2, $calls->count);
    }

    public function test_persistent_conflict_is_a_bounded_uncached_finnish_http_503(): void
    {
        $this->captureSentryIssues();
        $contract = $this->contract('changing', 5);
        $calls = $this->duringCalculation(fn (int $call) => $this->publish($contract, 5 + $call));
        Route::get('/test-price-conflict', fn () => app(ContractListCacheService::class)->getCachedMetrics(5000));
        $response = $this->get('/test-price-conflict');
        $response->assertStatus(503)->assertHeader('Retry-After', '30')
            ->assertHeader('Content-Type', 'text/plain; charset=UTF-8')
            ->assertSeeText('Hintatiedot ovat tilapäisesti poissa käytöstä.');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertSame(2, $calls->count);
        $this->assertNull(Cache::get(app(ContractListCacheService::class)->getCacheKey(5000)));
        $handler = app(ExceptionHandler::class);
        $this->assertSame([], $this->sentryIssues);
        $this->assertFalse($handler->shouldReport(ContractPriceCacheConflict::evidenceChanged()));
        $this->assertTrue($handler->shouldReport(new RuntimeException('unknown')));
    }

    public function test_company_recovery_uses_the_new_evidence_key_and_fresh_price(): void
    {
        $contract = $this->contract('changing', 5);
        $calls = $this->duringCalculation(function (int $call) use ($contract) {
            if ($call === 1) {
                $this->publish($contract, 20);
            }
        });
        $companies = app(CompanyListCacheService::class);
        $oldKey = $companies->getCacheKey(5000);
        $this->assertEqualsWithDelta(1000, $companies->getCachedCompanies()->first()['lowestPrice'], .01);
        $this->assertSame(2, $calls->count);
        $this->assertNull(Cache::get($oldKey));
        $this->assertNotNull(Cache::get($companies->getCacheKey(5000)));
    }

    public function test_cold_generation_conflict_reloads_active_descriptor_before_writing(): void
    {
        $this->contract('changing', 5);
        $lifecycle = app(ContractPriceCacheLifecycle::class);
        $calls = $this->duringCalculation(function (int $call) use ($lifecycle) {
            if ($call === 1) {
                $lifecycle->invalidate();
            }
        });
        $list = app(ContractListCacheService::class);
        $oldKey = $list->getCacheKey(5000);
        $this->assertEqualsWithDelta(250, $list->getCachedMetrics(5000)->metric('changing')->pricing()->total(), .01);
        $this->assertSame(2, $calls->count);
        $this->assertNull(Cache::get($oldKey));
        $this->assertNotNull(Cache::get($list->getCacheKey(5000)));
    }

    public function test_company_cold_read_shares_one_budget_with_list_builds(): void
    {
        $contract = $this->contract('changing', 5);
        $calls = $this->duringCalculation(fn (int $call) => $this->publish($contract, 5 + $call));
        $companies = app(CompanyListCacheService::class);
        try {
            $companies->getCachedCompanies();
            $this->fail('The conflict must escape direct calls.');
        } catch (ContractPriceCacheConflict $exception) {
            $this->assertSame('evidence_changed', $exception->reason);
        }
        $this->assertSame(2, $calls->count);
        $this->assertNull(Cache::get($companies->getCacheKey(5000)));
    }

    public function test_full_refresh_retries_in_a_new_candidate_and_builds_all_presets_and_company(): void
    {
        $contract = $this->contract('changing', 5);
        $calls = $this->duringCalculation(function (int $call) use ($contract) {
            if ($call === 2) {
                $this->publish($contract, 20);
            }
        });
        $list = app(ContractListCacheService::class);
        $companies = app(CompanyListCacheService::class);
        $lifecycle = app(ContractPriceCacheLifecycle::class);
        $starting = $lifecycle->active();
        $list->refresh($companies);
        $this->assertSame(10, $calls->count);
        $this->assertNotSame($starting, $lifecycle->active());
        foreach (ContractListCacheService::PRESET_CONSUMPTIONS as $consumption) {
            $this->assertEqualsWithDelta($consumption * .2, $list->getCachedMetrics($consumption)->metric('changing')->pricing()->total(), .01);
        }
        $this->assertEqualsWithDelta(1000, $companies->getCachedCompanies()->first()['lowestPrice'], .01);
        $retired = Cache::get(ContractPriceCacheLifecycle::RETIRED_KEY);
        $this->assertCount(2, $retired);
        $failedId = array_values(array_diff(array_keys($retired), [$starting['generation']]))[0];
        $failedKeys = Cache::get('contract_price_manifest:'.$failedId);
        $this->assertCount(1, $failedKeys);
        $this->travel(3601)->seconds();
        $lifecycle->cleanupRetired();
        $this->assertNull(Cache::get($failedKeys[0]));
        $this->assertNotNull(Cache::get($list->getCacheKey(5000)));
    }

    public function test_exhausted_full_candidates_are_retired_without_replacing_active(): void
    {
        $contract = $this->contract('changing', 5);
        $calls = $this->duringCalculation(function (int $call) use ($contract) {
            if ($call % 2 === 0) {
                $this->publish($contract, 5 + $call);
            }
        });
        $lifecycle = app(ContractPriceCacheLifecycle::class);
        $starting = $lifecycle->active();
        try {
            app(ContractListCacheService::class)->refresh(app(CompanyListCacheService::class));
            $this->fail('Both full attempts must fail.');
        } catch (ContractPriceCacheConflict) {
        }
        $this->assertSame(4, $calls->count);
        $this->assertSame($starting, $lifecycle->active());
        $retired = Cache::get(ContractPriceCacheLifecycle::RETIRED_KEY);
        $this->assertCount(2, $retired);
        foreach (array_keys($retired) as $id) {
            $this->assertCount(1, Cache::get('contract_price_manifest:'.$id));
        }
        $this->travel(3601)->seconds();
        $this->assertSame(['deleted' => 2, 'failures' => 0], $lifecycle->cleanupRetired());
        $this->assertSame($starting, $lifecycle->active());
    }

    public function test_non_conflict_exception_is_not_retried_or_suppressed(): void
    {
        $this->contract('first', 5);
        $calls = $this->duringCalculation(fn () => throw new RuntimeException('unknown secret'));
        try {
            app(ContractListCacheService::class)->getCachedMetrics(5000);
            $this->fail('Unknown errors must escape.');
        } catch (RuntimeException $exception) {
            $this->assertSame('unknown secret', $exception->getMessage());
            $this->assertNotInstanceOf(ContractPriceCacheConflict::class, $exception);
        }
        $this->assertSame(1, $calls->count);
    }

    private function duringCalculation(callable $callback): object
    {
        $real = app(CanonicalContractPricingService::class);
        $calls = (object) ['count' => 0];
        $proxy = Mockery::mock($real)->makePartial();
        $proxy->shouldReceive('metricsForContracts')->andReturnUsing(function ($contracts, $usage) use ($real, $callback, $calls) {
            $metrics = $real->metricsForContracts($contracts, $usage);
            $callback(++$calls->count);

            return $metrics;
        });
        $this->app->instance(CanonicalContractPricingService::class, $proxy);

        return $calls;
    }

    private function contract(string $id, float $rate): ElectricityContract
    {
        Company::create(['name' => $id, 'name_slug' => $id]);
        $contract = ElectricityContract::create([
            'id' => $id, 'company_name' => $id, 'name' => $id, 'availability_is_national' => true,
            'contract_type' => 'FixedTerm', 'fixed_time_range' => 12,
            'pricing_model' => 'FixedPrice', 'metering' => 'General', 'target_group' => 'Household',
            'canonical_calculation' => ['status' => 'exact', 'missing_facts' => [], 'required_assumptions' => []],
            'canonical_source_consistency' => ['misleading_first_12_months' => 'not_detected', 'structured_pricing_status' => 'complete', 'issue_codes' => []],
        ]);
        ActiveContract::create(['id' => $id]);
        $this->publish($contract, $rate);

        return $contract;
    }

    private function publish(ElectricityContract $contract, float $rate): void
    {
        $snapshot = ContractSourceSnapshot::create([
            'contract_id' => $contract->id, 'source_fingerprint' => hash('sha256', $contract->id.':'.$rate),
            'source_payload' => [], 'first_observed_at' => now(), 'last_observed_at' => now(),
        ]);
        $observation = ContractSourceObservation::create([
            'contract_id' => $contract->id, 'source_snapshot_id' => $snapshot->id,
            'first_observed_at' => now(), 'last_observed_at' => now(),
        ]);
        $interpretation = ContractInterpretation::create([
            'contract_id' => $contract->id, 'source_snapshot_id' => $snapshot->id,
            'analysis_fingerprint' => hash('sha256', 'interpretation'.$snapshot->id), 'status' => 'published',
            'schema_version' => 'test', 'prompt_version' => 'test', 'validator_version' => 'test',
            'provider' => 'test', 'model' => 'test', 'relational_pricing_published' => false,
        ]);
        $contract->update([
            'current_source_observation_id' => $observation->id, 'published_interpretation_id' => $interpretation->id,
            'canonical_pricing' => [
                'recurring_schedule' => ['present' => false, 'cadence' => 'none'],
                'consumption_effect' => ['present' => false, 'applies_to' => 'unknown'],
                'phases' => [[
                    'label' => 'current', 'phase_kind' => 'current_structured',
                    'starts' => ['kind' => 'contract_start', 'value' => null], 'ends' => ['kind' => 'none', 'value' => null],
                    'components' => [[
                        'component_type' => 'energy_general', 'amount' => $rate, 'normal_amount' => null,
                        'unit' => 'cents_per_kwh', 'vat_status' => 'included', 'price_role' => 'current', 'source_kind' => 'both', 'evidence' => [],
                    ]], 'evidence' => [],
                ]],
            ],
        ]);
    }
}
