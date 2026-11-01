<?php

namespace App\Services\OpenMeteo;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Store Open-Meteo responses: observed days are upserted into weather_daily_observations, and
 * each distinct outlook payload becomes one append-only row in weather_outlook_runs.
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
    private function assertUnits(array $payload, array $variables): void
    {
        $expected = (array) config('open_meteo.units');

        foreach ($variables as $variable) {
            $unit = $payload['daily_units'][$variable] ?? null;
            if (! is_array($payload['daily'][$variable] ?? null) || $unit !== ($expected[$variable] ?? null)) {
                throw new RuntimeException("Open-Meteo variable {$variable} is missing or has an unexpected unit.");
            }
        }
    }

    private function number(mixed $value): ?float
    {
        return (is_int($value) || is_float($value)) && is_finite((float) $value) ? (float) $value : null;
    }
}
