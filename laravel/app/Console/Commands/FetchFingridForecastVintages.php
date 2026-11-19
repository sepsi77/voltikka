<?php

namespace App\Console\Commands;

use App\Console\Concerns\StagedImport;
use App\Services\Fingrid\FingridForecastVintageImporter;
use App\Services\Fingrid\FingridOpenDataService;
use App\Services\ImportPayload\ImportPayloadInvalid;
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
 * `first_hour`, the horizon `hours` and the stored `variables` name per dataset in the header
 * facts; the store step inserts each vintage with the fetch time as its `fetched_at` (the vintage
 * time; `issued_at` stays null), keyed by the payload hash. A payload without a dataset's
 * variable or horizon is refused (`incomplete`), never stored under the current config. A replay
 * needs no configured dataset, and its `--dataset` IDs compare normalized (`0245` = `245`). Not
 * date-bound (S9). Read-only towards Fingrid.
 */
class FetchFingridForecastVintages extends Command
{
    use StagedImport {
        stagedDataOptions as private allDataOptions;
    }

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
        $requested = $this->requestedDatasetIds();
        // Only a new fetch needs a configured dataset. A replay stores from the facts recorded at
        // fetch time (hours, variable), so it still works after a dataset left the config.
        $unknown = $this->option('from-payload') === null ? array_diff($requested, array_keys($configured)) : [];

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

    /** The payload identity holds the dataset IDs as fetched: `0245` and `245` are one dataset. */
    protected function stagedDataOptions(): array
    {
        return [...$this->allDataOptions(), 'dataset' => $this->requestedDatasetIds()];
    }

    /**
     * Normalized, once each and sorted: a repeated ID would merge its pages into one vintage.
     *
     * @return list<int>
     */
    private function requestedDatasetIds(): array
    {
        $ids = array_values(array_unique(array_map('intval', (array) $this->option('dataset'))));
        sort($ids);

        return $ids;
    }

    /** @param  list<int>  $datasetIds */
    private function fetch(ImportPayloadWriter $payload, array $datasetIds): void
    {
        $this->client->assertConfigured();
        $firstHour = $payload->fetchedAt()->startOfHour()->addHour();
        $hours = [];
        $variables = [];
        foreach ($datasetIds as $datasetId) {
            $hours[(string) $datasetId] = (int) config("fingrid.forecast_vintages.{$datasetId}.horizon_hours");
            $variables[(string) $datasetId] = (string) config("fingrid.forecast_vintages.{$datasetId}.variable");
        }
        $payload->setFact('first_hour', $firstHour->format('Y-m-d\TH:i:s\Z'));
        $payload->setFact('hours', $hours);
        // The values key of the stored vintage (S5): a later config rename never relabels a replay.
        $payload->setFact('variables', $variables);

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
     * left out; one without a complete hour fails as before. Either makes the run exit 1. A
     * dataset with rows but no recorded variable refuses the whole payload before any write.
     */
    private function store(ImportPayloadReader $payload): ImportStoreResult
    {
        $fetchedAt = $payload->fetchedAt();
        $firstHour = CarbonImmutable::parse((string) $payload->fact('first_hour'), 'UTC');
        $hours = (array) $payload->fact('hours', []);
        $variables = (array) $payload->fact('variables', []);
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

        foreach (array_keys($rows) as $datasetId) {
            $variable = $variables[(string) $datasetId] ?? null;
            $horizon = $hours[(string) $datasetId] ?? null;
            if (! is_string($variable) || $variable === '' || ! is_int($horizon) || $horizon < 1) {
                // A payload without the recorded variable (written before it was a fact) or horizon
                // is not stored: the store never falls back to the current config.
                throw ImportPayloadInvalid::because('incomplete');
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
                $datasetId, $variables[(string) $datasetId], $series, $firstHour, $fetchedAt,
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
