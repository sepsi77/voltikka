<?php

namespace App\Services\EntsoeTransparency;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use SimpleXMLElement;

/**
 * Store ENTSO-E actual generation per production type (A75, A16) as hourly means of MW.
 *
 * A TimeSeries with inBiddingZone_Domain is generation; one with outBiddingZone_Domain is
 * consumption (for example pumped-storage pumping, B10 in DE-LU). The two are stored with
 * their own `direction`. A zone can split one type into several TimeSeries; when two of them
 * give the same slot, the later one wins (they are not summed). ENTSO-E revises data, so a
 * rerun replaces the stored hour.
 */
class GenerationImporter
{
    private const UPSERT_CHUNK_SIZE = 500;

    /**
     * @param  list<string>  $documents
     * @return int Number of hourly rows written
     */
    public function import(string $zone, array $documents, CarbonInterface $fetchedAt): int
    {
        /** @var array<string, array<int, float>> $slots key "psr|direction" => slot ts => MW */
        $slots = [];

        foreach ($documents as $document) {
            $xml = new SimpleXMLElement($document);
            foreach ($xml->TimeSeries as $series) {
                $psrType = (string) $series->MktPSRType->psrType;
                if ($psrType === '') {
                    continue;
                }
                $direction = isset($series->{'outBiddingZone_Domain.mRID'}) ? 'consumption' : 'generation';
                $curveType = (string) $series->curveType ?: 'A01';
                $key = $psrType.'|'.$direction;

                foreach ($series->Period as $period) {
                    foreach (EntsoeCurve::slots($period, $curveType) as $timestamp => $value) {
                        $slots[$key][$timestamp] = $value;
                    }
                }
            }
        }

        $now = now();
        $fetched = $fetchedAt->copy()->utc()->format('Y-m-d H:i:s');
        $records = [];

        foreach ($slots as $key => $values) {
            [$psrType, $direction] = explode('|', $key);
            $hours = [];
            foreach ($values as $timestamp => $value) {
                $hour = $timestamp - ($timestamp % 3600);
                $hours[$hour] ??= ['sum' => 0.0, 'count' => 0];
                $hours[$hour]['sum'] += $value;
                $hours[$hour]['count']++;
            }

            foreach ($hours as $hour => $group) {
                $records[] = [
                    'zone' => $zone,
                    'psr_type' => $psrType,
                    'direction' => $direction,
                    'timestamp' => $hour,
                    'utc_datetime' => gmdate('Y-m-d H:i:s', $hour),
                    'value_mw' => $group['sum'] / $group['count'],
                    'sample_count' => $group['count'],
                    'fetched_at' => $fetched,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        foreach (array_chunk($records, self::UPSERT_CHUNK_SIZE) as $chunk) {
            DB::table('entsoe_generation_hourly')->upsert(
                $chunk,
                ['zone', 'psr_type', 'direction', 'timestamp'],
                ['utc_datetime', 'value_mw', 'sample_count', 'fetched_at', 'updated_at']
            );
        }

        return count($records);
    }
}
