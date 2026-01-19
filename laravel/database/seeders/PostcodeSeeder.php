<?php

namespace Database\Seeders;

use App\Models\Postcode;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Seeder for Finnish postcodes from Posti.fi.
 *
 * Data source: https://www.posti.fi/webpcode/unzip/
 * File format: PCF_*.dat (fixed-width format, Latin-1 encoded)
 *
 * Ported from: legacy/python/shared/voltikka/utils/postcodes.py
 */
class PostcodeSeeder extends Seeder
{
    /**
     * The base URL for Posti.fi postcode data.
     */
    private const POSTI_URL = 'https://www.posti.fi/webpcode/unzip/';

    /**
     * Fixed-width field positions (0-indexed start, length).
     * Format: PONOT + fields...
     */
    private const FIELDS = [
        // 'marker' is PONOT (5 chars)
        'date' => [5, 8],
        'postcode' => [13, 5],
        'postcode_fi_name' => [18, 30],
        'postcode_sv_name' => [48, 30],
        'postcode_abbr_fi' => [78, 12],
        'postcode_abbr_sv' => [90, 12],
        'valid_from' => [102, 8],
        'type_code' => [110, 1],
        'ad_area_code' => [111, 5],
        'ad_area_fi' => [116, 30],
        'ad_area_sv' => [146, 30],
        'municipal_code' => [176, 3],
        'municipal_name_fi' => [179, 20],
        'municipal_name_sv' => [199, 20],
        'municipal_language_ratio_code' => [219, 1],
    ];

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $this->command->info('Fetching postcode data from Posti.fi...');

        try {
            $data = $this->fetchPostcodesData();
            $postcodes = $this->parsePostcodeRecords($data);

            $this->command->info(sprintf('Parsed %d postcode records', count($postcodes)));

            $this->insertPostcodes($postcodes);

            $this->command->info('Postcodes seeded successfully.');
        } catch (\Exception $e) {
            $this->command->error('Failed to seed postcodes: ' . $e->getMessage());
            Log::error('PostcodeSeeder failed: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Fetch postcode data file from Posti.fi.
     */
    private function fetchPostcodesData(): string
    {
        // First, fetch the index page to find the PCF file URL
        $response = Http::timeout(30)->get(self::POSTI_URL);

        if (!$response->successful()) {
            throw new \RuntimeException('Failed to fetch Posti.fi index page');
        }

        $html = $response->body();

        // Find the PCF file URL using regex
        if (!preg_match('/https:\/\/www\.posti\.fi\/webpcode\/unzip\/PCF_[0-9]+\.dat/', $html, $matches)) {
            throw new \RuntimeException('Could not find PCF file URL in Posti.fi page');
        }

        $pcfUrl = $matches[0];
        $this->command->info("Downloading: {$pcfUrl}");

        // Fetch the actual postcode file (Latin-1 encoded)
        $response = Http::timeout(60)->get($pcfUrl);

        if (!$response->successful()) {
            throw new \RuntimeException('Failed to fetch postcode data file');
        }

        // The file is Latin-1 encoded, convert to UTF-8
        $content = $response->body();
        return mb_convert_encoding($content, 'UTF-8', 'ISO-8859-1');
    }

    /**
     * Parse postcode records from the fixed-width format file.
     *
     * @param string $data Raw file content
     * @return array<array<string, string|null>>
     */
    private function parsePostcodeRecords(string $data): array
    {
        $postcodes = [];
        $lines = explode("\n", $data);

        foreach ($lines as $line) {
            // Check if line starts with PONOT marker
            if (!str_starts_with($line, 'PONOT')) {
                continue;
            }

            $record = [];

            foreach (self::FIELDS as $field => $position) {
                [$start, $length] = $position;
                $value = mb_substr($line, $start, $length);
                $value = trim($value);

                // Store empty strings as null
                $record[$field] = $value !== '' ? $value : null;
            }

            // Skip if no postcode
            if (empty($record['postcode'])) {
                continue;
            }

            // Generate slugs for name fields
            $record['postcode_fi_name_slug'] = $record['postcode_fi_name']
                ? Postcode::generateSlug($record['postcode_fi_name'])
                : null;
            $record['postcode_sv_name_slug'] = $record['postcode_sv_name']
                ? Postcode::generateSlug($record['postcode_sv_name'])
                : null;
            $record['ad_area_fi_slug'] = $record['ad_area_fi']
                ? Postcode::generateSlug($record['ad_area_fi'])
                : null;
            $record['ad_area_sv_slug'] = $record['ad_area_sv']
                ? Postcode::generateSlug($record['ad_area_sv'])
                : null;
            $record['municipal_name_fi_slug'] = $record['municipal_name_fi']
                ? Postcode::generateSlug($record['municipal_name_fi'])
                : null;
            $record['municipal_name_sv_slug'] = $record['municipal_name_sv']
                ? Postcode::generateSlug($record['municipal_name_sv'])
                : null;

            // Remove temporary fields not in the database
            unset($record['date'], $record['valid_from']);

            $postcodes[] = $record;
        }

        return $postcodes;
    }

    /**
     * Insert postcodes into the database in batches.
     *
     * @param array<array<string, string|null>> $postcodes
     */
    private function insertPostcodes(array $postcodes): void
    {
        $batchSize = 500;
        $total = count($postcodes);
        $batches = array_chunk($postcodes, $batchSize);

        $this->command->info("Inserting {$total} postcodes in " . count($batches) . " batches...");

        $progressBar = $this->command->getOutput()->createProgressBar(count($batches));
        $progressBar->start();

        foreach ($batches as $batch) {
            Postcode::upsert(
                $batch,
                ['postcode'], // Unique key for upsert
                array_keys($batch[0]) // Columns to update on conflict
            );

            $progressBar->advance();
        }

        $progressBar->finish();
        $this->command->newLine();
    }
}
