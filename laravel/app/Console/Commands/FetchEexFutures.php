<?php

namespace App\Console\Commands;

use App\Console\Concerns\StagedImport;
use App\Models\DataFreshnessCheckpoint;
use App\Models\ElectricityFuturesEodPrice;
use App\Services\ContractListCacheService;
use App\Services\ElectricityFutures\EexFuturesService;
use App\Services\ImportPayload\ImportPayloadInvalid;
use App\Services\ImportPayload\ImportPayloadReader;
use App\Services\ImportPayload\ImportPayloadWriter;
use App\Services\ImportPayload\ImportStoreResult;
use App\Services\MorningFreshness\MorningJobFreshnessService;
use App\Support\DataFetchFailureReporter;
use Carbon\Carbon;
use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Throwable;

class FetchEexFutures extends Command
{
    use StagedImport;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'futures:fetch-eex
        {--start-date= : First trade date to request (YYYY-MM-DD). Capped to the EEX history window.}
        {--end-date= : Last trade date to request (YYYY-MM-DD). Defaults to today.}
        {--maturity=* : Explicit maturity or maturities to fetch, for example 202606, 202607, or 202701. Applies to selected tenors.}
        {--area=* : Limit to one or more configured EEX areas, for example FI or SE3.}
        {--tenor=* : Limit to one or more maturity types: month, quarter, year. Defaults to all configured tenors.}
        {--months-back= : Number of previous month maturities to fetch when --maturity is not provided.}
        {--months-ahead= : Number of month maturities from the current month onward to fetch when --maturity is not provided.}
        {--quarters-ahead= : Number of quarter maturities to fetch when --maturity is not provided.}
        {--years-ahead= : Number of year maturities to fetch when --maturity is not provided.}
        {--history-window-days= : EEX public endpoint history window. Defaults to config value.}
        {--dry-run : Fetch and report data without writing rows or a checkpoint. A dry-run payload replays only with --dry-run.}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Fetch EEX electricity futures end-of-day settlement prices and save them to the database';

    public function __construct(
        private readonly EexFuturesService $eexFuturesService,
        private readonly MorningJobFreshnessService $freshness,
        private readonly ContractListCacheService $contractListCache,
    ) {
        parent::__construct();
    }

    /**
     * Execute the console command: the fetch step (discovery, EOD requests, throttle and retry
     * passes) saves every EEX answer in a payload file; the store step parses that file, builds
     * the current-run FI proof, upserts and writes the final checkpoint (StagedImport).
     */
    public function handle(): int
    {
        $this->checkpointDate = null;
        $this->checkpointOwner = null;
        $this->storedPayload = null;
        $this->fetchRun = null;
        $this->fullScope = $this->isFullScheduledScope();
        $this->failureReporter = new DataFetchFailureReporter('eex_futures');
        $exit = Command::FAILURE;
        try {
            $exit = $this->runStagedImport();
        } catch (Throwable $exception) {
            $this->failureReporter->fail('unexpected', $exception);
            $this->error('EEX import failed.');
            if ($this->checkpointOwner !== null) {
                $this->finishCheckpoint(false, ['reason' => 'unexpected']);
            }
        } finally {
            $this->failureReporter->report($exit === Command::FAILURE);
        }

        return $exit;
    }

    private DataFetchFailureReporter $failureReporter;

    private bool $fullScope = false;

    private ?string $checkpointDate = null;

    private ?string $checkpointOwner = null;

    /** @var array{path: string, sha256: string}|null the payload the store step reads */
    private ?array $storedPayload = null;

    /** @var array{date: string, owner: string}|null the `running` owner the separate fetch recorded in the payload */
    private ?array $fetchRun = null;

    /** @var array<string, list<string>> discovered maturities per tenor in this fetch */
    private array $maturitiesByTenor = [];

    protected function stagedImportSource(): string
    {
        return 'eex';
    }

    private function runStagedImport(): int
    {
        $effectiveDate = Carbon::today('Europe/Helsinki')->toDateString();

        // S11: `running` with a new owner at the start of the fetch. A --from-payload store takes
        // the checkpoint over once, after its date check and before its data transaction (storeStep).
        if ($this->option('from-payload') === null) {
            $this->failureReporter->step('fetch');
            if ($this->fullScope && ! $this->startCheckpoint($effectiveDate)) {
                return $this->stagedFailure('fetch', 'checkpoint_failed');
            }
        }

        // Not transactional in the trait: storeStep owns its data transaction, so a replay's
        // takeover stays committed when the data rolls back.
        return $this->runStaged(
            fn (ImportPayloadWriter $payload): array => $this->fetchStep($payload, $effectiveDate),
            fn (ImportPayloadReader $payload): ImportStoreResult => $this->storeStep($payload),
            transactional: false,
        );
    }

