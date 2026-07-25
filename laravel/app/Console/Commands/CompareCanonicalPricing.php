<?php

namespace App\Console\Commands;

use App\Models\ElectricityContract;
use App\Models\SpotPriceAverage;
use App\Services\CanonicalPricing\CanonicalContractPriceCalculator;
use App\Services\CanonicalPricing\CanonicalContractPricingService;
use App\Services\CanonicalPricing\MarketReset\DTO\ResetEstimatorSettings;
use App\Services\CanonicalPricing\MarketReset\MarketReferenceCurveProvider;
use App\Services\CanonicalPricing\MarketReset\MarketResetPriceEstimator;
use App\Services\ContractPriceCalculator;
use App\Services\DTO\EnergyUsage;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Diffs the legacy relational price calculation against the canonical phase-aware
 * calculation for every active contract, so the staged rollout can be reviewed before
 * enabling CANONICAL_PRICING_ENABLED. Read-only; makes no changes.
 *
 * `--resets` switches to the market-reset review table: hold-flat versus the shape-only
 * forward-curve shift for every active recurring-reset lineage, regardless of how
 * RESET_FORWARD_SHIFT_ENABLED is currently set. Run it before flipping that flag.
 */
class CompareCanonicalPricing extends Command
{
    protected $signature = 'contracts:compare-canonical-pricing
        {--consumption=5000 : Annual kWh used for both calculations}
        {--start-date= : Signup date for the 12-month window (default today)}
        {--json= : Write the full per-contract diff to this path}
        {--resets : Diff hold-flat vs the market-reset forward-curve shift instead of legacy vs canonical}
        {--fail-on-parse-errors : Exit non-zero if any active contract fails to parse}';

    protected $description = 'Compare legacy and canonical contract price calculations across active contracts.';

