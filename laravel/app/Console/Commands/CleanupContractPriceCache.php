<?php

namespace App\Console\Commands;

use App\Services\Caching\ContractPriceCacheLifecycle;
use Illuminate\Console\Command;
use Throwable;

class CleanupContractPriceCache extends Command
{
    protected $signature = 'contracts:cleanup-price-cache';

    protected $description = 'Delete tracked retired price-cache payloads after their in-flight grace period';

    public function handle(ContractPriceCacheLifecycle $lifecycle): int
    {
        try {
            $result = $lifecycle->cleanupRetired();
        } catch (Throwable) {
            $this->error('Price-cache cleanup failed. Retained cleanup state will be retried.');

            return self::FAILURE;
        }

        $this->info("Deleted {$result['deleted']} retired price-cache entries; {$result['failures']} generation cleanup failures.");

        return $result['failures'] === 0 ? self::SUCCESS : self::FAILURE;
    }
}
