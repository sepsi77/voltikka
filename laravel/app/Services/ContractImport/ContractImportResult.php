<?php

namespace App\Services\ContractImport;

final readonly class ContractImportResult
{
    /**
     * @param  array{linked:int, skipped_existing:int, skipped_no_match:int, skipped_not_high:int}  $replacementStats
     * @param  list<int>  $changedObservationIds
     * @param  list<int>  $observedObservationIds
     * @param  list<string>  $activeContractIds
     * @param  list<string>  $companyNames
     */
    public function __construct(
        public bool $complete,
        public int $contractCount,
        public int $activeContractCount,
        public int $priceComponentCount,
        public array $replacementStats,
        public array $changedObservationIds,
        public array $observedObservationIds,
        public array $activeContractIds,
        public array $companyNames,
    ) {}
}
