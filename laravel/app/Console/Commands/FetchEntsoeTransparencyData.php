<?php

namespace App\Console\Commands;

use App\Console\Concerns\StagedImport;
use App\Services\EntsoeTransparency\EntsoeTransparencyClient;
use App\Services\EntsoeTransparency\GenerationImporter;
use App\Services\EntsoeTransparency\OutageImporter;
use App\Services\EntsoeTransparency\ReservoirImporter;
use App\Services\ImportPayload\ImportPayloadReader;
use App\Services\ImportPayload\ImportPayloadWriter;
use App\Services\ImportPayload\ImportStoreResult;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Collect ENTSO-E Transparency datasets for market reviews (config/entsoe_transparency.php):
 * unavailability (A77/A80 per zone, A78 per border direction), actual generation per type
 * (A75) and weekly reservoir filling (A72).
 *
 * Without --from: the daily window. Outages: documents created or updated in the last 3 days
 * whose outage period touches the last year or the next two years. Generation: the last 4
 * days. Reservoirs: the last 8 weeks. With --from/--to: a backfill in pieces (outages: one
 * year, the API limit, ending by default one year ahead; generation: 7 days; reservoirs: 364
 * days). Generation and reservoir windows end at the start of the current UTC hour.
 *
 * Staged import (StagedImport, tasks/staged-api-imports/design.md): the fetch step records every
 * page and plans the outage version history from the fetched bodies and the stored revisions
 * (S19); it writes no rows. The store step parses the recorded bodies: one transaction for the
 * daily run, one per query (window) for a backfill. Not date-bound (S9). Read-only towards
 * ENTSO-E.
 */
class FetchEntsoeTransparencyData extends Command
{
    use StagedImport;

    protected $signature = 'entsoe:fetch
                            {--dataset=* : outages, generation or reservoirs (default: all)}
                            {--zone=* : Only these zone or border keys (default: all configured)}
                            {--from= : Backfill start date (YYYY-MM-DD, UTC)}
                            {--to= : Backfill end date, inclusive (YYYY-MM-DD, UTC)}';

    protected $description = 'Fetch ENTSO-E outages, generation per type and reservoir filling for market reviews';

    private const DATASETS = ['outages', 'generation', 'reservoirs'];

    private const OUTAGE_BACKFILL_CHUNK_DAYS = 365;

    /** A version query by mRID may not span more than one year around the outage start. */
    private const HISTORY_HALF_WINDOW_DAYS = 182;

    public function __construct(
        private readonly EntsoeTransparencyClient $client,
        private readonly OutageImporter $outages,
        private readonly GenerationImporter $generation,
        private readonly ReservoirImporter $reservoirs
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $requested = (array) $this->option('dataset');
        $unknown = array_diff($requested, self::DATASETS);
        if ($unknown !== []) {
            $this->error('Unknown dataset(s): '.implode(', ', $unknown));

            return $this->stagedFailure('fetch', 'invalid_options');
        }
        $datasets = $requested !== [] ? array_values($requested) : self::DATASETS;

        $units = $this->units($datasets, (array) $this->option('zone'));
        if ($units === null) {
            return $this->stagedFailure('fetch', 'invalid_options');
        }

        try {
            $from = $this->option('from') !== null
                ? CarbonImmutable::createFromFormat('!Y-m-d', (string) $this->option('from'), 'UTC')
                : null;
            $to = $this->option('to') !== null
                ? CarbonImmutable::createFromFormat('!Y-m-d', (string) $this->option('to'), 'UTC')->addDay()
                : null;
        } catch (Throwable) {
            $this->error('Invalid date. Use YYYY-MM-DD.');

            return $this->stagedFailure('fetch', 'invalid_options');
        }

        return $this->runStaged(
            fn (ImportPayloadWriter $payload) => $this->fetch($payload, $units, $from, $to),
            fn (ImportPayloadReader $payload): ImportStoreResult => $this->store($payload, perQuery: $from !== null),
            // A backfill commits per query window (S21); the daily run is one transaction.
            transactional: $from === null,
        );
    }

