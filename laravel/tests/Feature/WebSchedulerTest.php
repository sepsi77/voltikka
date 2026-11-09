<?php

namespace Tests\Feature;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Schedule as ScheduleFacade;
use RuntimeException;
use Tests\TestCase;

/**
 * Each phase 2 group (collectors, maintenance, spot, morning) runs in the web scheduler unless
 * TEMPORAL_SCHEDULE_GROUPS (config orchestration.temporal_schedule_groups) lists it. Railway leaves
 * it empty, so every group runs there; the VPS lists each group after its Temporal Schedules are
 * applied (tasks/vps-migration/). A group must never run in both schedulers.
 */
class WebSchedulerTest extends TestCase
{
    /** Exact command strings: a name alone would let another variant of the command pass. */
    private const GROUPS = [
        'collectors' => [
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
        ],
        'maintenance' => [
            'contracts:warm-cache --pending',
            'contracts:cleanup-price-cache',
            'cache:reclaim-expired-files --apply --scheduled',
            'backup:run --only-db',
            'backup:clean',
            'backup:monitor',
            'feedback:purge-text',
            'retail-premiums:calibrate',
            'social:weekly-offers-video',
        ],
        'spot' => [
            'spot:fetch',
            'spot:fetch-neighbours',
            'spot:check-freshness',
            'spot:fetch-forecast',
            'social:publish-daily-spot',
        ],
        'morning' => [
            'contracts:fetch',
            'contracts:complete-import',
            'futures:fetch-eex',
            'retail-premiums:collect --scheduled',
            'forecasting:run-fixed-contracts --scheduled',
            'forecasting:evaluate-fixed-contracts',
        ],
    ];

    /** Jobs that need the web volume stay in the web scheduler in every configuration. */
    private const WEB_ONLY = ['backup:run --only-files'];

    public function test_every_group_runs_in_the_web_scheduler_when_no_group_is_listed(): void
    {
        $commands = $this->scheduledCommands([]);

        $expected = [...array_merge(...array_values(self::GROUPS)), ...self::WEB_ONLY];
        sort($expected);
        $this->assertSame($expected, $commands);
        // Logos move to the web scheduler only when the morning import moves to the worker.
        $this->assertNotContains('logos:download', array_map(fn (string $command) => strtok($command, ' '), $commands));
    }

    public function test_a_listed_group_leaves_the_web_scheduler_and_the_others_stay(): void
    {
        foreach (self::GROUPS as $group => $groupCommands) {
            $commands = $this->scheduledCommands([$group]);

            foreach ($groupCommands as $command) {
                $this->assertNotContains($command, $commands, "{$command} must not run in the web scheduler when {$group} runs on Temporal.");
            }
            foreach (self::GROUPS as $other => $otherCommands) {
                if ($other !== $group) {
                    foreach ($otherCommands as $command) {
                        $this->assertContains($command, $commands, "{$command} must stay when only {$group} runs on Temporal.");
                    }
                }
            }
            foreach (self::WEB_ONLY as $command) {
                $this->assertContains($command, $commands);
            }
            $logos = in_array('logos:download', $commands, true);
            $this->assertSame($group === 'morning', $logos, 'logos:download runs in the web scheduler only when the morning import runs on Temporal.');
        }
    }

    public function test_every_group_on_temporal_leaves_only_the_web_volume_jobs_and_the_logo_download(): void
    {
        $expected = [...self::WEB_ONLY, 'logos:download'];
        sort($expected);
        $this->assertSame($expected, $this->scheduledCommands(['collectors', 'maintenance', 'spot', 'morning', 's-forecast']));
    }

    public function test_an_unknown_group_name_stops_the_scheduler(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unknown TEMPORAL_SCHEDULE_GROUPS value: collector');

        $this->scheduledCommands(['collector']);
    }

    /**
     * Loads routes/console.php again into an empty schedule with the given groups on Temporal.
     *
     * @param  list<string>  $temporalGroups
     * @return list<string>
     */
    private function scheduledCommands(array $temporalGroups): array
    {
        config(['orchestration.temporal_schedule_groups' => $temporalGroups]);
        $this->app->instance(Schedule::class, new Schedule);
        ScheduleFacade::clearResolvedInstances();

        require base_path('routes/console.php');

        return collect(app(Schedule::class)->events())
            ->map(fn ($event) => trim(preg_replace('/^.*artisan[\'"]?\s+/', '', (string) $event->command)))
            ->sort()
            ->values()
            ->all();
    }
}