    /**
     * One Issue per run: failures go to the run's reporter, which handle() sends once. The owned
     * checkpoint becomes failed with the step and, after a store failure, the payload reference.
     * It only finishes the owner this invocation already holds and never claims a new one; a
     * newer run's owner fails the compare-and-set. A `--from-payload` store refused before its
     * takeover (wrong date) holds no owner and finishes its fetch's owner instead (failFetchRun()).
     *
     * @param  array<string, scalar|null>  $fields
     */
    protected function reportStagedFailure(string $step, string $error, ?Throwable $exception, array $fields): void
    {
        $this->failureReporter->step($step);
        if ($error === 'checkpoint_failed') {
            return; // startCheckpoint() already recorded the checkpoint failure.
        }
        $this->failureReporter->fail(
            in_array($error, ['fetch_failed', 'store_failed', 'after_commit_failed'], true) ? 'unexpected' : $error,
            $exception,
        );
        if (! $this->fullScope) {
            return;
        }

        $metadata = [
            'reason' => $error === 'payload_invalid' ? 'payload_invalid' : 'unexpected',
            'step' => $step,
            'error' => $error,
            ...($this->storedPayload !== null ? ['payload' => $this->storedPayload] : []),
            ...array_diff_key($fields, ['reason' => true]),
            ...(isset($fields['reason']) ? ['payload_reason' => $fields['reason']] : []),
        ];
        if ($this->checkpointOwner !== null) {
            $this->finishCheckpoint(false, $metadata);
        } else {
            $this->failFetchRun($metadata);
        }
    }

    /**
     * The network part: maturity discovery, EOD requests and the retry passes, each answer
     * recorded in the payload. Writes no rows. Returns the end facts.
     *
     * @return array<string, int>
     */
    private function fetchStep(ImportPayloadWriter $payload, string $effectiveDate): array
    {
        [$startDate, $endDate] = $this->dateRange();
        $instruments = $this->selectedInstruments();
        $explicitMaturities = array_values(array_unique(array_map('strval', array_filter((array) $this->option('maturity')))));
        $this->maturitiesByTenor = [];

        // S5/S19: the store step takes the effective date, the window and the request plan from here.
        $payload->setFact('effective_date', $effectiveDate);
        if ($this->checkpointOwner !== null && $this->option('fetch-only')) {
            // The separate store process finishes this owner when it fails before its takeover
            // (LAR-202). A default-mode run finishes its owner itself, so its payload has no owner.
            $payload->setFact('checkpoint_run_uuid', $this->checkpointOwner);
        }
        $payload->setFact('start_date', $startDate->toDateString());
        $payload->setFact('end_date', $endDate->toDateString());
        $payload->setFact('instruments', $instruments);
        $payload->setFact('explicit_maturities', $explicitMaturities);

        if (empty($instruments)) {
            $this->warn('No EEX futures instruments selected.');
            $payload->setFact('discovery_tenors', []);
            $payload->setFact('maturities_by_tenor', []);

            return ['failures_before_retry' => 0, 'retry_passes' => 0];
        }

        [$requests, $failedDiscoveries, $representativeByTenor] = $this->buildInstrumentMaturityRequests($payload, $instruments, $explicitMaturities, $endDate);
        $payload->setFact('discovery_tenors', array_map(
            fn (array $instrument) => ['area' => $instrument['area'] ?? null, 'short_code' => $instrument['short_code'] ?? null],
            $representativeByTenor,
        ));

        $this->info(sprintf(
            'Fetching EEX futures EOD data for %d instruments, %d discovered instrument/maturity requests, %s to %s.',
            count($instruments),
            count($requests),
            $startDate->toDateString(),
            $endDate->toDateString()
        ));

        $failedRequests = $this->fetchRequests($payload, $requests, $startDate, $endDate);
        $failuresBeforeRetry = count($failedDiscoveries) + count($failedRequests);
        $retryPasses = 0;

        // One transient connection failure used to fail the whole day (2026-10-07: month discovery;
        // 2026-10-09: one year request). Retry only the failed units, with the same service
        // throttle, after a pause. A wide failure is an outage: retrying hundreds of units would
        // add hours, so it keeps the original failure outcome. A retried unit gets a new request
        // line; the store step uses the last answered line of each unit.
        $maxRetryPasses = max(0, (int) config('eex_futures.retry_passes', 2));
        $maxRetryUnits = max(0, (int) config('eex_futures.retry_max_failed_units', 25));
        while ($retryPasses < $maxRetryPasses && ($failedDiscoveries !== [] || $failedRequests !== [])) {
            $failedUnits = count($failedDiscoveries) + count($failedRequests);
            if ($failedUnits > $maxRetryUnits) {
                $this->warn("Not retrying {$failedUnits} failed EEX units; the retry limit is {$maxRetryUnits}.");

                break;
            }

            $retryPasses++;
            $pauseSeconds = max(0, (int) config('eex_futures.retry_pause_seconds', 60));
            $this->warn("Retry pass {$retryPasses}/{$maxRetryPasses}: {$failedUnits} failed EEX units after a {$pauseSeconds} s pause.");
            Sleep::for($pauseSeconds)->seconds();

            [$discoveredRequests, $failedDiscoveries] = $this->discoverRequests($payload, $instruments, $failedDiscoveries, $endDate);
            $failedRequests = $this->fetchRequests(
                $payload,
                [...$failedRequests, ...$discoveredRequests],
                $startDate,
                $endDate,
            );
        }

        $payload->setFact('maturities_by_tenor', (object) $this->maturitiesByTenor);

        return ['failures_before_retry' => $failuresBeforeRetry, 'retry_passes' => $retryPasses];
    }

