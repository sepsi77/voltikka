<?php

namespace App\Services\ContractImport;

final readonly class ContractImportResult
{
    /**
     * @param  array{linked:int, skipped_existing:int, skipped_no_match:int, skipped_not_high:int}  $replacementStats
     * @param  list<int>  $changedSnapshotIds
     * @param  list<int>  $observedSnapshotIds
     * @param  list<string>  $activeContractIds
     * @param  list<string>  $companyNames
     */
    public function __construct(
        public bool $complete,
        public int $contractCount,
        public int $activeContractCount,
        public int $priceComponentCount,
        public array $replacementStats,
        public array $changedSnapshotIds,
        public array $observedSnapshotIds,
        public array $activeContractIds,
        public array $companyNames,
    ) {}
}
