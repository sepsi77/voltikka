<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Phase 2 groups (collectors, maintenance, spot, morning) run here unless their Temporal
// Schedules run them (orchestration/src/voltikka_orchestration/schedules.py and jobs.py, the same
// group names). TEMPORAL_SCHEDULE_GROUPS (config orchestration.temporal_schedule_groups) lists the
// groups that Temporal runs. Railway leaves it empty, so every group runs here; the VPS lists each
// group after its Schedules are applied (tasks/vps-migration/). A group must never run in both
// schedulers, so an unknown name stops the scheduler instead of running the group twice.
// orchestration/tests/test_console_groups.py checks that every Temporal job has the same group here.
$temporalGroups = config('orchestration.temporal_schedule_groups', []);
$unknownGroups = array_diff($temporalGroups, ['collectors', 'maintenance', 'spot', 'morning', 's-forecast']);
if ($unknownGroups !== []) {
    throw new RuntimeException('Unknown TEMPORAL_SCHEDULE_GROUPS value: '.implode(', ', $unknownGroups));
}
$inWebScheduler = fn (string $group): bool => ! in_array($group, $temporalGroups, true);

// Schedule the contracts:fetch command to run daily at 06:00
if ($inWebScheduler('morning')) {
    Schedule::command('contracts:fetch')
        ->dailyAt('06:00')
        ->timezone('Europe/Helsinki')
        ->withoutOverlapping()
        ->onOneServer()
        ->appendOutputTo(storage_path('logs/contracts-fetch.log'));
}

// Logos are on the web volume (public disk). The Temporal import runs contracts:fetch --skip-logos,
// so the web scheduler downloads them only when the morning group runs on Temporal.
if (! $inWebScheduler('morning')) {
    Schedule::command('logos:download')
        ->dailyAt('07:00')
        ->timezone('Europe/Helsinki')
        ->withoutOverlapping()
        ->onOneServer()
        ->appendOutputTo(storage_path('logs/logos-download.log'));
}

// Finish only proven same-day imports; never refetch or redispatch interpretations.
if ($inWebScheduler('morning')) {
    Schedule::command('contracts:complete-import')
        ->everyMinute()
        ->timezone('Europe/Helsinki')
        ->withoutOverlapping(35)
        ->onOneServer()
        ->appendOutputTo(storage_path('logs/contracts-complete-import.log'));
}

// File price caches are instance-local. All producers also share the execution lease.
if ($inWebScheduler('maintenance')) {
    Schedule::command('contracts:warm-cache --pending')
        ->everyMinute()
        ->runInBackground()
        ->withoutOverlapping(35)
        ->appendOutputTo(storage_path('logs/contract-price-cache-warm.log'));
}

// Delete only tracked retired price-cache payloads, after their one-hour reader grace period.
if ($inWebScheduler('maintenance')) {
    Schedule::command('contracts:cleanup-price-cache')
        ->everyFiveMinutes()
        ->timezone('Europe/Helsinki')
        ->withoutOverlapping(10)
        ->onOneServer()
        ->appendOutputTo(storage_path('logs/contract-price-cache-cleanup.log'));
}

// Local file payloads need cleanup on each instance, not just one server.
if ($inWebScheduler('maintenance')) {
    Schedule::command('cache:reclaim-expired-files --apply --scheduled')
        ->everyMinute()
        ->withoutOverlapping(2)
        ->appendOutputTo(storage_path('logs/expired-file-cache-cleanup.log'));
}

// Schedule the spot:fetch command to run hourly
if ($inWebScheduler('spot')) {
    Schedule::command('spot:fetch')
        ->hourly()
        ->timezone('Europe/Helsinki')
        ->withoutOverlapping(60)
        ->onOneServer()
        ->appendOutputTo(storage_path('logs/spot-fetch.log'));
}

// Neighbour-zone day-ahead prices for market reviews. Separate from spot:fetch so that a
// neighbour failure cannot affect the Finnish import; insert-only, so reruns are harmless.
if ($inWebScheduler('spot')) {
    Schedule::command('spot:fetch-neighbours')
        ->hourlyAt(25)
        ->timezone('Europe/Helsinki')
        ->withoutOverlapping(30)
        ->onOneServer()
        ->appendOutputTo(storage_path('logs/spot-fetch-neighbours.log'));
}

