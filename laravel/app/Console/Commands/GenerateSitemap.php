<?php

namespace App\Console\Commands;

use App\Services\SitemapService;
use Illuminate\Console\Command;

class GenerateSitemap extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'sitemap:generate';

    /**
     * The console command description.
     */
    protected $description = 'Generate XML sitemap for SEO pages';

    /**
     * Execute the console command.
     */
    public function handle(SitemapService $sitemapService): int
    {
        $this->info('Generating sitemap...');

        $path = $sitemapService->saveToFile();
        $count = $sitemapService->getUrlCount();

        $this->info("Sitemap generated with {$count} URLs.");
        $this->info("Saved to: {$path}");

        return Command::SUCCESS;
    }
}
