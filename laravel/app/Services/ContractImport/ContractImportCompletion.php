<?php

namespace App\Services\ContractImport;

use App\Jobs\WarmContractPriceStatisticsCache;
use App\Models\ContractInterpretation;
use App\Models\ContractSourceObservation;
use App\Models\DataFreshnessCheckpoint;
use App\Models\ElectricityContract;
use App\Services\Caching\ContractPriceCacheConflict;
use App\Services\Caching\ContractPriceCacheLifecycle;
use App\Services\CompanyListCacheService;
use App\Services\ContractListCacheService;
use App\Services\ContractStatistics\ContractPriceStatisticsService;
use App\Services\SitemapService;
use App\Support\DataFetchFailureReporter;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/** Same-day post-import completion only; acquisition and interpretation dispatch stay outside. */
class ContractImportCompletion
{
    public const VERSION = 1;

    public const PENDING = 'pending_completion';

    public const LOCK = 'contract_import_required_work_v1';

    public const LEASE_SECONDS = 1800;

    public const MAX_CHECKS = 60;

    public function __construct(
        private readonly ContractPriceStatisticsService $statistics,
        private readonly ContractListCacheService $contracts,
        private readonly CompanyListCacheService $companies,
    ) {}

    public function start(string $date): string
    {
        $uuid = (string) Str::uuid();
        $this->ensureRow($date, $uuid);
        DB::transaction(function () use ($date, $uuid): void {
            $this->query($date)->lockForUpdate()->firstOrFail()->update([
                'status' => DataFreshnessCheckpoint::STATUS_RUNNING,
                'metadata' => ['run_uuid' => $uuid, 'stage' => 'started'],
                'recorded_at' => now(),
            ]);
        });

        return $uuid;
    }

    public function record(string $date, string $uuid, string $status, array $metadata): bool
    {
        return DB::transaction(function () use ($date, $uuid, $status, $metadata): bool {
            $row = $this->query($date)->lockForUpdate()->firstOrFail();
            if (($row->metadata['run_uuid'] ?? null) !== $uuid) {
                return false;
            }
            if ($status === DataFreshnessCheckpoint::STATUS_READY && isset($metadata['completion_version'])
                && ! $this->readyCurrent($metadata)) {
                $status = self::PENDING;
                unset($metadata['ready_evidence'], $metadata['cache_generation'], $metadata['cache_fingerprint']);
            }
            $row->update(['status' => $status, 'metadata' => ['run_uuid' => $uuid] + $metadata, 'recorded_at' => now()]);

            return true;
        });
    }

    public function initial(ContractImportResult $import, string $date, ?string $uuid, array $targets): ContractPostImportResult
    {
        $manifest = $uuid !== null && $import->complete ? $this->manifest($import, $date, $uuid, $targets) : [];

        return $this->required($date, $uuid, null, $manifest, $import->activeContractIds, $import->complete, false);
    }

    /** This closure must run inside the statistics writer's existing transaction. */
    public function statisticsFence(string $date, ?string $uuid, ?string $claim = null, ?array $manifest = null, ?array $expected = null, ?array $activeIds = null): \Closure
    {
        return function () use ($date, $uuid, $claim, $manifest, $expected, $activeIds): void {
            // Scoped work locks an existing fact but never inserts global readiness.
            // A missing-key locking read also fences insertion under InnoDB REPEATABLE READ.
            $row = $this->query($date)->lockForUpdate()->first();
            if ($uuid !== null) {
                if ($row === null) {
                    throw new ContractImportCompletionStopped('ownership_changed');
                }
                $this->assertOwner($row, $uuid, $claim);
                if ($date !== $this->today()) {
                    throw new ContractImportCompletionStopped('date_expired');
                }
            }
            if ($activeIds !== null && DB::table('active_contracts')->orderBy('id')->pluck('id')->all() !== $activeIds) {
                throw ContractPriceCacheConflict::evidenceChanged();
            }
            if ($manifest !== null) {
                $state = $this->inspect($manifest);
                if ($state['reason'] !== 'eligible') {
                    throw new ContractImportCompletionStopped($state['reason']);
                }
                if ($state['evidence'] !== $expected) {
                    throw ContractPriceCacheConflict::evidenceChanged();
                }
            }
        };
    }

