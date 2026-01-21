<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\Laravel\Facades\Image;

class OptimizeLogos extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'logos:optimize
                            {--dry-run : Show what would be optimized without making changes}
                            {--remove-originals : Delete original files after optimization}';

    /**
     * The console command description.
     */
    protected $description = 'Optimize company logos by resizing to max 200px width and converting to WebP';

    /**
     * Maximum width for optimized logos.
     */
    private const MAX_WIDTH = 200;

    /**
     * WebP quality (0-100).
     */
    private const WEBP_QUALITY = 80;

    /**
     * Supported image extensions.
     */
    private const SUPPORTED_EXTENSIONS = ['png', 'jpg', 'jpeg', 'gif'];

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $dryRun = $this->option('dry-run');
        $removeOriginals = $this->option('remove-originals');

        $disk = Storage::disk('public');
        $logosPath = 'logos';

        if (!$disk->exists($logosPath)) {
            $this->info('No images found in logos directory.');

            return Command::SUCCESS;
        }

        $files = collect($disk->files($logosPath))
            ->filter(function ($file) {
                $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));

                return in_array($extension, self::SUPPORTED_EXTENSIONS);
            });

        if ($files->isEmpty()) {
            $this->info('No images found in logos directory.');

            return Command::SUCCESS;
        }

        $this->info("Optimizing logos: {$files->count()} images found");

        if ($dryRun) {
            $this->warn('(dry run - no changes will be made)');
        }

        $successCount = 0;
        $failCount = 0;

        $progressBar = $this->output->createProgressBar($files->count());
        $progressBar->start();

        foreach ($files as $file) {
            $filename = pathinfo($file, PATHINFO_FILENAME);

            if ($dryRun) {
                $this->newLine();
                $this->line("  Would optimize: {$filename}");
                $successCount++;
            } else {
                try {
                    $this->optimizeImage($disk, $file, $removeOriginals);
                    $successCount++;
                } catch (\Exception $e) {
                    $this->newLine();
                    $this->error("  Failed to optimize {$filename}: {$e->getMessage()}");
                    $failCount++;
                }
            }

            $progressBar->advance();
        }

        $progressBar->finish();
        $this->newLine(2);

        $this->info('Optimization complete!');
        $this->table(
            ['Status', 'Count'],
            [
                ['Success', $successCount],
                ['Failed', $failCount],
                ['Total', $files->count()],
            ]
        );

        return Command::SUCCESS;
    }

    /**
     * Optimize a single image file.
     */
    private function optimizeImage($disk, string $filePath, bool $removeOriginal): void
    {
        $content = $disk->get($filePath);
        $image = Image::read($content);

        $currentWidth = $image->width();

        if ($currentWidth > self::MAX_WIDTH) {
            $image->scaleDown(width: self::MAX_WIDTH);
        }

        $webpContent = $image->toWebp(self::WEBP_QUALITY)->toString();

        $directory = pathinfo($filePath, PATHINFO_DIRNAME);
        $filename = pathinfo($filePath, PATHINFO_FILENAME);
        $webpPath = "{$directory}/{$filename}.webp";

        $disk->put($webpPath, $webpContent);

        if ($removeOriginal) {
            $disk->delete($filePath);
        }
    }
}
