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

// Schedule the descriptions:generate command to run daily at 08:00
Schedule::command('descriptions:generate')
    ->dailyAt('08:00')
    ->timezone('Europe/Helsinki')
    ->withoutOverlapping()
    ->onOneServer()
    ->appendOutputTo(storage_path('logs/descriptions-generate.log'));