    /** One bounded check. Dry run never creates rows, claims attempts or reads through a cache miss. */
    public function tick(bool $dryRun = false): string
    {
        $row = DataFreshnessCheckpoint::query()->where('key', DataFreshnessCheckpoint::KEY_CONTRACT_IMPORT)
            ->where('status', self::PENDING)->orderByDesc('effective_date')->first();
        if ($row === null) {
            return 'no_pending_completion';
        }
        $facts = $row->metadata;
        if (! $this->validManifest($facts)) {
            return 'invalid_manifest';
        }
        $reason = $this->bounds($row);
        if ($reason === null && isset($facts['claim_token'])) {
            $reason = CarbonImmutable::parse($facts['claim_until'])->isPast() ? 'interrupted_execution' : 'claimed';
        }
        $state = $reason === null ? $this->inspect($facts) : null;
        if ($dryRun) {
            return $reason ?? $state['reason'];
        }
        if ($reason === 'claimed') {
            return $reason;
        }
        if ($reason !== null) {
            return $this->terminal($row, $reason);
        }

        $claim = (string) Str::uuid();
        $claimed = DB::transaction(function () use ($row, $claim): ?DataFreshnessCheckpoint {
            $current = $this->query($row->effective_date->toDateString())->lockForUpdate()->first();
            if ($current === null || $current->status !== self::PENDING || $current->metadata != $row->metadata) {
                return null;
            }
            $facts = $current->metadata;
            $facts['checks']++;
            $facts['claim_token'] = $claim;
            $facts['claim_until'] = CarbonImmutable::now()->addSeconds(self::LEASE_SECONDS)->toIso8601String();
            $current->update(['metadata' => $facts, 'recorded_at' => now()]);

            return $current;
        });
        if ($claimed === null) {
            return 'ownership_changed';
        }
        $facts = $claimed->metadata;
        if ($state['reason'] === 'waiting') {
            return $this->release($claimed, $facts) ? 'waiting' : 'ownership_changed';
        }
        if ($state['reason'] !== 'eligible') {
            return $this->terminal($claimed, $state['reason']);
        }

        $result = $this->required($claimed->effective_date->toDateString(), $facts['run_uuid'], $claim, $facts, $state['active_ids'], true, true);
        if (! $result->succeeded()) {
            $exception = array_values($result->requiredExceptions)[0] ?? null;
            $reason = $exception instanceof ContractImportCompletionStopped ? $exception->reason : array_key_first($result->requiredFailures);

            return $this->terminal($claimed, $reason, $exception);
        }
        if ($result->deferred) {
            return $this->release($claimed, $result->completionMetadata) ? 'waiting' : 'ownership_changed';
        }

        // Reread source and generation inside the final short checkpoint transaction, not the build.
        $outcome = DB::transaction(function () use ($claimed, $result): string {
            $current = $this->query($claimed->effective_date->toDateString())->lockForUpdate()->firstOrFail();
            if (! $this->sameClaim($current, $claimed)) {
                return 'ownership_changed';
            }
            $expired = CarbonImmutable::parse($current->metadata['claim_until'])->isPast() ? 'interrupted_execution'
                : (CarbonImmutable::parse($current->metadata['deadline'])->isPast() ? 'deadline_exhausted' : null);
            if ($expired !== null) {
                $metadata = $current->metadata;
                unset($metadata['claim_token'], $metadata['claim_until']);
                $metadata['failure_reason'] = $expired;
                $current->update(['status' => DataFreshnessCheckpoint::STATUS_FAILED, 'metadata' => $metadata, 'recorded_at' => now()]);

                return 'failed:'.$expired;
            }
            if (! $this->readyCurrent($result->completionMetadata)) {
                return $this->release($claimed, $result->completionMetadata) ? 'waiting' : 'ownership_changed';
            }
            $metadata = $result->completionMetadata;
            unset($metadata['claim_token'], $metadata['claim_until']);
            $current->update(['status' => DataFreshnessCheckpoint::STATUS_READY, 'metadata' => $metadata, 'recorded_at' => now()]);

            return 'ready';
        });
        if (str_starts_with($outcome, 'failed:')) {
            $this->reportFailure(substr($outcome, 7));
        }

        return $outcome;
    }

