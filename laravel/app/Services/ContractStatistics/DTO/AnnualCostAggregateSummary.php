<?php

namespace App\Services\ContractStatistics\DTO;

readonly class AnnualCostAggregateSummary
{
    public function __construct(
        public string $segmentKey,
        public int $consumptionKwh,
        public float $median,
        public string $compatibilityKey,
    ) {}
}
