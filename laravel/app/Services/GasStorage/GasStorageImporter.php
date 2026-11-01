<?php

namespace App\Services\GasStorage;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Upsert AGSI+ daily rows into gas_storage_daily_values. GIE corrects estimated days later, so a
 * rerun replaces the stored values of the same area and gas day.
 */
class GasStorageImporter
{
    private const UPSERT_CHUNK_SIZE = 500;

    /** AGSI field => column. AGSI sends numbers as strings; '-' or '' means no value. */
    private const NUMERIC_FIELDS = [
        'gasInStorage' => 'gas_in_storage_twh',
        'workingGasVolume' => 'working_gas_volume_twh',
        'full' => 'full_pct',
        'trend' => 'trend_pct',
        'injection' => 'injection_gwh',
        'withdrawal' => 'withdrawal_gwh',
        'netWithdrawal' => 'net_withdrawal_gwh',
    ];

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return int Number of daily rows written
     */
    public function import(string $areaCode, array $rows, CarbonInterface $fetchedAt): int
    {
        $now = now();
        $records = [];

        foreach ($rows as $row) {
            $gasDay = $row['gasDayStart'] ?? null;
            if (! is_string($gasDay) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $gasDay) !== 1) {
                continue;
            }

            $record = [
                'area_code' => $areaCode,
                'gas_day' => $gasDay,
                'status' => is_string($row['status'] ?? null) ? substr($row['status'], 0, 1) : null,
                'source_updated_at' => is_string($row['updatedAt'] ?? null) ? substr($row['updatedAt'], 0, 32) : null,
                'fetched_at' => $fetchedAt->copy()->utc()->format('Y-m-d H:i:s'),
                'created_at' => $now,
                'updated_at' => $now,
            ];
            foreach (self::NUMERIC_FIELDS as $field => $column) {
                $value = $row[$field] ?? null;
                $record[$column] = is_numeric($value) && is_finite((float) $value) ? (float) $value : null;
            }

            // One row per area and gas day; a later duplicate in the response wins.
            $records[$gasDay] = $record;
        }

        foreach (array_chunk(array_values($records), self::UPSERT_CHUNK_SIZE) as $chunk) {
            DB::table('gas_storage_daily_values')->upsert(
                $chunk,
                ['area_code', 'gas_day'],
                [...array_values(self::NUMERIC_FIELDS), 'status', 'source_updated_at', 'fetched_at', 'updated_at']
            );
        }

        return count($records);
    }
}
