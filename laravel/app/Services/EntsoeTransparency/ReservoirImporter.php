<?php

namespace App\Services\EntsoeTransparency;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use SimpleXMLElement;

/**
 * Store ENTSO-E weekly reservoir filling (A72, A16): stored energy in MWh per country and week.
 *
 * Resolution is P7D with weeks starting on Monday 00:00 market time (Europe/Brussels), so the
 * UTC start is 22:00 or 23:00 on Sunday depending on DST. ENTSO-E revises data, so a rerun
 * replaces the stored week.
 */
class ReservoirImporter
{
    /**
     * @param  list<string>  $documents
     * @return int Number of weekly rows written
     */
    public function import(string $zone, array $documents, CarbonInterface $fetchedAt): int
    {
        $weeks = [];

        foreach ($documents as $document) {
            $xml = new SimpleXMLElement($document);
            foreach ($xml->TimeSeries as $series) {
                $curveType = (string) $series->curveType ?: 'A01';
                foreach ($series->Period as $period) {
                    foreach (EntsoeCurve::slots($period, $curveType) as $timestamp => $value) {
                        $weeks[$timestamp] = $value;
                    }
                }
            }
        }

        $now = now();
        $records = [];
        foreach ($weeks as $timestamp => $value) {
            [$isoYear, $isoWeek] = EntsoeCurve::isoWeek($timestamp);
            $records[] = [
                'zone' => $zone,
                'timestamp' => $timestamp,
                'week_start_utc' => gmdate('Y-m-d H:i:s', $timestamp),
                'iso_year' => $isoYear,
                'iso_week' => $isoWeek,
                'stored_energy_mwh' => $value,
                'fetched_at' => $fetchedAt->copy()->utc()->format('Y-m-d H:i:s'),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if ($records !== []) {
            DB::table('entsoe_reservoir_weeks')->upsert(
                $records,
                ['zone', 'timestamp'],
                ['week_start_utc', 'iso_year', 'iso_week', 'stored_energy_mwh', 'fetched_at', 'updated_at']
            );
        }

        return count($records);
    }
}
