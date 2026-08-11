<?php

namespace App\Services\ContractStatistics\DTO;

readonly class SellerSetEnergyPriceIndexDateSummary
{
    /**
     * @param  array<string, int>  $familyOfferCounts
     * @param  array<string, int>  $exclusionCounts
     * @param  array<string, int>  $provenanceCounts
     * @param  list<array<string, mixed>>  $rows
     */
    public function __construct(
        public string $date,
        public int $evidenceCount,
        public int $annualProofCount,
        public int $eligibleContractCount,
        public int $directRateCount,
        public int $rowCount,
        public array $familyOfferCounts,
        public array $exclusionCounts,
        public array $provenanceCounts,
        public array $rows,
    ) {}
}
