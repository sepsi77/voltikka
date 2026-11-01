<?php

namespace App\Console\Commands;

use App\Models\ElectricityFuturesEodPrice;
use App\Services\ElectricityFutures\EexFuturesService;
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
 * discovery. About 21 throttled requests (about 5 minutes). See
 * app/Services/ElectricityFutures/AGENTS.md.
 */
class FetchEexEuropeFutures extends Command
{
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

            return Command::FAILURE;
        }

        // Pricing readers select rows by area (FI) and product (Base). A Nordic area here could
        // mix review rows into Finnish pricing, so the command refuses it.
        $nordicAreas = array_map(fn (array $instrument) => $instrument['area'], (array) config('eex_futures.instruments', []));
        foreach ($instruments as $instrument) {
            if (in_array($instrument['area'], $nordicAreas, true)) {
                $this->error("Europe instrument {$instrument['short_code']} uses Nordic area {$instrument['area']}.");

                return Command::FAILURE;
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

            return Command::FAILURE;
        }

        $windowDays = max(1, (int) config('eex_futures.history_window_days', 45));
        $earliestStart = $endDate->subDays($windowDays - 1);
        if ($startDate === null || $startDate->lessThan($earliestStart)) {
            $startDate = $earliestStart;
        }

        $failed = 0;
        $saved = 0;

        foreach ($instruments as $instrument) {
            if ($requested !== [] && ! in_array(strtoupper((string) $instrument['short_code']), $requested, true)) {
                continue;
            }

            foreach ($this->maturities($instrument, $endDate) as $maturity) {
                try {
                    $payload = $this->eex->fetchEndOfDayData($instrument, $maturity, $startDate, $endDate);
                    $points = $this->eex->extractPricePoints($payload, $instrument, $maturity);
                    $this->save($points);
                    $saved += count($points);
                    $this->line("{$instrument['short_code']} {$maturity}: ".count($points).' settlements.');
                } catch (Throwable $e) {
                    $failed++;
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

        $this->info("EEX Europe futures: {$saved} settlements upserted, {$failed} failed requests ({$startDate->toDateString()} to {$endDate->toDateString()}).");

        return $failed === 0 ? Command::SUCCESS : Command::FAILURE;
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
