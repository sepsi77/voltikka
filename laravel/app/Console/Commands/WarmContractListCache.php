<?php

namespace App\Console\Commands;

use App\Services\CompanyListCacheService;
use App\Services\ContractListCacheService;
use Illuminate\Console\Command;

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
        } catch (\Throwable) {
            $this->error('Contract price cache refresh failed; demand remains pending.');

            return self::FAILURE;
        }
        if (! $this->option('pending')) {
            $this->info("Contract and company price cache version {$version} activated.");
        }

        return self::SUCCESS;
    }
}