    /**
     * Fetch and record each EOD request. Returns the failed requests for a retry pass.
     *
     * @param  array<int, array{instrument: array<string, mixed>, maturity: string}>  $requests
     * @return list<array{instrument: array<string, mixed>, maturity: string}>
     */
    private function fetchRequests(ImportPayloadWriter $payload, array $requests, Carbon $startDate, Carbon $endDate): array
    {
        $failed = [];

        foreach ($requests as $request) {
            $instrument = $request['instrument'];
            $maturity = $request['maturity'];
            $unit = ['kind' => 'eod', 'area' => $instrument['area'] ?? null, 'short_code' => $instrument['short_code'] ?? null, 'maturity' => $maturity];

            try {
                $payload->record($unit, $this->eexFuturesService->fetchEndOfDay($instrument, $maturity, $startDate, $endDate));
            } catch (RequestException|ConnectionException $e) {
                $payload->recordFailure(
                    $unit,
                    'GET',
                    (string) config('eex_futures.endpoint'),
                    $this->eexFuturesService->endOfDayQuery($instrument, $maturity, $startDate, $endDate),
                    $e,
                );
                $failed[] = $request;
                $this->warn(sprintf(
                    'Failed to fetch %s %s %s %s: %s',
                    $instrument['area'] ?? 'unknown-area',
                    $instrument['maturity_type'] ?? 'unknown-tenor',
                    $instrument['short_code'] ?? 'unknown-code',
                    $maturity,
                    $e::class
                ));
            }
        }

        return $failed;
    }

    /**
     * The store step: date check, a replay's one checkpoint takeover, then the rows and the final
     * checkpoint in one transaction. The cache bump runs after the commit (StagedImport).
     */
    private function storeStep(ImportPayloadReader $payload): ImportStoreResult
    {
        $this->storedPayload = ['path' => $payload->path(), 'sha256' => $payload->sha256()];
        $effectiveDate = (string) $payload->fact('effective_date');
        $fetchOwner = $payload->fact('checkpoint_run_uuid');
        if ($this->fullScope && $this->option('from-payload') !== null && is_string($fetchOwner)) {
            $this->fetchRun = ['date' => $effectiveDate, 'owner' => $fetchOwner];
        }
        // S9: the checkpoint and the FI proof are per Helsinki date; never store another day's run.
        if ($effectiveDate !== Carbon::today('Europe/Helsinki')->toDateString()) {
            throw ImportPayloadInvalid::because('wrong_date');
        }

        // S11: a replay takes the date over with a new owner exactly once, committed before the
        // data transaction. A failed store then rolls back only its rows, and the failure handler
        // finishes this same owner; it never starts another one (that could replace a newer run).
        if ($this->fullScope && $this->option('from-payload') !== null) {
            $this->failureReporter->step('store');
            if (! $this->startCheckpoint($effectiveDate, ['payload' => $this->storedPayload])) {
                $this->failFetchRun(['reason' => 'checkpoint_failed', 'step' => 'store', 'error' => 'checkpoint_failed', 'payload' => $this->storedPayload]);

                return new ImportStoreResult([], [], 'checkpoint_failed');
            }
        }

        // S21: one transaction for every row of the run and the final checkpoint. A database error
        // in either propagates, so nothing of the run stays and the JSON line gets the SQL facts.
        return DB::transaction(fn (): ImportStoreResult => $this->storeRows($payload, $effectiveDate));
    }

