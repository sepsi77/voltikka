<?php

namespace Tests\Feature;

use App\Models\ActiveContract;
use App\Models\Company;
use App\Models\ElectricityContract;
use App\Models\PriceComponent;
use App\Services\Caching\ContractPriceCacheLifecycle;
use App\Services\Caching\ContractPriceCacheUnavailable;
use App\Services\Caching\PublicPriceCalculationPolicy;
use App\Services\CanonicalPricing\CanonicalContractPricingService;
use App\Services\CompanyListCacheService;
use App\Services\ContractListCacheService;
use App\Services\ContractPriceCalculator;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\Query\Grammars\MySqlGrammar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use InvalidArgumentException;
use Mockery;
use Tests\TestCase;

class SharedPriceCacheReadBoundaryTest extends TestCase
{
    use RefreshDatabase;

    private function seedPrices(): ElectricityContract
    {
        Cache::flush();
        Company::create(['name' => 'seller', 'name_slug' => 'seller']);
        $contract = ElectricityContract::create([
            'id' => 'cached', 'name' => 'cached', 'company_name' => 'seller', 'availability_is_national' => true,
            'contract_type' => 'FixedTerm', 'fixed_time_range' => 'Fixed12',
            'pricing_model' => 'FixedPrice', 'metering' => 'General', 'target_group' => 'Household',
            'canonical_calculation' => ['status' => 'exact', 'missing_facts' => [], 'required_assumptions' => []],
            'canonical_source_consistency' => ['misleading_first_12_months' => 'not_detected', 'structured_pricing_status' => 'complete', 'issue_codes' => []],
            'canonical_pricing' => ['recurring_schedule' => ['present' => false, 'cadence' => 'none'],
                'consumption_effect' => ['present' => false, 'applies_to' => 'unknown'],
                'phases' => [['label' => 'current', 'phase_kind' => 'current_structured',
                    'starts' => ['kind' => 'contract_start', 'value' => null], 'ends' => ['kind' => 'none', 'value' => null],
                    'components' => [['component_type' => 'energy_general', 'amount' => 6, 'normal_amount' => null,
                        'unit' => 'cents_per_kwh', 'vat_status' => 'included', 'price_role' => 'current', 'source_kind' => 'both', 'evidence' => []]], 'evidence' => []]]],
        ]);
        ActiveContract::create(['id' => $contract->id]);
        PriceComponent::create(['id' => 'energy', 'electricity_contract_id' => $contract->id,
            'price_component_type' => 'General', 'price_date' => now()->toDateString(), 'price' => 6, 'payment_unit' => 'c/kWh']);
        app(ContractListCacheService::class)->refresh(app(CompanyListCacheService::class));

        return $contract;
    }

    private function forbid(): void
    {
        $this->partialMock(ContractPriceCalculator::class)->shouldNotReceive('calculate');
        $enabled = app(CanonicalContractPricingService::class)->enabled();
        $canonical = $this->partialMock(CanonicalContractPricingService::class);
        $canonical->shouldReceive('enabled')->andReturn($enabled);
        $canonical->shouldNotReceive('metricsForContracts');
        $canonical->shouldNotReceive('evaluate');
        app()->forgetInstance(ContractListCacheService::class);
        app()->forgetInstance(CompanyListCacheService::class);
    }

    public function test_cold_get_head_and_automatic_post_request_one_repair_without_calculation(): void
    {
        Cache::flush();
        $this->forbid();
        Route::match(['GET', 'HEAD', 'POST'], '/cache-boundary', fn () => app(CompanyListCacheService::class)->getCachedCompanies());
        $lifecycle = app(ContractPriceCacheLifecycle::class);
        $active = $lifecycle->active();
        foreach (['GET', 'HEAD', 'POST', 'GET'] as $method) {
            $response = $this->call($method, '/cache-boundary');
            $response->assertStatus(503)->assertHeader('Retry-After', '30');
            $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
            $this->assertSame($active, $lifecycle->active());
            $this->assertSame(1, $lifecycle->demandRevision());
        }
        $this->assertFalse(app(ExceptionHandler::class)->shouldReport(new ContractPriceCacheUnavailable));
        $this->assertTrue(app(ExceptionHandler::class)->shouldReport(new InvalidArgumentException('bad payload')));
    }

