<?php

namespace App\Services\ContractStatistics\DTO;

use App\Services\ContractStatistics\ContractPriceBasis;
use App\Services\ContractStatistics\Enums\AnnualCostCalculationBasis;
use App\Services\ContractStatistics\Enums\AnnualCostMethodVersion;
use Carbon\CarbonImmutable;

readonly class AsOfAnnualCostResult
{
    /**
     * @param  array{price_snapshot_id: int|null, price_component_ids: list<string>, observation_ids: list<int>, source_snapshot_id: int|null, interpretation_id: int|null}  $sourceEvidenceIds
     * @param  list<string>  $provenanceFlags
     */
    public function __construct(
        public string $contractId,
        public CarbonImmutable $date,
        public string $segmentKey,
        public int $consumptionKwh,
        public ?float $totalCost,
        public AnnualCostMethodVersion $methodVersion,
        public ContractPriceBasis $pricingBasis,
        public AnnualCostCalculationBasis $calculationBasis,
        public ?string $estimateMethod,
        public ?string $estimateBasis,
        public string $compatibilityKey,
        public array $sourceEvidenceIds,
        public ?CarbonImmutable $priceEpisodeStartedAt,
        public array $provenanceFlags,
        public ?string $unavailableReason = null,
    ) {}

    public function isAvailable(): bool
    {
        return $this->totalCost !== null && $this->unavailableReason === null;
    }
}
