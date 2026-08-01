<?php

namespace App\Services\CanonicalPricing\DTO;

use App\Enums\ContractType;
use App\Enums\PricingModel;
use App\Models\ElectricityContract;

/**
 * The relational contract facts the canonical calculator needs beyond the interpretation
 * JSON: broad classification and the fixed-term duration bucket used for the term carve-out.
 */
readonly class ContractContext
{
    public function __construct(
        public string $pricingModel,
        public string $contractType,
        public ?string $metering,
        public ?string $fixedTimeRange,
        public ?string $targetGroup,
    ) {}

    public static function fromContract(ElectricityContract $contract): self
    {
        return new self(
            pricingModel: (string) ($contract->pricing_model ?? ''),
            contractType: (string) ($contract->contract_type ?? ''),
            metering: $contract->metering,
            fixedTimeRange: $contract->fixed_time_range,
            targetGroup: $contract->target_group,
        );
    }

    public function isSpot(): bool
    {
        return PricingModel::fromSource($this->pricingModel) === PricingModel::Spot;
    }

    public function isFixedTerm(): bool
    {
        return ContractType::fromSource($this->contractType) === ContractType::FixedTerm;
    }

    /**
     * The fixed-term length in months implied by the relational `fixed_time_range` bucket,
     * or null when the bucket is open-ended or a range without an exact month count.
     */
    public function fixedTermMonths(): ?int
    {
        return match ($this->fixedTimeRange) {
            'Fixed6' => 6,
            'Fixed12' => 12,
            'Fixed24' => 24,
            default => null,
        };
    }
}