    public function test_retained_payload_and_company_key_survive_source_demand_and_publication_identity(): void
    {
        config(['canonical_pricing.enabled' => true]);
        app()->forgetScopedInstances();
        $contract = $this->seedPrices();
        $list = app(ContractListCacheService::class);
        $payload = Cache::get($list->getCacheKey(5000));
        $companyKey = app(CompanyListCacheService::class)->getCacheKey(5000);
        $storedCompany = Cache::get($companyKey)->first();
        $this->assertIsString($storedCompany['_availability']);
        $this->assertSame(64, strlen($storedCompany['_availability']));
        $this->assertSame($list->availabilityFingerprint(), $storedCompany['_availability']);
        // Broken new pointers must not substitute current exclusions for verified retained facts.
        DB::statement('PRAGMA foreign_keys = OFF');
        $contract->update(['current_source_observation_id' => 9001, 'published_interpretation_id' => 9002]);
        DB::statement('PRAGMA foreign_keys = ON');
        $list->bumpVersion();
        $this->forbid();
        Route::get('/retained', function () use ($payload, $companyKey) {
            $list = app(ContractListCacheService::class);
            $this->assertSame(serialize($payload), serialize($list->getCachedMetrics(5000)->toArray()));
            $this->assertSame($payload['calculated_at'], $list->calculatedAt());
            $this->assertSame($companyKey, app(CompanyListCacheService::class)->getCacheKey(5000));
            $this->assertEquals(300, app(CompanyListCacheService::class)->getCachedCompanies()->first()['lowestPrice']);

            return 'ok';
        });
        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            $queries[] = $query->sql;
        });
        $this->get('/retained?kulutus=5432')->assertOk();
        $this->head('/retained?kulutus=5432')->assertOk();
        $this->assertStringNotContainsString('contract_source_snapshots', implode("\n", $queries));
        $this->assertSame($payload, Cache::get($list->getCacheKey(5000)));
    }

    public function test_company_miss_and_availability_changes_only_aggregate_shared_metrics_in_both_modes(): void
    {
        $this->checkAvailability(false);
    }

    public function test_canonical_company_availability_changes_only_aggregate_shared_metrics(): void
    {
        $this->checkAvailability(true);
    }

    private function checkAvailability(bool $canonical): void
    {
        config(['canonical_pricing.enabled' => $canonical]);
        app()->forgetScopedInstances();
        $this->seedPrices();
        $key = app(CompanyListCacheService::class)->getCacheKey(5000);
        Cache::forget($key);
        $this->forbid();
        Route::get('/aggregate', function () {
            $companies = app(CompanyListCacheService::class)->getCachedCompanies();
            $this->assertSame(1, $companies->first()['contractCount']);
            $this->assertEquals(300, $companies->first()['lowestPrice']);

            return 'ok';
        });
        $this->get('/aggregate')->assertOk();
        ElectricityContract::create(['id' => 'new', 'name' => 'new', 'company_name' => 'seller', 'availability_is_national' => true,
            'contract_type' => 'FixedTerm', 'pricing_model' => 'FixedPrice', 'metering' => 'General', 'target_group' => 'Household']);
        ActiveContract::create(['id' => 'new']);
        $this->get('/aggregate')->assertOk();
        ActiveContract::where('id', 'cached')->delete();
        Route::get('/inactive', function () {
            $this->assertCount(0, app(CompanyListCacheService::class)->getCachedCompanies());

            return 'ok';
        });
        $this->get('/inactive')->assertOk();
        $this->assertCount(0, Cache::get($key));
        ActiveContract::create(['id' => 'cached']);
        app()->forgetInstance(ContractListCacheService::class);
        app()->forgetInstance(CompanyListCacheService::class);
        $this->get('/aggregate')->assertOk();
        app()->forgetScopedInstances();
    }

    public function test_unsupported_get_is_null_and_malformed_payload_is_not_repaired(): void
    {
        $this->seedPrices();
        $list = app(ContractListCacheService::class);
        Cache::forever($list->getCacheKey(5000), ['invalid' => true]);
        $this->forbid();
        Route::get('/custom', function () {
            $this->assertNull(app(ContractListCacheService::class)->getCachedMetrics(5432));

            return 'ok';
        });
        $this->get('/custom?kulutus=5432')->assertOk();
        Route::get('/malformed', fn () => app(ContractListCacheService::class)->getCachedMetrics(5000));
        $this->withoutExceptionHandling();
        try {
            $this->get('/malformed');
            $this->fail('Malformed data must fail.');
        } catch (InvalidArgumentException) {
            $this->assertFalse(app(ContractPriceCacheLifecycle::class)->pending());
        }
    }

    public function test_explicit_custom_preparation_is_exact_and_following_get_does_not_calculate(): void
    {
        $this->seedPrices();
        Route::post('/prepare-custom', function () {
            app(PublicPriceCalculationPolicy::class)->allowUserAction();
            $set = app(ContractListCacheService::class)->prepareComparisonForConsumption(5432);
            $this->assertSame(5432, $set->consumption());
            $this->assertEqualsWithDelta(325.92, $set->metric('cached')->pricing()->total(), .001);

            return 'ok';
        });
        $this->post('/prepare-custom')->assertOk();
        $key = app(ContractListCacheService::class)->getCacheKey(5432);
        $payload = Cache::get($key);
        $this->assertNotNull($payload);
        $this->forbid();
        Route::get('/prepared-custom', function () use ($payload) {
            $this->assertSame($payload, app(ContractListCacheService::class)->getCachedMetrics(5432)->toArray());
            $this->assertNull(app(ContractListCacheService::class)->getCachedMetrics(5433));

            return 'ok';
        });
        $this->get('/prepared-custom?kulutus=5432')->assertOk();
        $this->head('/prepared-custom?kulutus=5432')->assertOk();
        Route::get('/denied-prepare', fn () => app(ContractListCacheService::class)->prepareComparisonForConsumption(5432));
        $this->get('/denied-prepare')->assertStatus(503);
        $lifecycle = app(ContractPriceCacheLifecycle::class);
        $active = $lifecycle->active();
        $lifecycle->promote($active, $lifecycle->candidate($active), []);
        Route::get('/next-custom', function () {
            $this->assertNull(app(ContractListCacheService::class)->getCachedMetrics(5432));

            return 'ok';
        });
        $this->get('/next-custom')->assertOk();
        $this->assertFalse($lifecycle->pending());
    }

    public function test_strict_build_still_excludes_unsafe_current_identity_and_retained_exclusions_stay_excluded(): void
    {
        config(['canonical_pricing.enabled' => true]);
        app()->forgetScopedInstances();
        $contract = $this->seedPrices();
        DB::statement('PRAGMA foreign_keys = OFF');
        $contract->update(['current_source_observation_id' => 9001, 'published_interpretation_id' => 9002]);
        DB::statement('PRAGMA foreign_keys = ON');
        $list = app(ContractListCacheService::class);
        $build = new \ReflectionMethod($list, 'buildCachedMetrics');
        $unsafe = $build->invoke($list, 5000);
        $this->assertFalse($unsafe->metric('cached')->isListed());
        $this->assertNull($unsafe->metric('cached')->pricing()->total());
        Cache::forever($list->getCacheKey(5000), $unsafe->toArray());
        $contract->update(['current_source_observation_id' => null, 'published_interpretation_id' => null]);
        $this->forbid();
        Route::get('/excluded', function () {
            $this->assertFalse(app(ContractListCacheService::class)->getCachedMetrics(5000)->metric('cached')->isListed());

            return 'ok';
        });
        $this->get('/excluded')->assertOk();
    }

    public function test_price_class_change_excludes_retained_transport_without_calculation(): void
    {
        config(['canonical_pricing.enabled' => true]);
        app()->forgetScopedInstances();
        $contract = $this->seedPrices();
        $contract->update(['pricing_model' => 'Spot']);
        $this->forbid();
        Route::get('/changed-class', function () {
            $metric = app(ContractListCacheService::class)->getCachedMetrics(5000)->metric('cached');
            $this->assertFalse($metric->isListed());
            $this->assertNull($metric->pricing()->total());
            $this->assertCount(0, app(CompanyListCacheService::class)->getCachedCompanies());

            return 'ok';
        });
        $this->get('/changed-class')->assertOk();
    }

    public function test_custom_preparation_retries_one_promotion_race_and_tracks_only_current_write(): void
    {
        config(['canonical_pricing.enabled' => true]);
        app()->forgetScopedInstances();
        $this->seedPrices();
        $lifecycle = app(ContractPriceCacheLifecycle::class);
        $starting = $lifecycle->active();
        $oldKey = app(ContractListCacheService::class)->getCacheKey(5432);
        $calls = 0;
        $real = app(CanonicalContractPricingService::class);
        $proxy = Mockery::mock($real)->makePartial();
        $proxy->shouldReceive('metricsForContracts')->andReturnUsing(function ($contracts, $usage) use ($real, $lifecycle, $starting, &$calls) {
            $metrics = $real->metricsForContracts($contracts, $usage);
            if (++$calls === 1) {
                $lifecycle->promote($starting, $lifecycle->candidate($starting), []);
            }

            return $metrics;
        });
        app()->instance(CanonicalContractPricingService::class, $proxy);
        app()->forgetInstance(ContractListCacheService::class);
        request()->server->remove('REQUEST_METHOD');
        $list = app(ContractListCacheService::class);
        $this->assertEqualsWithDelta(325.92, $list->prepareComparisonForConsumption(5432)->metric('cached')->pricing()->total(), .001);
        $this->assertSame(2, $calls);
        $this->assertNull(Cache::get($oldKey));
        $this->assertNotNull(Cache::get($list->getCacheKey(5432)));
        $this->assertContains($list->getCacheKey(5432), Cache::get('contract_price_manifest:'.$lifecycle->active()['generation']));
    }

    public function test_exact_7312_handoff_keeps_financial_result_and_get_does_no_pricing(): void
    {
        config(['canonical_pricing.enabled' => true]);
        app()->forgetScopedInstances();
        $this->seedPrices();
        request()->server->remove('REQUEST_METHOD');
        $set = app(ContractListCacheService::class)->prepareComparisonForConsumption(7312);
        $this->assertSame(7312, $set->consumption());
        $this->assertEqualsWithDelta(438.72, $set->metric('cached')->pricing()->total(), .001);
        $this->forbid();
        Route::get('/exact-7312', function () use ($set) {
            $this->assertSame($set->toArray(), app(ContractListCacheService::class)->getCachedMetrics(7312)->toArray());
            $this->assertNull(app(ContractListCacheService::class)->getCachedMetrics(7313));

            return 'ok';
        });
        $this->get('/exact-7312')->assertOk();
    }

    public function test_custom_company_wrapper_is_request_only_and_expiry_cannot_use_an_old_wrapper(): void
    {
        config(['canonical_pricing.enabled' => true]);
        app()->forgetScopedInstances();
        $this->seedPrices();
        request()->server->remove('REQUEST_METHOD');
        $list = app(ContractListCacheService::class);
        $list->prepareComparisonForConsumption(7312);
        $key = app(CompanyListCacheService::class)->getCacheKey(7312);
        $active = app(ContractPriceCacheLifecycle::class)->active();
        $this->forbid();
        Route::get('/custom-company', function () {
            $companies = app(CompanyListCacheService::class)->getCachedCompanies(7312);
            $this->assertEqualsWithDelta(438.72, $companies->first()['lowestPrice'], .001);

            return 'ok';
        });
        $this->get('/custom-company')->assertOk();
        $this->assertNull(Cache::get($key));
        $this->assertNotContains($key, Cache::get('contract_price_manifest:'.$active['generation']));
        // A pre-change custom wrapper is neither read nor reused, even if still stored.
        $old = collect([['lowestPrice' => 777, '_availability' => $list->availabilityFingerprint()]]);
        Cache::forever($key, $old);
        app()->forgetInstance(ContractListCacheService::class);
        app()->forgetInstance(CompanyListCacheService::class);
        $this->get('/custom-company')->assertOk();
        $this->assertEquals($old, Cache::get($key));
        $this->travel(1801)->seconds();
        app()->forgetInstance(ContractListCacheService::class);
        app()->forgetInstance(CompanyListCacheService::class);
        $this->get('/custom-company')->assertStatus(503);
        $this->assertEquals($old, Cache::get($key));
        $this->assertFalse(app(ContractPriceCacheLifecycle::class)->pending());
    }

    public function test_mechanism_changes_exclude_retained_prices_until_verified_refresh(): void
    {
        config(['canonical_pricing.enabled' => true]);
        app()->forgetScopedInstances();
        $contract = $this->seedPrices();
        $real = app(CanonicalContractPricingService::class);
        $pricing = $contract->canonical_pricing;
        foreach ([
            ['recurring_schedule', ['present' => true, 'cadence' => 'quarterly']],
            ['recurring_schedule', ['present' => true, 'cadence' => 'monthly']],
            ['recurring_schedule', ['present' => false, 'cadence' => 'none']],
            ['consumption_effect', ['present' => true, 'applies_to' => 'base_contract']],
            ['consumption_effect', ['present' => true, 'applies_to' => 'optional_fixing']],
        ] as [$mechanism, $value]) {
            $pricing[$mechanism] = $value;
            $contract->update(['canonical_pricing' => $pricing]);
            $this->forbid();
            Route::get('/mechanism', function () {
                $metric = app(ContractListCacheService::class)->getCachedMetrics(5000)->metric('cached');
                $this->assertFalse($metric->isListed());
                $this->assertNull($metric->pricing()->total());

                return 'ok';
            });
            $this->get('/mechanism')->assertOk();
            app()->instance(CanonicalContractPricingService::class, $real);
            app()->forgetInstance(ContractListCacheService::class);
            app()->forgetInstance(CompanyListCacheService::class);
            request()->server->remove('REQUEST_METHOD');
            $list = app(ContractListCacheService::class);
            $list->refresh(app(CompanyListCacheService::class));
            $this->assertTrue($list->getCachedMetrics(5000)->metric('cached')->isListed());
        }
    }

    public function test_dates_and_prices_do_not_change_effective_mechanism_and_partial_metadata_remains_readable(): void
    {
        config(['canonical_pricing.enabled' => true]);
        app()->forgetScopedInstances();
        $contract = $this->seedPrices();
        $list = app(ContractListCacheService::class);
        $key = $list->getCacheKey(5000);
        $payload = Cache::get($key);
        $pricing = $contract->canonical_pricing;
        $pricing['recurring_schedule']['current_period_end'] = '2030-01-01';
        $pricing['phases'][0]['components'][0]['amount'] = 12;
        $contract->update(['canonical_pricing' => $pricing]);
        $this->forbid();
        Route::get('/same-mechanism', function () use ($payload) {
            $this->assertSame($payload, app(ContractListCacheService::class)->getCachedMetrics(5000)->toArray());

            return 'ok';
        });
        $this->get('/same-mechanism')->assertOk();
        foreach (['reset_cadence', 'has_reset', 'has_base_effect'] as $field) {
            unset($payload['source_classification']['cached'][$field]);
        }
        Cache::forever($key, $payload);
        $pricing['consumption_effect'] = ['present' => true, 'applies_to' => 'base_contract'];
        $contract->update(['canonical_pricing' => $pricing]);
        Route::get('/partial-metadata', function () use ($payload) {
            $this->assertSame($payload, app(ContractListCacheService::class)->getCachedMetrics(5000)->toArray());

            return 'ok';
        });
        $this->get('/partial-metadata')->assertOk();
        unset($payload['source_classification']);
        Cache::forever($key, $payload);
        app()->forgetInstance(ContractListCacheService::class);
        $this->assertTrue(app(ContractListCacheService::class)->getCachedMetrics(5000)->metric('cached')->isListed());
    }

    public function test_availability_reads_only_four_json_scalars_with_normalized_effective_flags(): void
    {
        $contract = $this->seedPrices();
        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            $queries[] = $query->sql;
        });
        $list = app(ContractListCacheService::class);
        $row = $list->currentAvailability()['cached'];
        $this->assertFalse($row['has_reset']);
        $this->assertFalse($row['has_base_effect']);
        $this->assertNull($row['reset_cadence']);
        $sql = implode("\n", $queries);
        $this->assertSame(4, substr_count($sql, 'json_extract('));
        $this->assertStringStartsWith('select "id",', $sql);
        $this->assertStringNotContainsString('contract_source_snapshots', $sql);
        $this->assertStringNotContainsString('phases', $sql);
        $pricing = $contract->canonical_pricing;
        $pricing['recurring_schedule'] = ['present' => true, 'cadence' => 'other'];
        $pricing['consumption_effect'] = ['present' => true, 'applies_to' => 'both'];
        $contract->update(['canonical_pricing' => $pricing]);
        $row = $list->currentAvailability()['cached'];
        $this->assertTrue($row['has_reset']);
        $this->assertTrue($row['has_base_effect']);
        $this->assertSame('other', $row['reset_cadence']);
        // Compile the same bounded query with MySQL grammar, without opening a connection.
        $connection = DB::connection();
        $grammar = $connection->getQueryGrammar();
        $connection->setQueryGrammar(new MySqlGrammar);
        try {
            $mysql = ElectricityContract::query()->select([
                'canonical_pricing->recurring_schedule->present as reset_present',
                'canonical_pricing->recurring_schedule->cadence as reset_cadence',
                'canonical_pricing->consumption_effect->present as effect_present',
                'canonical_pricing->consumption_effect->applies_to as effect_applies_to',
            ])->toSql();
            $this->assertSame(4, substr_count($mysql, 'json_unquote(json_extract('));
        } finally {
            $connection->setQueryGrammar($grammar);
        }
    }

    public function test_direct_cli_and_explicit_post_can_build_cold_metrics(): void
    {
        $this->seedPrices();
        $list = app(ContractListCacheService::class);
        Cache::forget($list->getCacheKey(5000));
        $list->resetCalculationState();
        request()->server->remove('REQUEST_METHOD');
        $this->assertNotNull($list->getCachedMetrics(5000));
        Cache::forget($list->getCacheKey(5000));
        $list->resetCalculationState();
        Route::post('/action', function () {
            app(PublicPriceCalculationPolicy::class)->allowUserAction();
            $this->assertNotNull(app(ContractListCacheService::class)->getCachedMetrics(5000));

            return 'ok';
        });
        $this->post('/action')->assertOk();
    }
}
