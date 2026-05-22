<?php

namespace App\Console\Commands;

use App\Models\ElectricityFuturesEodPrice;
use App\Services\ElectricityFutures\EexFuturesService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Log;

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

    public function __construct(private readonly EexFuturesService $eexFuturesService)
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        [$startDate, $endDate] = $this->dateRange();
        $instruments = $this->selectedInstruments();

        if (empty($instruments)) {
            $this->warn('No EEX futures instruments selected.');
            return Command::SUCCESS;
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

        foreach ($requests as $request) {
            $instrument = $request['instrument'];
            $maturity = $request['maturity'];

            try {
                $payload = $this->eexFuturesService->fetchEndOfDayData($instrument, $maturity, $startDate, $endDate);
                $points = $this->eexFuturesService->extractPricePoints($payload, $instrument, $maturity);
                $totalFetched += count($points);

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

        return $failures > 0 ? Command::FAILURE : Command::SUCCESS;
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
     * @param array<int, array<string, mixed>> $instruments
     * @return array<int, array{instrument: array<string, mixed>, maturity: string}>
     */
    private function buildInstrumentMaturityRequests(array $instruments, Carbon $referenceDate, int &$failures): array
    {
        $requests = [];

        foreach ($instruments as $instrument) {
            try {
                $maturities = $this->selectedMaturitiesForInstrument($instrument, $referenceDate);
            } catch (RequestException|ConnectionException $e) {
                $failures++;
                $this->warn(sprintf(
                    'Failed to discover maturities for %s %s %s: %s',
                    $instrument['area'] ?? 'unknown-area',
                    $instrument['maturity_type'] ?? 'unknown-tenor',
                    $instrument['short_code'] ?? 'unknown-code',
                    $e->getMessage()
                ));
                Log::warning('EEX futures maturity discovery failed', [
                    'area' => $instrument['area'] ?? null,
                    'maturity_type' => $instrument['maturity_type'] ?? null,
                    'short_code' => $instrument['short_code'] ?? null,
                    'exception_class' => $e::class,
                    'exception' => $e->getMessage(),
                ]);
                continue;
            }

            foreach ($maturities as $maturity) {
                $requests[] = ['instrument' => $instrument, 'maturity' => $maturity];
            }
        }

        return $requests;
    }

    /**
     * @param array<string, mixed> $instrument
     * @return array<int, string>
     * @throws RequestException|ConnectionException
     */
    private function selectedMaturitiesForInstrument(array $instrument, Carbon $referenceDate): array
    {
        $explicitMaturities = array_values(array_filter((array) $this->option('maturity')));

        if (!empty($explicitMaturities)) {
            return array_values(array_unique(array_map('strval', $explicitMaturities)));
        }

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
     * @param array<string, mixed> $instrument
     * @param array<int, string> $candidates
     * @return array<int, string>
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
     * @param array<int, array<string, mixed>> $points
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
