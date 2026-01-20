<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Services\CompanyLogoService;
use Illuminate\Console\Command;

class DownloadLogos extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'logos:download
                            {--force : Re-download all logos, even if local copy exists}';

    /**
     * The console command description.
     */
    protected $description = 'Download company logos from external URLs and store them locally';

    private CompanyLogoService $logoService;

    public function __construct(CompanyLogoService $logoService)
    {
        parent::__construct();
        $this->logoService = $logoService;
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $force = $this->option('force');

        // Build query based on --force option
        $query = Company::whereNotNull('logo_url')
            ->where('logo_url', '!=', '');

        if (!$force) {
            $query->whereNull('local_logo_path');
        }

        $companies = $query->get();

        if ($companies->isEmpty()) {
            $this->info('No company logos to download.');
            return Command::SUCCESS;
        }

        $this->info("Downloading logos for {$companies->count()} companies...");

        $successCount = 0;
        $failCount = 0;

        $progressBar = $this->output->createProgressBar($companies->count());
        $progressBar->start();

        foreach ($companies as $company) {
            $localPath = $this->logoService->downloadAndStore($company);

            if ($localPath) {
                $company->local_logo_path = $localPath;
                $company->save();
                $successCount++;
            } else {
                $failCount++;
            }

            $progressBar->advance();
        }

        $progressBar->finish();
        $this->newLine(2);

        $this->info("Download complete!");
        $this->table(
            ['Status', 'Count'],
            [
                ['Success', $successCount],
                ['Failed', $failCount],
                ['Total', $companies->count()],
            ]
        );

        return Command::SUCCESS;
    }
}
