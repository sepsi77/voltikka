<?php

namespace Tests\Unit;

use App\Services\CO2EmissionsCalculator;
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
            ->with('contract_list_metrics:v7:5000', 60 * 60 * 48, \Mockery::type(\Closure::class))
            ->andReturn($metrics);

        $service = new ContractListCacheService(
            $this->createMock(ContractPriceCalculator::class),
            $this->createMock(CO2EmissionsCalculator::class),
        );

        $this->assertSame($metrics, $service->getCachedMetrics(5000));
        $this->assertSame($metrics, $service->getCachedMetrics(5000));
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
            ->with('contract_rankings_5000kwh', 3600, \Mockery::type(\Closure::class))
            ->andReturn($rankings);

        $service = new ContractRankingService(
            $this->createMock(ContractPriceCalculator::class),
            $this->createMock(ContractListCacheService::class),
        );

        $this->assertSame(3, $service->getContractRank('contract-a'));
        $this->assertSame(12, $service->getTotalActiveContracts());
    }
}
