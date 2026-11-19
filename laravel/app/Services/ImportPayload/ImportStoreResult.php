<?php

namespace App\Services\ImportPayload;

use Closure;

/**
 * What a store step returns to StagedImport. Counts go into the JSON result line. After-commit
 * closures (cache invalidation, queue dispatch, statistics warming) run only after the store
 * transaction committed (S21). A closed $error makes the command exit 1 after the commit and
 * keeps the rows, for commands that store a partial acquisition and still fail today (S8, S10).
 */
final readonly class ImportStoreResult
{
    /**
     * @param  array<string, int>  $counts
     * @param  list<Closure(): void>  $afterCommit
     * @param  string|null  $error  closed key, for example fetch_incomplete
     * @param  'fetch'|'store'  $errorStep
     */
    public function __construct(
        public array $counts = [],
        public array $afterCommit = [],
        public ?string $error = null,
        public string $errorStep = 'store',
    ) {}
}