    public function readyCurrent(array $facts): bool
    {
        if (! $this->validManifest($facts) || $facts['effective_date'] !== $this->today()) {
            return false;
        }
        $state = $this->inspect($facts);

        return $state['reason'] === 'eligible'
            && ($facts['ready_evidence'] ?? null) === $state['evidence']
            && ($facts['active_contract_ids'] ?? null) === $state['active_ids']
            && isset($facts['cache_generation'], $facts['cache_fingerprint'])
            && Cache::get(ContractPriceCacheLifecycle::ACTIVE_KEY) == $facts['cache_generation']
            && $this->contracts->safetyFingerprint() === $facts['cache_fingerprint'];
    }

    public function inspect(array $facts): array
    {
        $active = DB::table('active_contracts')->orderBy('id')->pluck('id')->all();
        $waiting = false;
        $evidence = [];
        $covered = [];
        $observations = ContractSourceObservation::query()->with('sourceSnapshot:id,contract_id')
            ->whereIn('id', array_column($facts['episodes'], 'observation_id'))->get()->keyBy('id');
        $contracts = ElectricityContract::query()->whereIn('id', array_column($facts['episodes'], 'contract_id'))
            ->get(['id', 'current_source_observation_id', 'published_interpretation_id'])->keyBy('id');
        $targets = ContractInterpretation::query()->whereIn('id', array_filter(array_column($facts['episodes'], 'interpretation_id')))
            ->select(['id', 'contract_id', 'source_snapshot_id', 'analysis_source_observation_id', 'status', 'validation_errors', 'published_at', 'updated_at'])
            ->selectRaw("CASE WHEN output IS NOT NULL AND output != '[]' AND output != '{}' THEN 1 ELSE 0 END AS has_output")
            ->get()->keyBy('id');
        foreach ($facts['episodes'] as $episode) {
            $observation = $observations->get($episode['observation_id']);
            $contract = $contracts->get($episode['contract_id']);
            if ($observation === null || $contract === null
                || $observation->contract_id !== $contract->id
                || $observation->source_snapshot_id !== $episode['snapshot_id']
                || $observation->sourceSnapshot?->contract_id !== $contract->id
                || $contract->current_source_observation_id !== $observation->id) {
                return ['reason' => 'superseded'];
            }
            $covered[] = $contract->id;
            $target = isset($episode['interpretation_id']) ? $targets->get($episode['interpretation_id']) : null;
            if ($target !== null && ($target->contract_id !== $contract->id
                || $target->source_snapshot_id !== $observation->source_snapshot_id
                || ($target->analysis_source_observation_id !== null && $target->analysis_source_observation_id !== $observation->id))) {
                return ['reason' => 'superseded'];
            }
            $published = $target !== null && $target->status === ContractInterpretation::STATUS_PUBLISHED
                && $contract->published_interpretation_id === $target->id
                && $target->published_at !== null && (bool) $target->has_output && empty($target->validation_errors);
            $rejected = $target !== null && $target->status === 'failed' && ! empty($target->validation_errors);
            if ($facts['interpretation_enabled']) {
                if ($target !== null && (in_array($target->status, ['pending', 'processing'], true)
                    || ($target->status === 'failed' && empty($target->validation_errors)))) {
                    $waiting = true;
                } elseif ($target !== null && $target->status === 'superseded') {
                    return ['reason' => 'superseded'];
                } elseif (in_array($contract->id, $active, true) && ! $published && ! $rejected) {
                    return ['reason' => 'publication_missing'];
                } elseif (isset($episode['interpretation_id']) && $target === null) {
                    return ['reason' => 'superseded'];
                }
            }
            $evidence[] = [$contract->id, $observation->id, $observation->source_snapshot_id,
                $contract->published_interpretation_id, $target?->status, $target?->published_at?->toIso8601String(),
                $target?->updated_at?->toIso8601String()];
        }
        if (array_diff($active, $covered) !== []) {
            return ['reason' => 'superseded'];
        }
        if ($active === [] && ! $waiting) {
            return ['reason' => 'active_set_empty'];
        }

        return ['reason' => $waiting ? 'waiting' : 'eligible', 'active_ids' => $active, 'evidence' => [$active, $evidence]];
    }