// Check official FI data independently after the hourly import has time to finish.
if ($inWebScheduler('spot')) {
    Schedule::command('spot:check-freshness')
        ->hourlyAt(10)
        ->timezone('Europe/Helsinki')
        ->onOneServer()
        ->appendOutputTo(storage_path('logs/spot-freshness-check.log'));
}

// Poll the lightweight third-party forecast feed a few times per day.
if ($inWebScheduler('spot')) {
    Schedule::command('spot:fetch-forecast')
        ->everySixHours()
        ->timezone('Europe/Helsinki')
        ->withoutOverlapping()
        ->onOneServer()
        ->appendOutputTo(storage_path('logs/spot-forecast-fetch.log'));
}

// Take a database-only disaster-recovery backup before overnight imports mutate source data.
if ($inWebScheduler('maintenance')) {
    Schedule::command('backup:run --only-db')
        ->dailyAt('03:00')
        ->timezone('Europe/Helsinki')
        ->withoutOverlapping()
        ->onOneServer()
        ->appendOutputTo(storage_path('logs/backup-run.log'));
}

// Back up public app storage weekly. This currently covers company logos and similar generated public files.
Schedule::command('backup:run --only-files')
    ->weeklyOn(0, '02:30')
    ->timezone('Europe/Helsinki')
    ->withoutOverlapping()
    ->onOneServer()
    ->appendOutputTo(storage_path('logs/backup-files-run.log'));

// Prune old backup archives according to config/backup.php retention settings.
if ($inWebScheduler('maintenance')) {
    Schedule::command('backup:clean')
        ->dailyAt('03:30')
        ->timezone('Europe/Helsinki')
        ->withoutOverlapping()
        ->onOneServer()
        ->appendOutputTo(storage_path('logs/backup-clean.log'));
}

// Check that a recent backup exists and stays within configured size bounds.
if ($inWebScheduler('maintenance')) {
    Schedule::command('backup:monitor')
        ->dailyAt('03:45')
        ->timezone('Europe/Helsinki')
        ->withoutOverlapping()
        ->onOneServer()
        ->appendOutputTo(storage_path('logs/backup-monitor.log'));
}

// Delete page feedback free text after 12 months (the privacy page states this period).
if ($inWebScheduler('maintenance')) {
    Schedule::command('feedback:purge-text')
        ->dailyAt('02:15')
        ->timezone('Europe/Helsinki')
        ->withoutOverlapping()
        ->onOneServer();
}

// Schedule EEX electricity futures settlement price collection overnight, after EEX FI data is reliably available.
if ($inWebScheduler('morning')) {
    Schedule::command('futures:fetch-eex')
        ->dailyAt('04:00')
        ->timezone('Europe/Helsinki')
        ->withoutOverlapping()
        ->onOneServer()
        ->appendOutputTo(storage_path('logs/eex-futures-fetch.log'));
}

// Fingrid system data for market reviews (about 30 requests at one per 2.5 s). The 3/16-day
// lookback refills late and corrected hours, so one daily run is enough.
if ($inWebScheduler('collectors')) {
    Schedule::command('fingrid:fetch')
        ->dailyAt('10:20')
        ->timezone('Europe/Helsinki')
        ->withoutOverlapping(60)
        ->onOneServer()
        ->appendOutputTo(storage_path('logs/fingrid-fetch.log'));
}

// JAO Nordic flow-based day-ahead import capacity into FI (market reviews). The default
// window covers today-2..tomorrow; tomorrow's values exist after the day-ahead auction.
if ($inWebScheduler('collectors')) {
    Schedule::command('jao:fetch')
        ->dailyAt('12:40')
        ->timezone('Europe/Helsinki')
        ->withoutOverlapping(30)
        ->onOneServer()
        ->appendOutputTo(storage_path('logs/jao-fetch.log'));
}

// EEX DE power, TTF gas and EUA carbon reference futures for market reviews (21 throttled
// calls, about 5 min). Separate scope: never writes the eex_futures checkpoint or bumps caches.
// EEX terms: internal use only until EEX approves publication.
if ($inWebScheduler('collectors')) {
    Schedule::command('futures:fetch-eex-europe')
        ->dailyAt('12:30')
        ->timezone('Europe/Helsinki')
        ->withoutOverlapping(30)
        ->onOneServer()
        ->appendOutputTo(storage_path('logs/eex-europe-fetch.log'));
}

