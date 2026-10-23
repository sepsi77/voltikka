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

    #[DataProvider('representativePublicRoutes')]
    public function test_the_strip_is_the_first_element_after_the_hero(string $route): void
    {
        $response = $this->get($route);
        $response->assertOk();

        $document = new \DOMDocument;
        @$document->loadHTML($response->getContent());
        $strip = (new \DOMXPath($document))->query('//*[@data-page-action-strip]')->item(0);
        $this->assertNotNull($strip);

        $previous = $strip->previousSibling;
        while ($previous !== null && ! $previous instanceof \DOMElement) {
            $previous = $previous->previousSibling;
        }

        // Every painted hero is a <section> with a hero tone class (x-site.* hero components).
        $this->assertNotNull($previous, 'The strip must follow the hero.');
        $this->assertSame('section', $previous->nodeName);
        $this->assertMatchesRegularExpression('/\bvk-tone-(dark|light)\b/', $previous->getAttribute('class'));
    }

    public function test_the_strip_has_desktop_only_art_and_an_inked_text_action(): void
    {
        $content = $this->get('/tietoa')->assertOk()->getContent();
        $strip = substr($content, (int) strpos($content, 'data-page-action-strip'), 7000);

        // Option A (user decision 2026-10-23): a small notice board from a desktop-only media
        // source; the img keeps a 1×1 data URI, so phones never download the art.
        $this->assertMatchesRegularExpression(
            '#<source\s+media="\(min-width: 1024px\)"\s+srcset="[^"]*images/art/noticeboard-(talvi|kevat|kesa|syksy)-560\.webp 560w#',
            $strip,
        );
        $this->assertStringContainsString('src="data:image/gif;base64,', $strip);
        $this->assertStringNotContainsString('loading="lazy"', $strip);

        // The action is inked text, not a boxed paper note, and the title is not a heading.
        $this->assertStringContainsString('class="vk-strip-action vk-focus"', $strip);
        $this->assertStringNotContainsString('vk-strip-note', $strip);
        $this->assertStringNotContainsString('<h2', $strip);
        $this->assertStringContainsString('Kiinnitä Voltikka Google-hakuusi', $strip);
        $this->assertStringContainsString('Valitse suosikkilähteeksi', $strip);
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
            'spot price' => ['/spot-price'],
            'statistics' => ['/sahkosopimus/tilastot'],
            'forecast' => ['/sahkosopimus/sahkon-hintaennuste'],
            'locations' => ['/sahkosopimus/paikkakunnat'],
        ];
    }
}
