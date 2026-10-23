<?php

namespace App\Console\Commands;

use App\Services\ContractStatistics\HistoricalSnapshotRepair;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class RepairContractStatisticsDate extends Command
{
    protected $signature = 'contracts:repair-statistics-date
        {--date= : One past Helsinki date, YYYY-MM-DD}
        {--compare-existing : Also rebuild existing snapshots from dated evidence and report differences}
        {--apply : Write the snapshots and rebuild the date; default is a dry run that rolls back}';

    protected $description = 'Add the canonical snapshots that the current writer missed on one past date, from dated evidence';

    public function handle(HistoricalSnapshotRepair $repair): int
    {
        $value = (string) $this->option('date');
        $date = CarbonImmutable::createFromFormat('!Y-m-d', $value, 'Europe/Helsinki');
        if ($date === false || $date->format('Y-m-d') !== $value) {
            $this->error('The --date value must be a valid date in YYYY-MM-DD format.');

            return self::FAILURE;
        }

        $plan = $repair->plan($date, (bool) $this->option('compare-existing'));
        $this->info(sprintf('%s: %d snapshots to add.', $value, count($plan['added'])));
        foreach (collect($plan['added'])->countBy('segment_key')->sortKeys() as $segment => $count) {
            $this->line("  {$segment}: {$count}");
        }
        foreach (collect($plan['skipped'])->countBy()->sortKeys() as $reason => $count) {
            $this->line("  skipped {$reason}: {$count}");
        }
        if ($this->option('compare-existing')) {
            $differences = $plan['compared']['differences'];
            $this->info(sprintf('Existing snapshots rebuilt from dated evidence: %d match, %d differ.', $plan['compared']['matched'], count($differences)));
            foreach (array_slice($differences, 0, 20, true) as $contractId => $fields) {
                $this->line('  '.$contractId.': '.collect($fields)->map(fn (array $pair, string $field): string => $field.' '.json_encode($pair[0]).' -> '.json_encode($pair[1]))->implode('; '));
            }
        }
        if ($plan['added'] === []) {
            return self::SUCCESS;
        }

        $apply = (bool) $this->option('apply');
        $result = $repair->apply($date, $plan['added'], dryRun: ! $apply);
        $this->info(sprintf(
            '%s: %d snapshots, %d daily statistics rows, %d v3 annual rows, %d v3 aggregates.',
            $apply ? 'Applied' : 'Dry run (rolled back)',
            $result['snapshots'],
            $result['daily_statistics'],
            $result['annual_rows'],
            $result['aggregates'],
        ));
        foreach ($result['medians'] as $key => [$before, $after]) {
            if ($before !== $after) {
                $this->line(sprintf('  %s median: %s -> %s', $key, json_encode($before), json_encode($after)));
            }
        }

        return self::SUCCESS;
    }
}