// GIE AGSI+ EU and German gas storage (published in the evening CET). It runs only when
// GIE_API_KEY is set, so a missing key does not create a daily scheduler error.
if ($inWebScheduler('collectors')) {
    Schedule::command('gas-storage:fetch')
        ->dailyAt('10:35')
        ->when(fn () => filled(config('gas_storage.api_key')))
        ->timezone('Europe/Helsinki')
        ->withoutOverlapping(30)
        ->onOneServer()
        ->appendOutputTo(storage_path('logs/gas-storage-fetch.log'));
}

// ENTSO-E outage versions (A77/A78/A80), neighbour generation per type (A75) and reservoir
// filling (A72) for market reviews. The outage step takes about 2-3 minutes.
if ($inWebScheduler('collectors')) {
    Schedule::command('entsoe:fetch')
        ->dailyAt('10:50')
        ->timezone('Europe/Helsinki')
        ->withoutOverlapping(60)
        ->onOneServer()
        ->appendOutputTo(storage_path('logs/entsoe-transparency-fetch.log'));
}

// NVE publishes the Norwegian reservoir week on Wednesdays.
if ($inWebScheduler('collectors')) {
    Schedule::command('nve:fetch-reservoirs')
        ->weeklyOn(3, '15:30')
        ->timezone('Europe/Helsinki')
        ->withoutOverlapping(30)
        ->onOneServer()
        ->appendOutputTo(storage_path('logs/nve-fetch.log'));
}

// Open-Meteo weather for market reviews (free tier, non-commercial use): ERA5 window and the
// ECMWF 15-day ensemble daily, EC46 weekly, SEAS5 monthly (SEAS5 is published about the 5th).
if ($inWebScheduler('collectors')) {
    Schedule::command('weather:fetch')
        ->dailyAt('10:40')
        ->timezone('Europe/Helsinki')
        ->withoutOverlapping(60)
        ->onOneServer()
        ->appendOutputTo(storage_path('logs/weather-fetch.log'));
}

if ($inWebScheduler('collectors')) {
    Schedule::command('weather:fetch --only=ecmwf_ec46')
        ->weeklyOn(1, '10:50')
        ->timezone('Europe/Helsinki')
        ->withoutOverlapping(60)
        ->onOneServer()
        ->appendOutputTo(storage_path('logs/weather-fetch.log'));
}

if ($inWebScheduler('collectors')) {
    Schedule::command('weather:fetch --only=ecmwf_seas5')
        ->monthlyOn(8, '11:00')
        ->timezone('Europe/Helsinki')
        ->withoutOverlapping(60)
        ->onOneServer()
        ->appendOutputTo(storage_path('logs/weather-fetch.log'));
}

// Inputs for own spot price models (tasks/own-forecast-model-data/). Forecast vintages are fetched
// at 11:05 Helsinki, so that a model run at about 11:30 has them before the 13:00 day-ahead gate
// closure, and again at 23:05 for the 12Z model runs. Rows are append-only vintages.
// FMI: observations (72 h re-read) and edited/ECMWF point forecasts; no key, 600 requests / 5 min.
if ($inWebScheduler('collectors')) {
    Schedule::command('fmi:fetch')
        ->dailyAt('11:05')
        ->timezone('Europe/Helsinki')
        ->withoutOverlapping(30)
        ->onOneServer()
        ->appendOutputTo(storage_path('logs/fmi-fetch.log'));
}

if ($inWebScheduler('collectors')) {
    Schedule::command('fmi:fetch --only=forecast')
        ->dailyAt('23:05')
        ->timezone('Europe/Helsinki')
        ->withoutOverlapping(30)
        ->onOneServer()
        ->appendOutputTo(storage_path('logs/fmi-fetch.log'));
}

// Open-Meteo ECMWF HRES solar GHI vintages (7 calls each): 00Z run at 11:05, 12Z run at 23:05.
if ($inWebScheduler('collectors')) {
    Schedule::command('weather:fetch --only=solar_ghi')
        ->twiceDailyAt(11, 23, 5)
        ->timezone('Europe/Helsinki')
        ->withoutOverlapping(30)
        ->onOneServer()
        ->appendOutputTo(storage_path('logs/weather-fetch.log'));
}

// Own S model weather (s_v1): the ECMWF 00 UTC run of the day from the Open-Meteo Single Runs
// API. Each tick stores only the points still missing; a run that is not ready yet waits quietly.
// The availability time is not known yet (checked 2026-11-06 only after it), so try every hour.
if ($inWebScheduler('collectors')) {
    Schedule::command('weather:fetch --only=ecmwf_00z')
        ->hourlyAt(15)
        ->between('9:00', '14:30')
        ->timezone('Europe/Helsinki')
        ->withoutOverlapping(30)
        ->onOneServer()
        ->appendOutputTo(storage_path('logs/weather-fetch.log'));
}

