<?php

namespace App\Console\Commands;

use App\Services\CompanyListCacheService;
use App\Services\ContractListCacheService;
use Illuminate\Console\Command;

class WarmContractListCache extends Command
{
    protected $signature = 'contracts:warm-cache {--refresh : Bump cache version before warming}';

    protected $description = 'Warm cached contract list calculations for common consumption presets';

    public function handle(ContractListCacheService $contractListCache, CompanyListCacheService $companyListCache): int
    {
        if ($this->option('refresh')) {
            $version = $contractListCache->bumpVersion();
            $companyVersion = $companyListCache->bumpVersion();
            $this->info("Contract list cache version bumped to {$version}.");
            $this->info("Company list cache version bumped to {$companyVersion}.");
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
