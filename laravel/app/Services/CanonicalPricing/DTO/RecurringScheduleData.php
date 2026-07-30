<?php

namespace App\Services\CanonicalPricing\DTO;

/**
 * Canonical recurring reset schedule (schema-v4 `pricing.recurring_schedule`).
 */
readonly class RecurringScheduleData
{
    public function __construct(
        public bool $present,
        public string $cadence,
        public ?string $currentPeriodStart,
        public ?string $currentPeriodEnd,
        public ?bool $futurePriceKnown,
    ) {}

    public function isActiveReset(): bool
    {
        return $this->present && in_array($this->cadence, ['monthly', 'quarterly', 'seasonal', 'other'], true);
    }
}
