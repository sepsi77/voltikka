<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class BackfillEexFutures extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'futures:backfill-eex
        {--area=* : Limit to one or more configured EEX areas, for example FI or SE3.}
        {--tenor=* : Limit to one or more maturity types: month, quarter, year. Defaults to all configured tenors.}
        {--history-window-days= : EEX public endpoint history window. Defaults to config value.}
        {--dry-run : Discover and report data without writing to the database.}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Fetch all EEX futures history available from the public API (about the last 45 days)';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('Backfilling all EEX futures history available from the public API.');
        $this->warn('EEX public chart history is limited to roughly 45 days; older history cannot be backfilled from this endpoint.');

        $options = [];

        if (!empty($this->option('area'))) {
            $options['--area'] = (array) $this->option('area');
        }

        if (!empty($this->option('tenor'))) {
            $options['--tenor'] = (array) $this->option('tenor');
        }

        if ($this->option('history-window-days') !== null) {
            $options['--history-window-days'] = $this->option('history-window-days');
        }

        if ($this->option('dry-run')) {
            $options['--dry-run'] = true;
        }

        return $this->call('futures:fetch-eex', $options);
    }
}
