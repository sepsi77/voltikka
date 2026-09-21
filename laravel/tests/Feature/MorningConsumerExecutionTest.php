<?php

namespace Tests\Feature;

use App\Models\DataFreshnessCheckpoint;
use App\Services\MorningFreshness\MorningConsumerExecution;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class MorningConsumerExecutionTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_late_readiness_completes_at_next_tick_and_success_is_inert(): void
    {
        Log::spy();
        $service = app(MorningConsumerExecution::class);
        $date = CarbonImmutable::parse('2026-09-20', 'Europe/Helsinki');
        $writes = 0;
        foreach (['07:30', '07:35', '07:40', '07:45', '12:00', '13:00'] as $time) {
            CarbonImmutable::setTestNow("2026-09-20 {$time} Europe/Helsinki");
            $this->assertSame(0, $service->run('forecast', $date, true,
                fn () => CarbonImmutable::now('Europe/Helsinki')->format('H:i') >= '07:36',
                function () use (&$writes) {
                    $writes++;

                    return 0;
                }));
        }
        $this->assertSame(1, $writes);
        $this->assertSame('ready', DataFreshnessCheckpoint::sole()->status);
        $this->assertSame('2026-09-20T07:40:00+03:00', DataFreshnessCheckpoint::sole()->metadata['finished_at']);
        Log::shouldNotHaveReceived('error');
    }

    public function test_running_manual_writer_blocks_both_modes_but_not_other_consumer(): void
    {
        CarbonImmutable::setTestNow('2026-09-20 08:00 Europe/Helsinki');
        $service = app(MorningConsumerExecution::class);
        $date = CarbonImmutable::today('Europe/Helsinki');
        $never = function () {
            $this->fail('Blocked writer executed.');
        };
        $this->assertSame(0, $service->run('retail', $date, false, fn () => true, function () use ($service, $date, $never) {
            $this->assertSame(0, $service->run('retail', $date, true, $never, $never));
            $this->assertSame(1, $service->run('retail', $date, false, $never, $never));
            $this->assertSame(0, $service->run('forecast', $date, true, fn () => true, fn () => 0));

            return 0;
        }));
    }

    public function test_interrupted_writer_never_retries_and_deadline_alert_is_independent_and_once(): void
    {
        Log::spy();
        CarbonImmutable::setTestNow('2026-09-20 08:00 Europe/Helsinki');
        DataFreshnessCheckpoint::create(['key' => 'morning_retail', 'effective_date' => '2026-09-20',
            'status' => 'running', 'metadata' => ['owner' => 'interrupted', 'started_at' => '2026-09-20T07:15:00+03:00'], 'recorded_at' => now()]);
        $service = app(MorningConsumerExecution::class);
        $date = CarbonImmutable::today('Europe/Helsinki');
        $never = function () {
            $this->fail('Interrupted writer was retried.');
        };
        foreach (['08:00', '11:55', '12:00', '12:05', '23:55'] as $time) {
            CarbonImmutable::setTestNow("2026-09-20 {$time} Europe/Helsinki");
            $this->assertSame(0, $service->run('retail', $date, true, $never, $never));
        }
        $this->assertSame('running', DataFreshnessCheckpoint::sole()->status);
        $this->assertSame(1, $service->run('retail', $date, false, $never, $never));
        Log::shouldHaveReceived('error')->once()->with('Morning consumer deadline missed; inspect incomplete work.', \Mockery::any());
    }

    public function test_execution_failure_and_preflight_exception_are_terminal_not_waiting(): void
    {
        Log::spy();
        CarbonImmutable::setTestNow('2026-09-20 08:00 Europe/Helsinki');
        $service = app(MorningConsumerExecution::class);
        $date = CarbonImmutable::today('Europe/Helsinki');
        $never = function () {
            $this->fail('Terminal writer retried.');
        };
        $this->assertSame(0, $service->run('retail', $date, true, fn () => true, fn () => 1));
        $this->assertSame(0, $service->run('forecast', $date, true, fn () => throw new \RuntimeException('private detail'), $never));
        foreach (['retail', 'forecast'] as $consumer) {
            $this->assertSame(0, $service->run($consumer, $date, true, $never, $never));
        }
        $this->assertSame(2, DataFreshnessCheckpoint::where('status', 'failed')->count());
        Log::shouldHaveReceived('error')->twice()->with('Morning consumer execution failed; inspect before further writes.', \Mockery::on(fn ($context) => ! str_contains(json_encode($context), 'private detail')));
    }

    public function test_stale_owner_fence_stops_writes_without_replacing_new_facts(): void
    {
        Log::spy();
        CarbonImmutable::setTestNow('2026-09-20 08:00 Europe/Helsinki');
        $service = app(MorningConsumerExecution::class);
        $date = CarbonImmutable::today('Europe/Helsinki');
        $this->assertSame(1, $service->run('retail', $date, false, fn () => true, function ($fence) {
            DataFreshnessCheckpoint::sole()->update(['metadata' => ['owner' => 'new-owner']]);
            DB::transaction($fence);
            $this->fail('Stale fence permitted writes.');
        }));
        $this->assertSame('new-owner', DataFreshnessCheckpoint::sole()->metadata['owner']);
        $this->assertSame('running', DataFreshnessCheckpoint::sole()->status);
    }

    public function test_current_date_and_windows_follow_helsinki_across_dst_and_midnight(): void
    {
        Log::spy();
        $service = app(MorningConsumerExecution::class);
        $never = function () {
            $this->fail('Outside the current-day window.');
        };
        foreach (['2026-03-29', '2026-10-25'] as $day) {
            $date = CarbonImmutable::parse($day, 'Europe/Helsinki');
            CarbonImmutable::setTestNow($date->setTime(7, 10));
            $this->assertSame(0, $service->run('retail', $date, true, $never, $never));
            CarbonImmutable::setTestNow($date->setTime(7, 15));
            $this->assertSame(0, $service->run('retail', $date, true, fn () => true, fn () => 0));
            $this->assertSame(0, $service->run('forecast', $date, true, $never, $never));
            CarbonImmutable::setTestNow($date->addDay()->setTime(8, 0));
            $this->assertSame(0, $service->run('forecast', $date, true, $never, $never));
        }
        $this->assertDatabaseCount('data_freshness_checkpoints', 2);
    }

    public function test_running_work_can_finish_after_independent_noon_alert_without_a_second_alert(): void
    {
        Log::spy();
        CarbonImmutable::setTestNow('2026-09-20 11:55 Europe/Helsinki');
        $service = app(MorningConsumerExecution::class);
        $date = CarbonImmutable::today('Europe/Helsinki');
        $service->run('retail', $date, true, fn () => true, function ($fence) use ($service, $date) {
            CarbonImmutable::setTestNow('2026-09-20 12:00 Europe/Helsinki');
            $never = function () {
                $this->fail('Deadline tick started a second writer.');
            };
            $service->run('retail', $date, true, $never, $never);
            DB::transaction($fence);

            return 0;
        });
        $this->assertSame('ready', DataFreshnessCheckpoint::sole()->status);
        $this->assertTrue(DataFreshnessCheckpoint::sole()->metadata['scheduled_complete']);
        Log::shouldHaveReceived('error')->once();
    }

    public function test_preflight_crossing_noon_cannot_start_output_or_create_a_false_execution_alert(): void
    {
        Log::spy();
        CarbonImmutable::setTestNow('2026-09-20 11:55 Europe/Helsinki');
        app(MorningConsumerExecution::class)->run('retail', CarbonImmutable::today('Europe/Helsinki'), true, function () {
            CarbonImmutable::setTestNow('2026-09-20 12:00 Europe/Helsinki');

            return true;
        }, function () {
            $this->fail('Output started after the deadline.');
        });
        $this->assertSame('waiting', DataFreshnessCheckpoint::sole()->status);
        $this->assertSame('deadline_reached', DataFreshnessCheckpoint::sole()->metadata['reason']);
        Log::shouldHaveReceived('error')->once()->with('Morning consumer deadline missed; inspect incomplete work.', \Mockery::any());
    }

    public function test_checkpoint_creation_failure_is_visible_without_execution(): void
    {
        Log::spy();
        CarbonImmutable::setTestNow('2026-09-20 08:00 Europe/Helsinki');
        Schema::rename('data_freshness_checkpoints', 'unavailable_checkpoints');
        try {
            $never = function () {
                $this->fail('Writer ran without a checkpoint.');
            };
            $this->assertSame(1, app(MorningConsumerExecution::class)->run('retail', CarbonImmutable::today('Europe/Helsinki'), true, $never, $never));
            Log::shouldHaveReceived('error')->once()->with('Morning consumer checkpoint or preflight failed.', \Mockery::any());
        } finally {
            Schema::rename('unavailable_checkpoints', 'data_freshness_checkpoints');
        }
    }

    public function test_midnight_blocks_an_old_scheduled_writer_at_its_write_fence(): void
    {
        Log::spy();
        CarbonImmutable::setTestNow('2026-09-20 08:00 Europe/Helsinki');
        app(MorningConsumerExecution::class)->run('retail', CarbonImmutable::today('Europe/Helsinki'), true, fn () => true, function ($fence) {
            CarbonImmutable::setTestNow('2026-09-21 00:00 Europe/Helsinki');
            DB::transaction($fence);
            $this->fail('Previous-day writer passed its fence.');
        });
        $this->assertSame('failed', DataFreshnessCheckpoint::sole()->status);
    }

    public function test_schedules_are_independent_background_windows_without_overlap_mutex(): void
    {
        foreach (['retail-premiums:collect' => '07:15', 'forecasting:run-fixed-contracts' => '07:30'] as $command => $start) {
            $event = collect(app(Schedule::class)->events())
                ->first(fn ($event) => str_contains($event->command ?? '', $command));
            $this->assertNotNull($event);
            $this->assertStringContainsString('--scheduled', $event->command);
            $this->assertSame('*/5 * * * *', $event->expression);
            $this->assertSame('Europe/Helsinki', $event->timezone);
            $this->assertTrue($event->runInBackground);
            $this->assertFalse($event->withoutOverlapping);
            $this->assertTrue($event->onOneServer);
            foreach (['07:10' => false, $start => true, '07:40' => true, '12:00' => true, '12:00:59' => true, '12:01' => false, '12:05' => false] as $time => $expected) {
                CarbonImmutable::setTestNow("2026-10-25 {$time} Europe/Helsinki");
                // Laravel captures between() times when each schedule:run registers its events.
                $schedule = new Schedule;
                \Illuminate\Support\Facades\Schedule::swap($schedule);
                require base_path('routes/console.php');
                $currentEvent = collect($schedule->events())->first(fn ($event) => str_contains($event->command ?? '', $command));
                $this->assertSame($expected, $currentEvent->isDue($this->app) && $currentEvent->filtersPass($this->app), $command.' '.$time);
            }
        }
        $evaluation = collect(app(Schedule::class)->events())
            ->first(fn ($event) => str_contains($event->command ?? '', 'forecasting:evaluate-fixed-contracts'));
        $this->assertSame('45 7 * * *', $evaluation->expression);
    }

    public function test_noon_alert_is_once_even_without_an_earlier_tick(): void
    {
        Log::spy();
        CarbonImmutable::setTestNow('2026-09-20 12:00 Europe/Helsinki');
        $service = app(MorningConsumerExecution::class);
        $never = function () {
            $this->fail('No new execution at noon.');
        };
        for ($i = 0; $i < 3; $i++) {
            $service->run('forecast', CarbonImmutable::today('Europe/Helsinki'), true, $never, $never);
        }
        Log::shouldHaveReceived('error')->once();
        $this->assertArrayHasKey('deadline_alerted_at', DataFreshnessCheckpoint::sole()->metadata);
    }
}
