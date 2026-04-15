<?php

namespace App\Console\Commands;

use App\Services\ContractListCacheService;
use Illuminate\Console\Command;

class WarmContractListCache extends Command
{
    protected $signature = 'contracts:warm-cache {--refresh : Bump cache version before warming}';

    protected $description = 'Warm cached contract list calculations for common consumption presets';

    public function handle(ContractListCacheService $contractListCache): int
    {
        if ($this->option('refresh')) {
            $version = $contractListCache->bumpVersion();
            $this->info("Contract list cache version bumped to {$version}.");
        }

        $this->info('Warming contract list preset caches...');
        $contractListCache->warmPresetCaches();
        $this->info('Contract list preset caches warmed.');

        return self::SUCCESS;
    }
}