    private function required(string $date, ?string $uuid, ?string $claim, array $facts, array $activeIds, bool $complete, bool $delayed): ContractPostImportResult
    {
        $failures = $exceptions = $optional = [];
        $started = $completed = null;
        $deferred = false;
        $stage = 'daily_statistics';
        $lock = Cache::lock(self::LOCK, self::LEASE_SECONDS);
        try {
            $lock->block(10);
            if ($uuid !== null) {
                $this->assertOwner($this->query($date)->firstOrFail(), $uuid, $claim);
            }
            $before = $facts !== [] ? $this->inspect($facts) : null;
            if ($before !== null && ! in_array($before['reason'], $delayed ? ['eligible'] : ['eligible', 'waiting'], true)) {
                throw new ContractImportCompletionStopped($before['reason']);
            }
            $activeIds = $before['active_ids'] ?? DB::table('active_contracts')->orderBy('id')->pluck('id')->all();
            if ($complete && ($before['reason'] ?? null) === 'eligible') {
                $this->contracts->getVersion();
            }
            $startingGeneration = Cache::get(ContractPriceCacheLifecycle::ACTIVE_KEY);
            $fence = $this->statisticsFence($date, $uuid, $claim, $delayed ? $facts : null, $before['evidence'] ?? null, $activeIds);
            $this->contracts->resetCalculationState();
            $started = CarbonImmutable::now('Europe/Helsinki');
            $this->statistics->calculateForDate(
                date: $date, contractIds: $activeIds, overwrite: true, transactionFence: $fence,
            );
            $completed = CarbonImmutable::now('Europe/Helsinki');
            $stage = 'cache_invalidation';
            if (! Cache::forget(SitemapService::CACHE_KEY) && Cache::has(SitemapService::CACHE_KEY)) {
                throw new RuntimeException('Sitemap cache invalidation failed.');
            }
            $facts['initial_statistics_started_at'] ??= $started->toIso8601String();
            $facts['initial_statistics_completed_at'] ??= $completed->toIso8601String();
            $facts['statistics_started_at'] = $started->toIso8601String();
            $facts['statistics_completed_at'] = $completed->toIso8601String();
            $stage = 'price_cache_refresh';
            if ($complete) {
                $after = isset($facts['completion_version']) ? $this->inspect($facts) : null;
                if ($after !== null && ! in_array($after['reason'], ['waiting', 'eligible'], true)) {
                    throw new ContractImportCompletionStopped($after['reason']);
                }
                if ($after !== null && ($after['reason'] === 'waiting' || $before !== $after
                    || Cache::get(ContractPriceCacheLifecycle::ACTIVE_KEY) !== $startingGeneration)) {
                    $deferred = true;
                } else {
                    $version = $this->contracts->refresh($this->companies, $after === null ? null : function () use ($facts, $after, $date, $uuid, $claim, $startingGeneration): void {
                        $this->assertOwner($this->query($date)->firstOrFail(), $uuid, $claim);
                        if (Cache::get(ContractPriceCacheLifecycle::ACTIVE_KEY) !== $startingGeneration) {
                            throw ContractPriceCacheConflict::generationChanged();
                        }
                        if ($date !== $this->today() || $after !== $this->inspect($facts)) {
                            throw ContractPriceCacheConflict::evidenceChanged();
                        }
                    });
                    if ($after !== null) {
                        $generation = Cache::get(ContractPriceCacheLifecycle::ACTIVE_KEY);
                        if (($generation['version'] ?? null) !== $version) {
                            throw ContractPriceCacheConflict::generationChanged();
                        }
                        if ($after !== $this->inspect($facts)) {
                            throw ContractPriceCacheConflict::evidenceChanged();
                        }
                        $facts['active_contract_ids'] = $after['active_ids'];
                        $facts['ready_evidence'] = $after['evidence'];
                        $facts['cache_generation'] = $generation;
                        $facts['cache_fingerprint'] = $this->contracts->safetyFingerprint();
                    }
                }
            }
        } catch (Throwable $exception) {
            if ($uuid !== null && $complete
                && (($exception instanceof ContractPriceCacheConflict && ($delayed || ($completed !== null && $stage === 'price_cache_refresh')))
                    || ($delayed && $exception instanceof ContractImportCompletionStopped && $exception->reason === 'waiting'))) {
                $deferred = true;
            } else {
                $failures[$stage] = 'Required contract completion stage failed.';
                $exceptions[$stage] = $exception;
            }
        } finally {
            $lock->release();
        }
        if ($completed !== null) {
            try {
                WarmContractPriceStatisticsCache::dispatch('weekly', 5000);
            } catch (Throwable) {
                $optional['statistics_cache_dispatch'] = 'Statistics cache dispatch failed.';
            }
        }

        return new ContractPostImportResult($failures, $optional, [], $started, $completed, $exceptions, $deferred, $facts);
    }

