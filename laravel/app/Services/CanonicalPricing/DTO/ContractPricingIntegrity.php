<?php

namespace App\Services\CanonicalPricing\DTO;

use App\Services\CanonicalPricing\Enums\IntegrityReasonFamily;
use InvalidArgumentException;

/**
 * The deterministic pricing-integrity verdict for one contract. All UI copy is generated
 * from these typed fields; the raw LLM `summary` string is never surfaced.
 */
readonly class ContractPricingIntegrity
{
    /**
     * @param  list<string>  $issueCodes
     * @param  list<string>  $detailFacts  Short Finnish fact lines for the detail-page notice.
     */
    /**
     * @param  float|null  $promoRateCents  The energy rate in effect before `changeDate`.
     * @param  float|null  $normalRateCents  The disclosed energy rate from `changeDate` onward.
     *                                       Null when the seller never published the later price. Both are typed rather than
     *                                       only embedded in `detailFacts`, because the card renders them as two dated
     *                                       receipt rows and must not parse a Finnish sentence to get them back.
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
        public ?float $promoRateCents = null,
        public ?float $normalRateCents = null,
    ) {}

    public static function none(): self
    {
        return new self(detected: false, reasonFamily: IntegrityReasonFamily::None);
    }

    /** @param array<string, mixed> $payload */
    public static function fromArray(array $payload): self
    {
        foreach (['detected', 'reason_family', 'issue_codes', 'card_label', 'detail_heading', 'detail_facts', 'change_date', 'first_year_impact_eur', 'promo_rate_cents', 'normal_rate_cents'] as $key) {
            if (! array_key_exists($key, $payload)) {
                throw new InvalidArgumentException('Pricing integrity is missing required key '.$key.'.');
            }
        }

        if (! is_bool($payload['detected'])) {
            throw new InvalidArgumentException('Pricing integrity detected must be a boolean.');
        }
        $reason = is_string($payload['reason_family'])
            ? IntegrityReasonFamily::tryFrom($payload['reason_family'])
            : null;
        if ($reason === null) {
            throw new InvalidArgumentException('Pricing integrity reason family is not supported.');
        }
        foreach (['issue_codes', 'detail_facts'] as $key) {
            if (! is_array($payload[$key]) || ! array_is_list($payload[$key])) {
                throw new InvalidArgumentException('Pricing integrity '.$key.' must be a list.');
            }
            foreach ($payload[$key] as $value) {
                if (! is_string($value) || trim($value) === '') {
                    throw new InvalidArgumentException('Pricing integrity '.$key.' must contain non-empty strings.');
                }
            }
        }
        foreach (['card_label', 'detail_heading', 'change_date'] as $key) {
            if ($payload[$key] !== null && (! is_string($payload[$key]) || trim($payload[$key]) === '')) {
                throw new InvalidArgumentException('Pricing integrity '.$key.' must be a non-empty string or null.');
            }
        }
        foreach (['first_year_impact_eur', 'promo_rate_cents', 'normal_rate_cents'] as $key) {
            $value = $payload[$key];
            if ($value !== null && ((! is_int($value) && ! is_float($value)) || ! is_finite((float) $value))) {
                throw new InvalidArgumentException('Pricing integrity '.$key.' must be a finite number or null.');
            }
        }

        return new self(
            detected: $payload['detected'],
            reasonFamily: $reason,
            issueCodes: $payload['issue_codes'],
            cardLabel: $payload['card_label'],
            detailHeading: $payload['detail_heading'],
            detailFacts: $payload['detail_facts'],
            changeDate: $payload['change_date'],
            firstYearImpactEur: $payload['first_year_impact_eur'] === null ? null : (float) $payload['first_year_impact_eur'],
            promoRateCents: $payload['promo_rate_cents'] === null ? null : (float) $payload['promo_rate_cents'],
            normalRateCents: $payload['normal_rate_cents'] === null ? null : (float) $payload['normal_rate_cents'],
        );
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
            'promo_rate_cents' => $this->promoRateCents,
            'normal_rate_cents' => $this->normalRateCents,
        ];
    }
}
