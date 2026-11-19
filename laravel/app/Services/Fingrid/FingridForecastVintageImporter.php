<?php

namespace App\Services\Fingrid;

use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Store one vintage of a rolling Fingrid forecast (e.g. dataset 245) as one append-only row in
 * hourly_forecast_runs. Fingrid overwrites the forecast every 15 minutes, so only these
 * fetch-time copies keep the forecast that existed at decision time.
 *
 * The value of an hour is the mean of its four 15-minute values. An hour with fewer than four
 * quarters is null, so a partial hour is never mixed with a full one.
 */
class FingridForecastVintageImporter
{
    /**
     * The hourly series from the first hour: the mean of four numeric quarters, else null;
     * trailing null hours are trimmed. An empty list means the response has no complete hour.
     *
     * @param  list<array<string, mixed>>  $rows  Raw 15-minute rows
     * @return list<float|null>
     */
    public function series(array $rows, CarbonInterface $firstHour, int $hours): array
    {
        $firstTimestamp = $firstHour->copy()->utc()->startOfHour()->timestamp;
        $quarters = [];

        foreach ($rows as $row) {
            $value = $row['value'] ?? null;
            if ((! is_int($value) && ! is_float($value)) || ! is_finite((float) $value)) {
                continue;
            }

            $start = Carbon::parse((string) $row['startTime'], 'UTC')->utc();
            $index = intdiv($start->timestamp - $firstTimestamp, 3600);
            if ($start->timestamp < $firstTimestamp || $index >= $hours || $start->second !== 0 || $start->minute % 15 !== 0) {
                continue;
            }
            $quarters[$index][$start->minute] = (float) $value;
        }

        $series = [];
        for ($index = 0; $index < $hours; $index++) {
            $hour = $quarters[$index] ?? [];
            $series[] = count($hour) === 4 ? array_sum($hour) / 4 : null;
        }
        while ($series !== [] && end($series) === null) {
            array_pop($series);
        }

        return $series;
    }

    /**
     * Insert one vintage. `fetched_at` is the vintage time (the fetch time, never the store time).
     *
     * @param  non-empty-list<float|null>  $series  from series()
     * @return bool False when the same payload is already stored
     */
    public function store(int $datasetId, string $variable, array $series, CarbonInterface $firstHour, CarbonInterface $fetchedAt): bool
    {
        if ($series === []) {
            throw new InvalidArgumentException("Fingrid dataset {$datasetId} forecast has no complete hour.");
        }
        $firstTimestamp = $firstHour->copy()->utc()->startOfHour()->timestamp;
        $values = [$variable => $series];
        $now = now();

        $inserted = DB::table('hourly_forecast_runs')->insertOrIgnore([
            'source' => 'fingrid',
            'product' => (string) $datasetId,
            'point' => '',
            'payload_hash' => hash('sha256', json_encode([$firstTimestamp, $values], JSON_THROW_ON_ERROR)),
            'issued_at' => null, // the rows have no issue-time attribute; fetched_at is the vintage
            'fetched_at' => $fetchedAt->copy()->utc()->format('Y-m-d H:i:s'),
            'first_timestamp' => $firstTimestamp,
            'first_utc_datetime' => gmdate('Y-m-d H:i:s', $firstTimestamp),
            'hour_count' => count($series),
            'grid_latitude' => null,
            'grid_longitude' => null,
            'values' => json_encode($values, JSON_THROW_ON_ERROR),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $inserted > 0;
    }
}
