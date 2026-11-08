<?php

namespace App\Services\Orchestration;

use App\Models\DataFreshnessCheckpoint;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Read-only readiness facts for the Temporal S forecast workflow (config orchestration.s_forecast).
 *
 * One input is ready only when its exact fact is true for issue date D and the view's cut. The
 * workflow starts the model only when every input of both views is ready; this class never fills a
 * gap or accepts an older vintage. Timestamps in the database are UTC text.
 */
class SForecastReadiness
{
    public const VIEWS = ['morning', 'afternoon'];

    /**
     * @return array{pipeline: string, issue_date: string, view: string, cut_utc: string, ready: bool,
     *               inputs: list<array{key: string, ready: bool, detail: string}>}
     */
    public function check(string $issueDate, string $view): array
    {
        if (! in_array($view, self::VIEWS, true)) {
            throw new InvalidArgumentException("Unknown view {$view}.");
        }

        $config = (array) config('orchestration.s_forecast');
        $timezone = (string) $config['timezone'];
        $date = CarbonImmutable::createFromFormat('!Y-m-d', $issueDate, $timezone);
        if ($date === false || $date->format('Y-m-d') !== $issueDate) {
            throw new InvalidArgumentException('Issue date must be YYYY-MM-DD.');
        }

        $cut = $this->localTime($date, (string) $config['cuts'][$view])->utc();

        $inputs = [];
        foreach ((array) $config['inputs'] as $input) {
            if ($input['view'] !== $view) {
                continue;
            }
            [$ready, $detail] = $this->checkInput($input, $date, $cut);
            $inputs[] = ['key' => (string) $input['key'], 'ready' => $ready, 'detail' => $detail];
        }

        return [
            'pipeline' => 's_forecast',
            'issue_date' => $issueDate,
            'view' => $view,
            'cut_utc' => $cut->format('Y-m-d\TH:i:s\Z'),
            'ready' => $inputs !== [] && ! in_array(false, array_column($inputs, 'ready'), true),
            'inputs' => $inputs,
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{0: bool, 1: string}
     */
    private function checkInput(array $input, CarbonImmutable $date, CarbonImmutable $cut): array
    {
        return match ($input['type']) {
            'day_complete' => $this->dayComplete($input, $date),
            'fingrid_through' => $this->fingridThrough($input, $date),
            'forecast_run' => $this->forecastRun($input, $date),
            'forecast_window' => $this->forecastWindow($input, $date),
            'checkpoint_ready' => $this->checkpointReady($input, $date, $cut),
            default => throw new InvalidArgumentException("Unknown readiness type {$input['type']}."),
        };
    }

    /**
     * Every Helsinki hour of D + day_offset (23/24/25 with DST) has a row for each key, in a table
     * keyed by an integer UTC hour-start `timestamp` (spot_prices_hour, jao_max_border_flows).
     */
    private function dayComplete(array $input, CarbonImmutable $date): array
    {
        $offset = (int) $input['day_offset'];
        $start = $date->addDays($offset)->startOfDay()->utc();
        $end = $date->addDays($offset + 1)->startOfDay()->utc();
        $expected = intdiv($end->getTimestamp() - $start->getTimestamp(), 3600);
        $column = (string) $input['key_column'];

        $counts = DB::table((string) $input['table'])
            ->selectRaw("{$column} as series, count(*) as hours")
            ->whereIn($column, $input['keys'])
            ->where('timestamp', '>=', $start->getTimestamp())
            ->where('timestamp', '<', $end->getTimestamp())
            ->groupBy($column)
            ->pluck('hours', 'series');

        $missing = [];
        foreach ($input['keys'] as $key) {
            $count = (int) ($counts[$key] ?? 0);
            if ($count !== $expected) {
                $missing[] = "{$key} {$count}/{$expected}";
            }
        }

        $day = $date->addDays($offset)->format('Y-m-d');

        return $missing === []
            ? [true, "{$expected} hours of {$day} for ".implode(', ', $input['keys'])]
            : [false, "incomplete {$day}: ".implode(', ', $missing)];
    }

    /** A row exists for every dataset at the given local hour of D (no fetched_at cut: rows are re-read). */
    private function fingridThrough(array $input, CarbonImmutable $date): array
    {
        $hour = $this->localTime($date, (string) $input['through_local'])->utc()->getTimestamp();

        $present = DB::table('fingrid_hourly_values')
            ->where('series_key', '')
            ->whereIn('dataset_id', $input['datasets'])
            ->where('timestamp', $hour)
            ->pluck('dataset_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $missing = array_values(array_diff(array_map('intval', $input['datasets']), $present));

        return $missing === []
            ? [true, 'all datasets through '.$input['through_local']]
            : [false, 'missing datasets at '.$input['through_local'].': '.implode(', ', $missing)];
    }

    /**
     * Every point has a stored run whose model time is D run_hour_utc:00 UTC exactly. The points
     * come from the collector's own configuration (`points_from`, lists keyed by point), so the
     * check and the collector cannot drift apart.
     */
    private function forecastRun(array $input, CarbonImmutable $date): array
    {
        $points = [];
        foreach ((array) $input['points_from'] as $configKey) {
            $points = [...$points, ...array_map('strval', array_keys((array) config($configKey)))];
        }
        if ($points === []) {
            throw new InvalidArgumentException("No configured points for {$input['key']}.");
        }

        $runTime = CarbonImmutable::createFromFormat('!Y-m-d', $date->format('Y-m-d'), 'UTC')
            ->addHours((int) $input['run_hour_utc']);

        $present = DB::table('hourly_forecast_runs')
            ->where('source', $input['source'])
            ->where('product', $input['product'])
            ->whereIn('point', $points)
            ->where('issued_at', $runTime->format('Y-m-d H:i:s'))
            ->distinct()
            ->pluck('point')
            ->map(fn ($point) => (string) $point)
            ->all();

        $missing = array_values(array_diff($points, $present));
        $run = $runTime->format('Y-m-d H:i').' UTC run';

        return $missing === []
            ? [true, "{$run} at ".count($points).' points']
            : [false, "{$run}: ".count($missing).' of '.count($points).' points missing: '.implode(', ', array_slice($missing, 0, 5))];
    }

    /**
     * Every point has a stored vintage fetched at or before the local cut time of D (UTC text in
     * `fetched_at`) whose hours cover the `hours_before` hours that end at that cut. For series
     * without one run time (`issued_at` null), such as Previous Runs lead-1 values: the model reads
     * them by valid hour. The points come from the collector's own list (`point_keys_from`).
     */
    private function forecastWindow(array $input, CarbonImmutable $date): array
    {
        $points = array_map('strval', (array) config((string) $input['point_keys_from']));
        if ($points === []) {
            throw new InvalidArgumentException("No configured points for {$input['key']}.");
        }

        $cut = $this->localTime($date, (string) $input['cut_local'])->utc();
        $windowStart = $cut->getTimestamp() - 3600 * (int) $input['hours_before'];

        $present = DB::table('hourly_forecast_runs')
            ->where('source', $input['source'])
            ->where('product', $input['product'])
            ->whereIn('point', $points)
            ->where('fetched_at', '<=', $cut->format('Y-m-d H:i:s'))
            ->where('first_timestamp', '<=', $windowStart)
            ->whereRaw('first_timestamp + 3600 * hour_count >= ?', [$cut->getTimestamp()])
            ->distinct()
            ->pluck('point')
            ->map(fn ($point) => (string) $point)
            ->all();

        $missing = array_values(array_diff($points, $present));
        $window = $input['hours_before'].' h before '.$input['cut_local'];

        return $missing === []
            ? [true, "{$window} at ".count($points).' points']
            : [false, "{$window}: ".count($missing).' of '.count($points).' points missing: '.implode(', ', $missing)];
    }

    /**
     * The same-day checkpoint is ready and its last write was at or before the cut. `updated_at`
     * is used because Laravel writes it in the app time zone; `recorded_at` keeps the Helsinki
     * wall-clock time of the Carbon value that the producer passes.
     */
    private function checkpointReady(array $input, CarbonImmutable $date, CarbonImmutable $cut): array
    {
        $row = DB::table('data_freshness_checkpoints')
            ->where('key', $input['checkpoint'])
            ->whereDate('effective_date', $date->format('Y-m-d'))
            ->first(['status', 'updated_at']);

        if ($row === null) {
            return [false, 'no checkpoint for the issue date'];
        }
        if ($row->status !== DataFreshnessCheckpoint::STATUS_READY) {
            return [false, "checkpoint status {$row->status}"];
        }
        $cutInAppTimezone = $cut->setTimezone((string) config('app.timezone'))->format('Y-m-d H:i:s');
        if (substr((string) $row->updated_at, 0, 19) > $cutInAppTimezone) {
            return [false, 'checkpoint ready only after the cut'];
        }

        return [true, 'checkpoint ready'];
    }

    private function localTime(CarbonImmutable $date, string $time): CarbonImmutable
    {
        [$hour, $minute] = array_map('intval', explode(':', $time));

        return $date->setTime($hour, $minute);
    }
}
