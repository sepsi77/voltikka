<?php

namespace Tests\Feature;

use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskSkipped;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Console\Scheduling\EventMutex;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class ScheduledTaskFailureLoggingTest extends TestCase
{
    public function test_non_zero_finished_task_logs_an_error(): void
    {
        Log::spy();
        $task = $this->task();
        $task->exitCode = 2;

        Event::dispatch(new ScheduledTaskFinished($task, 1.25));

        Log::shouldHaveReceived('error')
            ->once()
            ->with('Scheduled task returned a non-zero exit code.', [
                ...$this->taskContext(),
                'exit_code' => 2,
                'runtime_seconds' => 1.25,
            ]);
    }

    public function test_successful_finished_task_does_not_log_an_error(): void
    {
        Log::spy();
        $task = $this->task();
        $task->exitCode = 0;

        Event::dispatch(new ScheduledTaskFinished($task, 0.5));

        Log::shouldNotHaveReceived('error');
    }

    public function test_failed_task_logs_safe_exception_context_without_message(): void
    {
        Log::spy();

        Event::dispatch(new ScheduledTaskFailed(
            $this->task(),
            new RuntimeException('secret-token-must-not-be-logged'),
        ));

        Log::shouldHaveReceived('error')
            ->once()
            ->with('Scheduled task threw an exception.', [
                ...$this->taskContext(),
                'exception_class' => RuntimeException::class,
            ]);
    }

    public function test_skipped_task_with_overlap_protection_logs_an_error(): void
    {
        Log::spy();
        $task = $this->task();
        $task->withoutOverlapping = true;

        Event::dispatch(new ScheduledTaskSkipped($task));

        Log::shouldHaveReceived('error')
            ->once()
            ->with(
                'Scheduled task was skipped because of an overlap lock.',
                $this->taskContext(),
            );
    }

    public function test_deliberately_skipped_task_without_overlap_protection_does_not_log_an_error(): void
    {
        Log::spy();

        Event::dispatch(new ScheduledTaskSkipped($this->task()));

        Log::shouldNotHaveReceived('error');
    }

    private function task(): ScheduledEvent
    {
        return (new ScheduledEvent(
            Mockery::mock(EventMutex::class),
            'php artisan example:scheduled',
        ))
            ->description('Example scheduled task')
            ->cron('15 7 * * *')
            ->timezone('Europe/Helsinki');
    }

    /**
     * @return array{task: string, cron_expression: string, timezone: string}
     */
    private function taskContext(): array
    {
        return [
            'task' => 'Example scheduled task',
            'cron_expression' => '15 7 * * *',
            'timezone' => 'Europe/Helsinki',
        ];
    }
}
