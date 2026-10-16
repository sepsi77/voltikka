<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Selects the Finnish season of the redesign art and the text tone of each hero.
 *
 * The site always shows the art of the current Europe/Helsinki season
 * (design/voltikka-design-system.md §7 "Seasons"). Files are built by
 * scripts/build-design-art.sh into public/images/art/<name>-<season>[-<width>].webp.
 */
final class Season
{
    public const TALVI = 'talvi';

    public const KEVAT = 'kevat';

    public const KESA = 'kesa';

    public const SYKSY = 'syksy';

    public const ALL = [self::TALVI, self::KEVAT, self::KESA, self::SYKSY];

    /**
     * Seasons in which a hero's art is bright, so the top bar and hero text use ink
     * (the "light hero variant"). All other seasons use white text on a navy shade.
     * The tone follows the brightness of the art, not the season name.
     */
    private const LIGHT_HEROES = [
        'listing-hero' => [self::KEVAT, self::KESA],
        'spot-hero' => [self::KEVAT, self::KESA],
        'companies-hero' => [self::KEVAT, self::KESA],
        'company-band' => [self::KEVAT, self::KESA],
        'home-hero' => [self::KEVAT, self::KESA],
        'cheapest-hero' => [self::KEVAT, self::KESA],
        'contract-band' => [self::KEVAT, self::KESA],
        'laskuri-hero' => [self::KEVAT, self::KESA],
        'maksatko-hero' => [self::KEVAT, self::KESA],
        'solar-hero' => [self::KEVAT, self::KESA],
        'heatpump-hero' => [self::KEVAT, self::KESA],
        '404-forest' => [self::KEVAT, self::KESA],
        'hero-porssisahko' => [self::KEVAT, self::KESA],
        'hero-city' => [self::KEVAT, self::KESA],
        'hero-city-helsinki' => [self::KEVAT, self::KESA],
        'hero-city-tampere' => [self::KEVAT, self::KESA],
        'hero-city-turku' => [self::KEVAT, self::KESA],
        'hero-city-oulu' => [self::KEVAT, self::KESA],
        'map-finland' => [self::KEVAT, self::KESA],
        'stats-hero' => [self::KEVAT, self::KESA],
        'forecast-hero' => [self::KEVAT, self::KESA, self::SYKSY],
    ];

    public static function current(?CarbonInterface $now = null): string
    {
        $override = self::localOverride();
        if ($override !== null) {
            return $override;
        }

        $month = ($now ?? CarbonImmutable::now('Europe/Helsinki'))
            ->setTimezone('Europe/Helsinki')
            ->month;

        return match (true) {
            $month === 12 || $month <= 2 => self::TALVI,
            $month <= 5 => self::KEVAT,
            $month <= 8 => self::KESA,
            default => self::SYKSY,
        };
    }

    /**
     * Public URL of a season-bound art file, for example art('listing-hero', 960).
     */
    public static function art(string $name, ?int $width = null): string
    {
        $suffix = $width === null ? '' : '-'.$width;

        return asset('images/art/'.$name.'-'.self::current().$suffix.'.webp');
    }

    /**
     * Absolute URL of the 1200 x 630 Open Graph image of a hero art in this season
     * (public/images/og/<art>-<season>.jpg, built by scripts/design-art/og.sh). A page whose
     * art has no share image gets the home hero of this season, then og/default.jpg.
     */
    public static function ogImage(?string $art = null): string
    {
        foreach (array_filter([$art, 'home-hero']) as $name) {
            $path = 'images/og/'.$name.'-'.self::current().'.jpg';

            if (is_file(public_path($path))) {
                return asset($path);
            }
        }

        return asset('images/og/default.jpg');
    }

    /**
     * 'light' when the hero art of this season is bright, otherwise 'dark'.
     */
    public static function heroTone(string $hero): string
    {
        return in_array(self::current(), self::LIGHT_HEROES[$hero] ?? [], true) ? 'light' : 'dark';
    }

    /**
     * Local preview of another season with ?vuodenaika=kesa. Never in production,
     * because shared edge-cached HTML must not depend on a query parameter.
     */
    private static function localOverride(): ?string
    {
        if (! app()->isLocal() || app()->runningInConsole()) {
            return null;
        }

        $season = request()->query('vuodenaika');

        return is_string($season) && in_array($season, self::ALL, true) ? $season : null;
    }
}
