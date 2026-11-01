<?php

namespace App\Services\Jao;

use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Aggregate JAO maxBorderFlow MTU rows to hourly values and upsert them into
 * jao_max_border_flows. The hour keeps the mean and the minimum of its MTUs (15-min MTU since
 * 2025-10-01), because the tightest quarter can matter for a price spike.
 */
class JaoMaxBorderFlowImporter
{
    private const UPSERT_CHUNK_SIZE = 500;

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return int Number of hourly rows written
     */
    public function import(array $rows, CarbonInterface $fetchedAt): int
    {
        $borders = (array) config('jao.borders', []);
        $groups = [];

        foreach ($rows as $row) {
            if (! is_string($row['dateTimeUtc'] ?? null)) {
                continue;
            }
            $hour = Carbon::parse($row['dateTimeUtc'], 'UTC')->utc()->startOfHour();
            $modified = is_string($row['lastModifiedOn'] ?? null) ? $row['lastModifiedOn'] : null;

            foreach ($borders as $field => $border) {
                $value = $row['border_'.$field] ?? null;
                if ((! is_int($value) && ! is_float($value)) || ! is_finite((float) $value)) {
                    continue;
                }

                $key = $border.'|'.$hour->timestamp;
                $groups[$key] ??= ['border' => $border, 'hour' => $hour, 'sum' => 0.0, 'min' => null, 'count' => 0, 'modified' => null];
                $groups[$key]['sum'] += (float) $value;
                $groups[$key]['min'] = $groups[$key]['min'] === null ? (float) $value : min($groups[$key]['min'], (float) $value);
                $groups[$key]['count']++;
                if ($modified !== null && ($groups[$key]['modified'] === null || strcmp($modified, $groups[$key]['modified']) > 0)) {
                    $groups[$key]['modified'] = $modified;
                }
            }
        }

        $now = now();
        $records = [];
        foreach ($groups as $group) {
            $records[] = [
                'border' => $group['border'],
                'timestamp' => $group['hour']->timestamp,
                'utc_datetime' => $group['hour']->format('Y-m-d H:i:s'),
                'capacity_mw' => $group['sum'] / $group['count'],
                'min_capacity_mw' => $group['min'],
                'sample_count' => $group['count'],
                'last_modified_on' => $group['modified'],
                'fetched_at' => $fetchedAt->copy()->utc()->format('Y-m-d H:i:s'),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        foreach (array_chunk($records, self::UPSERT_CHUNK_SIZE) as $chunk) {
            DB::table('jao_max_border_flows')->upsert(
                $chunk,
                ['border', 'timestamp'],
                ['utc_datetime', 'capacity_mw', 'min_capacity_mw', 'sample_count', 'last_modified_on', 'fetched_at', 'updated_at']
            );
        }

        return count($records);
    }
}
