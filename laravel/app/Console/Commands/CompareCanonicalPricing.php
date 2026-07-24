<?php

namespace App\Console\Commands;

use App\Models\ElectricityContract;
use App\Models\SpotPriceAverage;
use App\Services\CanonicalPricing\CanonicalContractPricingService;
use App\Services\ContractPriceCalculator;
use App\Services\DTO\EnergyUsage;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Diffs the legacy relational price calculation against the canonical phase-aware
 * calculation for every active contract, so the staged rollout can be reviewed before
 * enabling CANONICAL_PRICING_ENABLED. Read-only; makes no changes.
 */
class CompareCanonicalPricing extends Command
{
    protected $signature = 'contracts:compare-canonical-pricing
        {--consumption=5000 : Annual kWh used for both calculations}
        {--start-date= : Signup date for the 12-month window (default today)}
        {--json= : Write the full per-contract diff to this path}
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
}
