<?php

namespace App\Support;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;

/**
 * Finnish public holidays and the de facto non-working eves, calculated in code.
 *
 * This is a demand-model feature, not a legal calendar. Two kinds:
 *
 * - `public_holiday`: the 13 Finnish public holidays (Easter Sunday and Whit Sunday included,
 *   although they are Sundays anyway).
 * - `eve`: juhannusaatto and jouluaatto only. They are non-business days in the Annual Holidays
 *   Act (162/2005), most workplaces and shops close, and electricity demand falls like on a
 *   holiday.
 *
 * Deliberately not `eve`: uudenvuodenaatto (31.12) and vappuaatto (30.4) are normal working days
 *   for most workplaces (at most shorter office hours), so treating them as holidays would teach a
 *   model the wrong demand level; pääsiäislauantai is always a Saturday, so a weekday feature
 *   already covers it. A model can add them as own features if the data shows an effect.
 *
 * The movable rules are valid from 1991: in 1973–1990 loppiainen and helatorstai were moved to
 * Saturdays, so earlier years are rejected instead of returning wrong dates.
 */
final class FinnishHolidays
{
    public const PUBLIC_HOLIDAY = 'public_holiday';

    public const EVE = 'eve';

    private const FIRST_SUPPORTED_YEAR = 1991;

    /**
     * Holidays and eves of one year, sorted by date.
     *
     * @return array<string, array{name: string, kind: string}> keyed by 'Y-m-d'
     */
    public static function forYear(int $year): array
    {
        if ($year < self::FIRST_SUPPORTED_YEAR || $year > 9999) {
            throw new InvalidArgumentException('Finnish holidays are supported for 1991–9999.');
        }

        $easter = self::easterSunday($year);
        $midsummerDay = self::saturdayFrom($year, 6, 20);

        $days = [
            [self::date($year, 1, 1), 'uudenvuodenpäivä', self::PUBLIC_HOLIDAY],
            [self::date($year, 1, 6), 'loppiainen', self::PUBLIC_HOLIDAY],
            [$easter->modify('-2 days'), 'pitkäperjantai', self::PUBLIC_HOLIDAY],
            [$easter, 'pääsiäispäivä', self::PUBLIC_HOLIDAY],
            [$easter->modify('+1 day'), '2. pääsiäispäivä', self::PUBLIC_HOLIDAY],
            [self::date($year, 5, 1), 'vappu', self::PUBLIC_HOLIDAY],
            [$easter->modify('+39 days'), 'helatorstai', self::PUBLIC_HOLIDAY],
            [$easter->modify('+49 days'), 'helluntaipäivä', self::PUBLIC_HOLIDAY],
            [$midsummerDay->modify('-1 day'), 'juhannusaatto', self::EVE],
            [$midsummerDay, 'juhannuspäivä', self::PUBLIC_HOLIDAY],
            [self::saturdayFrom($year, 10, 31), 'pyhäinpäivä', self::PUBLIC_HOLIDAY],
            [self::date($year, 12, 6), 'itsenäisyyspäivä', self::PUBLIC_HOLIDAY],
            [self::date($year, 12, 24), 'jouluaatto', self::EVE],
            [self::date($year, 12, 25), 'joulupäivä', self::PUBLIC_HOLIDAY],
            [self::date($year, 12, 26), 'tapaninpäivä', self::PUBLIC_HOLIDAY],
        ];

        $result = [];
        foreach ($days as [$date, $name, $kind]) {
            $result[$date->format('Y-m-d')] = ['name' => $name, 'kind' => $kind];
        }
        ksort($result);

        return $result;
    }

    /**
     * Holidays and eves between two dates, both inclusive, sorted by date.
     * Only the calendar date of each argument is used.
     *
     * @return array<string, array{name: string, kind: string}> keyed by 'Y-m-d'
     */
    public static function between(DateTimeInterface|string $from, DateTimeInterface|string $to): array
    {
        $fromKey = self::key($from);
        $toKey = self::key($to);
        if ($fromKey > $toKey) {
            throw new InvalidArgumentException('The start date must not be after the end date.');
        }

        $result = [];
        for ($year = (int) substr($fromKey, 0, 4); $year <= (int) substr($toKey, 0, 4); $year++) {
            foreach (self::forYear($year) as $date => $day) {
                if ($date >= $fromKey && $date <= $toKey) {
                    $result[$date] = $day;
                }
            }
        }

        return $result;
    }

    /**
     * The holiday or eve on one date, or null for an ordinary day.
     *
     * @return array{name: string, kind: string}|null
     */
    public static function on(DateTimeInterface|string $date): ?array
    {
        $key = self::key($date);

        return self::forYear((int) substr($key, 0, 4))[$key] ?? null;
    }

    /**
     * Western Easter Sunday with the anonymous Gregorian algorithm (Meeus/Jones/Butcher), so the
     * PHP calendar extension (easter_date) is not needed.
     */
    public static function easterSunday(int $year): DateTimeImmutable
    {
        $a = $year % 19;
        $b = intdiv($year, 100);
        $c = $year % 100;
        $d = intdiv($b, 4);
        $e = $b % 4;
        $f = intdiv($b + 8, 25);
        $g = intdiv($b - $f + 1, 3);
        $h = (19 * $a + $b - $d - $g + 15) % 30;
        $i = intdiv($c, 4);
        $k = $c % 4;
        $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
        $m = intdiv($a + 11 * $h + 22 * $l, 451);
        $month = intdiv($h + $l - 7 * $m + 114, 31);
        $day = (($h + $l - 7 * $m + 114) % 31) + 1;

        return self::date($year, $month, $day);
    }

    /** The first Saturday on or after the given date (juhannuspäivä 20–26.6, pyhäinpäivä 31.10–6.11). */
    private static function saturdayFrom(int $year, int $month, int $day): DateTimeImmutable
    {
        $date = self::date($year, $month, $day);
        $daysToSaturday = (6 - (int) $date->format('w') + 7) % 7;

        return $date->modify("+{$daysToSaturday} days");
    }

    private static function date(int $year, int $month, int $day): DateTimeImmutable
    {
        return new DateTimeImmutable(sprintf('%04d-%02d-%02d 00:00:00', $year, $month, $day), new DateTimeZone('UTC'));
    }

    private static function key(DateTimeInterface|string $date): string
    {
        if ($date instanceof DateTimeInterface) {
            return $date->format('Y-m-d');
        }

        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date, new DateTimeZone('UTC'));
        if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
            throw new InvalidArgumentException('Use a YYYY-MM-DD date.');
        }

        return $date;
    }
}
