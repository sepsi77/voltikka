<?php

namespace App\Services\ContractStatistics\DTO;

use App\Services\ContractStatistics\Enums\AnnualCostMethodVersion;
use Carbon\CarbonImmutable;

readonly class AnnualCostStatisticsDateSummary
{
    /**
     * @param  array{pricing_basis: array<string, int>, calculation_basis: array<string, int>, estimate_method: array<string, int>, estimate_basis: array<string, int>, unavailable_reasons: array<string, int>}  $basisCounts
     * @param  list<AnnualCostAggregateSummary>  $aggregates
     */
    public function __construct(
        public CarbonImmutable $date,
        public AnnualCostMethodVersion $methodVersion,
        public int $evidenceResultCount,
        public int $availableCount,
        public int $unavailableCount,
        public int $persistedCount,
        public int $aggregateCount,
        public array $basisCounts,
        public array $aggregates,
        public bool $applied,
    ) {}
}
