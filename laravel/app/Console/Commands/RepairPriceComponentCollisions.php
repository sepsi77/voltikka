<?php

namespace App\Console\Commands;

use App\Services\ContractInterpretation\CanonicalPriceComponentWriter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Repair `price_components` rows where a null-UUID collision let a zero price
 * overwrite a real one.
 *
 * The upstream API can send two components for one contract that share
 * `Id = 00000000-...-000000000000`, `PriceComponentType` and `FuseSize`, one
 * carrying the real price and one carrying zero. Both collapse to the same
 * synthetic relational key `md5("{contract}:{type}:{fuse}")`, and before
 * `CanonicalPriceComponentWriter::preferCandidate()` existed the last source row
 * won the `upsert()`. When that was the zero, the stored price for that day is
 * an artifact: the contract's own price-development chart drew a crash to
 * 0,00 c/kWh while the version timeline beside it showed the real price.
 *
 * Ingestion is fixed. This repairs the rows written before that.
 *
 * Evidence, never inference. Each row is rebuilt from the immutable
 * `contract_source_snapshots` payload that was in observation on that date, and
 * through `CanonicalPriceComponentWriter::resolveRows()` so a repaired row is
 * byte-identical to what a correct import would have written. A row whose
 * snapshot is missing, whose payload holds no positive candidate, or whose
 * resolved identity disagrees with the stored row is reported and skipped, never
 * guessed at from neighbouring days.
 *
 * Dry run by default. `--apply` is the only thing that writes, and it writes
 * inside one transaction.
 */
class RepairPriceComponentCollisions extends Command
{
    protected $signature = 'contracts:repair-price-component-collisions
                            {--apply : Write the repairs. Without this the command only reports.}
                            {--contract=* : Limit to these contract ids.}
                            {--date=* : Limit to these price dates (Y-m-d).}';

    protected $description = 'Repair price_components rows where a null-UUID collision stored a zero price, using the immutable source snapshots';

    /**
     * Component types whose price is a per-kWh energy figure. A zero here beside
     * real prices on other dates is the collision artifact. `Monthly` is left
     * out on purpose: a base fee genuinely moving to 0 EUR/kk is an ordinary
     * seller move and must stay repairable-looking but untouched.
     *
     * @var list<string>
     */
    private const ENERGY_TYPES = [
        'General', 'DayTime', 'NightTime',
        'SeasonalWinterDay', 'SeasonalWinter', 'SeasonalOther', 'Spot',
    ];

