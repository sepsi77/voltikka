<?php

namespace Tests\Unit;

use App\Support\FinnishHolidays;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Known dates checked on 2026-11-01 against:
 * - https://holidaydb.com/guides/public-holidays/finland/2023 (and /2024, /2025, /2026, /2027):
 *   every weekday holiday plus Midsummer Eve and Christmas Eve. That list omits the two Sunday
 *   holidays, so pääsiäispäivä = its Good Friday + 2 days and helluntaipäivä = its Ascension
 *   Day + 10 days.
 * - https://fi.wikipedia.org/wiki/Pääsiäinen (Gregorian Easter table): 5.4.2026 and 28.3.2027.
 * - https://en.wikipedia.org/wiki/Public_holidays_in_Finland: the rules (Midsummer Day is the
 *   Saturday between 20 and 26 June, All Saints' Day the Saturday between 31 October and
 *   6 November, Ascension Easter + 39, Whit Sunday Easter + 49).
 */
class FinnishHolidaysTest extends TestCase
{
    /**
     * @return array<string, array{int, array<string, string>}>
     */
    public static function knownYears(): array
    {
        // Only the movable dates differ between years; the fixed ones are added in the test.
        return [
            '2023' => [2023, ['04-07' => 'pitkäperjantai', '04-09' => 'pääsiäispäivä', '04-10' => '2. pääsiäispäivä', '05-18' => 'helatorstai', '05-28' => 'helluntaipäivä', '06-23' => 'juhannusaatto', '06-24' => 'juhannuspäivä', '11-04' => 'pyhäinpäivä']],
            '2024' => [2024, ['03-29' => 'pitkäperjantai', '03-31' => 'pääsiäispäivä', '04-01' => '2. pääsiäispäivä', '05-09' => 'helatorstai', '05-19' => 'helluntaipäivä', '06-21' => 'juhannusaatto', '06-22' => 'juhannuspäivä', '11-02' => 'pyhäinpäivä']],
            '2025' => [2025, ['04-18' => 'pitkäperjantai', '04-20' => 'pääsiäispäivä', '04-21' => '2. pääsiäispäivä', '05-29' => 'helatorstai', '06-08' => 'helluntaipäivä', '06-20' => 'juhannusaatto', '06-21' => 'juhannuspäivä', '11-01' => 'pyhäinpäivä']],
            '2026' => [2026, ['04-03' => 'pitkäperjantai', '04-05' => 'pääsiäispäivä', '04-06' => '2. pääsiäispäivä', '05-14' => 'helatorstai', '05-24' => 'helluntaipäivä', '06-19' => 'juhannusaatto', '06-20' => 'juhannuspäivä', '10-31' => 'pyhäinpäivä']],
            '2027' => [2027, ['03-26' => 'pitkäperjantai', '03-28' => 'pääsiäispäivä', '03-29' => '2. pääsiäispäivä', '05-06' => 'helatorstai', '05-16' => 'helluntaipäivä', '06-25' => 'juhannusaatto', '06-26' => 'juhannuspäivä', '11-06' => 'pyhäinpäivä']],
        ];
    }

    /**
     * @param  array<string, string>  $movable
     */
    #[DataProvider('knownYears')]
    public function test_known_dates(int $year, array $movable): void
    {
        $expected = [
            '01-01' => 'uudenvuodenpäivä',
            '01-06' => 'loppiainen',
            '05-01' => 'vappu',
            '12-06' => 'itsenäisyyspäivä',
            '12-24' => 'jouluaatto',
            '12-25' => 'joulupäivä',
            '12-26' => 'tapaninpäivä',
        ] + $movable;
        ksort($expected);

        $actual = FinnishHolidays::forYear($year);

        $this->assertSame(
            array_map(fn (string $md) => "{$year}-{$md}", array_keys($expected)),
            array_keys($actual),
        );
        $this->assertSame(array_values($expected), array_column($actual, 'name'));

        $eves = array_keys(array_filter($actual, fn (array $day) => $day['kind'] === FinnishHolidays::EVE));
        $this->assertSame([$year.'-'.array_search('juhannusaatto', $movable, true), "{$year}-12-24"], $eves);
        $this->assertCount(13, array_filter($actual, fn (array $day) => $day['kind'] === FinnishHolidays::PUBLIC_HOLIDAY));
    }

    public function test_easter_sunday_for_extreme_and_known_years(): void
    {
        $this->assertSame('2026-04-05', FinnishHolidays::easterSunday(2026)->format('Y-m-d'));
        $this->assertSame('2008-03-23', FinnishHolidays::easterSunday(2008)->format('Y-m-d'));
        $this->assertSame('2038-04-25', FinnishHolidays::easterSunday(2038)->format('Y-m-d'));
        $this->assertSame('2285-03-22', FinnishHolidays::easterSunday(2285)->format('Y-m-d'));
    }

    public function test_between_spans_years_inclusively_and_on_finds_one_date(): void
    {
        $days = FinnishHolidays::between('2025-12-24', new DateTimeImmutable('2026-01-06 23:30:00'));

        $this->assertSame(['2025-12-24', '2025-12-25', '2025-12-26', '2026-01-01', '2026-01-06'], array_keys($days));
        $this->assertSame(['name' => 'juhannusaatto', 'kind' => FinnishHolidays::EVE], FinnishHolidays::on('2026-06-19'));
        $this->assertNull(FinnishHolidays::on('2026-12-31'));
        $this->assertNull(FinnishHolidays::on('2026-04-30'));
    }

    public function test_rejects_invalid_input_and_years_before_1991(): void
    {
        $this->expectException(InvalidArgumentException::class);
        FinnishHolidays::forYear(1990);
    }

    public function test_rejects_an_overflowing_date_string(): void
    {
        $this->expectException(InvalidArgumentException::class);
        FinnishHolidays::on('2026-02-30');
    }
}
