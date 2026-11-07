<?php

namespace App\Console\Commands;

use App\Services\OpenMeteo\OpenMeteoWeatherImporter;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use JsonException;
use Throwable;

/**
 * One-time import of the weather history that the own S model s_v1 was trained on
 * (tasks/own-forecast-model-data/s-model-freeze.md, gap 2; user approval 2026-11-06), from the
 * research cache files (git-ignored, local only) into hourly_forecast_runs:
 *
 *  - ecmwf_00z: research/data/om-single-runs/ecmwf_ifs/<YYYY-MM-DD>.json, one file per 00 UTC run
 *    with {"fmi_<FMISID>"|<neighbour key>: {"time": [...], <4 variables>: [...]}}; issued_at is
 *    the file date 00:00 UTC (the exact run, as the live collector stores it).
 *  - ecmwf_prev_day1: research/data/om-previous-runs/ecmwf_ifs025/fmi_<FMISID>_<year>.json, full
 *    Previous Runs answers; only temperature_2m_previous_day1 of the five southern stations.
 *
 * Every payload goes through OpenMeteoWeatherImporter::storeHourlyForecastRun, so the unit,
 * UTC-hour and payload-hash rules of the live collector apply and a second run adds nothing.
 * Dry run by default: it prints the counts; --apply writes.
 */
class ImportResearchWeatherRuns extends Command
{
    protected $signature = 'weather:import-research-runs
                            {product : ecmwf_00z or ecmwf_prev_day1}
                            {dir : Folder with the research cache files}
                            {--apply : Write the rows (default: dry run, counts only)}';

    protected $description = 'One-time import of the S model training weather (research cache) into hourly_forecast_runs';

    public function __construct(private readonly OpenMeteoWeatherImporter $importer)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $productKey = (string) $this->argument('product');
        $product = config("open_meteo.hourly_forecasts.{$productKey}");
        if (! in_array($productKey, ['ecmwf_00z', 'ecmwf_prev_day1'], true) || ! is_array($product)) {
            $this->error('Product must be ecmwf_00z or ecmwf_prev_day1.');

            return Command::FAILURE;
        }
        $dir = rtrim((string) $this->argument('dir'), '/');
        if (! is_dir($dir)) {
            $this->error('Folder not found.');

            return Command::FAILURE;
        }

        $before = DB::table('hourly_forecast_runs')->where('source', 'open_meteo')->where('product', $productKey)->count();
        $apply = (bool) $this->option('apply');
        $fetchedAt = Carbon::now('UTC');
        $files = 0;
        $payloads = 0;
        $stored = 0;
        $failed = 0;
        $runs = [];
        // One file at a time: the 962 single-run files are about 273 MB of JSON.
        foreach ($this->items($productKey, $dir, $product) as $item) {
            if ($item === null) {
                return Command::FAILURE;
            }
            $files += $item['first'] ? 1 : 0;
            $payloads++;
            if ($item['issued_at'] !== null) {
                $runs[$item['issued_at']] = true;
            }
            if (! $apply) {
                continue;
            }
            try {
                $stored += $this->importer->storeHourlyForecastRun($productKey, $product, $item['point'], $item['payload'],
                    $item['issued_at'], $fetchedAt) ? 1 : 0;
            } catch (Throwable $e) {
                $failed++;
                $this->warn("{$item['file']} {$item['point']}: ".class_basename($e).' '.$e->getMessage());
            }
        }
        $runText = $runs === [] ? '' : sprintf(', %d runs %s .. %s', count($runs), min(array_keys($runs)), max(array_keys($runs)));
        $this->info("{$productKey}: {$payloads} payloads from {$files} files{$runText}; {$before} rows of this product before.");
        if (! $apply) {
            $this->info('Dry run: nothing written. Add --apply to import.');

            return Command::SUCCESS;
        }
        $after = DB::table('hourly_forecast_runs')->where('source', 'open_meteo')->where('product', $productKey)->count();
        $this->info("Stored {$stored} new rows ({$failed} failed); rows of this product: {$before} -> {$after}.");

        return $failed === 0 ? Command::SUCCESS : Command::FAILURE;
    }

    /**
     * Payloads of the cache files, one file in memory at a time; null after an invalid file.
     *
     * @param  array<string, mixed>  $product
     * @return iterable<array{file: string, first: bool, point: string, issued_at: ?string, payload: array<string, mixed>}|null>
     */
    private function items(string $productKey, string $dir, array $product): iterable
    {
        if ($productKey === 'ecmwf_prev_day1') {
            foreach ($product['point_keys'] as $point) {
                foreach (glob("{$dir}/fmi_{$point}_*.json") ?: [] as $path) {
                    $answer = $this->decode($path);
                    if ($answer === null) {
                        yield null;

                        return;
                    }
                    // A full API answer: its units and offset stay, so the importer checks them.
                    yield ['file' => basename($path), 'first' => true, 'point' => (string) $point, 'issued_at' => null, 'payload' => $answer];
                }
            }

            return;
        }

        $points = array_map('strval', array_keys((array) config('fmi.weather_stations')));
        $points = [...$points, ...array_keys((array) config('open_meteo.neighbour_wind_points'))];
        foreach (glob("{$dir}/*.json") ?: [] as $path) {
            $day = basename($path, '.json');
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) !== 1) {
                continue;
            }
            $run = $this->decode($path);
            $keys = $run === null ? [] : array_map(fn ($key) => $this->pointKey((string) $key), array_keys($run));
            if ($run === null || array_diff($points, $keys) !== [] || array_diff($keys, $points) !== []) {
                $this->error("{$day}: the file is not one run with exactly the 38 collector points.");
                yield null;

                return;
            }
            $first = true;
            foreach ($run as $key => $block) {
                yield ['file' => basename($path), 'first' => $first, 'point' => $this->pointKey((string) $key),
                    'issued_at' => "{$day} 00:00:00", 'payload' => $this->payload($block, $product['variables'])];
                $first = false;
            }
        }
    }

    /** Research keys are fmi_<FMISID>; the collector stores the FMISID string. */
    private function pointKey(string $key): string
    {
        return str_starts_with($key, 'fmi_') ? substr($key, 4) : $key;
    }

    /**
     * The reduced research file kept only times and values; the request had timezone=GMT,
     * timeformat=unixtime and m/s wind (research/s_weather_single_runs.py), so the envelope is UTC
     * with the configured units.
     *
     * @param  array<string, mixed>  $block
     * @param  list<string>  $variables
     * @return array<string, mixed>
     */
    private function payload(array $block, array $variables): array
    {
        $units = ['time' => 'unixtime'];
        foreach ($variables as $variable) {
            $units[$variable] = config("open_meteo.units.{$variable}");
        }

        return ['utc_offset_seconds' => 0, 'hourly_units' => $units, 'hourly' => $block];
    }

    /** @return array<string, mixed>|null */
    private function decode(string $path): ?array
    {
        try {
            $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $this->error(basename($path).': not valid JSON.');

            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }
}
