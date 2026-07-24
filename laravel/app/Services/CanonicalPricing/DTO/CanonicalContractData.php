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
     * @param list<PricingPhase> $phases
     * @param list<string> $issueCodes Known canonical issue codes only; unknown codes are dropped.
     * @param list<string> $missingFacts
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
    ) {
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
