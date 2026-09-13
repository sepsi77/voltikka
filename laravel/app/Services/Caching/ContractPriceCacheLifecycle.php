<?php

namespace App\Services\Caching;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class ContractPriceCacheLifecycle
{
    public const ACTIVE_KEY = 'contract_price_cache_active_v1';

    public const LOCK_KEY = 'contract_price_cache_transition';

    public const GRACE_SECONDS = 3600;

    public const RETIRED_KEY = 'contract_price_cache_retired_v1';

    private const CLEANUP_KEY_LIMIT = 100;

    private const CLEANUP_GENERATION_LIMIT = 20;

    public function active(): array
    {
        $active = Cache::get(self::ACTIVE_KEY);
        if (is_array($active)) {
            return $active;
        }

        return Cache::lock(self::LOCK_KEY, 30)->block(10, function (): array {
            $active = Cache::get(self::ACTIVE_KEY);
            if (is_array($active)) {
                return $active;
            }
            $active = $this->descriptor((int) Cache::get('contract_list_cache_version', 1));
            $this->store(self::ACTIVE_KEY, $active);

            return $active;
        });
    }

    public function candidate(array $starting): array
    {
        return $this->descriptor($starting['version'] + 1);
    }

    public function invalidate(): int
    {
        $this->active();
        [$previous, $next] = Cache::lock(self::LOCK_KEY, 30)->block(10, function (): array {
            $previous = Cache::get(self::ACTIVE_KEY);
            $next = $this->descriptor($previous['version'] + 1);
            $this->trackRetirement($previous, extendGrace: true);
            $this->store(self::ACTIVE_KEY, $next);

            return [$previous, $next];
        });
        $this->retire($previous);

        return $next['version'];
    }

    public function write(array $generation, string $key, mixed $payload, bool $candidate = false): void
    {
        $write = function () use ($generation, $key, $payload, $candidate): void {
            $manifestKey = $this->manifestKey($generation);
            $keys = Cache::get($manifestKey, []);
            $keys[] = $key;
            $this->store($manifestKey, array_values(array_unique($keys)));
            if (! $candidate && Cache::get(self::ACTIVE_KEY) !== $generation) {
                // A late cold writer starts a new grace period, even after an earlier cleanup.
                $this->trackRetirement($generation, extendGrace: true);
            }
            $this->store($key, $payload);
        };
        if ($candidate) {
            // Private builds never hold the transition lock during payload writes.
            $write();
        } else {
            // Serialize cold writes with physical cleanup, not the expensive calculation.
            Cache::lock(self::LOCK_KEY, 30)->block(10, $write);
        }
    }

    public function promote(array $starting, array $candidate, array $expected): void
    {
        // Validate every required entry, not only the most recently written preset.
        foreach ($expected as $key => $digest) {
            if (hash('sha256', serialize(Cache::get($key))) !== $digest) {
                throw new RuntimeException('Candidate price cache readback failed.');
            }
        }
        Cache::lock(self::LOCK_KEY, 30)->block(10, function () use ($starting, $candidate): void {
            if (Cache::get(self::ACTIVE_KEY) !== $starting) {
                throw new RuntimeException('Price cache changed while replacement was built.');
            }
            // Persist cleanup ownership before switching. A failed write leaves the old active.
            $this->trackRetirement($starting, extendGrace: true);
            $this->store(self::ACTIVE_KEY, $candidate);
        });
        $this->retire($starting);
    }

    public function retire(array $generation): void
    {
        // Successful transitions already have durable cleanup state. Failed candidates use this path.
        try {
            Cache::lock(self::LOCK_KEY, 30)->block(10, function () use ($generation): void {
                if ((Cache::get(self::ACTIVE_KEY)['generation'] ?? null) !== $generation['generation']) {
                    $this->trackRetirement($generation);
                }
            });
        } catch (Throwable) {
            // Never roll back the pointer because retirement failed.
        }
    }

    /** @return array{deleted: int, failures: int} */
    public function cleanupRetired(): array
    {
        return Cache::lock(self::LOCK_KEY, 30)->block(10, function (): array {
            $retired = Cache::get(self::RETIRED_KEY, []);
            $activeId = Cache::get(self::ACTIVE_KEY)['generation'] ?? null;
            $deleted = 0;
            $failures = 0;
            $attempted = 0;
            foreach (array_slice($retired, 0, self::CLEANUP_GENERATION_LIMIT, true) as $id => $entry) {
                if ($id === $activeId || $entry['after'] > now()->timestamp) {
                    continue;
                }
                $manifestKey = $this->manifestKey(['generation' => $id]);
                try {
                    $keys = Cache::get($manifestKey, []);
                    foreach ($keys as $index => $key) {
                        if ($attempted >= self::CLEANUP_KEY_LIMIT) {
                            break;
                        }
                        $attempted++;
                        $this->forgetOwnedKey($key);
                        unset($keys[$index]);
                        $deleted++;
                    }
                    if ($keys !== []) {
                        $this->store($manifestKey, array_values($keys));
                    } else {
                        $this->forgetOwnedKey($manifestKey);
                        unset($retired[$id]);
                    }
                } catch (Throwable) {
                    // Keep the manifest and retirement entry for the next scheduled attempt.
                    $failures++;
                }
                if ($attempted >= self::CLEANUP_KEY_LIMIT) {
                    break;
                }
            }
            $this->store(self::RETIRED_KEY, $retired);

            return ['deleted' => $deleted, 'failures' => $failures];
        });
    }

    /** Caller must hold the transition lock. */
    private function trackRetirement(array $generation, bool $extendGrace = false): void
    {
        $retired = Cache::get(self::RETIRED_KEY, []);
        $id = $generation['generation'];
        if (! isset($retired[$id]) || $extendGrace) {
            $retired[$id] = ['after' => now()->timestamp + self::GRACE_SECONDS];
            $this->store(self::RETIRED_KEY, $retired);
        }
    }

    private function forgetOwnedKey(string $key): void
    {
        // DatabaseStore::forget physically deletes the exact row, even if it has expired.
        // Other stores can return false for an already absent key; that is also complete.
        if (! Cache::forget($key) && Cache::has($key)) {
            throw new RuntimeException('Retired price cache deletion failed.');
        }
    }

    private function descriptor(int $version): array
    {
        return ['version' => $version, 'generation' => (string) Str::uuid(), 'calculated_at' => now('Europe/Helsinki')->toIso8601String()];
    }

    private function manifestKey(array $generation): string
    {
        return 'contract_price_manifest:'.$generation['generation'];
    }

    private function store(string $key, mixed $payload): void
    {
        if (! Cache::forever($key, $payload)) {
            throw new RuntimeException('Price cache write failed.');
        }
    }
}
