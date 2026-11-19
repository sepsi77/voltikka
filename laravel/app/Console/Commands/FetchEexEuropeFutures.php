<?php

namespace App\Console\Commands;

use App\Console\Concerns\StagedImport;
use App\Models\ElectricityFuturesEodPrice;
use App\Services\ElectricityFutures\EexFuturesService;
use App\Services\ImportPayload\ImportPayloadInvalid;
use App\Services\ImportPayload\ImportPayloadReader;
use App\Services\ImportPayload\ImportPayloadWriter;
use App\Services\ImportPayload\ImportStoreResult;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Throwable;

/**
 * Collect continental reference futures (Phelix DE power, TTF gas, EUA carbon) from the public
 * EEX chart endpoint for the monthly market review.
 *
 * Separate from `futures:fetch-eex` on purpose: it never writes the EEX freshness checkpoint,
 * never bumps the contract pricing caches, and uses explicit maturities instead of price-ticker
 * discovery. About 21 throttled requests (about 5 minutes). Staged: the fetch saves every answer in
 * a payload file, the store parses it in one transaction (StagedImport). See
 * app/Services/ElectricityFutures/AGENTS.md.
 */
class FetchEexEuropeFutures extends Command
{
    use StagedImport;

    protected $signature = 'futures:fetch-eex-europe
        {--start-date= : First trade date (YYYY-MM-DD). Capped to the EEX history window.}
        {--end-date= : Last trade date (YYYY-MM-DD). Defaults to today (Europe/Helsinki).}
        {--short-code=* : Only these configured short codes, for example DEBM or G3BM.}';

    protected $description = 'Fetch EEX German power, TTF gas and EUA futures settlements for market reviews';

    public function __construct(private readonly EexFuturesService $eex)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $instruments = (array) config('eex_futures.europe_instruments', []);
        $requested = array_map('strtoupper', array_filter((array) $this->option('short-code')));
        $configuredCodes = array_map(fn (array $instrument) => strtoupper((string) $instrument['short_code']), $instruments);
        $unknown = array_diff($requested, $configuredCodes);

        if ($unknown !== []) {
            $this->error('Unknown short code(s): '.implode(', ', $unknown));

            return $this->stagedFailure('fetch', 'invalid_options');
        }

        // Pricing readers select rows by area (FI) and product (Base). A Nordic area here could
        // mix review rows into Finnish pricing, so the command refuses it.
        $nordicAreas = array_map(fn (array $instrument) => $instrument['area'], (array) config('eex_futures.instruments', []));
        foreach ($instruments as $instrument) {
            if (in_array($instrument['area'], $nordicAreas, true)) {
                $this->error("Europe instrument {$instrument['short_code']} uses Nordic area {$instrument['area']}.");

                return $this->stagedFailure('fetch', 'invalid_config');
            }
        }

        try {
            // Only the Y-m-d text and month arithmetic are used, so the object timezone is irrelevant.
            $endDate = CarbonImmutable::createFromFormat('!Y-m-d', (string) ($this->option('end-date')
                ?? CarbonImmutable::now('Europe/Helsinki')->toDateString()));
            $startDate = $this->option('start-date') !== null
                ? CarbonImmutable::createFromFormat('!Y-m-d', (string) $this->option('start-date'))
                : null;
        } catch (Throwable) {
            $this->error('Invalid date. Use YYYY-MM-DD.');

            return $this->stagedFailure('fetch', 'invalid_options');
        }

        $windowDays = max(1, (int) config('eex_futures.history_window_days', 45));
        $earliestStart = $endDate->subDays($windowDays - 1);
        if ($startDate === null || $startDate->lessThan($earliestStart)) {
            $startDate = $earliestStart;
        }

        $selected = array_values(array_filter(
            $instruments,
            fn (array $instrument) => $requested === [] || in_array(strtoupper((string) $instrument['short_code']), $requested, true),
        ));

