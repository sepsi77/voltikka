<?php

namespace Tests\Unit\CanonicalPricing;

use App\Services\CanonicalPricing\DTO\CanonicalComponent;
use App\Services\CanonicalPricing\DTO\PhaseBoundary;
use App\Services\CanonicalPricing\DTO\PricingPhase;
use App\Services\CanonicalPricing\DTO\RecurringScheduleData;
use App\Services\CanonicalPricing\DTO\WindowSegment;
use App\Services\CanonicalPricing\Enums\BoundaryKind;
use App\Services\CanonicalPricing\Enums\ComponentType;
use App\Services\CanonicalPricing\Enums\ComponentUnit;
use App\Services\CanonicalPricing\Enums\PhaseKind;
use App\Services\CanonicalPricing\Enums\PriceRole;
use App\Services\CanonicalPricing\Support\PhaseTimelineBuilder;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

class TimelineFractionReuseTest extends TestCase
{
    public function test_timeline_matches_original_boundary_and_fraction_arithmetic(): void
    {
        foreach (['2023-01-31', '2024-02-29', '2024-03-15', '2024-10-15'] as $date) {
            $start = CarbonImmutable::parse($date, 'Europe/Helsinki');
            $end = $start->addMonthsNoOverflow(12);
            $phases = [
                $this->phase(0, 1),
                $this->phase(2, 6),
                $this->phase(2, 4), // Equal starts: the later input wins.
                $this->phase(1, 2, false), // Empty pricing does not fill the gap.
            ];
            $phases[] = new PricingPhase(
                'dated split', PhaseKind::Future,
                new PhaseBoundary(BoundaryKind::Date, $start->addDays(10)->toDateString()),
                new PhaseBoundary(BoundaryKind::Date, $start->addDays(20)->toDateString()),
                $phases[0]->components,
            );
            $ranges = [
                [0, $start, $start->addMonthsNoOverflow(1)],
                [1, $start->addMonthsNoOverflow(2), $start->addMonthsNoOverflow(6)],
                [2, $start->addMonthsNoOverflow(2), $start->addMonthsNoOverflow(4)],
                [4, $start->addDays(10), $start->addDays(21)],
            ];
            $points = [$start->getTimestamp() => $start, $end->getTimestamp() => $end];
            $cursor = $start->addMonthNoOverflow()->startOfMonth();
            while ($cursor->lessThan($end)) {
                $points[$cursor->getTimestamp()] = $cursor;
                $cursor = $cursor->addMonthNoOverflow();
            }
            for ($month = 1; $month < 12; $month++) {
                $point = $start->addMonthsNoOverflow($month);
                $points[$point->getTimestamp()] = $point;
            }
            foreach ($ranges as [$index, $rangeStart, $rangeEnd]) {
                $points[$rangeStart->getTimestamp()] = $rangeStart;
                $points[$rangeEnd->getTimestamp()] = $rangeEnd;
            }
            ksort($points);
            $points = array_values($points);
            $expected = [];
            $fractions = array_fill(0, 12, 0.0);
            for ($i = 0; $i < count($points) - 1; $i++) {
                $a = $points[$i];
                $b = $points[$i + 1];
                $governing = null;
                foreach ($ranges as [$index, $rangeStart, $rangeEnd]) {
                    if ($rangeStart->lessThanOrEqualTo($a) && $rangeEnd->greaterThanOrEqualTo($b)) {
                        $governing = $index;
                    }
                }
                $monthIndex = (int) $a->month - 1;
                $fraction = max(0.0, min(1.0, $a->diffInDays($b) / (int) $a->daysInMonth));
                $fractions[$monthIndex] += $fraction;
                $expected[] = [$a, $b, $monthIndex, $governing, $fraction];
            }

            $segments = (new PhaseTimelineBuilder)->build($phases, new RecurringScheduleData(false, 'none', null, null, null), $start);
            $this->assertCount(count($expected), $segments);
            foreach ($segments as $i => $segment) {
                [$a, $b, $monthIndex, $governing, $fraction] = $expected[$i];
                $month = 0;
                while ($month < 11 && $a->greaterThanOrEqualTo($start->addMonthsNoOverflow($month + 1))) {
                    $month++;
                }
                $billingDays = $start->addMonthsNoOverflow($month)->diffInDays($start->addMonthsNoOverflow($month + 1));
                $this->assertSame($a->toIso8601String(), $segment->start->toIso8601String());
                $this->assertSame($b->toIso8601String(), $segment->end->toIso8601String());
                $this->assertSame($monthIndex, $segment->monthIndex);
                $this->assertSame($governing, $segment->phaseIndex);
                $this->assertSame($governing !== null, $segment->isCovered());
                $this->assertSame($fraction, $segment->monthFraction());
                $this->assertSame($fraction * (1 / $fractions[$monthIndex]), $segment->annualMonthFraction());
                $this->assertSame($billingDays, $segment->billingMonthDays);
                $this->assertSame($a->diffInDays($b) / $billingDays, $segment->billingMonthFraction());
            }
        }
    }

