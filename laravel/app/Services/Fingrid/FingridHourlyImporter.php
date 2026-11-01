<?php

namespace App\Services\Fingrid;

use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Aggregate raw Fingrid rows to hourly means and upsert them into fingrid_hourly_values.
 *
 * The mean is correct only for power series (MW, MWh/h) and for series that are already hourly
 * (Datahub kWh per hour). config/fingrid.php lists only those. Fingrid corrects data, so a rerun
 * replaces the stored value of the same dataset, series and hour.
 */
class FingridHourlyImporter
{
    private const UPSERT_CHUNK_SIZE = 500;

    /** additionalJson keys that describe the value, not the series. */
    private const NON_SERIES_KEYS = ['TimeSeriesType', 'Res', 'Uom', 'ReadTS', 'Value', 'Count'];

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return int Number of hourly rows written
     */
    public function import(int $datasetId, array $rows, CarbonInterface $fetchedAt): int
    {
        $groups = [];

        foreach ($rows as $row) {
            $value = $row['value'] ?? null;
            if (! is_int($value) && ! is_float($value)) {
                continue;
            }
            if (! is_finite((float) $value)) {
                continue;
            }

            $hour = Carbon::parse((string) $row['startTime'], 'UTC')->utc()->startOfHour();
            $additional = is_array($row['additionalJson'] ?? null) ? $row['additionalJson'] : [];
            $seriesKey = $this->seriesKey($additional);
            $key = $seriesKey.'|'.$hour->timestamp;

            $groups[$key] ??= ['series_key' => $seriesKey, 'hour' => $hour, 'sum' => 0.0, 'count' => 0, 'points' => null];
            $groups[$key]['sum'] += (float) $value;
            $groups[$key]['count']++;

            if (isset($additional['Count']) && is_numeric($additional['Count'])) {
                $groups[$key]['points'] = (int) $additional['Count'];
            }
        }

        $now = now();
        $records = [];
        foreach ($groups as $group) {
            $records[] = [
                'dataset_id' => $datasetId,
                'series_key' => $group['series_key'],
                'timestamp' => $group['hour']->timestamp,
                'utc_datetime' => $group['hour']->format('Y-m-d H:i:s'),
                'value' => $group['sum'] / $group['count'],
                'sample_count' => $group['count'],
                'point_count' => $group['points'],
                'fetched_at' => $fetchedAt->copy()->utc()->format('Y-m-d H:i:s'),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        foreach (array_chunk($records, self::UPSERT_CHUNK_SIZE) as $chunk) {
            DB::table('fingrid_hourly_values')->upsert(
                $chunk,
                ['dataset_id', 'series_key', 'timestamp'],
                ['utc_datetime', 'value', 'sample_count', 'point_count', 'fetched_at', 'updated_at']
            );
        }

        return count($records);
    }

    /**
     * Build a stable key from the breakdown attributes, e.g. "UserGroup=BE02".
     *
     * @param  array<string, mixed>  $additional
     */
    public function seriesKey(array $additional): string
    {
        $parts = [];
        foreach ($additional as $name => $value) {
            if (in_array($name, self::NON_SERIES_KEYS, true) || ! is_scalar($value)) {
                continue;
            }
            $parts[$name] = $name.'='.$value;
        }
        ksort($parts);

        return implode('|', $parts);
    }
}
