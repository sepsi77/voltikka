<?php

namespace App\Services\Orchestration;

use App\Models\DataFreshnessCheckpoint;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Checkpoint statuses of the morning chain for one Helsinki date (Temporal `MorningChain`).
 *
 * Only the latest status of each key is returned (null = no row). The workflow uses them to stop
 * polling: the producers and the consumers keep their own gates, claims and alerts.
 *
 * `scheduled_complete` tells for each consumer whether the scheduled default scope completed. A
 * successful manual subset run also leaves `ready`, but it does not certify the full daily scope
 * (MorningConsumerExecution::finish()), so `ready` alone is not completion.
 */
class MorningReadiness
{
    public const CONSUMER_KEYS = ['morning_retail', 'morning_forecast'];

    public const KEYS = [
        DataFreshnessCheckpoint::KEY_EEX_FUTURES,
        DataFreshnessCheckpoint::KEY_CONTRACT_IMPORT,
        ...self::CONSUMER_KEYS,
    ];

    /**
     * @return array{date: string, checkpoints: array<string, string|null>, scheduled_complete: array<string, bool>}
     */
    public function check(string $date): array
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1
            || CarbonImmutable::createFromFormat('!Y-m-d', $date)?->toDateString() !== $date) {
            throw new InvalidArgumentException('--date must be YYYY-MM-DD');
        }

        $rows = DataFreshnessCheckpoint::query()
            ->whereIn('key', self::KEYS)
            ->whereDate('effective_date', $date)
            ->get(['key', 'status', 'metadata'])
            ->keyBy('key');

        $checkpoints = [];
        foreach (self::KEYS as $key) {
            $checkpoints[$key] = $rows[$key]->status ?? null;
        }

        $scheduledComplete = [];
        foreach (self::CONSUMER_KEYS as $key) {
            $row = $rows[$key] ?? null;
            $scheduledComplete[$key] = $row !== null
                && $row->status === DataFreshnessCheckpoint::STATUS_READY
                && ($row->metadata['scheduled_complete'] ?? false) === true;
        }

        return ['date' => $date, 'checkpoints' => $checkpoints, 'scheduled_complete' => $scheduledComplete];
    }
}
