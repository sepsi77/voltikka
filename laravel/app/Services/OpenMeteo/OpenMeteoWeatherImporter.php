<?php

namespace App\Services\OpenMeteo;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Store Open-Meteo responses: observed days are upserted into weather_daily_observations,
 * observed UTC hours into weather_hourly_observations, each distinct outlook payload becomes one
 * append-only row in weather_outlook_runs, and each distinct hourly forecast payload one
 * append-only row in hourly_forecast_runs.
 */
class OpenMeteoWeatherImporter
{
    private const UPSERT_CHUNK_SIZE = 500;

    /**
     * @param  array<string, mixed>  $payload  Archive response
     * @return int Number of daily rows written
     */
    public function importObserved(string $point, array $payload, CarbonInterface $fetchedAt): int
    {
        $variables = (array) config('open_meteo.observed_variables');
        $daily = $payload['daily'];
        $this->assertUnits($payload, $variables);

        $now = now();
        $fetched = $fetchedAt->copy()->utc()->format('Y-m-d H:i:s');
        $records = [];

        foreach ($daily['time'] as $index => $date) {
            $values = [];
            foreach ($variables as $variable) {
                $values[$variable] = $this->number($daily[$variable][$index] ?? null);
            }

            // ERA5 is about 5-6 days late; the API returns null for those days. Skip them, so
            // that a missing day stays missing instead of becoming an empty row.
            if (array_filter($values, fn ($value) => $value !== null) === []) {
                continue;
            }

            $records[] = [
                'point' => $point,
                'model' => (string) config('open_meteo.observed_model'),
                'date' => (string) $date,
                ...$values,
                'fetched_at' => $fetched,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        foreach (array_chunk($records, self::UPSERT_CHUNK_SIZE) as $chunk) {
            DB::table('weather_daily_observations')->upsert(
                $chunk,
                ['point', 'model', 'date'],
                [...$variables, 'fetched_at', 'updated_at']
            );
        }

        return count($records);
    }

    /**
     * @param  array<string, mixed>  $payload  Hourly archive response (timeformat=unixtime, GMT)
     * @return int Number of hourly rows written
     */
    public function importObservedHourly(string $point, array $payload, CarbonInterface $fetchedAt): int
    {
        $variables = (array) config('open_meteo.observed_hourly_variables');
        $this->assertUnits($payload, $variables, 'hourly');
        $times = $this->hourlyTimes($payload);
        $hourly = $payload['hourly'];

        $now = now();
        $fetched = $fetchedAt->copy()->utc()->format('Y-m-d H:i:s');
        $records = [];

        foreach ($times as $index => $timestamp) {
            $values = [];
            foreach ($variables as $variable) {
                $values[$variable] = $this->number($hourly[$variable][$index] ?? null);
            }

            // ERA5 is about 5-6 days late; hours without any value stay missing.
            if (array_filter($values, fn ($value) => $value !== null) === []) {
                continue;
            }

            $records[] = [
                'point' => $point,
                'model' => (string) config('open_meteo.observed_hourly_model'),
                'timestamp' => $timestamp,
                'utc_datetime' => gmdate('Y-m-d H:i:s', $timestamp),
                ...$values,
                'fetched_at' => $fetched,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        foreach (array_chunk($records, self::UPSERT_CHUNK_SIZE) as $chunk) {
            DB::table('weather_hourly_observations')->upsert(
                $chunk,
                ['point', 'model', 'timestamp'],
                ['utc_datetime', ...$variables, 'fetched_at', 'updated_at']
            );
        }

        return count($records);
    }

    /**
     * Store one hourly forecast vintage in hourly_forecast_runs. Returns false when the same
     * payload is already stored. Index 0 of every series is the UTC hour that starts at
     * first_timestamp: the API time plus the product's time_shift_seconds (-3600 for a
     * preceding-hour mean such as shortwave_radiation).
     *
     * @param  array{variables: list<string>, time_shift_seconds?: int}  $product
     * @param  array<string, mixed>  $payload  Forecast response (timeformat=unixtime, GMT)
     */
    public function storeHourlyForecastRun(
        string $productKey,
        array $product,
        string $point,
        array $payload,
        ?string $issuedAt,
        CarbonInterface $fetchedAt
    ): bool {
        $this->assertUnits($payload, $product['variables'], 'hourly');
        $times = $this->hourlyTimes($payload);
        $hourCount = count($times);

        if ($hourCount === 0) {
            throw new RuntimeException('Open-Meteo forecast has no hours.');
        }
        foreach ($times as $index => $timestamp) {
            if ($timestamp !== $times[0] + $index * 3600) {
                throw new RuntimeException('Open-Meteo forecast hours are not consecutive.');
            }
        }

        $values = [];
        foreach ($product['variables'] as $variable) {
            $series = $payload['hourly'][$variable];
            if (count($series) !== $hourCount) {
                throw new RuntimeException("Open-Meteo forecast series {$variable} has the wrong length.");
            }
            $values[$variable] = array_map(fn ($value) => $this->number($value), array_values($series));
        }

        $firstTimestamp = $times[0] + (int) ($product['time_shift_seconds'] ?? 0);
        $now = now();

        $inserted = DB::table('hourly_forecast_runs')->insertOrIgnore([
            'source' => 'open_meteo',
            'product' => $productKey,
            'point' => $point,
            'payload_hash' => hash('sha256', json_encode([$firstTimestamp, $values], JSON_THROW_ON_ERROR)),
            'issued_at' => $issuedAt,
            'fetched_at' => $fetchedAt->copy()->utc()->format('Y-m-d H:i:s'),
            'first_timestamp' => $firstTimestamp,
            'first_utc_datetime' => gmdate('Y-m-d H:i:s', $firstTimestamp),
            'hour_count' => $hourCount,
            'grid_latitude' => is_numeric($payload['latitude'] ?? null) ? (float) $payload['latitude'] : null,
            'grid_longitude' => is_numeric($payload['longitude'] ?? null) ? (float) $payload['longitude'] : null,
            'values' => json_encode($values, JSON_THROW_ON_ERROR),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $inserted > 0;
    }

    /**
     * Store one outlook vintage. Returns false when the same payload is already stored.
     *
     * @param  array{model: string, variables: list<string>}  $product
     * @param  array<string, mixed>  $payload  Ensemble or seasonal response
     */
    public function storeOutlookRun(
        string $productKey,
        array $product,
        string $point,
        array $payload,
        ?string $modelRunAt,
        CarbonInterface $fetchedAt
    ): bool {
        $daily = $payload['daily'];
        $this->assertUnits($payload, $product['variables']);
        $dayCount = count($daily['time']);

        if ($dayCount === 0) {
            throw new RuntimeException('Open-Meteo outlook has no days.');
        }

        // members[variable][m][d]: m = 0 is the control run (the key without a suffix),
        // m = n is "<variable>_memberNN".
        $members = [];
        $memberCount = null;
        foreach ($product['variables'] as $variable) {
            $series = [];
            for ($m = 0; ; $m++) {
                $key = $m === 0 ? $variable : sprintf('%s_member%02d', $variable, $m);
                if (! array_key_exists($key, $daily)) {
                    break;
                }
                if (! is_array($daily[$key]) || count($daily[$key]) !== $dayCount) {
                    throw new RuntimeException("Open-Meteo outlook series {$key} has the wrong length.");
                }
                $series[] = array_map(fn ($value) => $this->number($value), array_values($daily[$key]));
            }

            if ($series === [] || ($memberCount !== null && count($series) !== $memberCount)) {
                throw new RuntimeException("Open-Meteo outlook variable {$variable} has a wrong member count.");
            }
            $memberCount = count($series);
            $members[$variable] = $series;
        }

        $firstDate = (string) $daily['time'][0];
        $hash = hash('sha256', json_encode([$product['model'], $firstDate, $members], JSON_THROW_ON_ERROR));
        $now = now();

        $inserted = DB::table('weather_outlook_runs')->insertOrIgnore([
            'product' => $productKey,
            'model' => $product['model'],
            'point' => $point,
            'payload_hash' => $hash,
            'model_run_at' => $modelRunAt,
            'fetched_at' => $fetchedAt->copy()->utc()->format('Y-m-d H:i:s'),
            'first_date' => $firstDate,
            'day_count' => $dayCount,
            'member_count' => $memberCount,
            'grid_latitude' => (float) ($payload['latitude'] ?? 0),
            'grid_longitude' => (float) ($payload['longitude'] ?? 0),
            'members' => json_encode($members, JSON_THROW_ON_ERROR),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $inserted > 0;
    }

    /**
     * The API default wind unit is km/h; a silent unit change would corrupt the history.
     *
     * @param  array<string, mixed>  $payload
     * @param  list<string>  $variables
     */
    private function assertUnits(array $payload, array $variables, string $block = 'daily'): void
    {
        $expected = (array) config('open_meteo.units');

        foreach ($variables as $variable) {
            $unit = $payload["{$block}_units"][$variable] ?? null;
            if (! is_array($payload[$block][$variable] ?? null) || $unit !== ($expected[$variable] ?? null)) {
                throw new RuntimeException("Open-Meteo variable {$variable} is missing or has an unexpected unit.");
            }
        }
    }

    /**
     * Hourly times as UTC unix seconds. A non-zero UTC offset or a time that is not a full hour
     * would shift every value, so it fails the point.
     *
     * @param  array<string, mixed>  $payload
     * @return list<int>
     */
    private function hourlyTimes(array $payload): array
    {
        if ((int) ($payload['utc_offset_seconds'] ?? 0) !== 0) {
            throw new RuntimeException('Open-Meteo hourly response is not in UTC.');
        }

        $times = array_values($payload['hourly']['time']);
        foreach ($times as $timestamp) {
            if (! is_int($timestamp) || $timestamp % 3600 !== 0) {
                throw new RuntimeException('Open-Meteo hourly time is not a full UTC hour in unix seconds.');
            }
        }

        return $times;
    }

    private function number(mixed $value): ?float
    {
        return (is_int($value) || is_float($value)) && is_finite((float) $value) ? (float) $value : null;
    }
}
