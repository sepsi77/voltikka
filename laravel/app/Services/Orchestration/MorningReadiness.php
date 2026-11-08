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
 */
class MorningReadiness
{
    public const KEYS = [
        DataFreshnessCheckpoint::KEY_EEX_FUTURES,
        DataFreshnessCheckpoint::KEY_CONTRACT_IMPORT,
        'morning_retail',
        'morning_forecast',
    ];

    /**
     * @return array{date: string, checkpoints: array<string, string|null>}
     */
    public function check(string $date): array
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1
            || CarbonImmutable::createFromFormat('!Y-m-d', $date)?->toDateString() !== $date) {
            throw new InvalidArgumentException('--date must be YYYY-MM-DD');
        }

        $statuses = DataFreshnessCheckpoint::query()
            ->whereIn('key', self::KEYS)
            ->whereDate('effective_date', $date)
            ->pluck('status', 'key');

        $checkpoints = [];
        foreach (self::KEYS as $key) {
            $checkpoints[$key] = $statuses[$key] ?? null;
        }

        return ['date' => $date, 'checkpoints' => $checkpoints];
    }
}