    protected function stagedImportSource(): string
    {
        return 'entsoe-transparency';
    }

    /**
     * One unit per dataset, zone (or border) and document type; a failed unit does not stop others.
     *
     * @param  list<string>  $datasets
     * @param  list<string>  $zoneFilter
     * @return list<array{dataset: string, zone: string, params: array<string, string>}>|null
     */
    private function units(array $datasets, array $zoneFilter): ?array
    {
        $units = [];
        $known = [];
        $keep = fn (string $zone) => $zoneFilter === [] || in_array($zone, $zoneFilter, true);

        foreach ($datasets as $dataset) {
            if ($dataset === 'outages') {
                $config = (array) config('entsoe_transparency.outages');
                foreach ((array) $config['zones'] as $zone => $eic) {
                    $known[] = $zone;
                    foreach ((array) $config['document_types'] as $type) {
                        if ($keep($zone)) {
                            $units[] = ['dataset' => $dataset, 'zone' => $zone, 'params' => ['documentType' => $type, 'biddingZone_Domain' => $eic]];
                        }
                    }
                }
                foreach ((array) $config['borders'] as $border => $ends) {
                    $known[] = $border;
                    if ($keep($border)) {
                        $units[] = ['dataset' => $dataset, 'zone' => $border, 'params' => [
                            'documentType' => 'A78',
                            'in_Domain' => $config['border_zones'][$ends['in']],
                            'out_Domain' => $config['border_zones'][$ends['out']],
                        ]];
                    }
                }

                continue;
            }

            $documentType = $dataset === 'generation' ? 'A75' : 'A72';
            foreach ((array) config("entsoe_transparency.{$dataset}.zones") as $zone => $eic) {
                $known[] = $zone;
                if ($keep($zone)) {
                    $units[] = ['dataset' => $dataset, 'zone' => $zone, 'params' => ['documentType' => $documentType, 'processType' => 'A16', 'in_Domain' => $eic]];
                }
            }
        }

        $unknown = array_diff($zoneFilter, $known);
        if ($unknown !== []) {
            $this->error('Unknown zone(s) for the selected datasets: '.implode(', ', $unknown));

            return null;
        }

        return $units;
    }

    /**
     * Fetch step: record every page of every unit. The windows come from the fetch time (S5). For
     * outages, the version-history queries depend on the fetched documents and the stored
     * revisions; the plan is recorded in the header facts (S19). Nothing is written.
     *
     * @param  list<array{dataset: string, zone: string, params: array<string, string>}>  $units
     */
    private function fetch(ImportPayloadWriter $payload, array $units, ?CarbonImmutable $from, ?CarbonImmutable $to): void
    {
        $this->client->assertConfigured();
        $minute = $payload->fetchedAt()->startOfMinute();
        $hour = $payload->fetchedAt()->startOfHour();
        $payload->setFact('window_minute', $minute->format('Y-m-d\TH:i:s\Z'));
        $payload->setFact('current_hour', $hour->format('Y-m-d\TH:i:s\Z'));

        $history = [];
        foreach ($units as $unit) {
            $queries = $unit['dataset'] === 'outages'
                ? $this->outageQueries($minute, $from, $to)
                : $this->seriesQueries($unit['dataset'], $hour, $from, $to);

            $query = 0;
            $versions = [];
            foreach ($queries as $window) {
                $fetched = $this->fetchQuery($payload, $unit, $query++, 'main', $unit['params'] + $window, $versions);
                if (! $fetched) {
                    continue 2; // the unit failed; the others go on
                }
            }
            if ($unit['dataset'] !== 'outages') {
                continue;
            }

            // A plain query returns only the latest version of each document. Earlier versions
            // (what was known before) need one query per mRID.
            $unused = [];
            foreach ($this->outages->missingPreviousRevisions($versions) as $document) {
                $history[] = [
                    'zone' => $unit['zone'], 'document_type' => $unit['params']['documentType'],
                    'mrid' => $document['mrid'], 'start' => $document['start']->format('Y-m-d\TH:i:s\Z'),
                ];
                $fetched = $this->fetchQuery($payload, $unit, $query++, 'history', $unit['params'] + [
                    'mRID' => $document['mrid'],
                    'periodStart' => $this->format($document['start']->subDays(self::HISTORY_HALF_WINDOW_DAYS)),
                    'periodEnd' => $this->format($document['start']->addDays(self::HISTORY_HALF_WINDOW_DAYS)),
                ], $unused);
                if (! $fetched) {
                    break;
                }
            }
        }
        $payload->setFact('history', $history);
    }

