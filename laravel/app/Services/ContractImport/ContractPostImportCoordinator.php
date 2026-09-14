<?php

namespace App\Services\ContractImport;

use App\Jobs\WarmContractPriceStatisticsCache;
use App\Models\ContractSourceObservation;
use App\Services\CompanyListCacheService;
use App\Services\ContractInterpretation\ContractInterpretationDispatcher;
use App\Services\ContractListCacheService;
use App\Services\ContractStatistics\ContractPercentileService;
use App\Services\ContractStatistics\ContractPriceStatisticsService;
use App\Services\SitemapService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
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
    ) {}

    public function run(ContractImportResult $import, string $importDate): ContractPostImportResult
    {
        $requiredFailures = [];
        $requiredExceptions = [];
        $optionalFailures = [];
        $dispatchFailureIds = [];

        // The dispatcher is fingerprint-idempotent. Revisit every observation from
        // this import so a transient pre-dispatch failure can recover on the next run.
        foreach ($import->observedObservationIds as $observationId) {
            try {
                $observation = ContractSourceObservation::query()
                    ->with('sourceSnapshot')
                    ->findOrFail($observationId);
                $this->interpretationDispatcher->dispatch($observation);
            } catch (Throwable $exception) {
                $dispatchFailureIds[] = $observationId;
                $optionalFailures["interpretation:{$observationId}"] = $exception->getMessage();
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
            $requiredExceptions['daily_statistics'] = $exception;
            $requiredFailures['daily_statistics'] = $exception->getMessage();
        }

        try {
            $this->clearStaleApplicationCache();
        } catch (Throwable $exception) {
            $requiredExceptions['cache_invalidation'] = $exception;
            $requiredFailures['cache_invalidation'] = $exception->getMessage();
        }

        if ($import->complete && $statisticsSucceeded) {
            try {
                $this->contractListCache->refresh($this->companyListCache);
            } catch (Throwable $exception) {
                $requiredExceptions['price_cache_refresh'] = $exception;
                $requiredFailures['price_cache_refresh'] = $exception->getMessage();
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
            interpretationDispatchFailureObservationIds: $dispatchFailureIds,
            statisticsStartedAt: $statisticsStartedAt,
            statisticsCompletedAt: $statisticsCompletedAt,
            requiredExceptions: $requiredExceptions,
        );
    }

    private function clearStaleApplicationCache(): void
    {
        $this->cache->store()->forget(SitemapService::CACHE_KEY);
    }
}
