<?php

namespace Tests\Feature;

use App\Services\CompanyListCacheService;
use App\Services\ContractListCacheService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Picture-book heroes of the homepage and the cheapest contracts page (redesign "home" area):
 * the season art, the overlay top bar in the hero tone, and the Preferred Sources strip
 * directly after the hero.
 */
class HomeAndCheapestHeroTest extends TestCase
{
    use RefreshDatabase;

    public function test_homepage_renders_the_hilltop_hero_with_the_strip_after_it(): void
    {
        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('images/art/home-hero-', $html);
        $this->assertStringContainsString('images/art/home-fox-hilltop-', $html);
        $this->assertStringContainsString('images/art/home-interlude-', $html);
        $this->assertStringContainsString('Sähkö, aurinko, lämpö.', $html);
        $this->assertStringContainsString('Yksi riippumaton palvelu.', $html);
        $this->assertHeroThenStrip($html);
    }

    public function test_cheapest_page_renders_the_sled_hero_podium_and_toboggan_interlude(): void
    {
        app()->forgetScopedInstances();
        app(ContractListCacheService::class)->refresh(app(CompanyListCacheService::class));

        $html = $this->get('/sahkosopimus/halvin-sahkosopimus')->assertOk()->getContent();

        $this->assertStringContainsString('images/art/cheapest-hero-', $html);
        $this->assertStringContainsString('images/art/cheapest-fox-sled-', $html);
        $this->assertStringContainsString('cheapest-hero-fox', $html);
        $this->assertStringContainsString('images/art/cheapest-interlude-', $html);
        $this->assertStringContainsString('Edullisimmat sopimukset', $html);
        $this->assertStringContainsString('Seuraavaksi edullisimmat', $html);
        $this->assertStringContainsString('Katso myös', $html);
        $this->assertHeroThenStrip($html);
    }

    private function assertHeroThenStrip(string $html): void
    {
        $hero = strpos($html, 'data-site-hero');
        $strip = strpos($html, 'data-page-action-strip');

        $this->assertNotFalse($hero);
        $this->assertNotFalse($strip);
        $this->assertLessThan($strip, $hero, 'The action strip must follow the hero.');
        // The top bar sits over the painted hero (layout heroTone), not on a night band.
        $this->assertStringContainsString('data-topbar-mode="overlay"', $html);
    }
}
