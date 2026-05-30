<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Schedule the contracts:fetch command to run daily at 06:00
Schedule::command('contracts:fetch')
    ->dailyAt('06:00')
    ->timezone('Europe/Helsinki')
    ->withoutOverlapping()
    ->onOneServer()
    ->appendOutputTo(storage_path('logs/contracts-fetch.log'));

// Schedule the spot:fetch command to run hourly
Schedule::command('spot:fetch')
    ->hourly()
    ->timezone('Europe/Helsinki')
    ->withoutOverlapping()
    ->onOneServer()
    ->appendOutputTo(storage_path('logs/spot-fetch.log'));

// Poll the lightweight third-party forecast feed a few times per day.
Schedule::command('spot:fetch-forecast')
    ->everySixHours()
    ->timezone('Europe/Helsinki')
    ->withoutOverlapping()
    ->onOneServer()
    ->appendOutputTo(storage_path('logs/spot-forecast-fetch.log'));

// Take a database-only disaster-recovery backup before overnight imports mutate source data.
Schedule::command('backup:run --only-db')
    ->dailyAt('03:00')
    ->timezone('Europe/Helsinki')
    ->withoutOverlapping()
    ->onOneServer()
    ->appendOutputTo(storage_path('logs/backup-run.log'));

// Back up public app storage weekly. This currently covers company logos and similar generated public files.
Schedule::command('backup:run --only-files')
    ->weeklyOn(0, '02:30')
    ->timezone('Europe/Helsinki')
    ->withoutOverlapping()
    ->onOneServer()
    ->appendOutputTo(storage_path('logs/backup-files-run.log'));

// Prune old backup archives according to config/backup.php retention settings.
Schedule::command('backup:clean')
    ->dailyAt('03:30')
    ->timezone('Europe/Helsinki')
    ->withoutOverlapping()
    ->onOneServer()
    ->appendOutputTo(storage_path('logs/backup-clean.log'));

// Check that a recent backup exists and stays within configured size bounds.
Schedule::command('backup:monitor')
    ->dailyAt('03:45')
    ->timezone('Europe/Helsinki')
    ->withoutOverlapping()
    ->onOneServer()
    ->appendOutputTo(storage_path('logs/backup-monitor.log'));

// Schedule EEX electricity futures settlement price collection overnight, after EEX FI data is reliably available.
Schedule::command('futures:fetch-eex')
    ->dailyAt('04:00')
    ->timezone('Europe/Helsinki')
    ->withoutOverlapping()
    ->onOneServer()
    ->appendOutputTo(storage_path('logs/eex-futures-fetch.log'));

// Run fixed-term price forecasts after the morning contract import/statistics update.
Schedule::command('forecasting:run-fixed-contracts')
    ->dailyAt('07:30')
    ->timezone('Europe/Helsinki')
    ->withoutOverlapping()
    ->onOneServer()
    ->appendOutputTo(storage_path('logs/fixed-contract-forecasts.log'));

// Evaluate matured fixed-term forecasts once fresh contract statistics should be available.
Schedule::command('forecasting:evaluate-fixed-contracts')
    ->dailyAt('07:45')
    ->timezone('Europe/Helsinki')
    ->withoutOverlapping()
    ->onOneServer()
    ->appendOutputTo(storage_path('logs/fixed-contract-forecast-evaluation.log'));

// Note: social:daily-video is triggered automatically by spot:fetch
// when tomorrow's prices become available (typically around 13:00-14:00 Finnish time)

// Schedule weekly offers video for Sunday at 13:00
Schedule::command('social:weekly-offers-video')
    ->weeklyOn(0, '13:00')  // 0 = Sunday
    ->timezone('Europe/Helsinki')
    ->withoutOverlapping()
    ->onOneServer()
    ->appendOutputTo(storage_path('logs/weekly-offers-video.log'));
