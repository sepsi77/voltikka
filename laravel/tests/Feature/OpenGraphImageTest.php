<?php

namespace Tests\Feature;

use App\Support\Season;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Every page has a 1200 x 630 share image: its own hero art in this season, or the home hero
 * (App\Support\Season::ogImage(), files from scripts/design-art/og.sh).
 */
class OpenGraphImageTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('pagesAndArt')]
    public function test_page_has_its_share_image_and_large_card(string $route, string $art, int $status): void
    {
        $response = $this->get($route);
        $response->assertStatus($status);

        self::assertShareImage($response->getContent(), $art);
    }

    public static function pagesAndArt(): array
    {
        return [
            'home' => ['/', 'home-hero', 200],
            'statistics' => ['/sahkosopimus/tilastot', 'stats-hero', 200],
            'forecast' => ['/sahkosopimus/sahkon-hintaennuste', 'forecast-hero', 200],
            'spot price' => ['/spot-price', 'spot-hero', 200],
            'bill comparison' => ['/maksatko-liikaa', 'maksatko-hero', 200],
            'about page without own share image' => ['/tietoa', 'home-hero', 200],
            'not found' => ['/tama-sivu-ei-ole-olemassa', 'home-hero', 404],
        ];
    }

    /**
     * Asserts the og:image / twitter tags of a rendered page and that the image file exists.
     */
    public static function assertShareImage(string $html, string $art): void
    {
        $pattern = '#<meta property="og:image" content="(https?://[^/"]+/images/og/'
            .preg_quote($art.'-'.Season::current(), '#').'\.jpg)">#';
        self::assertMatchesRegularExpression($pattern, $html);

        preg_match($pattern, $html, $match);
        self::assertFileExists(public_path(parse_url($match[1], PHP_URL_PATH)));

        self::assertStringContainsString('<meta property="og:image:width" content="1200">', $html);
        self::assertStringContainsString('<meta property="og:image:height" content="630">', $html);
        self::assertStringContainsString('<meta name="twitter:card" content="summary_large_image">', $html);
        self::assertStringContainsString('<meta name="twitter:image" content="'.$match[1].'">', $html);
    }
}
