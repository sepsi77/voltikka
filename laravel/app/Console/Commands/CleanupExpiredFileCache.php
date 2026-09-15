<?php

namespace App\Console\Commands;

use App\Services\Caching\ExpiredFileCacheCleanup;
use Illuminate\Cache\FileStore;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Throwable;

class CleanupExpiredFileCache extends Command
{
    protected $signature = 'cache:reclaim-expired-files
        {--apply : Truncate eligible files; otherwise report only}
        {--scheduled : Scan the current minute hash shard instead of all shards}
        {--max-seconds=30 : Stop and report failure after this runtime budget (1-3600)}';

    protected $description = 'Reclaim expired file-cache array/object payloads without deleting their inodes';

    public function handle(ExpiredFileCacheCleanup $cleanup): int
    {
        $seconds = filter_var($this->option('max-seconds'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 3600]]);
        if ($seconds === false) {
            $this->error('max-seconds must be an integer from 1 to 3600.');

            return self::INVALID;
        }
        try {
            $store = Cache::store()->getStore();
            if (! $store instanceof FileStore) {
                $this->info('No action: the default cache store is not FileStore.');

                return self::SUCCESS;
            }
            $result = $cleanup->scan($store, (bool) $this->option('apply'), (bool) $this->option('scheduled'), $seconds);
        } catch (Throwable) {
            $this->error('File-cache reclamation failed. No cache paths or payloads are reported.');

            return self::FAILURE;
        }

        $mode = $this->option('apply') ? 'Apply' : 'Dry run';
        $this->info("$mode: scanned={$result['scanned']} eligible={$result['eligible']} reclaimed={$result['reclaimed']} skipped={$result['skipped']} eligible_bytes={$result['bytes']} reclaimed_bytes={$result['reclaimed_bytes']} errors={$result['errors']}");
        if ($result['incomplete']) {
            $this->error('Runtime limit reached; scan incomplete. Use a larger max-seconds budget for manual catch-up.');
        }

        return $result['errors'] === 0 && ! $result['incomplete'] ? self::SUCCESS : self::FAILURE;
    }
}
