<?php

namespace App\Console\Commands;

use App\Services\ContractImport\ContractImportCompletion;
use App\Support\DataFetchFailureReporter;
use Illuminate\Console\Command;
use Throwable;

class CompleteContractImport extends Command
{
    protected $signature = 'contracts:complete-import {--dry-run : Inspect completion without any writes}';

    protected $description = 'Complete a proven pending full import for the current Helsinki date';

    public function handle(ContractImportCompletion $completion): int
    {
        try {
            $result = $completion->tick((bool) $this->option('dry-run'));
            $this->info('Contract import completion: '.$result);

            return str_starts_with($result, 'failed:') ? self::FAILURE : self::SUCCESS;
        } catch (Throwable $exception) {
            if (! $this->option('dry-run')) {
                $reporter = new DataFetchFailureReporter('contracts');
                $reporter->fail('completion_checkpoint', $exception);
                $reporter->report(true);
            }
            $this->error('Contract import completion could not be checked.');

            return self::FAILURE;
        }
    }
}