    public function test_segment_fractions_keep_clamping_signed_duration_and_null_fallback(): void
    {
        foreach ([
            ['2024-02-29', '2024-03-01'],
            ['2024-03-30', '2024-04-01'],
            ['2024-10-26', '2024-10-28'],
            ['2024-01-31', '2024-03-15'],
            ['2024-03-31', '2024-03-30'],
            ['2024-02-29', '2024-02-29'],
            ['2024-03-31 00:30', '2024-03-31 04:30'],
        ] as [$from, $to]) {
            $start = CarbonImmutable::parse($from, 'Europe/Helsinki');
            $end = CarbonImmutable::parse($to, 'Europe/Helsinki');
            $days = $start->diffInDays($end);
            $calendar = max(0.0, min(1.0, $days / (int) $start->daysInMonth));
            foreach ([null, 29.0, -29.0] as $billingDays) {
                $segment = new WindowSegment($start, $end, (int) $start->month - 1, null, 1.125, $billingDays);
                for ($repeat = 0; $repeat < 3; $repeat++) {
                    $this->assertSame($calendar, $segment->monthFraction());
                    $this->assertSame($calendar * 1.125, $segment->annualMonthFraction());
                    $this->assertSame($billingDays === null ? $calendar : $days / $billingDays, $segment->billingMonthFraction());
                }
            }
        }
    }

    public function test_split_segments_keep_their_denominators_and_fractions(): void
    {
        foreach ([['2024-02-01', '2024-02-15', '2024-03-01'], ['2024-03-01', '2024-03-31', '2024-04-01'], ['2024-10-01', '2024-10-27', '2024-11-01']] as [$from, $split, $to]) {
            $a = CarbonImmutable::parse($from, 'Europe/Helsinki');
            $b = CarbonImmutable::parse($split, 'Europe/Helsinki');
            $c = CarbonImmutable::parse($to, 'Europe/Helsinki');
            $whole = new WindowSegment($a, $c, (int) $a->month - 1, 2, 1.025, 30.0);
            $left = new WindowSegment($a, $b, $whole->monthIndex, $whole->phaseIndex, $whole->annualMonthScale, $whole->billingMonthDays);
            $right = new WindowSegment($b, $c, $whole->monthIndex, $whole->phaseIndex, $whole->annualMonthScale, $whole->billingMonthDays);
            foreach (['monthFraction', 'annualMonthFraction', 'billingMonthFraction'] as $method) {
                $this->assertEqualsWithDelta($whole->$method(), $left->$method() + $right->$method(), 1e-15);
            }
        }
    }

    public function test_segment_duration_is_calculated_once_not_on_each_fraction_read(): void
    {
        $start = CountingTimelineDate::parse('2024-03-30', 'Europe/Helsinki');
        $end = CarbonImmutable::parse('2024-04-01', 'Europe/Helsinki');
        CountingTimelineDate::$dayDiffCalls = 0;
        $segment = new WindowSegment($start, $end, 2, 0, 1.125, 31.0);
        for ($i = 0; $i < 10; $i++) {
            $segment->monthFraction();
            $segment->annualMonthFraction();
            $segment->billingMonthFraction();
        }
        $this->assertSame(1, CountingTimelineDate::$dayDiffCalls);
    }

    public function test_builder_calculates_anniversaries_and_billing_durations_once(): void
    {
        $start = CountingTimelineDate::parse('2024-01-31', 'Europe/Helsinki');
        CountingTimelineDate::$anniversaryCalls = 0;
        CountingTimelineDate::$dayDiffCalls = 0;
        $segments = (new PhaseTimelineBuilder)->build([], new RecurringScheduleData(false, 'none', null, null, null), $start);
        $this->assertSame(13, CountingTimelineDate::$anniversaryCalls);
        // Twelve billing months plus one duration for each raw and normalized segment.
        $this->assertSame(12 + 2 * count($segments), CountingTimelineDate::$dayDiffCalls);
    }

    private function phase(int $start, int $end, bool $known = true): PricingPhase
    {
        return new PricingPhase(
            'test', PhaseKind::CurrentStructured,
            new PhaseBoundary(BoundaryKind::AfterMonths, (string) $start),
            new PhaseBoundary(BoundaryKind::AfterMonths, (string) $end),
            $known ? [new CanonicalComponent(ComponentType::EnergyGeneral, 8.0, null, ComponentUnit::CentsPerKwh, PriceRole::Current)] : [],
        );
    }
}

class CountingTimelineDate extends CarbonImmutable
{
    public static int $dayDiffCalls = 0;

    public static int $anniversaryCalls = 0;

    public function __call(string $method, array $parameters): mixed
    {
        if ($method === 'addMonthsNoOverflow') {
            self::$anniversaryCalls++;
        }

        return parent::__call($method, $parameters);
    }

    public function diffInDays($date = null, bool $absolute = false, bool $utc = false): float
    {
        self::$dayDiffCalls++;

        return parent::diffInDays($date, $absolute, $utc);
    }
}
