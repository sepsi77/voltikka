<?php

namespace App\Services\CanonicalPricing\DTO;

use Carbon\CarbonImmutable;

/**
 * Rolling-365-day Spot evidence (VAT included, c/kWh). The overall average and
 * period dates support the forward shape estimate. Coverage stays optional for
 * direct callers. Insufficient shape evidence permits an unshaped forward strip.
 */
readonly class SpotAssumptions
{
    public function __construct(
        public ?float $dayAvgWithTax,
        public ?float $nightAvgWithTax,
        public ?float $overallAvgWithTax = null,
        public ?CarbonImmutable $periodStart = null,
        public ?CarbonImmutable $periodEnd = null,
        public ?int $actualHours = null,
        public ?int $expectedHours = null,
        public ?string $windowSemantics = null,
        public string $vatBasis = 'included',
    ) {}

    /** Selected-cost copy; the original rolling market facts remain unchanged. */
    public function withVatBasis(bool $includeVat, float $vatMultiplier): self
    {
        $basis = $includeVat ? 'included' : 'excluded';
        if ($this->vatBasis === $basis) {
            return $this;
        }
        $values = get_object_vars($this);
        $factor = $includeVat ? $vatMultiplier : 1 / $vatMultiplier;
        foreach (['dayAvgWithTax', 'nightAvgWithTax', 'overallAvgWithTax'] as $key) {
            $values[$key] = $values[$key] !== null ? $values[$key] * $factor : null;
        }
        $values['vatBasis'] = $basis;

        return new self(...$values);
    }

    /** @return array<string, mixed> */
    public function coverage(): array
    {
        return [
            'actual_hours' => $this->actualHours,
            'expected_hours' => $this->expectedHours,
            'coverage_ratio' => $this->expectedHours > 0 && $this->actualHours !== null
                ? $this->actualHours / $this->expectedHours : null,
            'window_semantics' => $this->windowSemantics,
        ];
    }

    public function isAvailable(): bool
    {
        return $this->dayAvgWithTax !== null && $this->nightAvgWithTax !== null;
    }
}