    /**
     * The database part, from the payload only: parse the last answered EOD body of each planned
     * unit, rebuild the current-run prior-date FI Base proof, upsert and write the final checkpoint.
     */
    private function storeRows(ImportPayloadReader $payload, string $effectiveDate): ImportStoreResult
    {
        $instruments = (array) $payload->fact('instruments', []);
        if ($instruments === []) {
            if (! $this->fullScope) {
                return new ImportStoreResult(['fetched_points' => 0, 'saved_points' => 0, 'failures' => 0]);
            }
            $this->failureReporter->step('store');
            $this->failureReporter->fail('no_instruments');
            $this->writeOwnedCheckpoint(false, ['reason' => 'no_instruments', 'payload' => $this->storedPayload]);

            return new ImportStoreResult([], [], 'no_instruments');
        }

        $explicitMaturities = (array) $payload->fact('explicit_maturities', []);
        $maturitiesByTenor = (array) $payload->fact('maturities_by_tenor', []);
        $planned = [];
        foreach ($instruments as $instrument) {
            $tenor = strtolower((string) ($instrument['maturity_type'] ?? 'year'));
            foreach ($explicitMaturities !== [] ? $explicitMaturities : ($maturitiesByTenor[$tenor] ?? []) as $maturity) {
                $planned[self::eodKey($instrument['area'] ?? null, $instrument['short_code'] ?? null, (string) $maturity)] = [
                    'instrument' => $instrument,
                    'maturity' => (string) $maturity,
                ];
            }
        }

        // A unit can have several lines (a failed answer, then a retry). The last answered (2xx)
        // line wins; a unit without one is a remaining failure. Bodies are read in a second pass.
        $answeredLine = [];
        $lastFailedEod = [];
        $lastFailedTicker = [];
        foreach ($payload->requests() as $index => $line) {
            $unit = (array) ($line['unit'] ?? []);
            $answered = is_int($line['status'] ?? null) && $line['status'] >= 200 && $line['status'] < 300;
            if (($unit['kind'] ?? null) === 'eod') {
                $key = self::eodKey($unit['area'] ?? null, $unit['short_code'] ?? null, (string) ($unit['maturity'] ?? ''));
                if ($answered) {
                    $answeredLine[$key] = $index;
                } else {
                    $lastFailedEod[$key] = $line;
                }
            } elseif (($unit['kind'] ?? null) === 'ticker' && ! $answered) {
                $lastFailedTicker[(string) ($unit['tenor'] ?? '')] = $line;
            }
        }
        $keyByLine = array_flip(array_intersect_key($answeredLine, $planned));

        $totalFetched = 0;
        $totalSaved = 0;
        $latestPriorFiTradeDateByTenor = [];
        foreach ($payload->requests() as $index => $line) {
            if (! isset($keyByLine[$index])) {
                continue;
            }
            ['instrument' => $instrument, 'maturity' => $maturity] = $planned[$keyByLine[$index]];
            $points = $this->eexFuturesService->extractPricePoints(
                $this->eexFuturesService->decode(ImportPayloadReader::body($line)),
                $instrument,
                $maturity,
            );
            $totalFetched += count($points);

            foreach ($points as $point) {
                $tradeDate = $point['trade_date'] ?? null;
                $tenor = (string) ($point['maturity_type'] ?? '');

                if (($point['exchange'] ?? null) === 'EEX'
                    && ($point['area'] ?? null) === 'FI'
                    && ($point['product'] ?? null) === 'Base'
                    && is_string($tradeDate)
                    && $tradeDate < $effectiveDate
                    && $tradeDate > ($latestPriorFiTradeDateByTenor[$tenor] ?? '')) {
                    $latestPriorFiTradeDateByTenor[$tenor] = $tradeDate;
                }
            }

            if ($this->option('dry-run')) {
                $this->line(sprintf(
                    '[dry-run] %s %s %s %s: %d prices',
                    $instrument['area'],
                    $instrument['maturity_type'] ?? 'unknown-tenor',
                    $instrument['short_code'],
                    $maturity,
                    count($points)
                ));

                continue;
            }

            $saved = $this->savePricePoints($points);
            $totalSaved += $saved;

            $this->line(sprintf(
                '%s %s %s %s: fetched %d, upserted %d',
                $instrument['area'],
                $instrument['maturity_type'] ?? 'unknown-tenor',
                $instrument['short_code'],
                $maturity,
                count($points),
                $saved
            ));
        }

        // Only failures that remain after the retry passes reach the aggregate Issue.
        $this->failureReporter->step('fetch');
        $failures = 0;
        if ($explicitMaturities === []) {
            foreach (array_keys((array) $payload->fact('discovery_tenors', [])) as $tenor) {
                if (! array_key_exists($tenor, $maturitiesByTenor)) {
                    $failures++;
                    $this->failureReporter->fail('discovery', self::failureException($lastFailedTicker[$tenor] ?? null));
                }
            }
        }
        foreach (array_diff_key($planned, $answeredLine) as $key => $request) {
            $failures++;
            $this->failureReporter->fail('acquisition', self::failureException($lastFailedEod[$key] ?? null));
        }
        $this->failureReporter->step('store');

        $endFacts = (array) ($payload->end()['facts'] ?? []);
        $failuresBeforeRetry = (int) ($endFacts['failures_before_retry'] ?? $failures);
        $retryPasses = (int) ($endFacts['retry_passes'] ?? 0);
        $latestCurrentRunPriorFiTradeDate = $latestPriorFiTradeDateByTenor === []
            ? null
            : max($latestPriorFiTradeDateByTenor);
        $priorFiTradeDatesByTenor = [];
        foreach (MorningJobFreshnessService::EEX_PROOF_TENORS as $tenor) {
            $priorFiTradeDatesByTenor[$tenor] = $latestPriorFiTradeDateByTenor[$tenor] ?? null;
        }
        // The proof needs month, quarter and year rows on the run's latest prior FI trade date,
        // so a run that silently returns no month rows cannot become ready.
        $completeFiProof = $latestCurrentRunPriorFiTradeDate !== null
            && array_filter(
                $priorFiTradeDatesByTenor,
                fn (?string $tradeDate) => $tradeDate !== $latestCurrentRunPriorFiTradeDate,
            ) === [];

        $counts = [
            'maturity_requests' => count($planned),
            'fetched_points' => $totalFetched,
            'saved_points' => $totalSaved,
            'failures' => $failures,
            'failures_before_retry' => $failuresBeforeRetry,
            'retry_passes' => $retryPasses,
        ];
        foreach (['maturity_requests', 'fetched_points', 'saved_points', 'retry_passes', 'failures_before_retry'] as $name) {
            $this->failureReporter->count($name, $counts[$name]);
        }
        if ($this->fullScope && ! $completeFiProof) {
            $this->failureReporter->fail('missing_current_run_prior_fi');
        }

        $this->info("EEX futures fetch complete. Fetched {$totalFetched} price points, upserted {$totalSaved}. Failures: {$failures} ({$failuresBeforeRetry} before {$retryPasses} retry passes).");

        // FI Base futures are a direct input to canonical Spot, market-reset, and supplier-adjusted
        // annual prices. Invalidate every calculated-cost-dependent cache after a real FI write.
        // Downstream company, ranking, and prepared-page keys include this shared list version.
        $afterCommit = ! $this->option('dry-run') && $totalSaved > 0 && $latestCurrentRunPriorFiTradeDate !== null
            ? [fn () => $this->contractListCache->bumpVersion()]
            : [];

        if (! $this->fullScope) {
            return new ImportStoreResult($counts, $afterCommit, $failures > 0 ? 'fetch_incomplete' : null, 'fetch');
        }

        $ready = $failures === 0
            && $totalFetched > 0
            && $completeFiProof;
        // A database error here propagates and rolls the run back; false means a newer run owns
        // the date, a closed outcome that keeps the rows (they are this payload's valid prices).
        $recorded = $this->writeOwnedCheckpoint($ready, [
            'fetched_points' => $totalFetched,
            'saved_points' => $totalSaved,
            'failures' => $failures,
            'failures_before_retry' => $failuresBeforeRetry,
            'retry_passes' => $retryPasses,
            'current_run_latest_prior_fi_trade_date' => $latestCurrentRunPriorFiTradeDate,
            'current_run_latest_prior_fi_trade_dates_by_tenor' => $priorFiTradeDatesByTenor,
            'payload' => $this->storedPayload,
        ]);

        $error = match (true) {
            $failures > 0 => 'fetch_incomplete',
            ! $ready => 'missing_current_run_prior_fi',
            ! $recorded => 'checkpoint_failed',
            default => null,
        };

        return new ImportStoreResult($counts, $afterCommit, $error, $error === 'fetch_incomplete' ? 'fetch' : 'store');
    }

