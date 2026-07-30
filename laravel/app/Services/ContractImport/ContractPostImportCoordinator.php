<?php

namespace App\Services\ContractImport;

use App\Jobs\WarmContractPriceStatisticsCache;
use App\Models\ContractSourceSnapshot;
use App\Services\CompanyListCacheService;
use App\Services\ContractInterpretation\ContractInterpretationDispatcher;
use App\Services\ContractListCacheService;
use App\Services\ContractStatistics\ContractPercentileService;
use App\Services\ContractStatistics\ContractPriceStatisticsService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Database\DatabaseManager;
use Throwable;

class ContractPostImportCoordinator
{
    public function __construct(
        private readonly ContractInterpretationDispatcher $interpretationDispatcher,
        private readonly ContractPriceStatisticsService $statistics,
        private readonly ContractListCacheService $contractListCache,
        private readonly CompanyListCacheService $companyListCache,
        private readonly ContractPercentileService $percentiles,
        private readonly CacheFactory $cache,
        private readonly ConfigRepository $config,
        private readonly DatabaseManager $database,
    ) {}

    public function run(ContractImportResult $import, string $importDate): ContractPostImportResult
    {
        $requiredFailures = [];
        $optionalFailures = [];
        $dispatchFailureIds = [];

        // The dispatcher is fingerprint-idempotent. Revisit every snapshot observed in
        // this import so a transient pre-dispatch failure can recover on the next run.
        foreach ($import->observedSnapshotIds as $snapshotId) {
            try {
                $snapshot = ContractSourceSnapshot::findOrFail($snapshotId);
                $this->interpretationDispatcher->dispatch($snapshot);
            } catch (Throwable $exception) {
                $dispatchFailureIds[] = $snapshotId;
                $optionalFailures["interpretation:{$snapshotId}"] = $exception->getMessage();
            }
        }

        $statisticsSucceeded = false;
        $statisticsStartedAt = null;
        $statisticsCompletedAt = null;
        try {
            $statisticsStartedAt = CarbonImmutable::now('Europe/Helsinki');
            $this->statistics->calculateForDate(
                date: $importDate,
                contractIds: $import->activeContractIds,
                overwrite: true,
            );
            $statisticsCompletedAt = CarbonImmutable::now('Europe/Helsinki');
            $statisticsSucceeded = true;
        } catch (Throwable $exception) {
            $requiredFailures['daily_statistics'] = $exception->getMessage();
        }

        try {
            $this->clearStaleApplicationCache();
        } catch (Throwable $exception) {
            $requiredFailures['cache_invalidation'] = $exception->getMessage();
        }

        $contractVersionBumped = false;
        try {
            $this->contractListCache->bumpVersion();
            $contractVersionBumped = true;
        } catch (Throwable $exception) {
            $requiredFailures['contract_cache_version'] = $exception->getMessage();
        }

        $companyVersionBumped = false;
        try {
            $this->companyListCache->bumpVersion();
            $companyVersionBumped = true;
        } catch (Throwable $exception) {
            $requiredFailures['company_cache_version'] = $exception->getMessage();
        }

        if ($contractVersionBumped) {
            try {
                $this->contractListCache->warmPresetCaches();
            } catch (Throwable $exception) {
                $optionalFailures['contract_cache_warm'] = $exception->getMessage();
            }
        }

        if ($companyVersionBumped) {
            try {
                $this->companyListCache->warm();
            } catch (Throwable $exception) {
                $optionalFailures['company_cache_warm'] = $exception->getMessage();
            }
        }

        if ($statisticsSucceeded) {
            try {
                WarmContractPriceStatisticsCache::dispatch('weekly', 5000);
            } catch (Throwable $exception) {
                $optionalFailures['statistics_cache_dispatch'] = $exception->getMessage();
            }
        }

        try {
            $this->percentiles->calculate();
        } catch (Throwable $exception) {
            $optionalFailures['percentiles'] = $exception->getMessage();
        }

        return new ContractPostImportResult(
            requiredFailures: $requiredFailures,
            optionalFailures: $optionalFailures,
            interpretationDispatchFailureSnapshotIds: $dispatchFailureIds,
            statisticsStartedAt: $statisticsStartedAt,
            statisticsCompletedAt: $statisticsCompletedAt,
        );
    }

    /**
     * Database cache uses TRUNCATE so expired large rows release InnoDB space.
     */
    private function clearStaleApplicationCache(): void
    {
        $defaultStore = $this->config->get('cache.default');
        $storeConfig = $this->config->get("cache.stores.{$defaultStore}", []);

        if (($storeConfig['driver'] ?? null) === 'database') {
            $connectionName = $storeConfig['connection'] ?? $this->config->get('database.default');
            $table = $storeConfig['table'] ?? 'cache';
            $connection = $this->database->connection($connectionName);
            $wrappedTable = $connection->getQueryGrammar()->wrapTable($table);
            $connection->statement("TRUNCATE TABLE {$wrappedTable}");

            return;
        }

        $this->cache->store($defaultStore)->clear();
    }
}
