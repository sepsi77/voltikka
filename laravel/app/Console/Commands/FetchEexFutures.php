<?php

namespace App\Console\Commands;

use App\Models\DataFreshnessCheckpoint;
use App\Models\ElectricityFuturesEodPrice;
use App\Services\ElectricityFutures\EexFuturesService;
use App\Services\MorningFreshness\MorningJobFreshnessService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Log;
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
    ) {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
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
            $this->recordFullScopeCheckpoint($fullScope, $effectiveDate, [
                'reason' => 'no_instruments',
            ]);

            return $fullScope ? Command::FAILURE : Command::SUCCESS;
        }

        $failures = 0;
        $requests = $this->buildInstrumentMaturityRequests($instruments, $endDate, $failures);

        $this->info(sprintf(
            'Fetching EEX futures EOD data for %d instruments, %d discovered instrument/maturity requests, %s to %s.',
            count($instruments),
            count($requests),
            $startDate->toDateString(),
            $endDate->toDateString()
        ));

        $totalFetched = 0;
        $totalSaved = 0;
        $latestCurrentRunPriorFiTradeDate = null;

        foreach ($requests as $request) {
            $instrument = $request['instrument'];
            $maturity = $request['maturity'];

            try {
                $payload = $this->eexFuturesService->fetchEndOfDayData($instrument, $maturity, $startDate, $endDate);
                $points = $this->eexFuturesService->extractPricePoints($payload, $instrument, $maturity);
                $totalFetched += count($points);

                foreach ($points as $point) {
                    $tradeDate = $point['trade_date'] ?? null;

                    if (($point['exchange'] ?? null) === 'EEX'
                        && ($point['area'] ?? null) === 'FI'
                        && ($point['product'] ?? null) === 'Base'
                        && is_string($tradeDate)
                        && $tradeDate < $effectiveDate
                        && ($latestCurrentRunPriorFiTradeDate === null || $tradeDate > $latestCurrentRunPriorFiTradeDate)) {
                        $latestCurrentRunPriorFiTradeDate = $tradeDate;
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
            } catch (RequestException|ConnectionException $e) {
                $failures++;
                $this->warn(sprintf(
                    'Failed to fetch %s %s %s %s: %s',
                    $instrument['area'] ?? 'unknown-area',
                    $instrument['maturity_type'] ?? 'unknown-tenor',
                    $instrument['short_code'] ?? 'unknown-code',
                    $maturity,
                    $e->getMessage()
                ));
                Log::warning('EEX futures fetch failed for instrument maturity', [
                    'area' => $instrument['area'] ?? null,
                    'maturity_type' => $instrument['maturity_type'] ?? null,
                    'short_code' => $instrument['short_code'] ?? null,
                    'maturity' => $maturity,
                    'exception_class' => $e::class,
                    'exception' => $e->getMessage(),
                ]);
            }
        }

        $this->info("EEX futures fetch complete. Fetched {$totalFetched} price points, upserted {$totalSaved}. Failures: {$failures}.");

        if (! $fullScope) {
            return $failures > 0 ? Command::FAILURE : Command::SUCCESS;
        }

        $ready = $failures === 0
            && $totalFetched > 0
            && $latestCurrentRunPriorFiTradeDate !== null;
        $recorded = $this->recordFullScopeCheckpoint($fullScope, $effectiveDate, [
            'fetched_points' => $totalFetched,
            'saved_points' => $totalSaved,
            'failures' => $failures,
            'current_run_latest_prior_fi_trade_date' => $latestCurrentRunPriorFiTradeDate,
        ], $ready);

        return $ready && $recorded ? Command::SUCCESS : Command::FAILURE;
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
            $this->freshness->record(
                DataFreshnessCheckpoint::KEY_EEX_FUTURES,
                $date,
                $ready ? DataFreshnessCheckpoint::STATUS_READY : DataFreshnessCheckpoint::STATUS_FAILED,
                $metadata,
            );

            return true;
        } catch (Throwable $exception) {
            $this->error('Failed to record the EEX freshness checkpoint.');
            Log::error('FetchEexFutures freshness checkpoint failed', [
                'exception_class' => $exception::class,
            ]);

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
     * @return array<int, array{instrument: array<string, mixed>, maturity: string}>
     */
    private function buildInstrumentMaturityRequests(array $instruments, Carbon $referenceDate, int &$failures): array
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

            return $requests;
        }

        $maturitiesByTenor = [];
        $representativeByTenor = [];

        foreach ($instruments as $instrument) {
            $tenor = strtolower((string) ($instrument['maturity_type'] ?? 'year'));
            $representativeByTenor[$tenor] ??= $instrument;
        }

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
                $failures++;
                $this->warn(sprintf(
                    'Failed to discover %s maturities using %s %s: %s',
                    $tenor,
                    $representativeInstrument['area'] ?? 'unknown-area',
                    $representativeInstrument['short_code'] ?? 'unknown-code',
                    $e->getMessage()
                ));
                Log::warning('EEX futures maturity discovery failed', [
                    'area' => $representativeInstrument['area'] ?? null,
                    'maturity_type' => $tenor,
                    'short_code' => $representativeInstrument['short_code'] ?? null,
                    'exception_class' => $e::class,
                    'exception' => $e->getMessage(),
                ]);
            }
        }

        foreach ($instruments as $instrument) {
            $tenor = strtolower((string) ($instrument['maturity_type'] ?? 'year'));

            foreach (($maturitiesByTenor[$tenor] ?? []) as $maturity) {
                $requests[] = ['instrument' => $instrument, 'maturity' => $maturity];
            }
        }

        return $requests;
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
