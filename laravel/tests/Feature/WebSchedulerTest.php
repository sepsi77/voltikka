<?php

namespace Tests\Feature;

use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

/**
 * Every group (collectors, maintenance, spot, morning) stays in the web scheduler on Railway. The
 * Temporal Schedules (orchestration/src/voltikka_orchestration/schedules.py) go live only on the VPS
 * (tasks/vps-migration/), and each group leaves this scheduler in its cut-over release. A group must
 * never run in both schedulers.
 */
class WebSchedulerTest extends TestCase
{
    private const COLLECTORS = [
        'fingrid:fetch',
        'jao:fetch',
        'futures:fetch-eex-europe',
        'gas-storage:fetch',
        'entsoe:fetch',
        'nve:fetch-reservoirs',
        'weather:fetch',
        'fmi:fetch',
        'fingrid:fetch-forecast-vintages',
        'syke:fetch',
    ];

    public function test_every_group_stays_in_the_web_scheduler_until_its_vps_cut_over(): void
    {
        $names = array_map(fn (string $command) => strtok($command, ' '), $this->scheduledCommands());

        foreach (self::COLLECTORS as $collector) {
            $this->assertContains($collector, $names, "{$collector} must run until the VPS cut-over.");
        }
        foreach (['contracts:fetch', 'spot:fetch', 'backup:run', 'retail-premiums:collect'] as $command) {
            $this->assertContains($command, $names);
        }
        // Logos move to the web scheduler only when the morning import moves to the worker.
        $this->assertNotContains('logos:download', $names);
    }

    /** @return list<string> */
    private function scheduledCommands(): array
    {
        return collect(app(Schedule::class)->events())
            ->map(fn ($event) => trim(preg_replace('/^.*artisan[\'"]?\s+/', '', (string) $event->command)))
            ->values()
            ->all();
    }
}
