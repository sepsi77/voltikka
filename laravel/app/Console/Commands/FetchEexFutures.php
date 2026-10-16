<?php

namespace App\Console\Commands;

use App\Models\DataFreshnessCheckpoint;
use App\Models\ElectricityFuturesEodPrice;
use App\Services\ContractListCacheService;
use App\Services\ElectricityFutures\EexFuturesService;
use App\Services\MorningFreshness\MorningJobFreshnessService;
use App\Support\DataFetchFailureReporter;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Throwable;

class FetchEexFutures extends Command
{
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
        {--dry-run : Fetch and report data without writing to the database.}';

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
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->checkpointDate = null;
        $this->checkpointOwner = null;
        $this->failureReporter = new DataFetchFailureReporter('eex_futures');
        $exit = Command::FAILURE;
        try {
            $exit = $this->fetch();
        } catch (Throwable $exception) {
            $this->failureReporter->fail('unexpected', $exception);
            $this->error('EEX import failed.');
            if ($this->checkpointDate !== null && $this->checkpointOwner !== null) {
                $this->recordFullScopeCheckpoint(true, $this->checkpointDate, ['reason' => 'unexpected']);
            }
        } finally {
            $this->failureReporter->report($exit === Command::FAILURE);
        }

        return $exit;
    }

    private DataFetchFailureReporter $failureReporter;

    private ?string $checkpointDate = null;

    private ?string $checkpointOwner = null;

    private int $totalFetched = 0;

    private int $totalSaved = 0;

    /** @var array<string, string> latest prior-date FI Base trade date per maturity type in this run */
    private array $latestPriorFiTradeDateByTenor = [];

    private function fetch(): int
    {
        $fullScope = $this->isFullScheduledScope();
        $effectiveDate = Carbon::today('Europe/Helsinki')->toDateString();

        if (! $this->recordFullScopeCheckpoint($fullScope, $effectiveDate, [
            'stage' => 'started',
        ])) {
            return Command::FAILURE;
        }

        [$startDate, $endDate] = $this->dateRange();
        $instruments = $this->selectedInstruments();

        if (empty($instruments)) {
            $this->warn('No EEX futures instruments selected.');
            if ($fullScope) {
                $this->failureReporter->fail('no_instruments');
            }
            $this->recordFullScopeCheckpoint($fullScope, $effectiveDate, [
                'reason' => 'no_instruments',
            ]);

            return $fullScope ? Command::FAILURE : Command::SUCCESS;
        }

        $this->totalFetched = 0;
        $this->totalSaved = 0;
        $this->latestPriorFiTradeDateByTenor = [];

        [$requests, $failedDiscoveries] = $this->buildInstrumentMaturityRequests($instruments, $endDate);

        $this->info(sprintf(
            'Fetching EEX futures EOD data for %d instruments, %d discovered instrument/maturity requests, %s to %s.',
            count($instruments),
            count($requests),
            $startDate->toDateString(),
            $endDate->toDateString()
        ));

        $requestCount = count($requests);
        $failedRequests = $this->fetchRequests($requests, $startDate, $endDate, $effectiveDate);
        $failuresBeforeRetry = count($failedDiscoveries) + count($failedRequests);
        $retryPasses = 0;

        // One transient connection failure used to fail the whole day (2026-10-07: month discovery;
        // 2026-10-09: one year request). Retry only the failed units, with the same service
        // throttle, after a pause. A wide failure is an outage: retrying hundreds of units would
        // add hours, so it keeps the original failure outcome.
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

            [$discoveredRequests, $failedDiscoveries] = $this->discoverRequests(
                $instruments,
                array_map(fn (array $failure) => $failure['instrument'], $failedDiscoveries),
                $endDate,
            );
            $requestCount += count($discoveredRequests);
            $failedRequests = $this->fetchRequests(
                [...array_column($failedRequests, 'request'), ...$discoveredRequests],
                $startDate,
                $endDate,
                $effectiveDate,
            );
        }

        // Only failures that remain after the retry passes reach the aggregate Issue.
        foreach ($failedDiscoveries as $failure) {
            $this->failureReporter->fail('discovery', $failure['exception']);
        }
        foreach ($failedRequests as $failure) {
            $this->failureReporter->fail('acquisition', $failure['exception']);
        }
        $failures = count($failedDiscoveries) + count($failedRequests);
        $totalFetched = $this->totalFetched;
        $totalSaved = $this->totalSaved;
        $latestCurrentRunPriorFiTradeDate = $this->latestPriorFiTradeDateByTenor === []
            ? null
            : max($this->latestPriorFiTradeDateByTenor);
        $priorFiTradeDatesByTenor = [];
        foreach (MorningJobFreshnessService::EEX_PROOF_TENORS as $tenor) {
            $priorFiTradeDatesByTenor[$tenor] = $this->latestPriorFiTradeDateByTenor[$tenor] ?? null;
        }
        // The proof needs month, quarter and year rows on the run's latest prior FI trade date,
        // so a run that silently returns no month rows cannot become ready.
        $completeFiProof = $latestCurrentRunPriorFiTradeDate !== null
            && array_filter(
                $priorFiTradeDatesByTenor,
                fn (?string $tradeDate) => $tradeDate !== $latestCurrentRunPriorFiTradeDate,
            ) === [];

        $this->failureReporter->count('maturity_requests', $requestCount);
        $this->failureReporter->count('fetched_points', $totalFetched);
        $this->failureReporter->count('saved_points', $totalSaved);
        $this->failureReporter->count('retry_passes', $retryPasses);
        $this->failureReporter->count('failures_before_retry', $failuresBeforeRetry);
        if ($fullScope && ! $completeFiProof) {
            $this->failureReporter->fail('missing_current_run_prior_fi');
        }

        $this->info("EEX futures fetch complete. Fetched {$totalFetched} price points, upserted {$totalSaved}. Failures: {$failures} ({$failuresBeforeRetry} before {$retryPasses} retry passes).");

        // FI Base futures are a direct input to canonical Spot, market-reset, and supplier-adjusted
        // annual prices. Invalidate every calculated-cost-dependent cache after a real FI write.
        // Downstream company, ranking, and prepared-page keys include this shared list version.
        if (! $this->option('dry-run') && $totalSaved > 0 && $latestCurrentRunPriorFiTradeDate !== null) {
            $this->contractListCache->bumpVersion();
        }

        if (! $fullScope) {
            return $failures > 0 ? Command::FAILURE : Command::SUCCESS;
        }

        $ready = $failures === 0
            && $totalFetched > 0
            && $completeFiProof;
        $recorded = $this->recordFullScopeCheckpoint($fullScope, $effectiveDate, [
            'fetched_points' => $totalFetched,
            'saved_points' => $totalSaved,
            'failures' => $failures,
            'failures_before_retry' => $failuresBeforeRetry,
            'retry_passes' => $retryPasses,
            'current_run_latest_prior_fi_trade_date' => $latestCurrentRunPriorFiTradeDate,
            'current_run_latest_prior_fi_trade_dates_by_tenor' => $priorFiTradeDatesByTenor,
        ], $ready);

        return $ready && $recorded ? Command::SUCCESS : Command::FAILURE;
    }

    /**
     * Fetch, extract and upsert each request. Returns the failed requests for a retry pass.
     *
     * @param  array<int, array{instrument: array<string, mixed>, maturity: string}>  $requests
     * @return list<array{request: array{instrument: array<string, mixed>, maturity: string}, exception: Throwable}>
     */
    private function fetchRequests(array $requests, Carbon $startDate, Carbon $endDate, string $effectiveDate): array
    {
        $failed = [];

        foreach ($requests as $request) {
            $instrument = $request['instrument'];
            $maturity = $request['maturity'];

            try {
                $payload = $this->eexFuturesService->fetchEndOfDayData($instrument, $maturity, $startDate, $endDate);
                $points = $this->eexFuturesService->extractPricePoints($payload, $instrument, $maturity);
                $this->totalFetched += count($points);

                foreach ($points as $point) {
                    $tradeDate = $point['trade_date'] ?? null;
                    $tenor = (string) ($point['maturity_type'] ?? '');

                    if (($point['exchange'] ?? null) === 'EEX'
                        && ($point['area'] ?? null) === 'FI'
                        && ($point['product'] ?? null) === 'Base'
                        && is_string($tradeDate)
                        && $tradeDate < $effectiveDate
                        && $tradeDate > ($this->latestPriorFiTradeDateByTenor[$tenor] ?? '')) {
                        $this->latestPriorFiTradeDateByTenor[$tenor] = $tradeDate;
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
                $this->totalSaved += $saved;

                $this->line(sprintf(
                    '%s %s %s %s: fetched %d, upserted %d',
                    $instrument['area'],
                    $instrument['maturity_type'] ?? 'unknown-tenor',
                    $instrument['short_code'],
                    $maturity,
                    count($points),
                    $saved
                ));
            } catch (RequestException|ConnectionException $e) {
                $failed[] = ['request' => $request, 'exception' => $e];
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

    /** @param array<string, mixed> $metadata */
    private function recordFullScopeCheckpoint(
        bool $fullScope,
        string $date,
        array $metadata,
        bool $ready = false,
    ): bool {
        if (! $fullScope) {
            return true;
        }

        try {
            $started = ($metadata['stage'] ?? null) === 'started';
            if ($started) {
                $this->checkpointDate = $date;
                $this->checkpointOwner = (string) Str::uuid();
            }

            return DB::transaction(function () use ($date, $metadata, $ready, $started): bool {
                if ($started && ! DataFreshnessCheckpoint::query()->where('key', DataFreshnessCheckpoint::KEY_EEX_FUTURES)->whereDate('effective_date', $date)->exists()) {
                    DataFreshnessCheckpoint::query()->insertOrIgnore([
                        'key' => DataFreshnessCheckpoint::KEY_EEX_FUTURES, 'effective_date' => $date,
                        'status' => DataFreshnessCheckpoint::STATUS_RUNNING,
                        'metadata' => json_encode(['run_uuid' => $this->checkpointOwner, 'stage' => 'started']),
                        'recorded_at' => now(), 'created_at' => now(), 'updated_at' => now(),
                    ]);
                }
                $row = DataFreshnessCheckpoint::query()->where('key', DataFreshnessCheckpoint::KEY_EEX_FUTURES)
                    ->whereDate('effective_date', $date)->lockForUpdate()->firstOrFail();
                if (! $started && ($row->metadata['run_uuid'] ?? null) !== $this->checkpointOwner) {
                    $this->failureReporter->fail('checkpoint');

                    return false;
                }
                $this->freshness->record(
                    DataFreshnessCheckpoint::KEY_EEX_FUTURES,
                    $date,
                    $started ? DataFreshnessCheckpoint::STATUS_RUNNING : ($ready ? DataFreshnessCheckpoint::STATUS_READY : DataFreshnessCheckpoint::STATUS_FAILED),
                    ['run_uuid' => $this->checkpointOwner] + $metadata,
                );

                return true;
            });
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
     * @param  array<int, array<string, mixed>>  $instruments
     * @return array{0: array<int, array{instrument: array<string, mixed>, maturity: string}>, 1: array<string, array{instrument: array<string, mixed>, exception: Throwable}>}
     */
    private function buildInstrumentMaturityRequests(array $instruments, Carbon $referenceDate): array
    {
        $requests = [];
        $explicitMaturities = array_values(array_filter((array) $this->option('maturity')));

        if (! empty($explicitMaturities)) {
            $maturities = array_values(array_unique(array_map('strval', $explicitMaturities)));

            foreach ($instruments as $instrument) {
                foreach ($maturities as $maturity) {
                    $requests[] = ['instrument' => $instrument, 'maturity' => $maturity];
                }
            }

            return [$requests, []];
        }

        $representativeByTenor = [];

        foreach ($instruments as $instrument) {
            $tenor = strtolower((string) ($instrument['maturity_type'] ?? 'year'));
            $representativeByTenor[$tenor] ??= $instrument;
        }

        return $this->discoverRequests($instruments, $representativeByTenor, $referenceDate);
    }

    /**
     * Discover maturities once per tenor and build the requests for every instrument of that tenor.
     * A failed tenor is returned with its representative so a retry pass can discover it again.
     *
     * @param  array<int, array<string, mixed>>  $instruments
     * @param  array<string, array<string, mixed>>  $representativeByTenor
     * @return array{0: array<int, array{instrument: array<string, mixed>, maturity: string}>, 1: array<string, array{instrument: array<string, mixed>, exception: Throwable}>}
     */
    private function discoverRequests(array $instruments, array $representativeByTenor, Carbon $referenceDate): array
    {
        $requests = [];
        $maturitiesByTenor = [];
        $failed = [];

        foreach ($representativeByTenor as $tenor => $representativeInstrument) {
            try {
                $maturitiesByTenor[$tenor] = $this->discoverMaturitiesForInstrument($representativeInstrument, $referenceDate);
                $this->line(sprintf(
                    'Discovered %d %s maturities using %s %s as representative.',
                    count($maturitiesByTenor[$tenor]),
                    $tenor,
                    $representativeInstrument['area'] ?? 'unknown-area',
                    $representativeInstrument['short_code'] ?? 'unknown-code'
                ));
            } catch (RequestException|ConnectionException $e) {
                $failed[$tenor] = ['instrument' => $representativeInstrument, 'exception' => $e];
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

            foreach (($maturitiesByTenor[$tenor] ?? []) as $maturity) {
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
    private function discoverMaturitiesForInstrument(array $instrument, Carbon $referenceDate): array
    {
        $candidates = match ($instrument['maturity_type'] ?? 'year') {
            'month' => $this->monthMaturities($referenceDate),
            'quarter' => $this->quarterMaturities($referenceDate),
            default => $this->yearMaturities($referenceDate),
        };

        return $this->discoverAvailableMaturities($instrument, $candidates);
    }

    /**
     * Probe EEX's price-ticker endpoint to find the contiguous listed maturity range.
     *
     * Out-of-bounds maturities return HTTP 200 with an empty `data` array, so the
     * first empty maturity after one or more valid maturities is treated as the max.
     * Leading empty maturities are skipped to tolerate recently expired months.
     *
     * @param  array<string, mixed>  $instrument
     * @param  array<int, string>  $candidates
     * @return array<int, string>
     *
     * @throws RequestException|ConnectionException
     */
    private function discoverAvailableMaturities(array $instrument, array $candidates): array
    {
        $available = [];
        $foundFirstValid = false;

        foreach ($candidates as $maturity) {
            if ($this->eexFuturesService->maturityHasTickerData($instrument, $maturity)) {
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