    private function manifest(ContractImportResult $import, string $date, string $uuid, array $targets): array
    {
        $episodes = [];
        foreach ($import->observedObservationIds as $id) {
            $observation = ContractSourceObservation::findOrFail($id);
            $episodes[] = ['contract_id' => $observation->contract_id, 'observation_id' => $id,
                'snapshot_id' => $observation->source_snapshot_id, 'interpretation_id' => $targets[$id] ?? null];
        }

        return ['completion_version' => self::VERSION, 'run_uuid' => $uuid, 'complete' => true,
            'effective_date' => $date, 'episodes' => $episodes, 'observed_source_observation_ids' => $import->observedObservationIds,
            'initial_active_contract_ids' => $import->activeContractIds, 'interpretation_enabled' => (bool) config('contract_interpretation.enabled'),
            'deadline' => CarbonImmutable::now()->addHours(2)->toIso8601String(), 'checks' => 0];
    }

    private function validManifest(?array $facts): bool
    {
        if (($facts['completion_version'] ?? null) !== self::VERSION || ($facts['complete'] ?? false) !== true
            || ! is_string($facts['run_uuid'] ?? null) || ! Str::isUuid($facts['run_uuid'])
            || ! is_string($facts['effective_date'] ?? null) || ! is_array($facts['episodes'] ?? null)
            || ! is_bool($facts['interpretation_enabled'] ?? null) || ! is_int($facts['checks'] ?? null)
            || $facts['checks'] < 0 || ! is_string($facts['deadline'] ?? null)
            || ! is_array($facts['initial_active_contract_ids'] ?? null)
            || ! is_array($facts['observed_source_observation_ids'] ?? null)
            || ! is_string($facts['statistics_started_at'] ?? null) || ! is_string($facts['statistics_completed_at'] ?? null)) {
            return false;
        }
        $observations = $contracts = [];
        foreach ($facts['episodes'] as $episode) {
            if (! is_array($episode) || ! is_string($episode['contract_id'] ?? null)
                || ! is_int($episode['observation_id'] ?? null) || ! is_int($episode['snapshot_id'] ?? null)
                || (isset($episode['interpretation_id']) && ! is_int($episode['interpretation_id']))) {
                return false;
            }
            $observations[] = $episode['observation_id'];
            $contracts[] = $episode['contract_id'];
        }
        if ($observations !== $facts['observed_source_observation_ids']
            || count(array_unique($observations)) !== count($observations)
            || count(array_unique($contracts)) !== count($contracts)) {
            return false;
        }
        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $facts['effective_date'], 'Europe/Helsinki');
            $started = CarbonImmutable::parse($facts['statistics_started_at']);
            $completed = CarbonImmutable::parse($facts['statistics_completed_at']);
            $deadline = CarbonImmutable::parse($facts['deadline']);
            if (isset($facts['claim_token']) && (! is_string($facts['claim_token']) || ! Str::isUuid($facts['claim_token'])
                || ! is_string($facts['claim_until'] ?? null) || ! CarbonImmutable::parse($facts['claim_until']))) {
                return false;
            }