        return $this->runStaged(
            fn (ImportPayloadWriter $payload) => $this->fetchStep($payload, $selected, $startDate, $endDate),
            fn (ImportPayloadReader $payload): ImportStoreResult => $this->storeStep($payload),
        );
    }

    protected function stagedImportSource(): string
    {
        return 'eex-europe';
    }

    /**
     * Every request is recorded, answered or failed; a failed request does not stop the others.
     *
     * @param  list<array<string, mixed>>  $instruments
     */
    private function fetchStep(ImportPayloadWriter $payload, array $instruments, CarbonImmutable $startDate, CarbonImmutable $endDate): void
    {
        // S5/S9: the store step takes the date and window from the header, never from the clock.
        $payload->setFact('effective_date', $payload->fetchedAt()->setTimezone('Europe/Helsinki')->toDateString());
        $payload->setFact('start_date', $startDate->toDateString());
        $payload->setFact('end_date', $endDate->toDateString());
        $payload->setFact('instruments', $instruments);

        foreach ($instruments as $instrument) {
            foreach ($this->maturities($instrument, $endDate) as $maturity) {
                $unit = ['short_code' => $instrument['short_code'], 'maturity' => $maturity];
                try {
                    $payload->record($unit, $this->eex->fetchEndOfDay($instrument, $maturity, $startDate, $endDate));
                } catch (Throwable $e) {
                    $payload->recordFailure($unit, 'GET', (string) config('eex_futures.endpoint'), $this->eex->endOfDayQuery($instrument, $maturity, $startDate, $endDate), $e);
                    // An HTTP exception message can contain the response body; log only the class.
                    logger()->error('EEX Europe futures fetch failed', [
                        'short_code' => $instrument['short_code'],
                        'maturity' => $maturity,
                        'exception_class' => $e::class,
                    ]);
                    $this->error("{$instrument['short_code']} {$maturity}: fetch failed (".$e::class.').');
                }
            }
        }
    }

    /** Runs inside the StagedImport store transaction; rows of answered requests stay after a partial fetch. */
    private function storeStep(ImportPayloadReader $payload): ImportStoreResult
    {
        if ($payload->fact('effective_date') !== CarbonImmutable::now('Europe/Helsinki')->toDateString()) {
            throw ImportPayloadInvalid::because('wrong_date');
        }

        $instruments = [];
        foreach ((array) $payload->fact('instruments', []) as $instrument) {
            $instruments[strtoupper((string) $instrument['short_code'])] = $instrument;
        }

        $failed = 0;
        $saved = 0;
        foreach ($payload->requests() as $line) {
            $status = $line['status'] ?? null;
            $instrument = $instruments[strtoupper((string) ($line['unit']['short_code'] ?? ''))] ?? null;
            if (! is_int($status) || $status < 200 || $status >= 300 || $instrument === null) {
                $failed++;

                continue;
            }

            $maturity = (string) $line['unit']['maturity'];
            $points = $this->eex->extractPricePoints($this->eex->decode(ImportPayloadReader::body($line)), $instrument, $maturity);
            $this->save($points);
            $saved += count($points);
            $this->line("{$instrument['short_code']} {$maturity}: ".count($points).' settlements.');
        }

        $this->info("EEX Europe futures: {$saved} settlements upserted, {$failed} failed requests ({$payload->fact('start_date')} to {$payload->fact('end_date')}).");

        return new ImportStoreResult(
            ['saved_points' => $saved, 'failures' => $failed],
            [],
            $failed === 0 ? null : 'fetch_incomplete',
            'fetch',
        );
    }

    /**
     * @param  array<string, mixed>  $instrument
     * @return list<string> EEX maturity values (YYYYMM)
     */
    public function maturities(array $instrument, CarbonImmutable $referenceDate): array
    {
        $count = max(1, (int) ($instrument['count'] ?? 1));
        $month = $referenceDate->startOfMonth();
        $rule = (string) ($instrument['maturity_rule'] ?? $instrument['maturity_type'] ?? '');

        $first = match ($rule) {
            'month' => $month,
            'quarter' => $month->firstOfQuarter()->addMonthsNoOverflow(3),
            'year' => $month->setDate($month->year + 1, 1, 1),
            'december' => $month->setDate($month->year, 12, 1),
            default => throw new InvalidArgumentException("Unknown maturity rule {$rule}."),
        };
        $stepMonths = match ($rule) {
            'month' => 1,
            'quarter' => 3,
            default => 12,
        };

        $maturities = [];
        for ($i = 0; $i < $count; $i++) {
            $maturities[] = $first->addMonthsNoOverflow($i * $stepMonths)->format('Ym');
        }

        return $maturities;
    }

    /**
     * @param  array<int, array<string, mixed>>  $points
     */
    private function save(array $points): void
    {
        foreach (array_chunk($points, 500) as $chunk) {
            ElectricityFuturesEodPrice::query()->upsert(
                $chunk,
                ['exchange', 'commodity', 'pricing', 'product', 'area', 'short_code', 'maturity', 'trade_date'],
                [
                    'market_region', 'area_name', 'maturity_type', 'display_year', 'display_season',
                    'display_quarter', 'display_month', 'display_week', 'display_day', 'settlement_price',
                    'volume', 'lot_size', 'currency', 'unit', 'long_name', 'last_update', 'updated_at',
                ]
            );
        }
    }
}
