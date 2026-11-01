<?php

namespace App\Services\Fmi;

use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use JsonException;

/**
 * Store FMI point forecasts as append-only vintages in hourly_forecast_runs (source fmi).
 *
 * One row per product and point. Values are contiguous hourly UTC arrays from first_timestamp;
 * index i is the forecast valid at first_timestamp + i h (FMI forecast values are instants, so
 * no shift is applied). Hours where every variable is null are trimmed from both ends; NaN is
 * null. A payload that is already stored is not inserted again (insertOrIgnore on its hash).
 */
class FmiForecastImporter
{
    /** A response position must be this close (degrees) to a configured point. */
    private const POSITION_TOLERANCE = 0.01;

    /**
     * @param  array{parameters: array<string, string>, speed_from?: array{0: string, 1: string, 2: string}}  $product
     * @param  list<array<string, mixed>>  $members  FmiMultiPointCoverageParser::parse() output
     * @param  array<string, array{latitude: float, longitude: float}>  $points  Point key => coordinates
     * @return array{stored: int, unchanged: int, missing: list<string>}
     *
     * @throws JsonException
     */
    public function store(string $productKey, array $product, array $members, array $points, CarbonInterface $fetchedAt): array
    {
        $series = [];

        foreach ($members as $member) {
            $issuedAt = $member['result_time'] !== null
                ? Carbon::parse($member['result_time'], 'UTC')->utc()->format('Y-m-d H:i:s')
                : null;

            foreach ($member['series'] as $positionSeries) {
                $point = $this->matchPoint($points, $positionSeries['latitude'], $positionSeries['longitude']);
                if ($point === null) {
                    continue;
                }

                $series[$point] ??= ['issued_at' => $issuedAt, 'latitude' => $positionSeries['latitude'], 'longitude' => $positionSeries['longitude'], 'hours' => []];
                foreach ($positionSeries['timestamps'] as $index => $timestamp) {
                    if ($timestamp % 3600 !== 0) {
                        continue;
                    }
                    foreach ($product['parameters'] as $field => $variable) {
                        if (isset($positionSeries['values'][$field])) {
                            $series[$point]['hours'][$timestamp][$variable] = $positionSeries['values'][$field][$index];
                        }
                    }
                }
            }
        }

        $result = ['stored' => 0, 'unchanged' => 0, 'missing' => []];

        foreach (array_keys($points) as $point) {
            $point = (string) $point;
            $run = isset($series[$point]) ? $this->run($product, $series[$point]['hours']) : null;

            if ($run === null) {
                $result['missing'][] = $point;

                continue;
            }

            [$firstTimestamp, $values] = $run;
            $now = now();
            $inserted = DB::table('hourly_forecast_runs')->insertOrIgnore([
                'source' => 'fmi',
                'product' => $productKey,
                'point' => $point,
                'payload_hash' => hash('sha256', json_encode([$firstTimestamp, $values], JSON_THROW_ON_ERROR)),
                'issued_at' => $series[$point]['issued_at'],
                'fetched_at' => $fetchedAt->copy()->utc()->format('Y-m-d H:i:s'),
                'first_timestamp' => $firstTimestamp,
                'first_utc_datetime' => gmdate('Y-m-d H:i:s', $firstTimestamp),
                'hour_count' => count(reset($values)),
                // FMI interpolates to the requested point; this is that point, not a grid cell.
                'grid_latitude' => $series[$point]['latitude'],
                'grid_longitude' => $series[$point]['longitude'],
                'values' => json_encode($values, JSON_THROW_ON_ERROR),
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $result[$inserted > 0 ? 'stored' : 'unchanged']++;
        }

        return $result;
    }

    /**
     * @param  array{parameters: array<string, string>, speed_from?: array{0: string, 1: string, 2: string}}  $product
     * @param  array<int, array<string, ?float>>  $hours  Valid time => value key => value
     * @return array{0: int, 1: array<string, list<?float>>}|null
     */
    private function run(array $product, array $hours): ?array
    {
        $variables = array_values($product['parameters']);
        $speedFrom = $product['speed_from'] ?? null;
        if ($speedFrom !== null) {
            $variables[] = $speedFrom[2];
        }

        // Only hours where at least one variable has a value define the range.
        $timestamps = [];
        foreach ($hours as $timestamp => $hourValues) {
            if ($speedFrom !== null) {
                $u = $hourValues[$speedFrom[0]] ?? null;
                $v = $hourValues[$speedFrom[1]] ?? null;
                $hours[$timestamp][$speedFrom[2]] = $u !== null && $v !== null ? round(sqrt($u * $u + $v * $v), 2) : null;
            }
            if (array_filter($hours[$timestamp], fn ($value) => $value !== null) !== []) {
                $timestamps[] = $timestamp;
            }
        }

        if ($timestamps === []) {
            return null;
        }

        $first = min($timestamps);
        $last = max($timestamps);
        $values = array_fill_keys($variables, []);

        for ($timestamp = $first; $timestamp <= $last; $timestamp += 3600) {
            foreach ($variables as $variable) {
                $values[$variable][] = $hours[$timestamp][$variable] ?? null;
            }
        }

        return [$first, $values];
    }

    /**
     * @param  array<string, array{latitude: float, longitude: float}>  $points
     */
    private function matchPoint(array $points, float $latitude, float $longitude): ?string
    {
        $best = null;
        $bestDistance = self::POSITION_TOLERANCE;

        foreach ($points as $key => $point) {
            $distance = max(abs($point['latitude'] - $latitude), abs($point['longitude'] - $longitude));
            if ($distance <= $bestDistance) {
                $best = (string) $key;
                $bestDistance = $distance;
            }
        }

        return $best;
    }
}
