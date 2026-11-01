<?php

namespace App\Console\Commands;

use App\Services\EntsoeService;
use App\Services\SpotPriceAverageService;
use App\Services\SpotPriceImport\SpotPriceHistoryRepair;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Repair stored official spot history from a fresh ENTSO-E fetch (dry run unless --apply).
 *
 * One Europe/Helsinki calendar month per request. Missing quarter/hour rows are inserted and
 * differing prices are updated; nothing is deleted. After an apply on FI, the daily, monthly
 * and yearly spot averages are recalculated. Past rolling 30/365-day rows are NOT recalculated:
 * stored contract statistics used them as dated evidence. See SpotPriceImport/AGENTS.md.
 */
class RepairSpotHistory extends Command
{
    protected $signature = 'spot:repair-history
                            {--from= : First Helsinki month (YYYY-MM)}
                            {--to= : Last Helsinki month, inclusive (YYYY-MM)}
                            {--region=FI : FI or a key of services.entsoe.bidding_zones}
                            {--apply : Write the repair (default: dry run)}';

    protected $description = 'Compare stored spot history with ENTSO-E and repair missing or wrong rows';

    private const API_DELAY_MS = 300;

    public function __construct(
        private readonly EntsoeService $entsoeService,
        private readonly SpotPriceHistoryRepair $repair,
        private readonly SpotPriceAverageService $averageService
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $region = (string) $this->option('region');
        $apply = (bool) $this->option('apply');

        if ($region !== 'FI' && ! is_string(config("services.entsoe.bidding_zones.{$region}"))) {
            $this->error("Unknown region: {$region}");

            return Command::FAILURE;
        }

        try {
            $first = Carbon::createFromFormat('!Y-m', (string) $this->option('from'), 'Europe/Helsinki');
            $last = Carbon::createFromFormat('!Y-m', (string) $this->option('to'), 'Europe/Helsinki');
        } catch (Throwable) {
            $this->error('Give --from and --to as YYYY-MM.');

            return Command::FAILURE;
        }

        if ($first === false || $last === false || $first->greaterThan($last)) {
            $this->error('Give --from and --to as YYYY-MM, with --from not after --to.');

            return Command::FAILURE;
        }

        if ($last->greaterThan(Carbon::now('Europe/Helsinki')->startOfMonth())) {
            $this->error('--to cannot be after the current month.');

            return Command::FAILURE;
        }

        $this->info(($apply ? 'APPLY' : 'DRY RUN').": {$region} spot history {$first->format('Y-m')}..{$last->format('Y-m')}");
        $this->line('month    | hours stored/fresh | missing h | changed h | max Δ c/kWh | missing q | changed q | stored-only h | mean VAT0 before → after');

        $totals = ['missing_hours' => 0, 'changed_hours' => 0, 'missing_quarters' => 0, 'changed_quarters' => 0];
        $failed = 0;
        $month = $first->copy();

        while ($month->lessThanOrEqualTo($last)) {
            $start = $month->copy()->utc();
            $end = $month->copy()->addMonthNoOverflow()->utc();

            try {
                $prices = $this->entsoeService->fetchDayAheadPrices($start, $end, $region);
                $result = $this->repair->repair($region, $start, $end, $prices, $apply);
                $this->printMonth($month, $result);

                $totals['missing_hours'] += $result['hours']['missing'];
                $totals['changed_hours'] += $result['hours']['changed'];
                $totals['missing_quarters'] += $result['quarters']['missing'];
                $totals['changed_quarters'] += $result['quarters']['changed'];
            } catch (RequestException|ConnectionException $e) {
                $failed++;
                // The exception message can contain the securityToken; log only the class.
                Log::error('Spot history repair fetch failed', [
                    'region' => $region,
                    'month' => $month->format('Y-m'),
                    'exception_class' => $e::class,
                ]);
                $this->error("{$month->format('Y-m')}: ENTSO-E fetch failed (".$e::class.').');
            }

            $month->addMonthNoOverflow();
            usleep(self::API_DELAY_MS * 1000);
        }

        $this->newLine();
        $this->info(sprintf(
            'Total: %d missing hours, %d changed hours, %d missing quarters, %d changed quarters%s.',
            $totals['missing_hours'],
            $totals['changed_hours'],
            $totals['missing_quarters'],
            $totals['changed_quarters'],
            $failed > 0 ? ", {$failed} failed months" : ''
        ));

        $changedAnything = array_sum($totals) > 0;
        if ($apply && $region === 'FI' && $changedAnything) {
            $this->info('Recalculating daily, monthly and yearly spot averages...');
            $this->averageService->calculateAllAverages('FI');
        }

        if (! $apply) {
            $this->warn('Dry run: nothing was written. Use --apply to write.');
        }

        return $failed > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function printMonth(Carbon $month, array $result): void
    {
        $format = fn (?float $value) => $value === null ? '-' : number_format($value, 4, '.', '');

        $this->line(sprintf(
            '%s  | %5d/%5d        | %9d | %9d | %11s | %9d | %9d | %13d | %s → %s',
            $month->format('Y-m'),
            $result['hours']['stored'],
            $result['hours']['fresh'],
            $result['hours']['missing'],
            $result['hours']['changed'],
            number_format($result['hours']['max_abs_change'], 4, '.', ''),
            $result['quarters']['missing'],
            $result['quarters']['changed'],
            $result['hours']['stored_only'],
            $format($result['mean_before']),
            $format($result['mean_after'])
        ));
    }
}
