<?php

namespace App\Services\Syke;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Upsert Syke hydrology rows into syke_hydrology_values.
 *
 * Syke corrects data, so a rerun replaces the stored value of the same series, place and date.
 * Rows with a missing or non-numeric value, an unknown place or another Jakso are skipped.
 */
class SykeHydrologyImporter
{
    private const UPSERT_CHUNK_SIZE = 500;

    /**
     * @param  list<int>  $placeIds  Configured places of the series
     * @param  list<array<string, mixed>>  $rows
     * @return int Number of rows written
     */
    public function import(string $series, ?int $jaksoId, array $placeIds, array $rows, CarbonInterface $fetchedAt): int
    {
        $now = now();
        $records = [];

        foreach ($rows as $row) {
            $placeId = $row['Paikka_Id'] ?? null;
            if (! is_int($placeId) || ! in_array($placeId, $placeIds, true)) {
                continue;
            }
            if ($jaksoId !== null && ($row['Jakso_Id'] ?? null) !== $jaksoId) {
                continue;
            }

            $date = $this->date($row['Aika'] ?? null);
            // LumiAlue Arvo is a decimal string ("139.41"); SadantaAlue Arvo is an integer.
            $value = $row['Arvo'] ?? null;
            if ($date === null || ! is_numeric($value) || ! is_finite((float) $value)) {
                continue;
            }

            // Key by identity so a duplicate row in one response cannot break the upsert.
            $records[$placeId.'|'.$date] = [
                'series' => $series,
                'place_id' => $placeId,
                'date' => $date,
                'value' => (float) $value,
                'fetched_at' => $fetchedAt->copy()->utc()->format('Y-m-d H:i:s'),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        foreach (array_chunk(array_values($records), self::UPSERT_CHUNK_SIZE) as $chunk) {
            DB::table('syke_hydrology_values')->upsert(
                $chunk,
                ['series', 'place_id', 'date'],
                ['value', 'fetched_at', 'updated_at']
            );
        }

        return count($records);
    }

    /** "2026-10-21T00:00:00" -> "2026-10-21". Aika is a local calendar date at midnight. */
    private function date(mixed $aika): ?string
    {
        if (! is_string($aika) || preg_match('/^(\d{4})-(\d{2})-(\d{2})T00:00:00$/', $aika, $m) !== 1) {
            return null;
        }

        return checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? "{$m[1]}-{$m[2]}-{$m[3]}" : null;
    }
}
