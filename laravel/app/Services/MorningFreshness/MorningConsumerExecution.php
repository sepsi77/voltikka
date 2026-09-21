<?php

namespace App\Services\MorningFreshness;

use App\Models\DataFreshnessCheckpoint;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/** Durable exclusion for the two morning consumers, not an expiring execution lease. */
class MorningConsumerExecution
{
    public function run(string $consumer, CarbonImmutable $date, bool $scheduled, callable $ready, callable $execute): int
    {
        $owner = null;
        try {
            $now = CarbonImmutable::now('Europe/Helsinki');
            if ($scheduled && ($date->toDateString() !== $now->toDateString()
                || $now->format('H:i') < ($consumer === 'retail' ? '07:15' : '07:30'))) {
                return 0;
            }

            $this->ensure($consumer, $date);
            [$owner, $deadline] = DB::transaction(function () use ($consumer, $date, $scheduled, $now) {
                $row = $this->row($consumer, $date)->lockForUpdate()->firstOrFail();
                $facts = $row->metadata ?? [];
                if ($scheduled && $now->format('H:i') >= '12:00') {
                    return [null, $this->deadline($row)];
                }
                if ($row->status === DataFreshnessCheckpoint::STATUS_RUNNING
                    || $row->status === DataFreshnessCheckpoint::STATUS_FAILED
                    || ($facts['scheduled_complete'] ?? false)) {
                    return [null, null];
                }

                $owner = (string) Str::uuid();
                unset($facts['finished_at'], $facts['exception_class']);
                $row->update(['status' => DataFreshnessCheckpoint::STATUS_RUNNING, 'recorded_at' => now(), 'metadata' => [
                    ...$facts, 'owner' => $owner, 'reason' => 'started', 'started_at' => $now->toIso8601String(), 'scheduled_complete' => false,
                ]]);

                return [$owner, null];
            });
            $this->reportDeadline($deadline);
            if ($owner === null) {
                return $scheduled ? 0 : 1;
            }

            if (! $ready() || ($scheduled && CarbonImmutable::now('Europe/Helsinki')->gte($date->setTime(12, 0)))) {
                $deadline = DB::transaction(function () use ($consumer, $date, $owner, $scheduled) {
                    $row = $this->row($consumer, $date)->lockForUpdate()->firstOrFail();
                    if (($row->metadata['owner'] ?? null) !== $owner) {
                        throw new \RuntimeException('Consumer ownership changed.');
                    }
                    $deadlineReached = $scheduled && CarbonImmutable::now('Europe/Helsinki')->gte($date->setTime(12, 0));
                    $row->update(['status' => 'waiting', 'recorded_at' => now(),
                        'metadata' => [...$row->metadata, 'reason' => $deadlineReached ? 'deadline_reached' : 'dependencies_not_ready']]);

                    return $deadlineReached ? $this->deadline($row) : null;
                });
                $this->reportDeadline($deadline);

                return $scheduled ? 0 : 1;
            }

            $fence = function () use ($consumer, $date, $owner, $scheduled): void {
                $row = $this->row($consumer, $date)->lockForUpdate()->firstOrFail();
                if (($row->metadata['owner'] ?? null) !== $owner || $row->status !== DataFreshnessCheckpoint::STATUS_RUNNING
                    || ($scheduled && CarbonImmutable::now('Europe/Helsinki')->toDateString() !== $date->toDateString())) {
                    throw new \RuntimeException('Consumer ownership or date changed.');
                }
            };
            $exit = $execute($fence);
            $this->finish($consumer, $date, $owner, $exit === 0, $scheduled);

            // A durable terminal outcome owns its alert, not each scheduler tick.
            return $scheduled ? 0 : $exit;
        } catch (Throwable $exception) {
            if ($owner !== null) {
                try {
                    $this->finish($consumer, $date, $owner, false, $scheduled, $exception::class);

                    return $scheduled ? 0 : 1;
                } catch (Throwable) {
                    // A checkpoint outage must stay visible even before durable state exists.
                }
            }
            Log::error('Morning consumer checkpoint or preflight failed.', ['consumer' => $consumer, 'date' => $date->toDateString(), 'exception_class' => $exception::class]);

            return 1;
        }
    }

    private function finish(string $consumer, CarbonImmutable $date, string $owner, bool $success, bool $scheduled, ?string $exceptionClass = null): void
    {
        $deadline = DB::transaction(function () use ($consumer, $date, $owner, $success, $scheduled, $exceptionClass) {
            $row = $this->row($consumer, $date)->lockForUpdate()->firstOrFail();
            $facts = $row->metadata ?? [];
            if (($facts['owner'] ?? null) !== $owner || $row->status !== DataFreshnessCheckpoint::STATUS_RUNNING) {
                throw new \RuntimeException('Consumer ownership changed.');
            }
            $deadline = null;
            if ($scheduled && CarbonImmutable::now('Europe/Helsinki')->gte($date->setTime(12, 0))) {
                $deadline = $this->deadline($row);
                $facts = $row->metadata;
            }
            $row->update(['status' => $success ? DataFreshnessCheckpoint::STATUS_READY : DataFreshnessCheckpoint::STATUS_FAILED,
                'recorded_at' => now(), 'metadata' => [...$facts, 'scheduled_complete' => $success && $scheduled,
                    'finished_at' => CarbonImmutable::now('Europe/Helsinki')->toIso8601String(),
                    'reason' => $success ? 'completed' : 'execution_failed', 'exception_class' => $exceptionClass]]);

            return $deadline;
        });
        $this->reportDeadline($deadline);
        if (! $success) {
            Log::error('Morning consumer execution failed; inspect before further writes.', ['consumer' => $consumer, 'date' => $date->toDateString(), 'exception_class' => $exceptionClass]);
        }
    }

    private function deadline(DataFreshnessCheckpoint $row): ?array
    {
        $facts = $row->metadata ?? [];
        if (($facts['scheduled_complete'] ?? false) || isset($facts['deadline_alerted_at'])) {
            return null;
        }
        $row->update(['metadata' => [...$facts, 'deadline_alerted_at' => CarbonImmutable::now('Europe/Helsinki')->toIso8601String()]]);

        return ['consumer' => $row->key, 'date' => $row->effective_date->toDateString(), 'status' => $row->status];
    }

    private function reportDeadline(?array $context): void
    {
        if ($context !== null) {
            Log::error('Morning consumer deadline missed; inspect incomplete work.', $context);
        }
    }

    private function ensure(string $consumer, CarbonImmutable $date): void
    {
        if ($this->row($consumer, $date)->exists()) {
            return;
        }
        DataFreshnessCheckpoint::query()->insertOrIgnore([
            'key' => 'morning_'.$consumer, 'effective_date' => $date->toDateString(), 'status' => 'waiting',
            'metadata' => '{}', 'recorded_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function row(string $consumer, CarbonImmutable $date): Builder
    {
        return DataFreshnessCheckpoint::query()->where('key', 'morning_'.$consumer)->whereDate('effective_date', $date->toDateString());
    }
}
