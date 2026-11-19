<?php

namespace App\Console\Commands;

use App\Services\ImportPayload\ImportPayloadCleanup;
use Illuminate\Console\Command;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

class CleanupImportPayloads extends Command
{
    protected $signature = 'import-payloads:cleanup
        {--apply : Delete the files; otherwise report only}';

    protected $description = 'Delete import payload files older than 14 days and temporary files older than 24 hours';

    public function handle(ImportPayloadCleanup $cleanup): int
    {
        $apply = (bool) $this->option('apply');
        try {
            $result = $cleanup->run($apply);
        } catch (Throwable $e) {
            $this->error('Import payload cleanup failed.');
            $this->result(['status' => 'failed', 'error' => 'cleanup_failed', 'exception_class' => $e::class]);

            return self::FAILURE;
        }

        $counts = array_map('count', $result);
        $this->info(sprintf(
            '%s: payload_files=%d tmp_files=%d directories=%d',
            $apply ? 'Apply' : 'Dry run', $counts['payload_files'], $counts['tmp_files'], $counts['directories'],
        ));
        $this->result(['status' => $apply ? 'deleted' : 'dry_run', ...$counts]);

        return self::SUCCESS;
    }

    /** @param  array<string, mixed>  $line */
    private function result(array $line): void
    {
        $this->output->writeln(json_encode($line, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), OutputInterface::OUTPUT_RAW | OutputInterface::VERBOSITY_QUIET);
    }
}
