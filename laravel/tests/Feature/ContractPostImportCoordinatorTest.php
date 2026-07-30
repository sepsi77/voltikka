<?php

namespace Tests\Feature;

use App\Jobs\WarmContractPriceStatisticsCache;
use App\Models\Company;
use App\Models\ContractSourceSnapshot;
use App\Models\ElectricityContract;
use App\Services\CompanyListCacheService;
use App\Services\ContractImport\ContractImportResult;
use App\Services\ContractImport\ContractPostImportCoordinator;
use App\Services\ContractInterpretation\ContractInterpretationDispatcher;
use App\Services\ContractListCacheService;
use App\Services\ContractStatistics\ContractPercentileService;
use App\Services\ContractStatistics\ContractPriceStatisticsService;
use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

class ContractPostImportCoordinatorTest extends TestCase
{
    use RefreshDatabase;

    public function test_dispatch_failure_is_isolated_and_cache_warm_failure_does_not_block_statistics(): void
    {
        Queue::fake();
        [$firstSnapshot, $secondSnapshot] = $this->snapshots();
        $events = [];

        $interpretations = $this->createMock(ContractInterpretationDispatcher::class);
        $interpretations->expects($this->exactly(2))
            ->method('dispatch')
            ->willReturnCallback(function (ContractSourceSnapshot $snapshot) use ($firstSnapshot, &$events) {
                $events[] = 'interpretation:'.$snapshot->id;
                if ($snapshot->is($firstSnapshot)) {
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
        $contractCache->expects($this->once())->method('bumpVersion')->willReturn(2);
        $contractCache->expects($this->once())
            ->method('warmPresetCaches')
            ->willReturnCallback(function () use (&$events): void {
                $events[] = 'contract_warm';
                throw new RuntimeException('Contract cache warm failed');
            });

        $companyCache = $this->createMock(CompanyListCacheService::class);
        $companyCache->expects($this->once())->method('bumpVersion')->willReturn(2);
        $companyCache->expects($this->once())->method('warm');

        $percentiles = $this->createMock(ContractPercentileService::class);
        $percentiles->expects($this->once())->method('calculate');

        config()->set('cache.default', 'array');
        $coordinator = new ContractPostImportCoordinator(
            $interpretations,
            $statistics,
            $contractCache,
            $companyCache,
            $percentiles,
            $this->app->make(\Illuminate\Contracts\Cache\Factory::class),
            $this->app->make(\Illuminate\Contracts\Config\Repository::class),
            $this->app->make(DatabaseManager::class),
        );

        $this->travelTo(CarbonImmutable::parse('2026-08-01 06:12:34', 'Europe/Helsinki'));
        $result = $coordinator->run($this->importResult([
            $firstSnapshot->id,
            $secondSnapshot->id,
        ]), '2026-08-01');

        $this->assertTrue($result->succeeded());
        $this->assertSame('2026-08-01T06:12:34+03:00', $result->statisticsStartedAt?->toIso8601String());
        $this->assertSame('2026-08-01T06:12:39+03:00', $result->statisticsCompletedAt?->toIso8601String());
        $this->assertSame([$firstSnapshot->id], $result->interpretationDispatchFailureSnapshotIds);
        $this->assertArrayHasKey('interpretation:'.$firstSnapshot->id, $result->optionalFailures);
        $this->assertArrayHasKey('contract_cache_warm', $result->optionalFailures);
        $this->assertContains('interpretation:'.$secondSnapshot->id, $events);
        Queue::assertPushed(WarmContractPriceStatisticsCache::class, 1);
        $this->assertLessThan(
            array_search('contract_warm', $events, true),
            array_search('statistics', $events, true),
        );
    }

    /** @return array{ContractSourceSnapshot, ContractSourceSnapshot} */
    private function snapshots(): array
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

        return [
            ContractSourceSnapshot::create([
                'contract_id' => $contract->id,
                'source_fingerprint' => str_repeat('a', 64),
                'source_payload' => ['Id' => 'coordinator-api', 'version' => 1],
                'first_observed_at' => now(),
                'last_observed_at' => now(),
            ]),
            ContractSourceSnapshot::create([
                'contract_id' => $contract->id,
                'source_fingerprint' => str_repeat('b', 64),
                'source_payload' => ['Id' => 'coordinator-api', 'version' => 2],
                'first_observed_at' => now(),
                'last_observed_at' => now(),
            ]),
        ];
    }

    /** @param list<int> $snapshotIds */
    private function importResult(array $snapshotIds): ContractImportResult
    {
        return new ContractImportResult(
            complete: true,
            contractCount: 1,
            activeContractCount: 1,
            priceComponentCount: 1,
            replacementStats: [
                'linked' => 0,
                'skipped_existing' => 0,
                'skipped_no_match' => 0,
                'skipped_not_high' => 0,
            ],
            changedSnapshotIds: $snapshotIds,
            observedSnapshotIds: $snapshotIds,
            activeContractIds: ['coordinator-contract'],
            companyNames: ['Coordinator Oy'],
        );
    }
}
