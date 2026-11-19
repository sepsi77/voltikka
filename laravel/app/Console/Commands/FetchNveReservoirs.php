<?php

namespace App\Console\Commands;

use App\Console\Concerns\StagedImport;
use App\Services\ImportPayload\ImportPayloadReader;
use App\Services\ImportPayload\ImportPayloadWriter;
use App\Services\ImportPayload\ImportStoreResult;
use App\Services\Nve\NveReservoirImporter;
use App\Services\Nve\NveReservoirService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Collect NVE weekly reservoir filling (Norway total and NO1-NO5) and the min/median/max of the
 * same ISO week over the last twenty years, for market reviews.
 *
 * NVE serves the whole history in one request, so every run reads it all. By default only rows
 * from the previous ISO year onwards are upserted; --from-year or --all store more. The
 * reference is stored only when its year period is proved from that history (see
 * NveReservoirImporter). The two units are independent where possible; a failed unit makes the
 * run exit 1. Read-only towards NVE; upserts locally.
 *
 * Staged import (StagedImport): the fetch step records both responses and fixes the ISO
 * `current_year` and `from_year` in the header (S5); the store step parses both and writes
 * weeks and reference in one transaction. Not date-bound (S9).
 */
class FetchNveReservoirs extends Command
{
    use StagedImport;

    protected $signature = 'nve:fetch-reservoirs
                            {--from-year= : Store weekly rows from this ISO year (default: previous year)}
                            {--all : Store the full weekly history (from 1995)}';

    protected $description = 'Fetch NVE weekly reservoir filling for Norway and NO1-NO5 with the 20-year reference';

    private const UNITS = ['weeks' => NveReservoirService::WEEKS, 'references' => NveReservoirService::REFERENCES];

    public function __construct(
        private readonly NveReservoirService $client,
        private readonly NveReservoirImporter $importer
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        if ($this->option('from-year') !== null && ! ctype_digit((string) $this->option('from-year'))) {
            $this->error('Invalid --from-year. Use YYYY.');

            return Command::FAILURE;
        }

        return $this->runStaged(
            fn (ImportPayloadWriter $payload) => $this->fetch($payload),
            fn (ImportPayloadReader $payload): ImportStoreResult => $this->store($payload),
        );
    }

    protected function stagedImportSource(): string
    {
        return 'nve';
    }

    private function fetch(ImportPayloadWriter $payload): void
    {
        $currentYear = (int) $payload->fetchedAt()->format('o');
        $payload->setFact('current_year', $currentYear);
        $payload->setFact('from_year', match (true) {
            (bool) $this->option('all') => null,
            $this->option('from-year') !== null => (int) $this->option('from-year'),
            default => $currentYear - (int) config('nve.default_years_back', 1),
        });

        // Both units are always requested: a failed history does not stop the reference request.
        foreach (self::UNITS as $dataset => $endpoint) {
            $unit = ['dataset' => $dataset];

            try {
                $response = $this->client->request($endpoint);
            } catch (Throwable $e) {
                $payload->recordFailure($unit, 'GET', $this->client->url($endpoint), [], $e);
                $this->reportFailure($dataset, $e);

                continue;
            }

            try {
                NveReservoirService::parse($endpoint, $response->body());
                $payload->record($unit, $response);
            } catch (Throwable $e) {
                $payload->record($unit, $response, failed: true);
                $this->reportFailure($dataset, $e);
            }
        }
    }

    /** Runs inside the StagedImport store transaction. */
    private function store(ImportPayloadReader $payload): ImportStoreResult
    {
        $failedUnits = $payload->end()['failed_units'];
        $currentYear = (int) $payload->fact('current_year');
        $parsed = [];

        foreach ($payload->requests() as $request) {
            $dataset = (string) $request['unit']['dataset'];
            if (! in_array($request['unit'], $failedUnits, true)) {
                $parsed[$dataset] = NveReservoirService::parse(self::UNITS[$dataset], (string) ImportPayloadReader::body($request));
            }
        }

        $weeks = $parsed['weeks'] ?? null;
        $references = $parsed['references'] ?? null;
        $counts = ['weeks' => 0, 'references' => 0];
        $messages = [];
        $unproved = false;

        if ($weeks !== null) {
            $counts['weeks'] = $this->importer->importWeeks($weeks, $payload->fact('from_year'), $payload->fetchedAt());
            $messages[] = "NVE weekly filling: {$counts['weeks']} rows.";
        }

        if ($references !== null) {
            if ($weeks === null) {
                $this->error('NVE reference: not stored, because the weekly history is needed to prove its years.');
            } elseif (($period = $this->importer->resolveReferencePeriod($weeks, $references, $currentYear)) === null) {
                logger()->error('NVE reservoir reference period could not be proved', ['current_year' => $currentYear]);
                $this->error('NVE reference: the twenty-year period could not be proved; nothing stored.');
                $unproved = true;
            } else {
                $counts['references'] = $this->importer->importReferences($references, $period[0], $period[1], $payload->fetchedAt());
                $messages[] = "NVE reference {$period[0]}-{$period[1]}: {$counts['references']} rows.";
            }
        }

        [$error, $step] = match (true) {
            $failedUnits !== [] => ['fetch_incomplete', 'fetch'],
            $unproved => ['reference_period_unproved', 'store'],
            default => [null, 'store'],
        };

        return new ImportStoreResult($counts, [function () use ($messages): void {
            foreach ($messages as $message) {
                $this->info($message);
            }
        }], $error, $step);
    }

    private function reportFailure(string $unit, Throwable $e): void
    {
        // The exception message can contain the request URL; log only the class.
        logger()->error('NVE reservoir fetch failed', [
            'unit' => $unit,
            'exception_class' => $e::class,
        ]);
        $this->error("NVE {$unit}: fetch failed (".$e::class.').');
    }
}
