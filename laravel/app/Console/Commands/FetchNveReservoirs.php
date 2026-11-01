<?php

namespace App\Console\Commands;

use App\Services\Nve\NveReservoirImporter;
use App\Services\Nve\NveReservoirService;
use Carbon\Carbon;
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
 */
class FetchNveReservoirs extends Command
{
    protected $signature = 'nve:fetch-reservoirs
                            {--from-year= : Store weekly rows from this ISO year (default: previous year)}
                            {--all : Store the full weekly history (from 1995)}';

    protected $description = 'Fetch NVE weekly reservoir filling for Norway and NO1-NO5 with the 20-year reference';

    public function __construct(
        private readonly NveReservoirService $client,
        private readonly NveReservoirImporter $importer
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $currentYear = (int) Carbon::now('UTC')->format('o');

        if ($this->option('from-year') !== null && ! ctype_digit((string) $this->option('from-year'))) {
            $this->error('Invalid --from-year. Use YYYY.');

            return Command::FAILURE;
        }

        $fromYear = match (true) {
            (bool) $this->option('all') => null,
            $this->option('from-year') !== null => (int) $this->option('from-year'),
            default => $currentYear - (int) config('nve.default_years_back', 1),
        };

        $failed = false;
        $weeks = null;

        try {
            $weeks = $this->client->fetchWeeks();
            $written = $this->importer->importWeeks($weeks, $fromYear, Carbon::now('UTC'));
            $this->info("NVE weekly filling: {$written} rows.");
        } catch (Throwable $e) {
            $failed = true;
            $this->reportFailure('weeks', $e);
        }

        try {
            $references = $this->client->fetchReferences();

            if ($weeks === null) {
                $this->error('NVE reference: not stored, because the weekly history is needed to prove its years.');
                $failed = true;
            } elseif (($period = $this->importer->resolveReferencePeriod($weeks, $references, $currentYear)) === null) {
                logger()->error('NVE reservoir reference period could not be proved', ['current_year' => $currentYear]);
                $this->error('NVE reference: the twenty-year period could not be proved; nothing stored.');
                $failed = true;
            } else {
                $written = $this->importer->importReferences($references, $period[0], $period[1], Carbon::now('UTC'));
                $this->info("NVE reference {$period[0]}-{$period[1]}: {$written} rows.");
            }
        } catch (Throwable $e) {
            $failed = true;
            $this->reportFailure('references', $e);
        }

        return $failed ? Command::FAILURE : Command::SUCCESS;
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
