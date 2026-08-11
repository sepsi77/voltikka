<?php

namespace App\Services\ContractStatistics\DTO;

use App\Services\CanonicalPricing\DTO\CanonicalContractData;
use App\Services\ContractStatistics\ContractPriceBasis;
use Carbon\CarbonImmutable;

/**
 * Immutable evidence for one contract on one historical statistics date.
 */
readonly class AsOfAnnualCostEvidence
{
    /**
     * @param  array<int, array<string, mixed>>  $priceComponents
     * @param  array<int, bool>  $consumptionAvailability
     * @param  array{price_snapshot_id: int|null, price_component_ids: list<string>, observation_ids: list<int>, source_snapshot_id: int|null, interpretation_id: int|null, historical_episode_id: int|null, historical_interpretation_id: int|null, historical_evidence_grade: string|null}  $sourceEvidenceIds
     * @param  list<string>  $provenanceFlags
     */
    public function __construct(
        public string $contractId,
        public CarbonImmutable $date,
        public ?string $companyName,
        public string $segmentKey,
        public string $pricingModel,
        public string $contractType,
        public ?string $fixedTimeRange,
        public ?string $metering,
        public ContractPriceBasis $pricingBasis,
        public array $priceComponents,
        public array $consumptionAvailability,
        public ?CanonicalContractData $canonicalData,
        public array $sourceEvidenceIds,
        public array $provenanceFlags = [],
        public ?string $exclusionReason = null,
    ) {}

    public function isAvailableForConsumption(int $consumption): bool
    {
        return $this->consumptionAvailability[$consumption] ?? false;
    }
}
