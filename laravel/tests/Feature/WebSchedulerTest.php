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
    /** Exact command strings: a name alone would let another variant of the command pass. */
    private const COLLECTORS = [
        'fingrid:fetch',
        'jao:fetch',
        'futures:fetch-eex-europe',
        'gas-storage:fetch',
        'entsoe:fetch',
        'nve:fetch-reservoirs',
        'weather:fetch',
        'weather:fetch --only=ecmwf_ec46',
        'weather:fetch --only=ecmwf_seas5',
        'weather:fetch --only=solar_ghi',
        'weather:fetch --only=ecmwf_00z',
        'weather:fetch --only=ecmwf_prev_day1',
        'fmi:fetch',
        'fmi:fetch --only=forecast',
        'fingrid:fetch-forecast-vintages',
        'syke:fetch',
    ];

    private const OTHER_GROUPS = [
        'contracts:fetch',
        'spot:fetch',
        'backup:run --only-db',
        'retail-premiums:collect --scheduled',
    ];

    public function test_every_group_stays_in_the_web_scheduler_until_its_vps_cut_over(): void
    {
        $commands = $this->scheduledCommands();

        foreach ([...self::COLLECTORS, ...self::OTHER_GROUPS] as $command) {
            $this->assertContains($command, $commands, "{$command} must run until its VPS cut-over.");
        }
        // Logos move to the web scheduler only when the morning import moves to the worker.
        $this->assertNotContains('logos:download', array_map(fn (string $command) => strtok($command, ' '), $commands));
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
