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

// Note: social:daily-video is triggered automatically by spot:fetch
// when tomorrow's prices become available (typically around 13:00-14:00 Finnish time)

// Schedule weekly offers video for Sunday at 13:00
Schedule::command('social:weekly-offers-video')
    ->weeklyOn(0, '13:00')  // 0 = Sunday
    ->timezone('Europe/Helsinki')
    ->withoutOverlapping()
    ->onOneServer()
    ->appendOutputTo(storage_path('logs/weekly-offers-video.log'));
