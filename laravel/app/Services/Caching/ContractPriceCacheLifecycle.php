<?php

namespace App\Services\Caching;

use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class ContractPriceCacheLifecycle
{
    public const ACTIVE_KEY = 'contract_price_cache_active_v1';

    public const LOCK_KEY = 'contract_price_cache_transition';

    public const DEMAND_KEY = 'contract_price_cache_demand_v1';

    public const PRODUCER_LOCK_KEY = 'contract_price_cache_producer';

    public const PRODUCER_LEASE_SECONDS = 1800;

    public const GRACE_SECONDS = 3600;

    public const RETIRED_KEY = 'contract_price_cache_retired_v1';

    public const CUSTOM_TTL_SECONDS = 1800;

    public const MAX_CUSTOM_PROFILES = 64;

    private const CUSTOM_EXPIRATIONS = 'custom_expirations';

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
        return [...$this->descriptor($starting['version'] + 1), 'demand_revision' => $this->demandRevision()];
    }

    public function invalidate(): int
    {
        $this->active();

        return Cache::lock(self::LOCK_KEY, 30)->block(10, function (): int {
            $revision = $this->demandRevision() + 1;
            $this->store(self::DEMAND_KEY, $revision);

            return $revision;
        });
    }

    public function demandRevision(): int
    {
        // The active descriptor retains the satisfied floor if only demand metadata is lost.
        // Read it directly: callers can already hold the transition lock.
        $active = Cache::get(self::ACTIVE_KEY);

        return max((int) Cache::get(self::DEMAND_KEY, 0), (int) ($active['demand_revision'] ?? 0));
    }

    public function pending(): bool
    {
        return $this->demandRevision() > ($this->active()['demand_revision'] ?? 0);
    }

    /** Missing payload requests coalesce without superseding an in-progress repair. */
    public function requestRepair(): void
    {
        $this->active();
        Cache::lock(self::LOCK_KEY, 30)->block(10, function (): void {
            if ($this->demandRevision() <= (Cache::get(self::ACTIVE_KEY)['demand_revision'] ?? 0)) {
                $this->store(self::DEMAND_KEY, $this->demandRevision() + 1);
            }
        });
    }

    public function checkCustomCapacity(array $generation, string $key): void
    {
        Cache::lock(self::LOCK_KEY, 30)->block(10, function () use ($generation, $key): void {
            if (Cache::get(self::ACTIVE_KEY) !== $generation) {
                throw ContractPriceCacheConflict::generationChanged();
            }
            $this->customManifest($generation, $key);
        });
    }

    /** Caller holds the transition lock. Failed deletion leaves exact cleanup ownership intact. */
    private function customManifest(array $generation, string $key): array
    {
        $manifestKey = $this->manifestKey($generation);
        $manifest = Cache::get($manifestKey, []);
        $expirations = $manifest[self::CUSTOM_EXPIRATIONS] ?? [];
        foreach ($expirations as $expiredKey => $expiresAt) {
            if ($expiresAt > now()->timestamp) {
                continue;
            }
            if (! Cache::forget($expiredKey)) {
                // An expired GET can already remove a file/array entry. A short null marker
                // proves deletion on retry without restoring a price or losing ownership.
                if (! Cache::put($expiredKey, null, 1) || ! Cache::forget($expiredKey)) {
                    throw ContractPriceCacheStorageException::writeFailed();
                }
            }
            unset($expirations[$expiredKey], $manifest[self::CUSTOM_EXPIRATIONS]);
            $manifest = array_values(array_diff($manifest, [$expiredKey]));
            $manifest[self::CUSTOM_EXPIRATIONS] = $expirations;
            $this->store($manifestKey, $manifest);
        }
        if (! isset($expirations[$key]) && count($expirations) >= self::MAX_CUSTOM_PROFILES) {
            throw new ContractPriceCacheUnavailable;
        }

        return $manifest;
    }

    public function write(array $generation, string $key, mixed $payload, bool $candidate = false, bool $custom = false): void
    {
        if ($candidate && $custom) {
            throw new RuntimeException('Custom profiles require an active generation.');
        }
        $write = function () use ($generation, $key, $payload, $candidate, $custom): void {
            if (! $candidate && Cache::get(self::ACTIVE_KEY) !== $generation) {
                throw ContractPriceCacheConflict::generationChanged();
            }
            $manifestKey = $this->manifestKey($generation);
            $keys = $custom ? $this->customManifest($generation, $key) : Cache::get($manifestKey, []);
            $expirations = $keys[self::CUSTOM_EXPIRATIONS] ?? [];
            unset($keys[self::CUSTOM_EXPIRATIONS]);
            $keys[] = $key;
            $keys = array_values(array_unique($keys));
            if ($custom) {
                $expirations[$key] = now()->timestamp + self::CUSTOM_TTL_SECONDS;
            }
            if ($expirations !== []) {
                $keys[self::CUSTOM_EXPIRATIONS] = $expirations;
            }
            $this->store($manifestKey, $keys);
            if ($custom) {
                if (! Cache::put($key, $payload, self::CUSTOM_TTL_SECONDS)) {
                    throw ContractPriceCacheStorageException::writeFailed();
                }
            } else {
                $this->store($key, $payload);
            }
        };
        if ($candidate) {
            // Private builds never hold the transition lock during payload writes.
            $write();
        } else {
            // Serialize cold writes with physical cleanup, not the expensive calculation.
            Cache::lock(self::LOCK_KEY, 30)->block(10, $write);
        }
    }

    public function promote(array $starting, array $candidate, array $expected, ?Lock $producer = null): void
    {
        // Validate every required entry, not only the most recently written preset.
        foreach ($expected as $key => $digest) {
            if (hash('sha256', serialize(Cache::get($key))) !== $digest) {
                throw ContractPriceCacheStorageException::readbackFailed();
            }
        }
        Cache::lock(self::LOCK_KEY, 30)->block(10, function () use ($starting, $candidate, $producer): void {
            if ($producer !== null && ! $producer->isOwnedByCurrentProcess()) {
                throw new RuntimeException('Price cache producer lease lost.');
            }
            if ($this->demandRevision() !== $candidate['demand_revision'] || Cache::get(self::ACTIVE_KEY) !== $starting) {
                throw ContractPriceCacheConflict::generationChanged();
            }
            // Persist cleanup ownership before switching. A failed write leaves the old active.
            $this->trackRetirement($starting, extendGrace: true);
            $this->store(self::ACTIVE_KEY, $candidate);
        });
        $this->retire($starting);
    }

    public function retire(array $generation, bool $required = false): void
    {
        // Successful transitions already have durable cleanup state. Failed candidates use this path.
        try {
            Cache::lock(self::LOCK_KEY, 30)->block(10, function () use ($generation): void {
                if ((Cache::get(self::ACTIVE_KEY)['generation'] ?? null) !== $generation['generation']) {
                    $this->trackRetirement($generation);
                }
            });
        } catch (Throwable $exception) {
            // A failed candidate must have durable retirement before another attempt.
            if ($required) {
                throw $exception;
            }
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
                    // Custom keys are already in the owned key list; expiry metadata is not a key.
                    unset($keys[self::CUSTOM_EXPIRATIONS]);
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
            throw ContractPriceCacheStorageException::writeFailed();
        }
    }
}