    /**
     * Record one query: every offset page for outages (the next page exists only when this one
     * is full), one request otherwise. Returns false when the unit failed; the failed request is
     * recorded and logged with the class only.
     *
     * @param  array{dataset: string, zone: string, params: array<string, string>}  $unit
     * @param  array<string, string>  $params
     * @param  array<string, array{mrid: string, revision: int, start: CarbonImmutable}>  $versions  outage versions seen
     */
    private function fetchQuery(ImportPayloadWriter $payload, array $unit, int $query, string $kind, array $params, array &$versions): bool
    {
        $paged = $unit['dataset'] === 'outages';
        $offset = 0;

        while (true) {
            $requestParams = $paged ? $params + ['offset' => (string) $offset] : $params;
            $key = [
                'dataset' => $unit['dataset'], 'zone' => $unit['zone'], 'document_type' => $unit['params']['documentType'],
                'query' => $query, 'kind' => $kind, 'offset' => $offset,
            ];

            try {
                $response = $this->client->get($requestParams);
            } catch (Throwable $e) {
                $payload->recordFailure($key, 'GET', $this->client->baseUrl(), $requestParams, $e);

                return $this->unitFailed($unit, $e);
            }

            $encoding = EntsoeTransparencyClient::isZip($response->body()) ? 'base64' : null;
            try {
                $documents = $this->client->documents($response->body());
                if ($paged) {
                    $versions += $this->outages->versions($documents);
                }
                $full = $paged && count($documents) >= EntsoeTransparencyClient::OUTAGE_PAGE_SIZE;
                if ($full && $offset + EntsoeTransparencyClient::OUTAGE_PAGE_SIZE > EntsoeTransparencyClient::MAX_OFFSET) {
                    throw new RuntimeException('ENTSO-E unavailability query has more than 5000 documents; use a shorter period.');
                }
            } catch (Throwable $e) {
                // An answer the store step cannot use (acknowledgement, unreadable ZIP, too many pages).
                $payload->record($key, $response, $encoding, failed: true);

                return $this->unitFailed($unit, $e);
            }
            $payload->record($key, $response, $encoding);

            if (! $full) {
                return true;
            }
            $offset += EntsoeTransparencyClient::OUTAGE_PAGE_SIZE;
        }
    }

    /** @param  array{dataset: string, zone: string, params: array<string, string>}  $unit */
    private function unitFailed(array $unit, Throwable $e): bool
    {
        // Exception messages can contain the request URL with the securityToken.
        logger()->error('ENTSO-E transparency fetch failed', [
            'dataset' => $unit['dataset'],
            'zone' => $unit['zone'],
            'document_type' => $unit['params']['documentType'],
            'exception_class' => $e::class,
        ]);
        $this->error("{$unit['dataset']} {$unit['zone']} {$unit['params']['documentType']}: fetch failed (".$e::class.').');

        return false;
    }

