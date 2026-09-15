<?php

namespace App\Services\Caching;

use Illuminate\Cache\FileStore;
use RuntimeException;

class ExpiredFileCacheCleanup
{
    public function scan(FileStore $store, bool $apply, bool $scheduled, int $maxSeconds): array
    {
        $result = ['scanned' => 0, 'eligible' => 0, 'reclaimed' => 0, 'skipped' => 0, 'bytes' => 0, 'reclaimed_bytes' => 0, 'errors' => 0, 'incomplete' => false];
        $deadline = hrtime(true) + $maxSeconds * 1_000_000_000;
        $minute = intdiv(now()->timestamp, 60);
        $root = rtrim($store->getDirectory(), '/');

        for ($i = 0; $i < ($scheduled ? 1 : 256); $i++) {
            $first = sprintf('%02x', ($minute + $i) % 256);
            for ($j = 0; $j < 256; $j++) {
                if (hrtime(true) >= $deadline) {
                    $result['incomplete'] = true;

                    return $result;
                }
                $second = sprintf('%02x', (intdiv($minute, 256) + $j) % 256);
                $directory = "$root/$first/$second";
                if (! $this->safeDirectories($root, $first, $second)) {
                    continue;
                }
                $handle = @opendir($directory);
                if ($handle === false) {
                    $result['errors']++;

                    continue;
                }
                try {
                    while (($name = readdir($handle)) !== false) {
                        if (hrtime(true) >= $deadline) {
                            $result['incomplete'] = true;

                            return $result;
                        }
                        if ($name === '.' || $name === '..') {
                            continue;
                        }
                        $result['scanned']++;
                        if (! preg_match('/\A'.$first.$second.'[0-9a-f]{36}\z/', $name)) {
                            $result['skipped']++;

                            continue;
                        }
                        try {
                            $bytes = $this->reclaim("$directory/$name", $apply, $root, $first, $second);
                            if ($bytes === 0) {
                                $result['skipped']++;
                            } else {
                                $result['eligible']++;
                                $result['bytes'] += $bytes;
                                if ($apply) {
                                    $result['reclaimed']++;
                                    $result['reclaimed_bytes'] += $bytes;
                                }
                            }
                        } catch (RuntimeException) {
                            $result['errors']++;
                        }
                    }
                } finally {
                    closedir($handle);
                }
            }
        }

        return $result;
    }

    private function safeDirectories(string $root, string $first, string $second): bool
    {
        foreach ([$root, "$root/$first", "$root/$first/$second"] as $path) {
            clearstatcache(true, $path);
            $stat = @lstat($path);
            if ($stat === false || ($stat['mode'] & 0170000) !== 0040000) {
                return false;
            }
        }

        return true;
    }

    private function reclaim(string $path, bool $apply, string $root, string $first, string $second): int
    {
        clearstatcache(true, $path);
        $before = @lstat($path);
        if ($before === false || ($before['mode'] & 0170000) !== 0100000 || $before['size'] < 16 || $before['nlink'] !== 1) {
            return 0;
        }
        // Neither mode creates a missing file. Dry runs never open for writing.
        $file = @fopen($path, $apply ? 'r+b' : 'rb');
        if ($file === false) {
            throw new RuntimeException('open_failed');
        }
        try {
            if (! flock($file, LOCK_EX | LOCK_NB)) {
                return 0;
            }
            clearstatcache(true, $path);
            $current = @lstat($path);
            $opened = fstat($file);
            if (! $this->safeDirectories($root, $first, $second)
                || $current === false || $opened === false
                || ($current['mode'] & 0170000) !== 0100000
                || $current['dev'] !== $opened['dev'] || $current['ino'] !== $opened['ino']
                || $opened['nlink'] !== 1) {
                return 0;
            }
            $header = fread($file, 266);
            if ($header === false) {
                throw new RuntimeException('read_failed');
            }
            $expiration = substr($header, 0, 10);
            if (! preg_match('/\A[0-9]{10}\z/', $expiration)
                || (int) $expiration === 0 || $expiration === '9999999999'
                || (int) $expiration > now()->timestamp) {
                return 0;
            }
            $type = substr($header, 10);
            if (! preg_match('/\Aa:[0-9]+:\{/', $type)
                && ! preg_match('/\AO:([0-9]+):"([^"\x00-\x1f]+)":[0-9]+:\{/', $type, $object)) {
                return 0;
            }
            if (isset($object[1]) && (int) $object[1] !== strlen($object[2])) {
                return 0;
            }
            // Keep the inode: waiting put/add writers must not write to an unlinked file.
            if ($apply && ! ftruncate($file, 0)) {
                throw new RuntimeException('truncate_failed');
            }

            return $opened['size'];
        } finally {
            flock($file, LOCK_UN);
            fclose($file);
        }
    }
}