    public function handle(CanonicalPriceComponentWriter $writer): int
    {
        $apply = (bool) $this->option('apply');
        $contractFilter = (array) $this->option('contract');
        $dateFilter = (array) $this->option('date');

        // Storage keys that are positive on at least one date and non-positive on
        // another. A key that is zero on every observed date is a real zero-priced
        // package component and is never a candidate.
        $mixedKeys = DB::table('price_components')
            ->whereIn('price_component_type', self::ENERGY_TYPES)
            ->when($contractFilter !== [], fn ($q) => $q->whereIn('electricity_contract_id', $contractFilter))
            ->groupBy('id')
            ->havingRaw('SUM(CASE WHEN price > 0 THEN 1 ELSE 0 END) > 0')
            ->havingRaw('SUM(CASE WHEN price <= 0 THEN 1 ELSE 0 END) > 0')
            ->pluck('id');

        if ($mixedKeys->isEmpty()) {
            $this->info('No collided zero-price rows found.');

            return self::SUCCESS;
        }

        $suspect = DB::table('price_components')
            ->whereIn('id', $mixedKeys)
            ->where('price', '<=', 0)
            ->when($contractFilter !== [], fn ($q) => $q->whereIn('electricity_contract_id', $contractFilter))
            ->when($dateFilter !== [], fn ($q) => $q->whereIn(DB::raw('DATE(price_date)'), $dateFilter))
            ->orderBy('electricity_contract_id')
            ->orderBy('price_date')
            ->get();

        $repairs = [];
        $skipped = [];

        foreach ($suspect as $row) {
            $date = substr((string) $row->price_date, 0, 10);
            $resolved = $this->resolveFromSnapshot($writer, $row, $date, $skipped);

            if ($resolved !== null) {
                $repairs[] = ['stored' => $row, 'date' => $date, 'row' => $resolved];
            }
        }

        $this->table(
            ['contract', 'date', 'type', 'stored', 'repaired'],
            array_map(fn (array $r) => [
                substr($r['stored']->electricity_contract_id, 0, 46),
                $r['date'],
                $r['stored']->price_component_type,
                number_format((float) $r['stored']->price, 2),
                number_format((float) $r['row']['price'], 2),
            ], $repairs)
        );

        foreach ($skipped as $line) {
            $this->warn($line);
        }

        $this->newLine();
        $this->line('repairable: '.count($repairs).'   skipped: '.count($skipped));

        if (! $apply) {
            $this->newLine();
            $this->comment('Dry run. Nothing was written. Re-run with --apply to write these repairs.');

            return self::SUCCESS;
        }

        if ($repairs === []) {
            return self::SUCCESS;
        }

        DB::transaction(function () use ($repairs): void {
            foreach ($repairs as $repair) {
                $row = $repair['row'];

                DB::table('price_components')
                    ->where('id', $repair['stored']->id)
                    ->where('price_date', $repair['stored']->price_date)
                    ->update([
                        'price_component_type' => $row['price_component_type'],
                        'fuse_size' => $row['fuse_size'],
                        'electricity_contract_id' => $row['electricity_contract_id'],
                        'has_discount' => $row['has_discount'],
                        'discount_value' => $row['discount_value'],
                        'discount_is_percentage' => $row['discount_is_percentage'],
                        'discount_type' => $row['discount_type'],
                        'discount_discount_n_first_kwh' => $row['discount_discount_n_first_kwh'],
                        'discount_discount_n_first_months' => $row['discount_discount_n_first_months'],
                        'discount_discount_until_date' => $row['discount_discount_until_date'],
                        'price' => $row['price'],
                        'payment_unit' => $row['payment_unit'],
                    ]);
            }
        });

        $this->newLine();
        $this->info('Wrote '.count($repairs).' repaired rows.');
        $this->comment('Contract page caches still hold the old series; run `php artisan cache:clear` next.');

        return self::SUCCESS;
    }

    /**
     * The row a correct import would have written for this stored row's day.
     *
     * @param  list<string>  $skipped
     */
    private function resolveFromSnapshot(
        CanonicalPriceComponentWriter $writer,
        object $stored,
        string $date,
        array &$skipped
    ): ?array {
        $label = substr($stored->electricity_contract_id, 0, 46)." {$date} {$stored->price_component_type}";

        $snapshot = DB::table('contract_source_snapshots')
            ->where('contract_id', $stored->electricity_contract_id)
            ->whereDate('first_observed_at', '<=', $date)
            ->whereDate('last_observed_at', '>=', $date)
            ->orderByDesc('last_observed_at')
            ->first(['id', 'source_payload']);

        if ($snapshot === null) {
            $skipped[] = "no covering source snapshot: {$label}";

            return null;
        }

        $payload = json_decode((string) $snapshot->source_payload, true);
        $components = $payload['Details']['Pricing']['PriceComponents'] ?? [];

        $resolved = $writer->resolveRows($components, $stored->electricity_contract_id, $date);
        $row = $resolved[$stored->id.'|'.$date] ?? null;

        if ($row === null) {
            $skipped[] = "storage key absent from snapshot {$snapshot->id}: {$label}";

            return null;
        }

        if ((float) $row['price'] <= 0) {
            $skipped[] = "snapshot {$snapshot->id} holds no positive price either, leaving as is: {$label}";

            return null;
        }

        if ($row['price_component_type'] !== $stored->price_component_type) {
            $skipped[] = "resolved type {$row['price_component_type']} disagrees with stored: {$label}";

            return null;
        }

        return $row;
    }
}
