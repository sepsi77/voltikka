<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PreferredSourceActionStripTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('representativePublicRoutes')]
    public function test_representative_public_routes_render_one_preferred_source_strip(string $route): void
    {
        $response = $this->get($route);

        $response->assertOk();
        $response->assertSee('data-page-action-strip', false);
        $response->assertSee('data-google-preferred-source-action', false);
        $response->assertSee("placement: 'post_hero'", false);
        $this->assertSame(1, substr_count($response->getContent(), 'data-page-action-strip'));
        $this->assertSame(1, substr_count(
            $response->getContent(),
            'https://news.google.com/swg/js/v1/publisher.js',
        ));
    }

    public static function representativePublicRoutes(): array
    {
        return [
            'home' => ['/'],
            'about' => ['/tietoa'],
            'privacy' => ['/tietosuoja'],
            'terms' => ['/kayttoehdot'],
            'consumption calculator' => ['/sahkosopimus/laskuri'],
            'bill comparison' => ['/maksatko-liikaa'],
            'solar calculator' => ['/aurinkopaneelit/laskuri'],
            'heat pump calculator' => ['/lampopumput/laskuri'],
        ];
    }
}