    /**
     * Store step: parse every recorded body and write it, query by query. A query with a failed
     * page is left out, as before (the unit stopped there). Failed units make the run exit 1 after
     * the commit; their rows from earlier queries stay (S10).
     */
    private function store(ImportPayloadReader $payload, bool $perQuery): ImportStoreResult
    {
        $fetchedAt = $payload->fetchedAt();
        $failedQueries = [];
        foreach ($payload->end()['failed_units'] as $failed) {
            $failedQueries[self::queryKey($failed)] = true;
        }

        $written = [];
        $flush = function (?array $query, array $documents) use (&$written, $fetchedAt, $perQuery): void {
            if ($query === null || isset($query['failed'])) {
                return;
            }
            $write = fn (): int => match ($query['dataset']) {
                'outages' => $this->outages->import($query['document_type'], $query['zone'], $documents, $fetchedAt),
                'generation' => $this->generation->import($query['zone'], $documents, $fetchedAt),
                'reservoirs' => $this->reservoirs->import($query['zone'], $documents, $fetchedAt),
            };
            $label = "{$query['dataset']} {$query['zone']} {$query['document_type']}";
            $written[$label] = ($written[$label] ?? 0) + ($perQuery ? DB::transaction($write) : $write());
        };

        $query = null;
        $documents = [];
        foreach ($payload->requests() as $request) {
            $unit = $request['unit'];
            $key = self::queryKey($unit);
            if ($query === null || $query['key'] !== $key) {
                $flush($query, $documents);
                $query = $unit + ['key' => $key] + (isset($failedQueries[$key]) ? ['failed' => true] : []);
                $documents = [];
            }
            if (! isset($query['failed'])) {
                array_push($documents, ...$this->client->documents((string) ImportPayloadReader::body($request)));
            }
        }
        $flush($query, $documents);

        $counts = ['outages' => 0, 'generation' => 0, 'reservoirs' => 0];
        foreach ($written as $label => $rows) {
            $this->info("{$label}: {$rows} rows.");
            $counts[strtok($label, ' ')] += $rows;
        }

        return new ImportStoreResult(
            $counts,
            error: $payload->complete() ? null : 'fetch_incomplete',
            errorStep: 'fetch',
        );
    }

    /** @param  array<string, mixed>  $unit */
    private static function queryKey(array $unit): string
    {
        return "{$unit['dataset']}|{$unit['zone']}|{$unit['document_type']}|{$unit['query']}";
    }

    /**
     * @return list<array<string, string>>
     */
    private function outageQueries(CarbonImmutable $now, ?CarbonImmutable $from, ?CarbonImmutable $to): array
    {
        $config = (array) config('entsoe_transparency.outages');

        if ($from === null) {
            return [[
                'periodStart' => $this->format($now->subDays((int) $config['past_days'])),
                'periodEnd' => $this->format($now->addDays((int) $config['future_days'])),
                'periodStartUpdate' => $this->format($now->subDays((int) $config['update_lookback_days'])),
                'periodEndUpdate' => $this->format($now),
            ]];
        }

        $end = $to ?? $now->startOfDay()->addDays((int) $config['backfill_future_days']);

        return array_map(fn (array $chunk) => [
            'periodStart' => $this->format($chunk[0]),
            'periodEnd' => $this->format($chunk[1]),
        ], $this->chunks($from, $end, self::OUTAGE_BACKFILL_CHUNK_DAYS));
    }

    /**
     * @return list<array<string, string>>
     */
    private function seriesQueries(string $dataset, CarbonImmutable $currentHour, ?CarbonImmutable $from, ?CarbonImmutable $to): array
    {
        $end = $to !== null && $to->lessThan($currentHour) ? $to : $currentHour;

        $windows = $from === null
            ? [[$currentHour->subDays((int) config("entsoe_transparency.{$dataset}.lookback_days")), $currentHour]]
            : $this->chunks($from, $end, (int) config("entsoe_transparency.{$dataset}.backfill_chunk_days"));

        return array_map(fn (array $window) => [
            'periodStart' => $this->format($window[0]),
            'periodEnd' => $this->format($window[1]),
        ], $windows);
    }

    private function format(\DateTimeInterface $time): string
    {
        return Carbon::instance($time)->utc()->format('YmdHi');
    }

    /**
     * @return list<array{0: CarbonImmutable, 1: CarbonImmutable}>
     */
    private function chunks(CarbonImmutable $start, CarbonImmutable $end, int $days): array
    {
        $chunks = [];
        $cursor = $start;

        while ($cursor->lessThan($end)) {
            $chunkEnd = $cursor->addDays($days);
            if ($chunkEnd->greaterThan($end)) {
                $chunkEnd = $end;
            }
            $chunks[] = [$cursor, $chunkEnd];
            $cursor = $chunkEnd;
        }

        return $chunks;
    }
}
