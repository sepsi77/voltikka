<?php

namespace Tests\Feature;

use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

/**
 * Data, maintenance and social jobs run as Temporal Schedules in voltikka-worker
 * (orchestration/src/voltikka_orchestration/schedules.py, tested in orchestration/tests). The web
 * scheduler keeps only the jobs that need the web volume, so a web deploy stops no data job.
 */
class WebSchedulerTest extends TestCase
{
    public function test_the_web_scheduler_runs_only_the_volume_jobs(): void
    {
        $events = collect(app(Schedule::class)->events())
            ->mapWithKeys(fn ($event) => [trim(preg_replace('/^.*artisan[\'"]?\s+/', '', (string) $event->command)) => $event]);

        $this->assertSame(['backup:run --only-files', 'logos:download'], $events->keys()->sort()->values()->all());
        $this->assertSame('30 2 * * 0', $events['backup:run --only-files']->expression);
        $this->assertSame('30 8 * * *', $events['logos:download']->expression);
        foreach ($events as $event) {
            $this->assertSame('Europe/Helsinki', $event->timezone);
            $this->assertTrue($event->onOneServer);
        }
    }
}
