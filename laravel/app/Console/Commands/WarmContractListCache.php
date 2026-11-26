<?php

namespace App\Console\Commands;

use App\Services\Caching\ContractPriceCacheConflict;
use App\Services\Caching\ContractPriceCacheProducerBusy;
use App\Services\CompanyListCacheService;
use App\Services\ContractListCacheService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class WarmContractListCache extends Command
{
    protected $signature = 'contracts:warm-cache {--refresh : Build and verify a replacement before activating it} {--pending : Refresh only pending demand or missing compatible payloads}';

    protected $description = 'Warm cached contract list calculations for common consumption presets';

    public function handle(ContractListCacheService $contractListCache, CompanyListCacheService $companyListCache): int
    {
        if (! $this->option('refresh') && ! $contractListCache->needsRefresh($companyListCache)) {
            return self::SUCCESS;
        }
        try {
            $version = $contractListCache->refresh($companyListCache, onlyIfNeeded: ! $this->option('refresh'));
        } catch (ContractPriceCacheConflict|ContractPriceCacheProducerBusy $exception) {
            if (! $this->option('pending')) {
                return $this->failed($exception);
            }
            // A wait, not a failure: during the morning import each published interpretation
            // changes the evidence, so both candidates can conflict, and import completion can
            // hold the producer lease. Demand stays pending for the next five-minute tick
            // (Sentry VOLTIKKA-5D, every morning from 2026-11-20).
            Log::info('Contract price cache refresh deferred; demand remains pending.', [
                'reason' => $exception instanceof ContractPriceCacheConflict ? $exception->reason : 'producer_busy',
            ]);

            return self::SUCCESS;
        } catch (\Throwable $exception) {
            return $this->failed($exception);
        }
        if (! $this->option('pending')) {
            $this->info("Contract and company price cache version {$version} activated.");
        }

        return self::SUCCESS;
    }

    private function failed(\Throwable $exception): int
    {
        // The class only: exception messages can carry contract data.
        $this->error('Contract price cache refresh failed ('.$exception::class.'); demand remains pending.');

        return self::FAILURE;
    }
}
