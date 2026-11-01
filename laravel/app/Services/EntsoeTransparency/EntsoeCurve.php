<?php

namespace App\Services\EntsoeTransparency;

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use SimpleXMLElement;

/**
 * Read the points of one ENTSO-E Period (or Available_Period) element.
 *
 * Curve type A01: each point covers exactly one slot. Curve type A03 (variable-sized blocks): a
 * point is omitted when the value does not change, so each point lasts until the next point's
 * position and the last point until the end of the time interval. ENTSO-E sends A03 for
 * generation, reservoirs and outages; without the fill, slots and whole hours go missing.
 *
 * Minute and hour resolutions step in UTC. Day and week resolutions (P1D, P7D) step in
 * Europe/Brussels market time, so a week keeps its local Monday 00:00 start across DST.
 */
class EntsoeCurve
{
    private const MARKET_TIMEZONE = 'Europe/Brussels';

    /**
     * Value of every slot, keyed by the slot start (unix seconds).
     *
     * @return array<int, float>
     */
    public static function slots(SimpleXMLElement $period, string $curveType): array
    {
        [$start, $end, $resolution] = self::interval($period);
        if ($start === null || $end === null || $resolution === null) {
            return [];
        }

        $points = self::points($period);
        if ($points === []) {
            return [];
        }

        $slots = [];
        if ($curveType !== 'A03') {
            foreach ($points as $position => $value) {
                $slotStart = self::slotStart($start, $resolution, $position - 1);
                if ($slotStart->lessThan($end)) {
                    $slots[$slotStart->getTimestamp()] = $value;
                }
            }

            return $slots;
        }

        $value = null;
        for ($position = array_key_first($points); ; $position++) {
            $slotStart = self::slotStart($start, $resolution, $position - 1);
            if ($slotStart->greaterThanOrEqualTo($end)) {
                break;
            }
            $value = $points[$position] ?? $value;
            $slots[$slotStart->getTimestamp()] = $value;
        }

        return $slots;
    }

    /**
     * Constant-value segments of the curve, adjacent equal values merged. Used for outage curves,
     * which have minute resolution over months and must not be expanded slot by slot.
     *
     * @return list<array{start: string, end: string, mw: float}>
     */
    public static function segments(SimpleXMLElement $period, string $curveType): array
    {
        [$start, $end, $resolution] = self::interval($period);
        if ($start === null || $end === null || $resolution === null) {
            return [];
        }

        $points = self::points($period);
        $positions = array_keys($points);
        $segments = [];

        foreach ($positions as $index => $position) {
            $segmentStart = self::slotStart($start, $resolution, $position - 1);
            if ($curveType === 'A03') {
                $next = $positions[$index + 1] ?? null;
                $segmentEnd = $next === null ? $end : self::slotStart($start, $resolution, $next - 1);
            } else {
                $segmentEnd = self::slotStart($start, $resolution, $position);
            }
            if ($segmentEnd->greaterThan($end)) {
                $segmentEnd = $end;
            }
            if (! $segmentStart->lessThan($segmentEnd)) {
                continue;
            }

            $last = count($segments) - 1;
            if ($last >= 0 && $segments[$last]['mw'] === $points[$position]
                && $segments[$last]['end'] === self::format($segmentStart)) {
                $segments[$last]['end'] = self::format($segmentEnd);

                continue;
            }

            $segments[] = ['start' => self::format($segmentStart), 'end' => self::format($segmentEnd), 'mw' => $points[$position]];
        }

        return $segments;
    }

    public static function format(CarbonImmutable $time): string
    {
        return $time->utc()->format('Y-m-d H:i:s');
    }

    /**
     * Point values keyed by position, sorted. Later duplicates of a position win.
     *
     * @return array<int, float>
     */
    private static function points(SimpleXMLElement $period): array
    {
        $points = [];
        foreach ($period->Point as $point) {
            $position = (int) $point->position;
            $quantity = (string) $point->quantity;
            if ($position < 1 || $quantity === '' || ! is_numeric($quantity)) {
                continue;
            }
            $points[$position] = (float) $quantity;
        }
        ksort($points);

        return $points;
    }

    /**
     * @return array{0: ?CarbonImmutable, 1: ?CarbonImmutable, 2: ?array{unit: string, size: int}}
     */
    private static function interval(SimpleXMLElement $period): array
    {
        $start = (string) $period->timeInterval->start;
        $end = (string) $period->timeInterval->end;
        $resolution = self::parseResolution((string) $period->resolution);

        if ($start === '' || $end === '') {
            return [null, null, $resolution];
        }

        return [CarbonImmutable::parse($start, 'UTC')->utc(), CarbonImmutable::parse($end, 'UTC')->utc(), $resolution];
    }

    /**
     * @return array{unit: string, size: int}|null
     */
    private static function parseResolution(string $resolution): ?array
    {
        if (preg_match('/^PT(\d+)M$/', $resolution, $m)) {
            return ['unit' => 'minute', 'size' => (int) $m[1]];
        }
        if (preg_match('/^PT(\d+)H$/', $resolution, $m)) {
            return ['unit' => 'minute', 'size' => (int) $m[1] * 60];
        }
        if (preg_match('/^P(\d+)D$/', $resolution, $m)) {
            return ['unit' => 'day', 'size' => (int) $m[1]];
        }

        return null;
    }

    /**
     * @param  array{unit: string, size: int}  $resolution
     */
    private static function slotStart(CarbonImmutable $start, array $resolution, int $index): CarbonImmutable
    {
        if ($resolution['unit'] === 'minute') {
            return $start->addMinutes($index * $resolution['size']);
        }

        return $start->setTimezone(self::MARKET_TIMEZONE)->addDays($index * $resolution['size'])->utc();
    }

    /**
     * Monday-based ISO year and week of a slot start in market time.
     *
     * @return array{0: int, 1: int}
     */
    public static function isoWeek(int $timestamp): array
    {
        $local = Carbon::createFromTimestamp($timestamp, self::MARKET_TIMEZONE);

        return [(int) $local->format('o'), (int) $local->format('W')];
    }
}
