<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Every data, maintenance and social job runs as a Temporal Schedule in the voltikka-worker
// service (orchestration/src/voltikka_orchestration/schedules.py; tasks/temporal-orchestration/
// phase2-plan.md), so a web deploy never stops one. Only the jobs that need the web volume
// (/app/storage) stay here. Do not add a data job to this file.

// Back up public app storage weekly. This currently covers company logos and similar generated public files.
Schedule::command('backup:run --only-files')
    ->weeklyOn(0, '02:30')
    ->timezone('Europe/Helsinki')
    ->withoutOverlapping()
    ->onOneServer()
    ->appendOutputTo(storage_path('logs/backup-files-run.log'));

// Company logos live on the web public disk. The worker import runs `contracts:fetch --skip-logos`,
// so the web service downloads missing logos after the 06:00 import (idempotent: only companies
// without a stored logo).
Schedule::command('logos:download')
    ->dailyAt('08:30')
    ->timezone('Europe/Helsinki')
    ->withoutOverlapping()
    ->onOneServer()
    ->appendOutputTo(storage_path('logs/logos-download.log'));
