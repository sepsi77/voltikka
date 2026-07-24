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
    public function __construct(
        public CarbonImmutable $start,
        public CarbonImmutable $end,
        public int $monthIndex,
        public ?int $phaseIndex,
    ) {
    }

    /**
     * Fraction of the calendar month this segment represents (0..1), used to pro-rate
     * the full-month usage profile and monthly fees onto a partial-month slice.
     */
    public function monthFraction(): float
    {
        $daysInMonth = (int) $this->start->daysInMonth;
        if ($daysInMonth <= 0) {
            return 0.0;
        }

        $segmentDays = $this->start->diffInDays($this->end);

        return max(0.0, min(1.0, $segmentDays / $daysInMonth));
    }

    public function isCovered(): bool
    {
        return $this->phaseIndex !== null;
    }
}
