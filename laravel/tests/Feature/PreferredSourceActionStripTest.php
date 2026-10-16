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

    public function test_the_strip_shows_the_desktop_only_notice_board_art_and_the_paper_note_action(): void
    {
        $content = $this->get('/tietoa')->assertOk()->getContent();

        // The art comes only from a desktop media source; the img itself keeps a 1×1 data URI,
        // so phones and tablets never download it.
        $this->assertMatchesRegularExpression(
            '#<source\s+media="\(min-width: 1024px\)"\s+srcset="[^"]*images/art/noticeboard-(talvi|kevat|kesa|syksy)-560\.webp 560w#',
            $content,
        );
        $this->assertStringContainsString('src="data:image/gif;base64,', $content);
        $this->assertStringContainsString('width="384"', $content);
        $this->assertStringNotContainsString('loading="lazy"', substr($content, (int) strpos($content, 'data-page-action-strip'), 4000));
        $this->assertStringContainsString('class="vk-strip-note vk-focus"', $content);
        $this->assertStringContainsString('Kiinnitä Voltikka Google-hakuusi', $content);
        $this->assertStringContainsString('Google avaa valinnan. Valinta on maksuton.', $content);
        $this->assertStringNotContainsString('Kylän ilmoitustaulu', $content);
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
