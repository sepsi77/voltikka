<?php

namespace App\Console\Commands;

use App\Console\Concerns\StagedImport;
use App\Services\Fingrid\FingridForecastVintageImporter;
use App\Services\Fingrid\FingridOpenDataService;
use App\Services\ImportPayload\ImportPayloadReader;
use App\Services\ImportPayload\ImportPayloadWriter;
use App\Services\ImportPayload\ImportStoreResult;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Keep vintages of rolling Fingrid forecasts (config fingrid.forecast_vintages, e.g. dataset
 * 245 wind power) in hourly_forecast_runs. One request per dataset: the hours from the next
 * full UTC hour to the end of the forecast horizon. The current hour is not stored, because
 * some of its quarters are already in the past.
 *
 * Staged import (StagedImport, tasks/staged-api-imports/design.md): the fetch step fixes
 * `first_hour` and the horizon `hours` in the header facts; the store step inserts each vintage
 * with the fetch time as its `fetched_at` (the vintage time; `issued_at` stays null), keyed by the
 * payload hash. Not date-bound (S9). Read-only towards Fingrid.
 */
class FetchFingridForecastVintages extends Command
{
    use StagedImport;

    protected $signature = 'fingrid:fetch-forecast-vintages
                            {--dataset=* : Only these dataset IDs (default: all configured)}';

    protected $description = 'Store the current vintage of rolling Fingrid forecasts for own spot price models';

    public function __construct(
        private readonly FingridOpenDataService $client,
        private readonly FingridForecastVintageImporter $importer
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $configured = (array) config('fingrid.forecast_vintages', []);
        $requested = array_map('intval', (array) $this->option('dataset'));
        $unknown = array_diff($requested, array_keys($configured));

        if ($unknown !== []) {
            $this->error('Unknown forecast dataset ID(s): '.implode(', ', $unknown));

            return $this->stagedFailure('fetch', 'invalid_options');
        }
        $datasetIds = $requested !== [] ? $requested : array_keys($configured);

        return $this->runStaged(
            fn (ImportPayloadWriter $payload) => $this->fetch($payload, $datasetIds),
            fn (ImportPayloadReader $payload): ImportStoreResult => $this->store($payload),
        );
    }

    protected function stagedImportSource(): string
    {
        return 'fingrid';
    }

    /** @param  list<int>  $datasetIds */
    private function fetch(ImportPayloadWriter $payload, array $datasetIds): void
    {
        $this->client->assertConfigured();
        $firstHour = $payload->fetchedAt()->startOfHour()->addHour();
        $hours = [];
        foreach ($datasetIds as $datasetId) {
            $hours[(string) $datasetId] = (int) config("fingrid.forecast_vintages.{$datasetId}.horizon_hours");
        }
        $payload->setFact('first_hour', $firstHour->format('Y-m-d\TH:i:s\Z'));
        $payload->setFact('hours', $hours);

        foreach ($datasetIds as $datasetId) {
            $failure = $this->client->recordPages(
                $payload, ['dataset' => $datasetId], $datasetId, $firstHour, $firstHour->addHours($hours[(string) $datasetId]),
            );
            if ($failure !== null) {
                $this->datasetFailed($datasetId, ['exception_class' => $failure::class]);
            }
        }
    }

    /**
     * Store step: one vintage per dataset from its recorded pages. A dataset whose fetch failed is
     * left out; one without a complete hour fails as before. Either makes the run exit 1.
     */
    private function store(ImportPayloadReader $payload): ImportStoreResult
    {
        $fetchedAt = $payload->fetchedAt();
        $firstHour = CarbonImmutable::parse((string) $payload->fact('first_hour'), 'UTC');
        $hours = (array) $payload->fact('hours', []);
        $failed = [];
        foreach ($payload->end()['failed_units'] as $unit) {
            $failed[(int) $unit['dataset']] = true;
        }

        $rows = [];
        foreach ($payload->requests() as $request) {
            $datasetId = (int) $request['unit']['dataset'];
            if (! isset($failed[$datasetId])) {
                $rows[$datasetId] ??= [];
                array_push($rows[$datasetId], ...FingridOpenDataService::page((string) ImportPayloadReader::body($request))['rows']);
            }
        }

        $stored = 0;
        $incomplete = false;
        foreach ($rows as $datasetId => $datasetRows) {
            $series = $this->importer->series($datasetRows, $firstHour, (int) $hours[(string) $datasetId]);
            if ($series === []) {
                $incomplete = true;
                $this->datasetFailed($datasetId, ['reason' => 'no_complete_hour']);

                continue;
            }
            $new = $this->importer->store(
                $datasetId, (string) config("fingrid.forecast_vintages.{$datasetId}.variable"), $series, $firstHour, $fetchedAt,
            );
            $stored += $new ? 1 : 0;
            $this->info("Forecast dataset {$datasetId}: ".($new ? 'new vintage stored' : 'unchanged').'.');
        }

        return new ImportStoreResult(['vintages_inserted' => $stored], ...match (true) {
            ! $payload->complete() => ['error' => 'fetch_incomplete', 'errorStep' => 'fetch'],
            $incomplete => ['error' => 'no_complete_hour', 'errorStep' => 'store'],
            default => [],
        });
    }

    /** @param  array<string, string>  $facts  closed facts; the exception message can contain the request URL */
    private function datasetFailed(int $datasetId, array $facts): void
    {
        logger()->error('Fingrid forecast vintage fetch failed', ['dataset_id' => $datasetId, ...$facts]);
        $this->error("Forecast dataset {$datasetId}: fetch failed (".($facts['exception_class'] ?? $facts['reason']).').');
    }
}