    private static function eodKey(mixed $area, mixed $shortCode, string $maturity): string
    {
        return "{$area}|{$shortCode}|{$maturity}";
    }

    /**
     * The failure a request line stands for, rebuilt for the Issue's closed reason and exception
     * class without its body: a status line is a RequestException (`http_<status>`), a line
     * without an answer is a ConnectionException, as the fetch saw them.
     *
     * @param  array<string, mixed>|null  $line
     */
    private static function failureException(?array $line): ?Throwable
    {
        return match (true) {
            $line === null => null,
            is_int($line['status'] ?? null) => new RequestException(new Response(new Psr7Response($line['status']))),
            default => new ConnectionException('The EEX request got no answer.'),
        };
    }

    private function isFullScheduledScope(): bool
    {
        if ((bool) $this->option('dry-run')) {
            return false;
        }

        foreach ([
            'start-date',
            'end-date',
            'months-back',
            'months-ahead',
            'quarters-ahead',
            'years-ahead',
            'history-window-days',
        ] as $option) {
            if ($this->option($option) !== null) {
                return false;
            }
        }

        foreach (['maturity', 'area', 'tenor'] as $option) {
            if (array_filter((array) $this->option($option)) !== []) {
                return false;
            }
        }

        return true;
    }

    /**
     * Write `running` with a new owner for the date. A run that started earlier and finishes
     * later compares its owner in writeOwnedCheckpoint() and cannot replace these facts. The
     * owner is held only after the write commits; one invocation calls this at most once.
     *
     * @param  array<string, mixed>  $metadata
     */
    private function startCheckpoint(string $date, array $metadata = []): bool
    {
        $owner = (string) Str::uuid();
        try {
            DB::transaction(function () use ($date, $metadata, $owner): void {
                if (! DataFreshnessCheckpoint::query()->where('key', DataFreshnessCheckpoint::KEY_EEX_FUTURES)->whereDate('effective_date', $date)->exists()) {
                    DataFreshnessCheckpoint::query()->insertOrIgnore([
                        'key' => DataFreshnessCheckpoint::KEY_EEX_FUTURES, 'effective_date' => $date,
                        'status' => DataFreshnessCheckpoint::STATUS_RUNNING,
                        'metadata' => json_encode(['run_uuid' => $owner, 'stage' => 'started']),
                        'recorded_at' => now(), 'created_at' => now(), 'updated_at' => now(),
                    ]);
                }
                DataFreshnessCheckpoint::query()->where('key', DataFreshnessCheckpoint::KEY_EEX_FUTURES)
                    ->whereDate('effective_date', $date)->lockForUpdate()->firstOrFail();
                $this->freshness->record(
                    DataFreshnessCheckpoint::KEY_EEX_FUTURES,
                    $date,
                    DataFreshnessCheckpoint::STATUS_RUNNING,
                    ['run_uuid' => $owner, 'stage' => 'started'] + $metadata,
                );
            });
        } catch (Throwable $exception) {
            $this->error('Failed to record the EEX freshness checkpoint.');
            $this->failureReporter->fail('checkpoint', $exception);

            return false;
        }
        [$this->checkpointDate, $this->checkpointOwner] = [$date, $owner];

        return true;
    }

