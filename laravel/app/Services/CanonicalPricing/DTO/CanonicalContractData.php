<?php

namespace App\Services\CanonicalPricing\DTO;

use App\Services\CanonicalPricing\Enums\CalculationStatus;
use App\Services\CanonicalPricing\Enums\MisleadingState;

/**
 * The parsed, typed canonical interpretation of one contract: phases, schedules,
 * calculation feasibility, and source-consistency findings. Produced by
 * CanonicalPricingParser from the three `electricity_contracts.canonical_*` JSON columns.
 */
readonly class CanonicalContractData
{
    /**
     * @param  list<PricingPhase>  $phases
     * @param  list<string>  $issueCodes  Known canonical issue codes only; unknown codes are dropped.
     * @param  list<string>  $missingFacts
     * @param  list<float>  $sourceCampaignEnergyRates  Source-scope evidence only, never a price fallback.
     */
    public function __construct(
        public array $phases,
        public RecurringScheduleData $recurringSchedule,
        public ConsumptionEffectData $consumptionEffect,
        public CalculationStatus $calculationStatus,
        public array $missingFacts,
        public MisleadingState $misleadingState,
        public string $structuredPricingStatus,
        public array $issueCodes,
        public array $sourceCampaignEnergyRates = [],
    ) {}

    /** Normalize a calculation copy; packages and effect disclosures assume the target basis. */
    public function withVatBasis(bool $includeVat, float $vatMultiplier): self
    {
        return new self(
            phases: array_map(static fn (PricingPhase $phase) => new PricingPhase(
                label: $phase->label,
                phaseKind: $phase->phaseKind,
                starts: $phase->starts,
                ends: $phase->ends,
                components: array_map(
                    static fn (CanonicalComponent $component) => $component->withVatBasis($includeVat, $vatMultiplier),
                    $phase->components,
                ),
                package: $phase->package,
            ), $this->phases),
            recurringSchedule: $this->recurringSchedule,
            consumptionEffect: $this->consumptionEffect,
            calculationStatus: $this->calculationStatus,
            missingFacts: $this->missingFacts,
            misleadingState: $this->misleadingState,
            structuredPricingStatus: $this->structuredPricingStatus,
            issueCodes: $this->issueCodes,
            sourceCampaignEnergyRates: $this->sourceCampaignEnergyRates,
        );
    }

    public function withComparisonEvidence(?array $phases = null, ?array $sourceCampaignEnergyRates = null): self
    {
        return new self(
            $phases ?? $this->phases,
            $this->recurringSchedule,
            $this->consumptionEffect,
            $this->calculationStatus,
            $this->missingFacts,
            $this->misleadingState,
            $this->structuredPricingStatus,
            $this->issueCodes,
            $sourceCampaignEnergyRates ?? $this->sourceCampaignEnergyRates,
        );
    }

    public function hasIssueCode(string $code): bool
    {
        return in_array($code, $this->issueCodes, true);
    }

    public function hasAnyIssueCode(string ...$codes): bool
    {
        return array_intersect($codes, $this->issueCodes) !== [];
    }
}
