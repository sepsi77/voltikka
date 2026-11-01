<?php

namespace App\Services\Fmi;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Upsert FMI observations into fmi_hourly_observations, keyed by the START of the UTC hour that
 * the value describes (like spot_prices_hour).
 *
 * FMI labels an aggregate with the END of its period: TA_PT1H_AVG at 12:00 is the mean of
 * 11:00-12:00 (verified against 10-minute data, see AGENTS.md). So an hourly value at T is stored
 * at T - 1 h, and a 1-minute radiation value at T belongs to the hour that contains T - 1 min.
 * FMI corrects observations, so a rerun replaces the stored value.
 */
class FmiObservationImporter
{
    private const UPSERT_CHUNK_SIZE = 500;

    /**
     * Store hourly PT1H values. sample_count is 1: one FMI hourly value.
     *
     * @param  list<array<string, mixed>>  $members  FmiMultiPointCoverageParser::parse() output
     * @param  list<string>  $parameters
     * @return int Number of rows written
     */
    public function importHourly(array $members, array $parameters, CarbonInterface $fetchedAt): int
    {
        $records = [];

        foreach ($members as $member) {
            $stations = $this->stationsByPosition($member['points']);
            $fields = array_intersect($member['fields'], $parameters);

            foreach ($member['series'] as $series) {
                $fmisid = $this->station($stations, $series);
                foreach ($series['timestamps'] as $index => $timestamp) {
                    if ($timestamp % 3600 !== 0) {
                        continue;
                    }
                    $hour = $timestamp - 3600;

                    foreach ($fields as $field) {
                        $value = $series['values'][$field][$index];
                        if ($value === null) {
                            continue;
                        }
                        // Keyed, so a repeated row cannot hit the same unique key twice in one upsert.
                        $records[$fmisid.'|'.$field.'|'.$hour] = $this->record($fmisid, $field, $hour, $value, 1, $fetchedAt);
                    }
                }
            }
        }

        return $this->upsert(array_values($records));
    }

    /**
     * Store the hourly MEAN of 1-minute values as $storedParameter. sample_count is the number of
     * minutes with a value; missing minutes are not filled.
     *
     * @param  list<array<string, mixed>>  $members
     * @return int Number of rows written
     */
    public function importMinuteMeans(array $members, string $parameter, string $storedParameter, CarbonInterface $fetchedAt): int
    {
        $groups = [];

        foreach ($members as $member) {
            if (! in_array($parameter, $member['fields'], true)) {
                throw new FmiRequestException("FMI response has no {$parameter} field.");
            }
            $stations = $this->stationsByPosition($member['points']);

            foreach ($member['series'] as $series) {
                $fmisid = $this->station($stations, $series);
                foreach ($series['timestamps'] as $index => $timestamp) {
                    $value = $series['values'][$parameter][$index];
                    if ($value === null) {
                        continue;
                    }
                    $hour = intdiv($timestamp - 60, 3600) * 3600;
                    $key = $fmisid.'|'.$hour;

                    $groups[$key] ??= ['fmisid' => $fmisid, 'hour' => $hour, 'sum' => 0.0, 'count' => 0];
                    $groups[$key]['sum'] += $value;
                    $groups[$key]['count']++;
                }
            }
        }

        $records = [];
        foreach ($groups as $group) {
            $records[] = $this->record(
                $group['fmisid'],
                $storedParameter,
                $group['hour'],
                $group['sum'] / $group['count'],
                $group['count'],
                $fetchedAt
            );
        }

        return $this->upsert($records);
    }

    /**
     * Observation points have gml:id "point-<fmisid>"; rows refer to them by coordinates.
     *
     * @param  list<array{id: string, latitude: float, longitude: float}>  $points
     * @return array<string, int>
     */
    private function stationsByPosition(array $points): array
    {
        $stations = [];
        foreach ($points as $point) {
            if (preg_match('/^point-(\d+)$/', $point['id'], $match) === 1) {
                $stations[$this->positionKey($point['latitude'], $point['longitude'])] = (int) $match[1];
            }
        }

        return $stations;
    }

    /**
     * @param  array<string, int>  $stations
     * @param  array{latitude: float, longitude: float}  $series
     */
    private function station(array $stations, array $series): int
    {
        return $stations[$this->positionKey($series['latitude'], $series['longitude'])]
            ?? throw new FmiRequestException('FMI series position matches no station.');
    }

    private function positionKey(float $latitude, float $longitude): string
    {
        return sprintf('%.5f %.5f', $latitude, $longitude);
    }

    /**
     * @return array<string, mixed>
     */
    private function record(int $fmisid, string $parameter, int $hour, float $value, int $sampleCount, CarbonInterface $fetchedAt): array
    {
        $now = now();

        return [
            'fmisid' => $fmisid,
            'parameter' => $parameter,
            'timestamp' => $hour,
            'utc_datetime' => gmdate('Y-m-d H:i:s', $hour),
            'value' => $value,
            'sample_count' => $sampleCount,
            'fetched_at' => $fetchedAt->copy()->utc()->format('Y-m-d H:i:s'),
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $records
     */
    private function upsert(array $records): int
    {
        foreach (array_chunk($records, self::UPSERT_CHUNK_SIZE) as $chunk) {
            DB::table('fmi_hourly_observations')->upsert(
                $chunk,
                ['fmisid', 'parameter', 'timestamp'],
                ['utc_datetime', 'value', 'sample_count', 'fetched_at', 'updated_at']
            );
        }

        return count($records);
    }
}