    /**
     * The final write of the owned checkpoint, compared with its owner under the row lock. False
     * when a newer run owns the date; a database error propagates (the store's transaction rolls
     * back with it).
     *
     * @param  array<string, mixed>  $metadata
     */
    private function writeOwnedCheckpoint(bool $ready, array $metadata): bool
    {
        if ($this->checkpointDate === null || $this->checkpointOwner === null) {
            return false;
        }

        $status = $ready ? DataFreshnessCheckpoint::STATUS_READY : DataFreshnessCheckpoint::STATUS_FAILED;
        if ($this->recordIfOwner($this->checkpointDate, $this->checkpointOwner, $status, $metadata)) {
            return true;
        }
        $this->failureReporter->fail('checkpoint');

        return false;
    }

    /**
     * Write the date's checkpoint only while $owner still owns it (and, with $onlyRunning, while
     * the row is still `running`), compared under the row lock.
     *
     * @param  array<string, mixed>  $metadata
     */
    private function recordIfOwner(string $date, string $owner, string $status, array $metadata, bool $onlyRunning = false): bool
    {
        return DB::transaction(function () use ($date, $owner, $status, $metadata, $onlyRunning): bool {
            $row = DataFreshnessCheckpoint::query()->where('key', DataFreshnessCheckpoint::KEY_EEX_FUTURES)
                ->whereDate('effective_date', $date)->lockForUpdate()->firstOrFail();
            if (($row->metadata['run_uuid'] ?? null) !== $owner
                || ($onlyRunning && $row->status !== DataFreshnessCheckpoint::STATUS_RUNNING)) {
                return false;
            }
            $this->freshness->record(DataFreshnessCheckpoint::KEY_EEX_FUTURES, $date, $status, ['run_uuid' => $owner] + $metadata);

            return true;
        });
    }

    /**
     * A `--from-payload` store that fails before its takeover (wrong date, a failed takeover)
     * finishes the `running` marker its `--fetch-only` fetch wrote, so that marker is not left
     * running (review LAR-202). Only while that owner still holds the date and the row is still
     * `running`, by compare-and-set: when a newer run or replay owns the date, an old payload's
     * replay changes nothing. It never starts an owner. A payload the trait refuses before the
     * store step (changed file, other source or options) is not read, so its owner stays as it is.
     *
     * @param  array<string, mixed>  $metadata
     */
    private function failFetchRun(array $metadata): void
    {
        if ($this->checkpointOwner !== null || $this->fetchRun === null) {
            return;
        }

        try {
            $this->recordIfOwner($this->fetchRun['date'], $this->fetchRun['owner'], DataFreshnessCheckpoint::STATUS_FAILED, $metadata, onlyRunning: true);
        } catch (Throwable $exception) {
            $this->error('Failed to record the EEX freshness checkpoint.');
            $this->failureReporter->fail('checkpoint', $exception);
        }
    }

