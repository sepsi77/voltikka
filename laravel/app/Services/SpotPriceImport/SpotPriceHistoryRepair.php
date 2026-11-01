<?php

namespace App\Services\SpotPriceImport;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Compare stored official spot rows with a fresh ENTSO-E fetch and, on apply, repair them.
 *
 * Reason: before 2026-11-01 the parser did not expand ENTSO-E A03 curves, and an old backfill
 * stored only the first day of every second month in 2022–2025. Insert-only imports can add
 * missing rows but never correct a wrong hourly mean. This class:
 * - inserts missing quarter and hour rows,
 * - updates rows whose stored price differs from the fresh value by more than the tolerance,
 * - never deletes a stored row (rows absent from the fresh fetch are only counted).
 *
 * Hours are compared with HOUR_TOLERANCE because spot_prices_hour.price_without_tax is a MySQL
 * single-precision FLOAT; 0.0001 c/kWh (0.001 €/MWh) is above its rounding error for normal
 * prices and below the smallest real price step. Quarters are DECIMAL(10,4).
 */
class SpotPriceHistoryRepair
{
    public const HOUR_TOLERANCE = 0.0001;

    public const QUARTER_TOLERANCE = 0.00005;

    private const WRITE_CHUNK_SIZE = 500;

    public function __construct(private readonly SpotPriceImporter $importer) {}

    /**
     * @param  array  $spotPrices  Fresh EntsoeService records (may cover more than the range)
     * @return array{quarters: array<string, int>, hours: array<string, int|float>, mean_before: ?float, mean_after: ?float, changed_examples: list<array{utc: string, stored: float, fresh: float}>}
     */
    public function repair(string $region, Carbon $start, Carbon $end, array $spotPrices, bool $apply): array
    {
        [$quarters, $hours] = $this->importer->prepare($spotPrices);
        $inRange = fn (array $row) => $row['region'] === $region
            && $row['timestamp'] >= $start->timestamp
            && $row['timestamp'] < $end->timestamp;
        $quarters = array_values(array_filter($quarters, $inRange));
        $hours = array_values(array_filter($hours, $inRange));

        $storedQuarters = $this->storedPrices('spot_prices_quarter', $region, $start, $end);
        $storedHours = $this->storedPrices('spot_prices_hour', $region, $start, $end);

        $quarterPlan = $this->plan($quarters, $storedQuarters, self::QUARTER_TOLERANCE);
        $hourPlan = $this->plan($hours, $storedHours, self::HOUR_TOLERANCE);

        $freshHourPrices = [];
        foreach ($hours as $row) {
            $freshHourPrices[$row['timestamp']] = (float) $row['price_without_tax'];
        }

        if ($apply) {
            DB::transaction(function () use ($quarterPlan, $hourPlan) {
                $this->write('spot_prices_quarter', $quarterPlan);
                $this->write('spot_prices_hour', $hourPlan);
            });
        }

        $examples = [];
        foreach (array_slice($hourPlan['changed'], 0, 5) as $row) {
            $examples[] = [
                'utc' => Carbon::createFromTimestampUTC($row['timestamp'])->format('Y-m-d H:i'),
                'stored' => $storedHours[$row['timestamp']],
                'fresh' => (float) $row['price_without_tax'],
            ];
        }

        $after = $freshHourPrices + $storedHours; // fresh wins; stored-only hours stay

        return [
            'quarters' => [
                'fresh' => count($quarters),
                'stored' => count($storedQuarters),
                'missing' => count($quarterPlan['missing']),
                'changed' => count($quarterPlan['changed']),
                'stored_only' => $quarterPlan['stored_only'],
            ],
            'hours' => [
                'fresh' => count($hours),
                'stored' => count($storedHours),
                'missing' => count($hourPlan['missing']),
                'changed' => count($hourPlan['changed']),
                'stored_only' => $hourPlan['stored_only'],
                'max_abs_change' => $hourPlan['max_abs_change'],
            ],
            'mean_before' => $storedHours === [] ? null : array_sum($storedHours) / count($storedHours),
            'mean_after' => $after === [] ? null : array_sum($after) / count($after),
            'changed_examples' => $examples,
        ];
    }

    /**
     * @return array<int, float> price_without_tax by unix timestamp
     */
    private function storedPrices(string $table, string $region, Carbon $start, Carbon $end): array
    {
        return DB::table($table)
            ->where('region', $region)
            ->where('timestamp', '>=', $start->timestamp)
            ->where('timestamp', '<', $end->timestamp)
            ->pluck('price_without_tax', 'timestamp')
            ->mapWithKeys(fn ($price, $timestamp) => [(int) $timestamp => (float) $price])
            ->all();
    }

    /**
     * @param  list<array<string, mixed>>  $fresh
     * @param  array<int, float>  $stored
     * @return array{missing: list<array<string, mixed>>, changed: list<array<string, mixed>>, stored_only: int, max_abs_change: float}
     */
    private function plan(array $fresh, array $stored, float $tolerance): array
    {
        $missing = [];
        $changed = [];
        $maxAbsChange = 0.0;
        $freshTimestamps = [];

        foreach ($fresh as $row) {
            $timestamp = (int) $row['timestamp'];
            $freshTimestamps[$timestamp] = true;

            if (! array_key_exists($timestamp, $stored)) {
                $missing[] = $row;

                continue;
            }

            $difference = abs($stored[$timestamp] - (float) $row['price_without_tax']);
            if ($difference > $tolerance) {
                $changed[] = $row;
                $maxAbsChange = max($maxAbsChange, $difference);
            }
        }

        return [
            'missing' => $missing,
            'changed' => $changed,
            'stored_only' => count(array_diff_key($stored, $freshTimestamps)),
            'max_abs_change' => $maxAbsChange,
        ];
    }

    /**
     * @param  array{missing: list<array<string, mixed>>, changed: list<array<string, mixed>>}  $plan
     */
    private function write(string $table, array $plan): void
    {
        foreach (array_chunk($plan['missing'], self::WRITE_CHUNK_SIZE) as $chunk) {
            DB::table($table)->insertOrIgnore(array_map(fn (array $row) => $this->databaseRow($row), $chunk));
        }

        foreach ($plan['changed'] as $row) {
            DB::table($table)
                ->where('region', $row['region'])
                ->where('timestamp', $row['timestamp'])
                ->update([
                    'price_without_tax' => $row['price_without_tax'],
                    'vat_rate' => $row['vat_rate'],
                ]);
        }
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function databaseRow(array $row): array
    {
        return [
            'region' => $row['region'],
            'timestamp' => $row['timestamp'],
            'utc_datetime' => $row['utc_datetime']->copy()->utc()->format('Y-m-d H:i:s'),
            'price_without_tax' => $row['price_without_tax'],
            'vat_rate' => $row['vat_rate'],
        ];
    }
}
