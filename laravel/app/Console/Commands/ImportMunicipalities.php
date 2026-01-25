<?php

namespace App\Console\Commands;

use App\Models\Municipality;
use App\Models\Postcode;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

class ImportMunicipalities extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'municipalities:import
                            {--source= : Path to municipalities.json source file}
                            {--skip-coordinates : Skip calculating center coordinates from postcodes}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Import municipalities with grammatical forms from JSON file';

    /**
     * Default source file path (relative to Laravel base path).
     */
    private const DEFAULT_SOURCE = 'database/data/municipalities.json';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $sourcePath = $this->option('source') ?? base_path(self::DEFAULT_SOURCE);

        if (!File::exists($sourcePath)) {
            $this->error("Source file not found: {$sourcePath}");
            return Command::FAILURE;
        }

        $this->info("Importing municipalities from: {$sourcePath}");

        $json = File::get($sourcePath);
        $municipalities = json_decode($json, true);

        if (!is_array($municipalities)) {
            $this->error('Failed to parse JSON file');
            return Command::FAILURE;
        }

        $created = 0;
        $updated = 0;
        $total = count($municipalities);

        $progressBar = $this->output->createProgressBar($total);
        $progressBar->start();

        foreach ($municipalities as $data) {
            $code = $data['code'] ?? null;
            $name = $data['name'] ?? null;

            if (!$code || !$name) {
                $progressBar->advance();
                continue;
            }

            $slug = Municipality::generateSlug($name);

            $municipalityData = [
                'code' => $code,
                'slug' => $slug,
                'name' => $name,
                'name_locative' => $data['name_locative'] ?? $name . 'ssa',
                'name_genitive' => $data['name_genitive'] ?? $name . 'n',
                'region_code' => $data['region_code'] ?? null,
                'region_name' => $data['region_name'] ?? null,
            ];

            $existing = Municipality::where('code', $code)->first();
            if ($existing) {
                $existing->update($municipalityData);
                $updated++;
            } else {
                Municipality::create($municipalityData);
                $created++;
            }

            $progressBar->advance();
        }

        $progressBar->finish();
        $this->newLine();

        $this->info("Created {$created} municipalities, updated {$updated}.");

        // Calculate center coordinates if not skipped
        if (!$this->option('skip-coordinates')) {
            $this->calculateCenterCoordinates();
        }

        return Command::SUCCESS;
    }

    /**
     * Calculate center coordinates for municipalities by averaging postcode coordinates.
     */
    private function calculateCenterCoordinates(): void
    {
        $this->info('Calculating center coordinates from postcodes...');

        $municipalities = Municipality::all();
        $progressBar = $this->output->createProgressBar($municipalities->count());
        $progressBar->start();

        $updated = 0;

        foreach ($municipalities as $municipality) {
            // Get average coordinates from postcodes with coordinates
            $coords = Postcode::where('municipal_code', $municipality->code)
                ->whereNotNull('latitude')
                ->whereNotNull('longitude')
                ->select(
                    DB::raw('AVG(latitude) as avg_lat'),
                    DB::raw('AVG(longitude) as avg_lng')
                )
                ->first();

            if ($coords && $coords->avg_lat !== null && $coords->avg_lng !== null) {
                $municipality->update([
                    'center_latitude' => round($coords->avg_lat, 7),
                    'center_longitude' => round($coords->avg_lng, 7),
                ]);
                $updated++;
            }

            $progressBar->advance();
        }

        $progressBar->finish();
        $this->newLine();

        $this->info("Updated center coordinates for {$updated} municipalities.");
    }
}
