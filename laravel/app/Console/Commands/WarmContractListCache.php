<?php

namespace App\Console\Commands;

use App\Services\CompanyListCacheService;
use App\Services\ContractListCacheService;
use Illuminate\Console\Command;

class WarmContractListCache extends Command
{
    protected $signature = 'contracts:warm-cache {--refresh : Build and verify a replacement before activating it}';

    protected $description = 'Warm cached contract list calculations for common consumption presets';

    public function handle(ContractListCacheService $contractListCache, CompanyListCacheService $companyListCache): int
    {
        if ($this->option('refresh')) {
            $version = $contractListCache->refresh($companyListCache);
            $this->info("Contract and company price cache version {$version} activated.");

            return self::SUCCESS;
        }

        $this->info('Warming contract list preset caches...');
        $contractListCache->warmPresetCaches();
        $this->info('Contract list preset caches warmed.');

        $this->info('Warming company list cache...');
        $companyListCache->warm();
        $this->info('Company list cache warmed.');

        return self::SUCCESS;
    }
}
