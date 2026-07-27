<?php

namespace App\Console\Commands;

use App\Models\ContractInterpretation;
use App\Models\ElectricityContract;
use App\Models\PriceComponent;
use App\Services\ContractInterpretation\CanonicalPriceComponentWriter;
use App\Services\ContractInterpretation\ContractInterpretationPublisher;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Re-open the relational price publication gate for interpretations that were
 * blocked by a rule that has since been relaxed, and fill the days they lost.
 *
 * `relational_pricing_published` is decided once, when an interpretation publishes,
 * and every later `contracts:fetch` reads that stored boolean
 * (`FetchContracts::contractsAllowedForImmediatePricePublication()`). So relaxing
 * `ContractInterpretationPublisher::canPublishSourcePricing()` does nothing for a
 * contract whose interpretation already published under the stricter rule: its
 * flag stays false until a new source snapshot triggers a new interpretation,
 * which for a stable product may be weeks away. This command re-runs the current
 * gate over those stored outputs and lifts the flag where it now passes.
 *
 * Concretely it exists because the `unsupported_consumption_effect` carve-out
 * landed after every Hybrid contract had already been gated shut on 2026-07-24,
 * blanking the `hybrid` segment of `/sahkosopimus/tilastot`. It is written
 * generally so the next relaxation can reuse it.
 *
 * Evidence, never inference. A missing day is rebuilt only from the immutable
 * `contract_source_snapshots` payload that was in observation on that day, through
 * `CanonicalPriceComponentWriter::resolveRows()`, so a filled row is identical to
 * what a correct import would have written. A day with no covering snapshot stays
 * missing; nothing is carried forward from a neighbouring day, which is the same
 * rule `ContractStatistics` applies to gaps.
 *
 * Days that already have rows are never touched: rows exist exactly when the
 * import was not gated that day, and that import saw the live payload.
 *
 * Activation is deliberately left alone. Once the flag is true, the next
 * `contracts:fetch` activates the contract through its normal path.
 *
 * Dry run by default. `--apply` is the only thing that writes.
 */
class RepublishGatedRelationalPricing extends Command
{
    protected $signature = 'contracts:republish-gated-pricing
                            {--apply : Write the changes. Without this the command only reports.}
                            {--from= : First price date to fill (Y-m-d). Defaults to the earliest day the contract is missing rows for, bounded by --days.}
                            {--to= : Last price date to fill (Y-m-d). Defaults to today.}
                            {--days=14 : How far back to look for missing days when --from is absent.}
                            {--contract=* : Limit to these contract ids.}';

    protected $description = 'Re-evaluate the relational price publication gate for already-published interpretations and backfill the price components they lost';

