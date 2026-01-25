<?php

namespace App\Console\Commands;

use App\Models\Postcode;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class ImportPostcodeCoordinates extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'postcodes:import-coordinates
                            {--source= : Path to postcodes.json source file}
                            {--create-missing : Create postcodes that don\'t exist in database}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Import latitude/longitude coordinates for postcodes from JSON file';

    /**
     * Default source file path.
     */
    private const DEFAULT_SOURCE = '/Users/seppo/code/likaiset-sivut/source-data/postcodes.json';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $sourcePath = $this->option('source') ?? self::DEFAULT_SOURCE;

        if (!File::exists($sourcePath)) {
            $this->error("Source file not found: {$sourcePath}");
            return Command::FAILURE;
        }

        $this->info("Importing postcode coordinates from: {$sourcePath}");

        $json = File::get($sourcePath);
        $postcodes = json_decode($json, true);

        if (!is_array($postcodes)) {
            $this->error('Failed to parse JSON file');
            return Command::FAILURE;
        }

        $updated = 0;
        $created = 0;
        $notFound = 0;
        $createMissing = $this->option('create-missing');
        $total = count($postcodes);

        $progressBar = $this->output->createProgressBar($total);
        $progressBar->start();

        foreach ($postcodes as $postcodeData) {
            $code = $postcodeData['postcode'] ?? null;
            $latitude = $postcodeData['latitude'] ?? null;
            $longitude = $postcodeData['longitude'] ?? null;

            if (!$code) {
                $progressBar->advance();
                continue;
            }

            $postcode = Postcode::find($code);
            if ($postcode) {
                if ($latitude !== null && $longitude !== null) {
                    $postcode->update([
                        'latitude' => $latitude,
                        'longitude' => $longitude,
                    ]);
                }
                $updated++;
            } elseif ($createMissing) {
                Postcode::create([
                    'postcode' => $code,
                    'postcode_fi_name' => $postcodeData['name_fi'] ?? null,
                    'postcode_fi_name_slug' => isset($postcodeData['name_fi']) ? Postcode::generateSlug($postcodeData['name_fi']) : null,
                    'postcode_sv_name' => $postcodeData['name_sv'] ?? null,
                    'postcode_sv_name_slug' => isset($postcodeData['name_sv']) ? Postcode::generateSlug($postcodeData['name_sv']) : null,
                    'municipal_code' => $postcodeData['municipality_code'] ?? null,
                    'municipal_name_fi' => $postcodeData['municipality_name'] ?? null,
                    'municipal_name_fi_slug' => isset($postcodeData['municipality_name']) ? Postcode::generateSlug($postcodeData['municipality_name']) : null,
                    'latitude' => $latitude,
                    'longitude' => $longitude,
                ]);
                $created++;
            } else {
                $notFound++;
            }

            $progressBar->advance();
        }

        $progressBar->finish();
        $this->newLine();

        $this->info("Updated {$updated} postcodes with coordinates.");
        if ($created > 0) {
            $this->info("Created {$created} new postcodes.");
        }
        if ($notFound > 0) {
            $this->warn("{$notFound} postcodes from source file not found in database. Use --create-missing to create them.");
        }

        return Command::SUCCESS;
    }
}
