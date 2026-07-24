<?php

namespace App\Services\CanonicalPricing\Support;

use App\Services\CanonicalPricing\DTO\PricingPhase;
use App\Services\CanonicalPricing\DTO\RecurringScheduleData;
use App\Services\CanonicalPricing\DTO\WindowSegment;
use App\Services\CanonicalPricing\Enums\BoundaryKind;
use Carbon\CarbonImmutable;

/**
 * Resolves canonical pricing phases onto absolute dates and segments the 12-month
 * comparison window into elemental slices, each governed by at most one known-pricing
 * phase. Phases with no known pricing, unresolved boundaries, or that fall outside the
 * window do not cover any slice (those slices are reported uncovered).
 *
 * Overlap rule: the latest-starting resolved phase wins.
 */
class PhaseTimelineBuilder
{
    /**
     * @param list<PricingPhase> $phases
     * @return list<WindowSegment>
     */
    public function build(array $phases, RecurringScheduleData $recurring, CarbonImmutable $windowStart): array
    {
        $windowStart = $windowStart->startOfDay();
        $windowEnd = $windowStart->addYear();

        // Resolve each known-pricing phase to a clamped [start, end) inside the window.
        $resolved = [];
        foreach ($phases as $index => $phase) {
            if (! $phase->hasKnownPricing()) {
                continue;
            }

            $range = $this->resolveRange($phase, $windowStart, $windowEnd, $recurring);
            if ($range === null) {
                continue;
            }

            [$start, $end] = $range;
            if ($end->lessThanOrEqualTo($start)) {
                continue;
            }

            $resolved[] = ['index' => $index, 'start' => $start, 'end' => $end];
        }

        $boundaries = $this->boundaryPoints($resolved, $windowStart, $windowEnd);
        $segments = [];

        for ($i = 0; $i < count($boundaries) - 1; $i++) {
            $a = $boundaries[$i];
            $b = $boundaries[$i + 1];
            if ($b->lessThanOrEqualTo($a)) {
                continue;
            }

            $segments[] = new WindowSegment(
                start: $a,
                end: $b,
                monthIndex: (int) $a->month - 1,
                phaseIndex: $this->governingPhaseIndex($resolved, $a, $b),
            );
        }

        return $segments;
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}|null
     */
    private function resolveRange(PricingPhase $phase, CarbonImmutable $windowStart, CarbonImmutable $windowEnd, RecurringScheduleData $recurring): ?array
    {
        $start = $this->resolveStart($phase->starts->kind, $phase->starts->value, $windowStart, $phase, $recurring);
        $end = $this->resolveEnd($phase->ends->kind, $phase->ends->value, $windowStart, $windowEnd, $recurring);

        if ($start === null || $end === null) {
            return null;
        }

        // Drop phases that ended before the window or start at/after its end.
        if ($end->lessThanOrEqualTo($windowStart) || $start->greaterThanOrEqualTo($windowEnd)) {
            return null;
        }

        $clampedStart = $start->greaterThan($windowStart) ? $start : $windowStart;
        $clampedEnd = $end->lessThan($windowEnd) ? $end : $windowEnd;

        return [$clampedStart, $clampedEnd];
    }

    private function resolveStart(BoundaryKind $kind, ?string $value, CarbonImmutable $windowStart, PricingPhase $phase, RecurringScheduleData $recurring): ?CarbonImmutable
    {
        return match ($kind) {
            // An unknown start on a phase whose end resolves is the already-running current
            // price: it is in effect at signup, so it covers from the window start. If the end
            // is also unknown the phase is dropped in resolveRange (both boundaries null).
            BoundaryKind::ContractStart, BoundaryKind::None, BoundaryKind::Unknown => $windowStart,
            BoundaryKind::Date => $this->parseDate($value),
            BoundaryKind::AfterMonths => $this->afterMonths($windowStart, $value),
            BoundaryKind::PeriodBoundary => $this->parseDate($recurring->currentPeriodStart) ?? $windowStart,
        };
    }

    private function resolveEnd(BoundaryKind $kind, ?string $value, CarbonImmutable $windowStart, CarbonImmutable $windowEnd, RecurringScheduleData $recurring): ?CarbonImmutable
    {
        return match ($kind) {
            BoundaryKind::None => $windowEnd,
            // Disclosed end dates are inclusive; convert to an exclusive boundary.
            BoundaryKind::Date => ($d = $this->parseDate($value)) !== null ? $d->addDay() : null,
            BoundaryKind::AfterMonths => $this->afterMonths($windowStart, $value),
            BoundaryKind::PeriodBoundary => ($d = $this->parseDate($recurring->currentPeriodEnd)) !== null ? $d->addDay() : null,
            BoundaryKind::ContractStart, BoundaryKind::Unknown => null,
        };
    }

    private function afterMonths(CarbonImmutable $windowStart, ?string $value): ?CarbonImmutable
    {
        if ($value === null || ! is_numeric($value)) {
            return null;
        }

        return $windowStart->addMonths((int) $value);
    }

    private function parseDate(?string $value): ?CarbonImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value, 'Europe/Helsinki')->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param list<array{index:int,start:CarbonImmutable,end:CarbonImmutable}> $resolved
     * @return list<CarbonImmutable>
     */
    private function boundaryPoints(array $resolved, CarbonImmutable $windowStart, CarbonImmutable $windowEnd): array
    {
        $points = [$windowStart->getTimestamp() => $windowStart, $windowEnd->getTimestamp() => $windowEnd];

        // Calendar-month boundaries inside the window.
        $cursor = $windowStart->addMonthNoOverflow()->startOfMonth();
        while ($cursor->lessThan($windowEnd)) {
            $points[$cursor->getTimestamp()] = $cursor;
            $cursor = $cursor->addMonthNoOverflow();
        }

        // Phase boundaries.
        foreach ($resolved as $range) {
            foreach ([$range['start'], $range['end']] as $point) {
                if ($point->greaterThanOrEqualTo($windowStart) && $point->lessThanOrEqualTo($windowEnd)) {
                    $points[$point->getTimestamp()] = $point;
                }
            }
        }

        ksort($points);

        return array_values($points);
    }

    /**
     * @param list<array{index:int,start:CarbonImmutable,end:CarbonImmutable}> $resolved
     */
    private function governingPhaseIndex(array $resolved, CarbonImmutable $a, CarbonImmutable $b): ?int
    {
        $governing = null;
        $governingStart = null;

        foreach ($resolved as $range) {
            if ($range['start']->lessThanOrEqualTo($a) && $range['end']->greaterThanOrEqualTo($b)) {
                if ($governingStart === null || $range['start']->greaterThanOrEqualTo($governingStart)) {
                    $governing = $range['index'];
                    $governingStart = $range['start'];
                }
            }
        }

        return $governing;
    }
}
