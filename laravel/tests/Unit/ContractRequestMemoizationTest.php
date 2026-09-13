<?php

namespace Tests\Unit;

use App\Services\Caching\ContractPriceCacheEvidence;
use App\Services\Caching\ContractPriceCacheLifecycle;
use App\Services\CalculatedCostPayloadSchema;
use App\Services\CanonicalPricing\CanonicalContractPricingService;
use App\Services\CanonicalPricing\PricingMode;
use App\Services\CO2EmissionsCalculator;
use App\Services\CompanyListCacheService;
use App\Services\ContractListCacheService;
use App\Services\ContractPriceCalculator;
use App\Services\ContractPricing\ContractMetricSet;
use App\Services\ContractRankingService;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class ContractRequestMemoizationTest extends TestCase
{
    public function test_contract_list_cache_service_is_shared_within_the_application_scope(): void
    {
        $first = $this->app->make(ContractListCacheService::class);
        $second = $this->app->make(ContractListCacheService::class);

        $this->assertSame($first, $second);
    }

    public function test_contract_list_metrics_cache_reads_are_memoized_per_service_instance(): void
    {
        $metrics = [
            'contracts' => [],
            'sorted_ids' => [],
            'excluded_ids' => [],
            'consumption' => 5000,
        ];

        $lifecycle = $this->createMock(ContractPriceCacheLifecycle::class);
        $lifecycle->method('active')->willReturn(['version' => 7, 'generation' => 'test']);
        $evidence = $this->createMock(ContractPriceCacheEvidence::class);
        $evidence->method('current')->willReturn([]);
        Cache::shouldReceive('get')->once()
            ->with('contract_list_metrics:v7:s'.CalculatedCostPayloadSchema::VERSION.':c0r0:5000:gtest')
            ->andReturn($metrics);

        $canonical = $this->createMock(CanonicalContractPricingService::class);
        $canonical->method('enabled')->willReturn(false);

        $service = new ContractListCacheService(
            $this->createMock(ContractPriceCalculator::class),
            $this->createMock(CO2EmissionsCalculator::class),
            $canonical,
            new PricingMode(canonicalPricingEnabled: false, resetForwardShiftEnabled: false),
            $lifecycle,
            $evidence,
        );

        $first = $service->getCachedMetrics(5000);
        $second = $service->getCachedMetrics(5000);

        $this->assertInstanceOf(ContractMetricSet::class, $first);
        $this->assertSame($metrics, $first->toArray());
        $this->assertSame($first, $second);
    }

    public function test_company_list_cache_reads_are_memoized_per_service_instance(): void
    {
        $companies = collect();

        $lifecycle = $this->createMock(ContractPriceCacheLifecycle::class);
        $lifecycle->method('active')->willReturn(['version' => 7, 'generation' => 'test']);
        Cache::shouldReceive('get')->once()
            ->with('company_list:v7:s2:'.CalculatedCostPayloadSchema::cacheMarker().':lv7:c1r0:5000:gtest:')
            ->andReturn($companies);

        $listCache = $this->createMock(ContractListCacheService::class);
        $listCache->method('getVersion')->willReturn(7);

        $canonical = $this->createMock(CanonicalContractPricingService::class);
        $canonical->method('enabled')->willReturn(true);
        $canonical->method('resetForwardShiftEnabled')->willReturn(false);

        $service = new CompanyListCacheService(
            $listCache,
            $canonical,
            new PricingMode(canonicalPricingEnabled: true, resetForwardShiftEnabled: false),
            $lifecycle,
        );

        $this->assertSame($companies, $service->getCachedCompanies(5000));
        $this->assertSame($companies, $service->getCachedCompanies(5000));
    }

    public function test_contract_rankings_cache_reads_are_memoized_per_service_instance(): void
    {
        $rankings = [
            'contract_ranks' => ['contract-a' => 3],
            'company_ranks' => [],
            'total_contracts' => 12,
            'total_companies' => 0,
        ];

        Cache::shouldReceive('remember')
            ->once()
            // s3 = ranking payload schema; shared calculated-cost schema;
            // lv7 = contract pricing data version; c0r0 = pricing mode.
            ->with('contract_rankings_5000kwh:s3:'.CalculatedCostPayloadSchema::cacheMarker().':lv7:g:c0r0:', 3600, \Mockery::type(\Closure::class))
            ->andReturn($rankings);

        $listCache = $this->createMock(ContractListCacheService::class);
        $listCache->method('getVersion')->willReturn(7);

        $canonical = $this->createMock(CanonicalContractPricingService::class);
        $canonical->method('enabled')->willReturn(false);

        $service = new ContractRankingService(
            $this->createMock(ContractPriceCalculator::class),
            $listCache,
            $canonical,
            new PricingMode(canonicalPricingEnabled: false, resetForwardShiftEnabled: false),
        );

        $this->assertSame(3, $service->getContractRank('contract-a'));
        $this->assertSame(12, $service->getTotalActiveContracts());
    }
}
