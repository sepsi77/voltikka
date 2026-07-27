<?php

namespace Tests\Unit;

use App\Services\CanonicalPricing\CanonicalContractPricingService;
use App\Services\CO2EmissionsCalculator;
use App\Services\CompanyListCacheService;
use App\Services\ContractListCacheService;
use App\Services\ContractPriceCalculator;
use App\Services\ContractRankingService;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class ContractRequestMemoizationTest extends TestCase
{
    public function test_contract_list_metrics_cache_reads_are_memoized_per_service_instance(): void
    {
        $metrics = [
            'contracts' => [],
            'sorted_ids' => [],
            'consumption' => 5000,
        ];

        Cache::shouldReceive('get')
            ->once()
            ->with('contract_list_cache_version', 1)
            ->andReturn(7);

        Cache::shouldReceive('remember')
            ->once()
            // v7 = import-driven data version, s10 = cached payload shape version,
            // c0r0 = canonical pricing off, market-reset forward shift off.
            ->with('contract_list_metrics:v7:s10:c0r0:5000', 60 * 60 * 48, \Mockery::type(\Closure::class))
            ->andReturn($metrics);

        $canonical = $this->createMock(CanonicalContractPricingService::class);
        $canonical->method('enabled')->willReturn(false);

        $service = new ContractListCacheService(
            $this->createMock(ContractPriceCalculator::class),
            $this->createMock(CO2EmissionsCalculator::class),
            $canonical,
        );

        $this->assertSame($metrics, $service->getCachedMetrics(5000));
        $this->assertSame($metrics, $service->getCachedMetrics(5000));
    }

    public function test_company_list_cache_reads_are_memoized_per_service_instance(): void
    {
        $companies = collect();

        Cache::shouldReceive('get')
            ->once()
            ->with('company_list_cache_version', 1)
            ->andReturn(4);

        Cache::shouldReceive('remember')
            ->once()
            // v4 = company import version, s1 = company payload schema,
            // lv7 = contract pricing data version, c1r0 = pricing flags.
            ->with('company_list:v4:s1:lv7:c1r0:5000', 60 * 60 * 48, \Mockery::type(\Closure::class))
            ->andReturn($companies);

        $listCache = $this->createMock(ContractListCacheService::class);
        $listCache->method('getVersion')->willReturn(7);

        $canonical = $this->createMock(CanonicalContractPricingService::class);
        $canonical->method('enabled')->willReturn(true);
        $canonical->method('resetForwardShiftEnabled')->willReturn(false);

        $service = new CompanyListCacheService(
            $listCache,
            $canonical,
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
            // s1 = ranking payload schema; lv7 = contract pricing data version;
            // c0/r0 = both pricing flags off.
            ->with('contract_rankings_5000kwh:s1:lv7:c0:r0', 3600, \Mockery::type(\Closure::class))
            ->andReturn($rankings);

        $listCache = $this->createMock(ContractListCacheService::class);
        $listCache->method('getVersion')->willReturn(7);

        $canonical = $this->createMock(CanonicalContractPricingService::class);
        $canonical->method('enabled')->willReturn(false);

        $service = new ContractRankingService(
            $this->createMock(ContractPriceCalculator::class),
            $listCache,
            $canonical,
        );

        $this->assertSame(3, $service->getContractRank('contract-a'));
        $this->assertSame(12, $service->getTotalActiveContracts());
    }
}
