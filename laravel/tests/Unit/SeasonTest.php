<?php

namespace Tests\Unit;

use App\Support\Season;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SeasonTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function seasonBoundaries(): array
    {
        return [
            'december is talvi' => ['2026-12-01 00:00', Season::TALVI],
            'february is talvi' => ['2027-02-28 23:59', Season::TALVI],
            'march is kevat' => ['2027-03-01 00:00', Season::KEVAT],
            'may is kevat' => ['2027-05-31 23:59', Season::KEVAT],
            'june is kesa' => ['2027-06-01 00:00', Season::KESA],
            'august is kesa' => ['2027-08-31 23:59', Season::KESA],
            'september is syksy' => ['2026-09-01 00:00', Season::SYKSY],
            'november is syksy' => ['2026-11-30 23:59', Season::SYKSY],
        ];
    }

    #[DataProvider('seasonBoundaries')]
    public function test_season_follows_the_helsinki_month(string $helsinkiTime, string $expected): void
    {
        $now = CarbonImmutable::parse($helsinkiTime, 'Europe/Helsinki');

        $this->assertSame($expected, Season::current($now));
    }

    public function test_season_uses_helsinki_time_not_utc(): void
    {
        // 2026-11-30 22:30 UTC is already 1 December 00:30 in Helsinki.
        $now = CarbonImmutable::parse('2026-11-30 22:30', 'UTC');

        $this->assertSame(Season::TALVI, Season::current($now));
    }

    public function test_art_url_names_the_current_season_and_width(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-16 12:00', 'Europe/Helsinki'));

        try {
            $this->assertStringEndsWith('/images/art/listing-hero-syksy.webp', Season::art('listing-hero'));
            $this->assertStringEndsWith('/images/art/listing-hero-syksy-960.webp', Season::art('listing-hero', 960));
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_listing_hero_is_light_only_in_spring_and_summer(): void
    {
        foreach (['2027-01-15' => 'dark', '2027-04-15' => 'light', '2027-07-15' => 'light', '2026-10-15' => 'dark'] as $date => $tone) {
            CarbonImmutable::setTestNow(CarbonImmutable::parse($date.' 12:00', 'Europe/Helsinki'));
            $this->assertSame($tone, Season::heroTone('listing-hero'), $date);
        }

        CarbonImmutable::setTestNow();
        $this->assertSame('dark', Season::heroTone('unknown-hero'));
    }

    public function test_every_listing_art_file_exists_for_every_season(): void
    {
        foreach (Season::ALL as $season) {
            foreach (['listing-hero', 'listing-hero-960', 'fox-walk-lantern', 'footer', 'footer-960', 'interlude-walk'] as $file) {
                $name = str_contains($file, '-960')
                    ? str_replace('-960', '', $file).'-'.$season.'-960'
                    : $file.'-'.$season;

                $this->assertFileExists(public_path('images/art/'.$name.'.webp'));
            }
        }
    }

    public function test_og_image_uses_the_art_of_this_season_when_it_exists(): void
    {
        $this->assertSame(
            asset('images/og/stats-hero-'.Season::current().'.jpg'),
            Season::ogImage('stats-hero'),
        );
    }

    public function test_og_image_falls_back_to_the_home_hero_of_this_season(): void
    {
        $home = asset('images/og/home-hero-'.Season::current().'.jpg');

        // tietoa-hero has no share image, and a page without art passes nothing.
        $this->assertSame($home, Season::ogImage('tietoa-hero'));
        $this->assertSame($home, Season::ogImage(null));
    }

    public function test_every_og_image_file_exists_for_every_season(): void
    {
        $this->assertFileExists(public_path('images/og/default.jpg'));

        foreach (['home-hero', 'listing-hero', 'contract-band', 'company-band', 'stats-hero', 'forecast-hero', 'hero-city', 'hero-city-helsinki', 'hero-city-tampere', 'hero-city-turku', 'hero-city-oulu'] as $art) {
            foreach (Season::ALL as $season) {
                $this->assertFileExists(public_path('images/og/'.$art.'-'.$season.'.jpg'));
            }
        }
    }
}
