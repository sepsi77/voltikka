<?php

namespace Tests\Feature;

use App\Jobs\WarmContractPriceStatisticsCache;
use App\Models\Company;
use App\Models\ContractSourceObservation;
use App\Models\ContractSourceSnapshot;
use App\Models\ElectricityContract;
use App\Services\CompanyListCacheService;
use App\Services\ContractImport\ContractImportResult;
use App\Services\ContractImport\ContractPostImportCoordinator;
use App\Services\ContractInterpretation\ContractInterpretationDispatcher;
use App\Services\ContractListCacheService;
use App\Services\ContractStatistics\ContractPercentileService;
use App\Services\ContractStatistics\ContractPriceStatisticsService;
use App\Services\SitemapService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Factory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

class ContractPostImportCoordinatorTest extends TestCase
{
    use RefreshDatabase;

    public function test_dispatch_failure_is_isolated_and_cache_warm_failure_does_not_block_statistics(): void
    {
        Queue::fake();
        [$firstObservation, $secondObservation] = $this->observations();
        $events = [];

        $interpretations = $this->createMock(ContractInterpretationDispatcher::class);
        $interpretations->expects($this->exactly(2))
            ->method('dispatch')
            ->willReturnCallback(function (ContractSourceObservation $observation) use ($firstObservation, &$events) {
                $events[] = 'interpretation:'.$observation->id;
                if ($observation->is($firstObservation)) {
                    throw new RuntimeException('First dispatch failed');
                }

                return null;
            });

        $statistics = $this->createMock(ContractPriceStatisticsService::class);
        $statistics->expects($this->once())
            ->method('calculateForDate')
            ->willReturnCallback(function () use (&$events): array {
                $events[] = 'statistics';
                $this->travel(5)->seconds();

                return ['snapshots' => 0, 'statistics' => 0];
            });

        $contractCache = $this->createMock(ContractListCacheService::class);
        $contractCache->expects($this->never())->method('bumpVersion');
        $contractCache->expects($this->once())
            ->method('refresh')
            ->willReturnCallback(function () use (&$events): int {
                $events[] = 'contract_warm';
                throw new RuntimeException('Contract cache warm failed');
            });

        $companyCache = $this->createMock(CompanyListCacheService::class);
        $companyCache->expects($this->never())->method('bumpVersion');
        $companyCache->expects($this->never())->method('warm');

        $percentiles = $this->createMock(ContractPercentileService::class);
        $percentiles->expects($this->once())->method('calculate');

        config()->set('cache.default', 'array');
        $coordinator = new ContractPostImportCoordinator(
            $interpretations,
            $statistics,
            $contractCache,
            $companyCache,
            $percentiles,
            $this->app->make(Factory::class),
        );

        Cache::forever('unrelated-price-refresh-test', 'keep');
        Cache::forever(SitemapService::CACHE_KEY, 'old');
        $this->travelTo(CarbonImmutable::parse('2026-08-01 06:12:34', 'Europe/Helsinki'));
        $result = $coordinator->run($this->importResult([
            $firstObservation->id,
            $secondObservation->id,
        ]), '2026-08-01');

        $this->assertFalse($result->succeeded());
        $this->assertSame('keep', Cache::get('unrelated-price-refresh-test'));
        $this->assertNull(Cache::get(SitemapService::CACHE_KEY));
        $this->assertSame('2026-08-01T06:12:34+03:00', $result->statisticsStartedAt?->toIso8601String());
        $this->assertSame('2026-08-01T06:12:39+03:00', $result->statisticsCompletedAt?->toIso8601String());
        $this->assertSame([$firstObservation->id], $result->interpretationDispatchFailureObservationIds);
        $this->assertArrayHasKey('interpretation:'.$firstObservation->id, $result->optionalFailures);
        $this->assertArrayHasKey('price_cache_refresh', $result->requiredFailures);
        $this->assertContains('interpretation:'.$secondObservation->id, $events);
        Queue::assertPushed(WarmContractPriceStatisticsCache::class, 1);
        $this->assertLessThan(
            array_search('contract_warm', $events, true),
            array_search('statistics', $events, true),
        );
    }

    public function test_partial_import_or_statistics_failure_does_not_start_a_price_refresh(): void
    {
        Queue::fake();
        foreach ([[false, true], [true, false]] as [$complete, $statisticsSucceeded]) {
            $statistics = $this->createMock(ContractPriceStatisticsService::class);
            if ($statisticsSucceeded) {
                $statistics->method('calculateForDate')->willReturn([]);
            } else {
                $statistics->method('calculateForDate')->willThrowException(new RuntimeException('statistics'));
            }
            $contracts = $this->createMock(ContractListCacheService::class);
            $contracts->expects($this->never())->method('refresh');
            $contracts->expects($this->never())->method('bumpVersion');
            $companies = $this->createMock(CompanyListCacheService::class);
            $companies->expects($this->never())->method('bumpVersion');
            $coordinator = new ContractPostImportCoordinator(
                $this->createMock(ContractInterpretationDispatcher::class), $statistics, $contracts, $companies,
                $this->createMock(ContractPercentileService::class),
                $this->app->make(Factory::class),
            );
            $result = $coordinator->run($this->importResult([], $complete), '2026-08-01');
            $this->assertSame($statisticsSucceeded, $result->statisticsCompletedAt !== null);
            $this->assertSame($statisticsSucceeded, $result->succeeded());
        }
    }

    /** @return array{ContractSourceObservation, ContractSourceObservation} */
    private function observations(): array
    {
        Company::create(['name' => 'Coordinator Oy', 'name_slug' => 'coordinator-oy']);
        $contract = ElectricityContract::create([
            'id' => 'coordinator-contract',
            'api_id' => 'coordinator-api',
            'name' => 'Coordinator Contract',
            'company_name' => 'Coordinator Oy',
            'contract_type' => 'FixedTerm',
            'metering' => 'General',
            'availability_is_national' => true,
        ]);

        $firstSnapshot = ContractSourceSnapshot::create([
            'contract_id' => $contract->id,
            'source_fingerprint' => str_repeat('a', 64),
            'source_payload' => ['Id' => 'coordinator-api', 'version' => 1],
            'first_observed_at' => now(),
            'last_observed_at' => now(),
        ]);
        $secondSnapshot = ContractSourceSnapshot::create([
            'contract_id' => $contract->id,
            'source_fingerprint' => str_repeat('b', 64),
            'source_payload' => ['Id' => 'coordinator-api', 'version' => 2],
            'first_observed_at' => now(),
            'last_observed_at' => now(),
        ]);

        return [
            ContractSourceObservation::create([
                'contract_id' => $contract->id,
                'source_snapshot_id' => $firstSnapshot->id,
                'first_observed_at' => now(),
                'last_observed_at' => now(),
            ]),
            ContractSourceObservation::create([
                'contract_id' => $contract->id,
                'source_snapshot_id' => $secondSnapshot->id,
                'first_observed_at' => now(),
                'last_observed_at' => now(),
            ]),
        ];
    }

    /** @param list<int> $observationIds */
    private function importResult(array $observationIds, bool $complete = true): ContractImportResult
    {
        return new ContractImportResult(
            complete: $complete,
            contractCount: 1,
            activeContractCount: 1,
            priceComponentCount: 1,
            replacementStats: [
                'linked' => 0,
                'skipped_existing' => 0,
                'skipped_no_match' => 0,
                'skipped_not_high' => 0,
            ],
            changedObservationIds: $observationIds,
            observedObservationIds: $observationIds,
            activeContractIds: ['coordinator-contract'],
            companyNames: ['Coordinator Oy'],
        );
    }
}