    public function handle(CanonicalPriceComponentWriter $writer, ContractInterpretationPublisher $publisher): int
    {
        $apply = (bool) $this->option('apply');
        $contractFilter = (array) $this->option('contract');

        $to = $this->option('to')
            ? Carbon::parse($this->option('to'))->toDateString()
            : Carbon::now('Europe/Helsinki')->toDateString();
        $from = $this->option('from')
            ? Carbon::parse($this->option('from'))->toDateString()
            : Carbon::parse($to)->subDays(max(0, (int) $this->option('days')))->toDateString();

        if ($from > $to) {
            $this->error('--from is after --to.');

            return self::FAILURE;
        }

        $blocked = ContractInterpretation::query()
            ->where('status', ContractInterpretation::STATUS_PUBLISHED)
            ->where('relational_pricing_published', false)
            ->whereIn('id', ElectricityContract::query()
                ->whereNotNull('published_interpretation_id')
                ->select('published_interpretation_id'))
            ->when($contractFilter !== [], fn ($q) => $q->whereIn('contract_id', $contractFilter))
            ->with('contract:id,company_name,name,pricing_model')
            ->orderBy('contract_id')
            ->get();

        if ($blocked->isEmpty()) {
            $this->info('No published interpretation is currently blocked from relational pricing.');

            return self::SUCCESS;
        }

        $reopened = $blocked->filter(
            fn (ContractInterpretation $interpretation) => $publisher->canPublishSourcePricing($interpretation->output ?? [])
        );

        $this->line(sprintf(
            'Blocked published interpretations: %d. Now passing the current gate: %d.',
            $blocked->count(),
            $reopened->count(),
        ));

        if ($reopened->isEmpty()) {
            $this->comment('The current gate blocks all of them for the same reasons. Nothing to do.');

            return self::SUCCESS;
        }

        $plan = [];
        $skipped = [];

        foreach ($reopened as $interpretation) {
            foreach ($this->missingDates($interpretation->contract_id, $from, $to) as $date) {
                $rows = $this->resolveFromSnapshot($writer, $interpretation->contract_id, $date, $skipped);

                if ($rows !== []) {
                    $plan[] = ['contract_id' => $interpretation->contract_id, 'date' => $date, 'rows' => $rows];
                }
            }
        }

        $this->table(
            ['contract', 'model', 'days to fill', 'component rows'],
            $reopened->map(function (ContractInterpretation $interpretation) use ($plan): array {
                $mine = array_filter($plan, fn (array $p) => $p['contract_id'] === $interpretation->contract_id);

                return [
                    substr($interpretation->contract->company_name.' / '.$interpretation->contract->name, 0, 52),
                    $interpretation->contract->pricing_model,
                    count($mine),
                    array_sum(array_map(fn (array $p) => count($p['rows']), $mine)),
                ];
            })->all(),
        );

        foreach (array_slice($skipped, 0, 20) as $line) {
            $this->warn($line);
        }
        if (count($skipped) > 20) {
            $this->warn(sprintf('... and %d more days with no usable snapshot.', count($skipped) - 20));
        }

        $this->newLine();
        $this->line(sprintf(
            'Flags to lift: %d.   Days to fill (%s..%s): %d.   Component rows: %d.',
            $reopened->count(),
            $from,
            $to,
            count($plan),
            array_sum(array_map(fn (array $p) => count($p['rows']), $plan)),
        ));

        if (! $apply) {
            $this->newLine();
            $this->comment('Dry run. Nothing was written. Re-run with --apply to write these changes.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($reopened, $plan): void {
            ContractInterpretation::whereIn('id', $reopened->pluck('id'))
                ->update(['relational_pricing_published' => true]);

            foreach ($plan as $entry) {
                foreach (array_chunk(array_values($entry['rows']), 500) as $chunk) {
                    PriceComponent::upsert($chunk, ['id', 'price_date'], [
                        'price_component_type',
                        'fuse_size',
                        'electricity_contract_id',
                        'has_discount',
                        'discount_value',
                        'discount_is_percentage',
                        'discount_type',
                        'discount_discount_n_first_kwh',
                        'discount_discount_n_first_months',
                        'discount_discount_until_date',
                        'price',
                        'payment_unit',
                    ]);
                }
            }
        });

        $this->newLine();
        $this->info(sprintf(
            'Lifted %d flags and filled %d days.',
            $reopened->count(),
            count($plan),
        ));

        $dates = array_values(array_unique(array_map(fn (array $p) => $p['date'], $plan)));
        sort($dates);

        if ($dates !== []) {
            $this->newLine();
            $this->comment('Daily statistics still hold the gap. Recalculate the filled days:');
            foreach ($dates as $date) {
                $this->line("  php artisan contracts:calculate-price-statistics --date={$date} --overwrite");
            }
        }

        $this->comment('Then clear cached contract pages: php artisan cache:clear');

        return self::SUCCESS;
    }

    /**
     * Dates in the window that have no price component row for this contract.
     *
     * @return list<string>
     */
    private function missingDates(string $contractId, string $from, string $to): array
    {
        $present = DB::table('price_components')
            ->where('electricity_contract_id', $contractId)
            ->whereBetween(DB::raw('DATE(price_date)'), [$from, $to])
            ->distinct()
            ->pluck('price_date')
            ->map(fn ($date) => substr((string) $date, 0, 10))
            ->flip();

        $missing = [];
        for ($date = Carbon::parse($from); $date->toDateString() <= $to; $date->addDay()) {
            if (! $present->has($date->toDateString())) {
                $missing[] = $date->toDateString();
            }
        }

        return $missing;
    }

    /**
     * The rows a correct import would have written for one contract-day.
     *
     * @param  list<string>  $skipped
     * @return array<string, array<string, mixed>>
     */
    private function resolveFromSnapshot(
        CanonicalPriceComponentWriter $writer,
        string $contractId,
        string $date,
        array &$skipped,
    ): array {
        $snapshot = DB::table('contract_source_snapshots')
            ->where('contract_id', $contractId)
            ->whereDate('first_observed_at', '<=', $date)
            ->whereDate('last_observed_at', '>=', $date)
            ->orderByDesc('last_observed_at')
            ->first(['id', 'source_payload']);

        if ($snapshot === null) {
            $skipped[] = 'no covering source snapshot: '.substr($contractId, 0, 46)." {$date}";

            return [];
        }

        $payload = json_decode((string) $snapshot->source_payload, true);
        $components = $payload['Details']['Pricing']['PriceComponents'] ?? [];

        if ($components === []) {
            $skipped[] = "snapshot {$snapshot->id} holds no price components: ".substr($contractId, 0, 46)." {$date}";

            return [];
        }

        return $writer->resolveRows($components, $contractId, $date);
    }
}