    /**
     * Failure paths, after any store transaction: record `failed` for the owned checkpoint and
     * never throw, so the original failure is the one reported.
     *
     * @param  array<string, mixed>  $metadata
     */
    private function finishCheckpoint(bool $ready, array $metadata): bool
    {
        try {
            return $this->writeOwnedCheckpoint($ready, $metadata);
        } catch (Throwable $exception) {
            $this->error('Failed to record the EEX freshness checkpoint.');
            $this->failureReporter->fail('checkpoint', $exception);

            return false;
        }
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    private function dateRange(): array
    {
        $endDate = $this->option('end-date')
            ? Carbon::parse($this->option('end-date'))->startOfDay()
            : Carbon::today('Europe/Helsinki');

        $historyWindowDays = max(1, (int) ($this->option('history-window-days') ?: config('eex_futures.history_window_days', 45)));
        $earliestAllowedStart = $endDate->copy()->subDays($historyWindowDays - 1);

        $requestedStartDate = $this->option('start-date')
            ? Carbon::parse($this->option('start-date'))->startOfDay()
            : $earliestAllowedStart->copy();

        if ($requestedStartDate->lt($earliestAllowedStart)) {
            $this->warn(sprintf(
                'EEX public chart endpoint only returns about %d days; capping start date from %s to %s.',
                $historyWindowDays,
                $requestedStartDate->toDateString(),
                $earliestAllowedStart->toDateString()
            ));
            $requestedStartDate = $earliestAllowedStart;
        }

        if ($requestedStartDate->gt($endDate)) {
            $requestedStartDate = $endDate->copy();
        }

        return [$requestedStartDate, $endDate];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function selectedInstruments(): array
    {
        $instruments = config('eex_futures.instruments', []);
        $areas = array_map('strtoupper', (array) $this->option('area'));
        $tenors = array_map(
            fn (string $tenor) => strtolower($tenor),
            array_filter((array) $this->option('tenor'))
        );

        return array_values(array_filter(
            $instruments,
            function (array $instrument) use ($areas, $tenors): bool {
                $areaMatches = empty($areas)
                    || in_array(strtoupper((string) ($instrument['area'] ?? '')), $areas, true);
                $tenorMatches = empty($tenors)
                    || in_array(strtolower((string) ($instrument['maturity_type'] ?? '')), $tenors, true);

                return $areaMatches && $tenorMatches;
            }
        ));
    }

    /**
     * The EOD requests, the failed tenors with their representative, and every tenor's representative.
     *
     * @param  array<int, array<string, mixed>>  $instruments
     * @param  list<string>  $explicitMaturities
     * @return array{0: array<int, array{instrument: array<string, mixed>, maturity: string}>, 1: array<string, array<string, mixed>>, 2: array<string, array<string, mixed>>}
     */
    private function buildInstrumentMaturityRequests(ImportPayloadWriter $payload, array $instruments, array $explicitMaturities, Carbon $referenceDate): array
    {
        if ($explicitMaturities !== []) {
            $requests = [];
            foreach ($instruments as $instrument) {
                foreach ($explicitMaturities as $maturity) {
                    $requests[] = ['instrument' => $instrument, 'maturity' => $maturity];
                }
            }

            return [$requests, [], []];
        }

        $representativeByTenor = [];

        foreach ($instruments as $instrument) {
            $tenor = strtolower((string) ($instrument['maturity_type'] ?? 'year'));
            $representativeByTenor[$tenor] ??= $instrument;
        }

        return [...$this->discoverRequests($payload, $instruments, $representativeByTenor, $referenceDate), $representativeByTenor];
    }

    /**
     * Discover maturities once per tenor and build the requests for every instrument of that tenor.
     * A failed tenor is returned with its representative so a retry pass can discover it again.
     *
     * @param  array<int, array<string, mixed>>  $instruments
     * @param  array<string, array<string, mixed>>  $representativeByTenor
     * @return array{0: array<int, array{instrument: array<string, mixed>, maturity: string}>, 1: array<string, array<string, mixed>>}
     */
    private function discoverRequests(ImportPayloadWriter $payload, array $instruments, array $representativeByTenor, Carbon $referenceDate): array
    {
        $requests = [];
        $discovered = [];
        $failed = [];

        foreach ($representativeByTenor as $tenor => $representativeInstrument) {
            try {
                $discovered[$tenor] = $this->discoverMaturitiesForInstrument($payload, (string) $tenor, $representativeInstrument, $referenceDate);
                $this->maturitiesByTenor[$tenor] = $discovered[$tenor];
                $this->line(sprintf(
                    'Discovered %d %s maturities using %s %s as representative.',
                    count($discovered[$tenor]),
                    $tenor,
                    $representativeInstrument['area'] ?? 'unknown-area',
                    $representativeInstrument['short_code'] ?? 'unknown-code'
                ));
            } catch (RequestException|ConnectionException $e) {
                $failed[$tenor] = $representativeInstrument;
                $this->warn(sprintf(
                    'Failed to discover %s maturities using %s %s: %s',
                    $tenor,
                    $representativeInstrument['area'] ?? 'unknown-area',
                    $representativeInstrument['short_code'] ?? 'unknown-code',
                    $e::class
                ));
            }
        }

        foreach ($instruments as $instrument) {
            $tenor = strtolower((string) ($instrument['maturity_type'] ?? 'year'));

            foreach (($discovered[$tenor] ?? []) as $maturity) {
                $requests[] = ['instrument' => $instrument, 'maturity' => $maturity];
            }
        }

        return [$requests, $failed];
    }

    /**
     * @param  array<string, mixed>  $instrument
     * @return array<int, string>
     *
     * @throws RequestException|ConnectionException
     */
    private function discoverMaturitiesForInstrument(ImportPayloadWriter $payload, string $tenor, array $instrument, Carbon $referenceDate): array
    {
        $candidates = match ($instrument['maturity_type'] ?? 'year') {
            'month' => $this->monthMaturities($referenceDate),
            'quarter' => $this->quarterMaturities($referenceDate),
            default => $this->yearMaturities($referenceDate),
        };

        return $this->discoverAvailableMaturities($payload, $tenor, $instrument, $candidates);
    }

    /**
     * Probe EEX's price-ticker endpoint to find the contiguous listed maturity range. Every
     * ticker answer is recorded in the payload.
     *
     * Out-of-bounds maturities return HTTP 200 with an empty `data` array or one all-null row, so the
     * first empty maturity after one or more valid maturities is treated as the max.
     * Leading empty maturities are skipped to tolerate recently expired months.
     *
     * @param  array<string, mixed>  $instrument
     * @param  array<int, string>  $candidates
     * @return array<int, string>
     *
     * @throws RequestException|ConnectionException
     */
    private function discoverAvailableMaturities(ImportPayloadWriter $payload, string $tenor, array $instrument, array $candidates): array
    {
        $available = [];
        $foundFirstValid = false;

        foreach ($candidates as $maturity) {
            $unit = ['kind' => 'ticker', 'tenor' => $tenor, 'area' => $instrument['area'] ?? null, 'short_code' => $instrument['short_code'] ?? null, 'maturity' => $maturity];
            try {
                $response = $this->eexFuturesService->fetchPriceTicker($instrument, $maturity);
            } catch (RequestException|ConnectionException $e) {
                $payload->recordFailure(
                    $unit,
                    'GET',
                    (string) config('eex_futures.price_ticker_endpoint'),
                    $this->eexFuturesService->priceTickerQuery($instrument, $maturity),
                    $e,
                );

                throw $e;
            }
            $payload->record($unit, $response);

            if ($this->eexFuturesService->tickerHasData($this->eexFuturesService->decode($response->body()))) {
                $available[] = $maturity;
                $foundFirstValid = true;

                continue;
            }

            if ($foundFirstValid) {
                break;
            }
        }

        return $available;
    }

    /**
     * @return array<int, string>
     */
    private function monthMaturities(Carbon $referenceDate): array
    {
        $monthsBack = max(0, $this->integerOption('months-back', (int) config('eex_futures.months_back', 1)));
        $monthsAhead = max(1, $this->integerOption('months-ahead', (int) config('eex_futures.months_ahead', 7)));
        $firstMonth = $referenceDate->copy()->startOfMonth()->subMonthsNoOverflow($monthsBack);
        $maturityCount = $monthsBack + $monthsAhead;
        $maturities = [];

        for ($offset = 0; $offset < $maturityCount; $offset++) {
            $maturities[] = $firstMonth->copy()->addMonthsNoOverflow($offset)->format('Ym');
        }

        return $maturities;
    }

    /**
     * @return array<int, string>
     */
    private function quarterMaturities(Carbon $referenceDate): array
    {
        $quartersAhead = max(1, $this->integerOption('quarters-ahead', (int) config('eex_futures.quarters_ahead', 8)));
        $firstQuarterStart = $referenceDate->copy()
            ->firstOfQuarter()
            ->addQuarter()
            ->startOfMonth();
        $maturities = [];

        for ($offset = 0; $offset < $quartersAhead; $offset++) {
            $maturities[] = $firstQuarterStart->copy()->addQuarters($offset)->format('Ym');
        }

        return $maturities;
    }

    /**
     * @return array<int, string>
     */
    private function yearMaturities(Carbon $referenceDate): array
    {
        $yearsAhead = max(1, $this->integerOption('years-ahead', (int) config('eex_futures.years_ahead', 6)));
        $firstYear = $referenceDate->year + 1;
        $maturities = [];

        for ($year = $firstYear; $year < $firstYear + $yearsAhead; $year++) {
            $maturities[] = sprintf('%d01', $year);
        }

        return $maturities;
    }

    private function integerOption(string $name, int $default): int
    {
        $value = $this->option($name);

        return $value === null ? $default : (int) $value;
    }

    /**
     * @param  array<int, array<string, mixed>>  $points
     */
    private function savePricePoints(array $points): int
    {
        if (empty($points)) {
            return 0;
        }

        foreach (array_chunk($points, 500) as $chunk) {
            ElectricityFuturesEodPrice::query()->upsert(
                $chunk,
                ['exchange', 'commodity', 'pricing', 'product', 'area', 'short_code', 'maturity', 'trade_date'],
                [
                    'market_region',
                    'area_name',
                    'maturity_type',
                    'display_year',
                    'display_season',
                    'display_quarter',
                    'display_month',
                    'display_week',
                    'display_day',
                    'settlement_price',
                    'volume',
                    'lot_size',
                    'currency',
                    'unit',
                    'long_name',
                    'last_update',
                    'updated_at',
                ]
            );
        }

        return count($points);
    }
}
