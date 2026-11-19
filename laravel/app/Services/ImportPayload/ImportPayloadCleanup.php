<?php

namespace App\Services\ImportPayload;

use Carbon\CarbonImmutable;

/**
 * Retention for payload files (S6): a payload and its .sha256 file go when their Helsinki date
 * directory is more than 14 days old, a *.tmp file left by a killed fetch goes after 24 hours,
 * then expired date directories that are empty and source directories left empty go. The date
 * directory decides, never the file time. No open run can use a deleted file: Temporal schedules a
 * store (staged step, S forecast input, ImportReplay) only on a payload whose date directory stays
 * within RETENTION_DAYS until that store's one-day schedule-to-close ends, and a store that has not
 * ended by then fails with one Issue (orchestration/src/voltikka_orchestration/staged.py:
 * RETENTION_DAYS, STORE_SCHEDULE_TO_CLOSE, payload_expired; a test keeps RETENTION_DAYS equal).
 * Dry run unless $apply.
 */
final class ImportPayloadCleanup
{
    public const RETENTION_DAYS = 14;

    public const TMP_MAX_AGE_HOURS = 24;

    /** @return array{payload_files: list<string>, tmp_files: list<string>, directories: list<string>} */
    public function run(bool $apply, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $result = ['payload_files' => [], 'tmp_files' => [], 'directories' => []];
        $base = rtrim((string) config('import_payloads.path'), '/');
        if (! is_dir($base)) {
            return $result;
        }

        $oldestKept = $now->setTimezone('Europe/Helsinki')->subDays(self::RETENTION_DAYS)->toDateString();
        $tmpCutoff = $now->getTimestamp() - self::TMP_MAX_AGE_HOURS * 3600;

        foreach (self::entries($base) as $sourceDir) {
            if (! is_dir($sourceDir) || is_link($sourceDir)) {
                continue;
            }
            $sourceEntries = self::entries($sourceDir);
            $sourceRemoved = 0;
            foreach ($sourceEntries as $dateDir) {
                if (! is_dir($dateDir) || is_link($dateDir)) {
                    continue;
                }
                $expired = preg_match('/^\d{4}-\d{2}-\d{2}$/', basename($dateDir)) === 1 && basename($dateDir) < $oldestKept;
                $files = self::entries($dateDir);
                $removed = 0;
                foreach ($files as $file) {
                    $kind = match (true) {
                        ! is_file($file) || is_link($file) => null,
                        str_ends_with($file, '.tmp') => filemtime($file) < $tmpCutoff ? 'tmp_files' : null,
                        $expired && (str_ends_with($file, '.ndjson.gz') || str_ends_with($file, '.sha256')) => 'payload_files',
                        default => null,
                    };
                    if ($kind === null) {
                        continue;
                    }
                    $result[$kind][] = $file;
                    $removed++;
                    if ($apply) {
                        unlink($file);
                    }
                }
                // Only an expired date directory goes; a current one can be in use by a starting fetch.
                if ($expired && $removed === count($files)) {
                    $result['directories'][] = $dateDir;
                    $sourceRemoved++;
                    if ($apply) {
                        rmdir($dateDir);
                    }
                }
            }
            if ($sourceEntries !== [] && $sourceRemoved === count($sourceEntries)) {
                $result['directories'][] = $sourceDir;
                if ($apply) {
                    rmdir($sourceDir);
                }
            }
        }

        return $result;
    }

    /** @return list<string> */
    private static function entries(string $directory): array
    {
        return array_map(
            fn (string $name) => "{$directory}/{$name}",
            array_values(array_diff(scandir($directory) ?: [], ['.', '..'])),
        );
    }
}