// Own S model (s_v1): Previous Runs lead-1 temperature at the five southern stations for the 7 days
// before each issue (5 calls). Twice, so that both cuts (Fingrid 10:00 and 14:00) have a fetch.
if ($inWebScheduler('collectors')) {
    Schedule::command('weather:fetch --only=ecmwf_prev_day1')
        ->twiceDailyAt(9, 13, 40)
        ->timezone('Europe/Helsinki')
        ->withoutOverlapping(30)
        ->onOneServer()
        ->appendOutputTo(storage_path('logs/weather-fetch.log'));
}

// Fingrid 245 wind power forecast vintages (one request per run, 72 h ahead).
if ($inWebScheduler('collectors')) {
    Schedule::command('fingrid:fetch-forecast-vintages')
        ->hourlyAt(45)
        ->timezone('Europe/Helsinki')
        ->withoutOverlapping(10)
        ->onOneServer()
        ->appendOutputTo(storage_path('logs/fingrid-forecast-vintages.log'));
}

// Syke 5-day areal precipitation and snow water equivalent (slow features; 90-day re-read).
if ($inWebScheduler('collectors')) {
    Schedule::command('syke:fetch')
        ->dailyAt('11:10')
        ->timezone('Europe/Helsinki')
        ->withoutOverlapping(30)
        ->onOneServer()
        ->appendOutputTo(storage_path('logs/syke-fetch.log'));
}

// Collect per-contract retail premiums after the morning contract interpretations can publish.
if ($inWebScheduler('morning')) {
    Schedule::command('retail-premiums:collect --scheduled')
        ->everyFiveMinutes()
        ->timezone('Europe/Helsinki')
        ->between('07:15', '12:01') // Include the full noon tick despite process startup seconds.
        ->runInBackground()
        ->onOneServer()
        ->appendOutputTo(storage_path('logs/retail-premiums.log'));
}

// Re-measure market-reset pass-through (beta) monthly, on the 2nd, after the 1st-of-month resets
// have been imported and interpreted. Read-only. It logs a summary line and escalates to warning
// when the quarterly cadence becomes measurable and disagrees with the configured global beta, so
// the calibration surfaces itself instead of depending on someone reading a docs file.
if ($inWebScheduler('maintenance')) {
    Schedule::command('retail-premiums:calibrate')
        ->monthlyOn(2, '08:00')
        ->timezone('Europe/Helsinki')
        ->withoutOverlapping()
        ->onOneServer()
        ->appendOutputTo(storage_path('logs/retail-premium-calibration.log'));
}

// Run fixed-term price forecasts after the morning contract import/statistics update.
if ($inWebScheduler('morning')) {
    Schedule::command('forecasting:run-fixed-contracts --scheduled')
        ->everyFiveMinutes()
        ->timezone('Europe/Helsinki')
        ->between('07:30', '12:01') // The five-minute cron still ends at the noon deadline tick.
        ->runInBackground()
        ->onOneServer()
        ->appendOutputTo(storage_path('logs/fixed-contract-forecasts.log'));
}

// Evaluate matured fixed-term forecasts once fresh contract statistics should be available.
if ($inWebScheduler('morning')) {
    Schedule::command('forecasting:evaluate-fixed-contracts')
        ->dailyAt('07:45')
        ->timezone('Europe/Helsinki')
        ->withoutOverlapping()
        ->onOneServer()
        ->appendOutputTo(storage_path('logs/fixed-contract-forecast-evaluation.log'));
}

// Check each hour and publish only after complete hourly data exists for today and tomorrow.
if ($inWebScheduler('spot')) {
    Schedule::command('social:publish-daily-spot')
        ->hourlyAt(15)
        ->timezone('Europe/Helsinki')
        ->withoutOverlapping()
        ->onOneServer()
        ->appendOutputTo(storage_path('logs/daily-spot-social.log'));
}

// Schedule weekly offers video for Sunday at 13:00
if ($inWebScheduler('maintenance')) {
    Schedule::command('social:weekly-offers-video')
        ->weeklyOn(0, '13:00')  // 0 = Sunday
        ->timezone('Europe/Helsinki')
        ->withoutOverlapping()
        ->onOneServer()
        ->appendOutputTo(storage_path('logs/weekly-offers-video.log'));
}
