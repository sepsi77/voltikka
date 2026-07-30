<?php

namespace App\Services\ContractStatistics;

final readonly class ContractPercentileResult
{
    /**
     * @param  list<array{component:string, count:int, p15:float, p85:float}>  $calculated
     * @param  array<string, int>  $skipped
     */
    public function __construct(
        public int $activeContractCount,
        public array $calculated,
        public array $skipped,
    ) {}
}
