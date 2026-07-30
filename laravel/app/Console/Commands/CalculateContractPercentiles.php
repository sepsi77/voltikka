<?php

namespace App\Console\Commands;

use App\Services\ContractStatistics\ContractPercentileService;
use Illuminate\Console\Command;

class CalculateContractPercentiles extends Command
{
    protected $signature = 'contracts:calculate-percentiles';

    protected $description = 'Calculate P15/P85 percentiles for contract pricing components';

    public function handle(ContractPercentileService $percentiles): int
    {
        $this->info('Calculating contract pricing percentiles...');
        $result = $percentiles->calculate();

        if ($result->activeContractCount === 0) {
            $this->warn('No active contracts found.');

            return self::SUCCESS;
        }

        foreach ($result->skipped as $component => $count) {
            $this->warn("Skipping {$component}: only {$count} values (need >= 10)");
        }

        foreach ($result->calculated as $row) {
            $this->info(sprintf(
                '%s: n=%d, P15=%.4f, P85=%.4f',
                $row['component'],
                $row['count'],
                $row['p15'],
                $row['p85'],
            ));
        }

        $this->info('Done.');

        return self::SUCCESS;
    }
}
