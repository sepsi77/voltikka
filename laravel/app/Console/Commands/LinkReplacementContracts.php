<?php

namespace App\Console\Commands;

use App\Services\ContractReplacement\ContractReplacementLinker;
use Illuminate\Console\Command;

class LinkReplacementContracts extends Command
{
    protected $signature = 'contracts:link-replacements';

    protected $description = 'Persist high-confidence replacement links for inactive contracts';

    public function handle(ContractReplacementLinker $linker): int
    {
        $stats = $linker->linkHighConfidenceMatches();

        $this->table(['Metric', 'Count'], [
            ['linked', $stats['linked']],
            ['skipped_existing', $stats['skipped_existing']],
            ['skipped_no_match', $stats['skipped_no_match']],
            ['skipped_not_high', $stats['skipped_not_high']],
        ]);

        return self::SUCCESS;
    }
}