            return $date->toDateString() === $facts['effective_date'] && $completed->gte($started)
                && $deadline->gte($date) && $deadline->lte($date->addDay()->addHours(2));
        } catch (Throwable) {
            return false;
        }
    }

    private function bounds(DataFreshnessCheckpoint $row): ?string
    {
        if ($row->effective_date->toDateString() !== $this->today() || $row->metadata['effective_date'] !== $this->today()) {
            return 'date_expired';
        }
        if (CarbonImmutable::parse($row->metadata['deadline'])->isPast()) {
            return 'deadline_exhausted';
        }

        return $row->metadata['checks'] >= self::MAX_CHECKS ? 'checks_exhausted' : null;
    }

    private function assertOwner(DataFreshnessCheckpoint $row, string $uuid, ?string $claim): void
    {
        if (($row->metadata['run_uuid'] ?? null) !== $uuid
            || ($claim !== null && ($row->status !== self::PENDING || ($row->metadata['claim_token'] ?? null) !== $claim
                || CarbonImmutable::parse($row->metadata['claim_until'])->isPast()
                || CarbonImmutable::parse($row->metadata['deadline'])->isPast()))) {
            throw new ContractImportCompletionStopped('ownership_changed');
        }
    }

    private function sameClaim(DataFreshnessCheckpoint $current, DataFreshnessCheckpoint $claimed): bool
    {
        return $current->status === self::PENDING && $current->metadata == $claimed->metadata;
    }

    private function release(DataFreshnessCheckpoint $claimed, array $facts): bool
    {
        unset($facts['claim_token'], $facts['claim_until'], $facts['ready_evidence'], $facts['cache_generation'], $facts['cache_fingerprint']);

        return $this->replaceClaim($claimed, self::PENDING, $facts);
    }

    private function terminal(DataFreshnessCheckpoint $claimed, string $reason, ?Throwable $exception = null): string
    {
        $facts = $claimed->metadata;
        unset($facts['claim_token'], $facts['claim_until']);
        $facts['failure_reason'] = $reason;
        if (! $this->replaceClaim($claimed, DataFreshnessCheckpoint::STATUS_FAILED, $facts)) {
            return 'ownership_changed';
        }
        $this->reportFailure($reason, $exception);

        return 'failed:'.$reason;
    }

    private function reportFailure(string $reason, ?Throwable $exception = null): void
    {
        $reporter = new DataFetchFailureReporter('contracts');
        $reporter->fail($reason, $exception);
        $reporter->report(true);
    }

    private function replaceClaim(DataFreshnessCheckpoint $claimed, string $status, array $facts): bool
    {
        return DB::transaction(function () use ($claimed, $status, $facts): bool {
            $current = $this->query($claimed->effective_date->toDateString())->lockForUpdate()->firstOrFail();
            if (! $this->sameClaim($current, $claimed)) {
                return false;
            }
            $current->update(['status' => $status, 'metadata' => $facts, 'recorded_at' => now()]);

            return true;
        });
    }

    private function ensureRow(string $date, string $uuid): void
    {
        DB::table('data_freshness_checkpoints')->insertOrIgnore([
            'key' => DataFreshnessCheckpoint::KEY_CONTRACT_IMPORT, 'effective_date' => $date,
            'status' => DataFreshnessCheckpoint::STATUS_RUNNING, 'metadata' => json_encode(['run_uuid' => $uuid, 'stage' => 'started']),
            'recorded_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function query(string $date): Builder
    {
        return DataFreshnessCheckpoint::query()->where('key', DataFreshnessCheckpoint::KEY_CONTRACT_IMPORT)->where('effective_date', $date);
    }

    private function today(): string
    {
        return CarbonImmutable::now('Europe/Helsinki')->toDateString();
    }
}