    public function handle(
        CanonicalContractPricingService $canonical,
        ContractPriceCalculator $legacy,
    ): int {
        $consumption = (int) $this->option('consumption');
        $startDate = $this->option('start-date')
            ? CarbonImmutable::parse($this->option('start-date'), 'Europe/Helsinki')
            : CarbonImmutable::now('Europe/Helsinki');

        $usage = new EnergyUsage(total: $consumption, basicLiving: $consumption);

        if ($this->option('resets')) {
            return $this->compareResets($usage, $consumption, $startDate);
        }

        $spotAvg = SpotPriceAverage::latestRolling365Days();
        $spotDay = $spotAvg?->day_avg_with_tax;
        $spotNight = $spotAvg?->night_avg_with_tax;

        $contracts = ElectricityContract::query()->active()->get();
        $componentsByContract = ElectricityContract::getLatestPriceComponentsForCalculationByContractIds($contracts->pluck('id'));

        $rows = [];
        $comparabilityCounts = [];
        $labeled = [];
        $excluded = [];
        $parseErrors = [];

        foreach ($contracts as $contract) {
            $evaluation = $canonical->evaluate($contract, $usage, null, $startDate);
            $outcome = $evaluation['outcome'];
            $integrity = $evaluation['integrity'];

            $legacyTotal = null;
            $components = $componentsByContract[$contract->id] ?? [];
            if ($components !== []) {
                $legacyResult = $legacy->calculate(
                    $components,
                    ['contract_type' => $contract->contract_type, 'pricing_model' => $contract->pricing_model, 'metering' => $contract->metering],
                    $usage,
                    $spotDay,
                    $spotNight,
                    $startDate,
                );
                $legacyTotal = $legacyResult->totalCost;
            }

            $comparability = $outcome->comparability->value;
            $comparabilityCounts[$comparability] = ($comparabilityCounts[$comparability] ?? 0) + 1;

            $delta = ($legacyTotal !== null && $outcome->totalCost !== null)
                ? $outcome->totalCost - $legacyTotal
                : null;

            $rows[] = [
                'id' => $contract->id,
                'name' => $contract->name,
                'legacy_total' => $legacyTotal !== null ? round($legacyTotal, 2) : null,
                'canonical_total' => $outcome->totalCost !== null ? round($outcome->totalCost, 2) : null,
                'delta' => $delta !== null ? round($delta, 2) : null,
                'comparability' => $comparability,
                'estimate_method' => $outcome->estimateMethod->value,
                'integrity' => $integrity->reasonFamily->value,
                'card_label' => $integrity->cardLabel,
            ];

            if ($outcome->comparability === \App\Services\CanonicalPricing\Enums\ContractComparability::ExcludedIncomplete
                || $outcome->comparability === \App\Services\CanonicalPricing\Enums\ContractComparability::ExcludedUnknownFuture) {
                $excluded[] = "{$contract->id} — {$contract->name} ({$comparability})";
            }

            if ($integrity->detected) {
                $labeled[] = "{$contract->id} — {$contract->name} [{$integrity->reasonFamily->value}] ".($integrity->cardLabel ?? '(detail-only)');
            }

            if ($outcome->totalCost === null && $components !== [] && $comparability === 'excluded_incomplete' && $contract->canonical_pricing === null) {
                $parseErrors[] = $contract->id;
            }
        }

        $this->info("Compared {$contracts->count()} active contracts at {$consumption} kWh, window from {$startDate->toDateString()}.");
        $this->newLine();

        $this->line('Comparability distribution:');
        ksort($comparabilityCounts);
        foreach ($comparabilityCounts as $key => $count) {
            $this->line(sprintf('  %-24s %d', $key, $count));
        }

        $this->newLine();
        $this->line('Excluded from listings ('.count($excluded).'):');
        foreach ($excluded as $line) {
            $this->line('  '.$line);
        }

        $this->newLine();
        $this->line('Integrity-labeled ('.count($labeled).'):');
        foreach ($labeled as $line) {
            $this->line('  '.$line);
        }

        if ($this->option('json')) {
            file_put_contents($this->option('json'), json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            $this->newLine();
            $this->info('Wrote per-contract diff to '.$this->option('json'));
        }

        if ($this->option('fail-on-parse-errors') && $parseErrors !== []) {
            $this->error('Parse errors for: '.implode(', ', $parseErrors));

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * Hold-flat versus forward-shift for every active market-reset lineage.
     *
     * Both totals are produced in one process by constructing two pricing services with the
     * estimator forced off and on, so the review does not depend on the deployed flag value.
     */
    private function compareResets(EnergyUsage $usage, int $consumption, CarbonImmutable $startDate): int
    {
        $settings = ResetEstimatorSettings::fromConfig();
        $provider = app(MarketReferenceCurveProvider::class);

        $holdFlat = new CanonicalContractPricingService(
            calculator: new CanonicalContractPriceCalculator(
                resetEstimator: new MarketResetPriceEstimator($provider, $settings->withEnabled(false)),
            ),
        );
        $shifted = new CanonicalContractPricingService(
            calculator: new CanonicalContractPriceCalculator(
                resetEstimator: new MarketResetPriceEstimator($provider, $settings->withEnabled(true)),
            ),
        );

        $tradeDate = $provider->tradeDate($startDate);
        $this->info(sprintf(
            'Market-reset review at %d kWh, window from %s, beta %.2f, curve vintage %s.',
            $consumption,
            $startDate->toDateString(),
            $settings->beta,
            $tradeDate?->toDateString() ?? 'NONE',
        ));
        $this->line('Fixed-term 12 kk median energy price: '.($provider->fixedTermMedianEnergyPrice() !== null
            ? number_format($provider->fixedTermMedianEnergyPrice(), 2).' c/kWh'
            : 'unavailable'));
        $this->newLine();

        $rows = [];
        $basisCounts = [];
        $referenceCounts = [];
        $flagCounts = [];

        foreach ($this->resetContracts() as $contract) {
            $before = $holdFlat->evaluate($contract, $usage, null, $startDate)['outcome'];
            $after = $shifted->evaluate($contract, $usage, null, $startDate)['outcome'];
            $reset = $after->resetEstimate;

            $cadence = (string) data_get($contract->canonical_pricing, 'recurring_schedule.cadence', '?');
            $basis = $reset['basis'] ?? 'hold_flat';
            $basisCounts[$cadence.'/'.$basis] = ($basisCounts[$cadence.'/'.$basis] ?? 0) + 1;
            $referenceKey = $cadence.'/'.($reset['reference_kind'] ?? 'none').' @ '.($reset['reference_trade_date'] ?? 'none');
            $referenceCounts[$referenceKey] = ($referenceCounts[$referenceKey] ?? 0) + 1;
            foreach ((array) ($reset['flags'] ?? []) as $flag) {
                $flagCounts[$flag] = ($flagCounts[$flag] ?? 0) + 1;
            }

            $rows[] = [
                'id' => $contract->id,
                'company' => $contract->company_name,
                'name' => $contract->name,
                'pricing_model' => $contract->pricing_model,
                'cadence' => $cadence,
                'current_price' => $reset['current_period_energy_price'] ?? $before->generalKwhPrice,
                'reference_kind' => $reset['reference_kind'] ?? null,
                'reference_price' => $reset['reference_price'] ?? null,
                'reference_trade_date' => $reset['reference_trade_date'] ?? null,
                'forward_trade_date' => $reset['curve_trade_date'] ?? null,
                'anchor_period' => $reset['anchor_period'] ?? null,
                'tail_starts' => $reset['tail_starts'] ?? null,
                'basis' => $basis,
                'hold_flat_total' => $before->totalCost !== null ? round($before->totalCost, 2) : null,
                'shifted_total' => $after->totalCost !== null ? round($after->totalCost, 2) : null,
                'delta_eur' => ($before->totalCost !== null && $after->totalCost !== null)
                    ? round($after->totalCost - $before->totalCost, 2)
                    : null,
                'annual_equivalent_price' => $reset['annual_equivalent_energy_price'] ?? null,
                'comparability' => $after->comparability->value,
                'estimate_method' => $after->estimateMethod->value,
                'flags' => (array) ($reset['flags'] ?? []),
            ];
        }

        usort($rows, fn (array $a, array $b) => ($b['delta_eur'] ?? 0) <=> ($a['delta_eur'] ?? 0));

        $this->table(
            ['Company / contract', 'Cad', 'Now c/kWh', 'Ref', 'Ref vintage', 'Hold-flat €', 'Shifted €', 'Δ €', '12 kk c/kWh'],
            array_map(fn (array $row) => [
                mb_substr($row['company'].' — '.$row['name'], 0, 44),
                mb_substr($row['cadence'], 0, 4),
                $this->num($row['current_price'], 2),
                $row['reference_kind'] ?? '—',
                $row['reference_trade_date'] ?? '—',
                $this->num($row['hold_flat_total'], 0),
                $this->num($row['shifted_total'], 0),
                $this->num($row['delta_eur'], 0),
                $this->num($row['annual_equivalent_price'], 2),
            ], $rows),
        );

        $this->newLine();
        $this->line('Basis by cadence:');
        ksort($basisCounts);
        foreach ($basisCounts as $key => $count) {
            $this->line(sprintf('  %-44s %d', $key, $count));
        }

        $this->newLine();
        $this->line('Reference kind by cadence:');
        ksort($referenceCounts);
        foreach ($referenceCounts as $key => $count) {
            $this->line(sprintf('  %-44s %d', $key, $count));
        }

        if ($flagCounts !== []) {
            $this->newLine();
            $this->line('Flags:');
            ksort($flagCounts);
            foreach ($flagCounts as $key => $count) {
                $this->line(sprintf('  %-44s %d', $key, $count));
            }
        }

        $shiftedRows = array_filter($rows, fn (array $row) => $row['basis'] !== 'hold_flat');
        $deltas = array_values(array_filter(array_column($shiftedRows, 'delta_eur'), fn ($d) => $d !== null));
        $this->newLine();
        $this->info(sprintf(
            '%d reset lineages, %d shifted, %d fell back to hold flat. Mean delta %s €, max %s €.',
            count($rows),
            count($shiftedRows),
            count($rows) - count($shiftedRows),
            $deltas !== [] ? number_format(array_sum($deltas) / count($deltas), 1) : '—',
            $deltas !== [] ? number_format(max($deltas), 1) : '—',
        ));

        if ($this->option('json')) {
            file_put_contents($this->option('json'), json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            $this->newLine();
            $this->info('Wrote per-contract reset diff to '.$this->option('json'));
        }

        return self::SUCCESS;
    }

    /**
     * @return \Illuminate\Support\Collection<int, ElectricityContract>
     */
    private function resetContracts(): \Illuminate\Support\Collection
    {
        return ElectricityContract::query()
            ->active()
            ->whereNotNull('canonical_pricing')
            ->get()
            ->filter(function (ElectricityContract $contract) {
                $schedule = (array) data_get($contract->canonical_pricing, 'recurring_schedule', []);

                return ($schedule['present'] ?? false) === true
                    && in_array($schedule['cadence'] ?? 'none', ['monthly', 'quarterly', 'seasonal'], true);
            })
            ->sortBy([['company_name', 'asc'], ['name', 'asc']])
            ->values();
    }

    private function num(?float $value, int $decimals): string
    {
        return $value === null ? '—' : number_format($value, $decimals, ',', ' ');
    }
}
