<?php

namespace Tests\Feature;

use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

/**
 * The collectors group runs as Temporal Schedules in voltikka-worker
 * (orchestration/src/voltikka_orchestration/schedules.py, tested in orchestration/tests). The other
 * groups (maintenance, spot, morning) stay in the web scheduler until each moves in its own release
 * (tasks/temporal-orchestration/phase2-plan.md). A group must never run in both schedulers.
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

    public function test_the_collectors_group_is_not_in_the_web_scheduler(): void
    {
        $commands = $this->scheduledCommands();

        foreach ($commands as $command) {
            $name = strtok($command, ' ');
            $this->assertNotContains($name, self::COLLECTORS, "{$command} runs in Temporal (collectors group).");
        }
    }

    public function test_the_groups_that_have_not_moved_stay_in_the_web_scheduler(): void
    {
        $commands = $this->scheduledCommands();

        foreach (['contracts:fetch', 'spot:fetch', 'backup:run --only-db', 'retail-premiums:collect --scheduled'] as $command) {
            $this->assertContains($command, $commands);
        }
        // Logos move to the web scheduler only when the morning import moves to the worker.
        $this->assertNotContains('logos:download', $commands);
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
