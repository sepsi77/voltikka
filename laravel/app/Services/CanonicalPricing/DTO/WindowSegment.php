<?php

namespace App\Services\CanonicalPricing\DTO;

use Carbon\CarbonImmutable;

/**
 * One elemental slice of the 12-month comparison window. Each segment lies within a
 * single calendar month and is governed by at most one known-pricing phase
 * (`phaseIndex`), or none (`phaseIndex === null`, an uncovered slice).
 */
readonly class WindowSegment
{
    private float $calendarFraction;

    private float $billingFraction;

    public function __construct(
        public CarbonImmutable $start,
        public CarbonImmutable $end,
        public int $monthIndex,
        public ?int $phaseIndex,
        public float $annualMonthScale = 1.0,
        public ?float $billingMonthDays = null,
    ) {
        $daysInMonth = (int) $this->start->daysInMonth;
        $segmentDays = $this->start->diffInDays($this->end);
        $this->calendarFraction = $daysInMonth <= 0
            ? 0.0
            : max(0.0, min(1.0, $segmentDays / $daysInMonth));
        $this->billingFraction = $this->billingMonthDays !== null
            ? $segmentDays / $this->billingMonthDays
            : $this->calendarFraction;
    }

    /**
     * Fraction of the calendar month this segment represents (0..1), used to pro-rate
     * the full-month usage profile and monthly fees onto a partial-month slice.
     */
    public function monthFraction(): float
    {
        return $this->calendarFraction;
    }

    public function billingMonthFraction(): float
    {
        return $this->billingFraction;
    }

    public function annualMonthFraction(): float
    {
        return $this->monthFraction() * $this->annualMonthScale;
    }

    public function isCovered(): bool
    {
        return $this->phaseIndex !== null;
    }
}
