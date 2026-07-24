<?php

namespace App\Services\CanonicalPricing\DTO;

use App\Services\CanonicalPricing\Enums\IntegrityReasonFamily;

/**
 * The deterministic pricing-integrity verdict for one contract. All UI copy is generated
 * from these typed fields; the raw LLM `summary` string is never surfaced.
 */
readonly class ContractPricingIntegrity
{
    /**
     * @param list<string> $issueCodes
     * @param list<string> $detailFacts Short Finnish fact lines for the detail-page notice.
     */
    public function __construct(
        public bool $detected,
        public IntegrityReasonFamily $reasonFamily,
        public array $issueCodes = [],
        public ?string $cardLabel = null,
        public ?string $detailHeading = null,
        public array $detailFacts = [],
        public ?string $changeDate = null,
        public ?float $firstYearImpactEur = null,
    ) {
    }

    public static function none(): self
    {
        return new self(detected: false, reasonFamily: IntegrityReasonFamily::None);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'detected' => $this->detected,
            'reason_family' => $this->reasonFamily->value,
            'issue_codes' => $this->issueCodes,
            'card_label' => $this->cardLabel,
            'detail_heading' => $this->detailHeading,
            'detail_facts' => $this->detailFacts,
            'change_date' => $this->changeDate,
            'first_year_impact_eur' => $this->firstYearImpactEur,
        ];
    }
}
