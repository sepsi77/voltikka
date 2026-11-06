<?php

namespace Tests\Feature;

use App\Services\SitemapService;
use App\Support\MarketReviews;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Monthly market review URLs (user decisions 2026-11-06): /markkinakatsaus always shows the
 * latest review, each review has /markkinakatsaus/YYYY-MM, both are self-canonical, and a draft
 * is a 404 unless drafts are shown.
 */
class MarketReviewPageTest extends TestCase
{
    use RefreshDatabase;

    private string $viewDirectory;

    protected function setUp(): void
    {
        parent::setUp();

        // Two reviews that reuse the October body view: September published, October draft.
        config()->set('market_reviews.show_drafts', false);
        config()->set('market_reviews.editions', [
            '2026-10' => $this->edition('Lokakuu 2026', '2026-11-05', draft: true),
            '2026-09' => $this->edition('Syyskuu 2026', '2026-10-05', draft: false),
        ]);

        // The September fixture gets a minimal body view from a temporary view location.
        $this->viewDirectory = sys_get_temp_dir().'/market-review-test-'.getmypid();
        @mkdir($this->viewDirectory.'/market-reviews', 0777, true);
        file_put_contents($this->viewDirectory.'/market-reviews/2026-09.blade.php', '<p>Syyskuun runko</p>');
        view()->addLocation($this->viewDirectory);
    }

    protected function tearDown(): void
    {
        @unlink($this->viewDirectory.'/market-reviews/2026-09.blade.php');
        @rmdir($this->viewDirectory.'/market-reviews');
        @rmdir($this->viewDirectory);

        parent::tearDown();
    }

    public function test_the_stable_url_shows_the_latest_published_review_and_is_self_canonical(): void
    {
        $response = $this->get('/markkinakatsaus')->assertOk();

        $response->assertSee('Syyskuu 2026');
        $response->assertSee('Syyskuun runko');
        $response->assertSee('<link rel="canonical" href="'.config('app.url').'/markkinakatsaus">', false);
        $response->assertDontSee('Tämä on aiempi katsaus.');
    }

    public function test_a_dated_url_is_self_canonical_also_while_it_is_the_latest(): void
    {
        $this->get('/markkinakatsaus/2026-09')
            ->assertOk()
            ->assertSee('<link rel="canonical" href="'.config('app.url').'/markkinakatsaus/2026-09">', false);
    }

    public function test_a_draft_is_not_found_and_not_in_the_sitemap(): void
    {
        $this->get('/markkinakatsaus/2026-10')->assertNotFound();
        $this->get('/markkinakatsaus/2026-10.csv')->assertNotFound();

        $locations = array_column(app(SitemapService::class)->getMarketReviewUrls(), 'loc');
        $this->assertSame([
            config('app.url').'/markkinakatsaus',
            config('app.url').'/markkinakatsaus/2026-09',
        ], $locations);
    }

    public function test_unknown_and_malformed_months_are_not_found(): void
    {
        $this->get('/markkinakatsaus/2025-01')->assertNotFound();
        $this->get('/markkinakatsaus/2026-9')->assertNotFound();
    }

    public function test_with_shown_drafts_the_draft_is_the_latest_and_the_older_review_links_to_it(): void
    {
        config()->set('market_reviews.show_drafts', true);
        $latest = $this->get('/markkinakatsaus')->assertOk();
        $latest->assertSee('Halpa lokakuu, kallis talvi');
        $latest->assertSee('Luonnos, julkaistaan 5.11.2026');
        $latest->assertSee('href="/markkinakatsaus/2026-09"', false);

        $this->get('/markkinakatsaus/2026-09')
            ->assertOk()
            ->assertSee('Tämä on aiempi katsaus.')
            ->assertSee('Uusin katsaus: Lokakuu 2026');

        // A shown draft still never enters the sitemap.
        $this->assertCount(2, app(SitemapService::class)->getMarketReviewUrls());
    }

    public function test_the_page_has_the_strip_after_the_hero_and_inline_charts(): void
    {
        config()->set('market_reviews.show_drafts', true);

        $content = $this->get('/markkinakatsaus/2026-10')->assertOk()->getContent();

        $document = new \DOMDocument;
        @$document->loadHTML($content);
        $xpath = new \DOMXPath($document);
        $strip = $xpath->query('//*[@data-page-action-strip]');
        $this->assertSame(1, $strip->length);

        // SEO: one H1, the keyword heading (drawn as the kicker); the display title is not a heading.
        $h1 = $xpath->query('//h1');
        $this->assertSame(1, $h1->length);
        $this->assertSame('Sähkömarkkinakatsaus lokakuu 2026', trim(preg_replace('/\s+/u', ' ', $h1->item(0)->textContent)));
        $this->assertSame(1, $xpath->query('//p[contains(@class,"mr-title")][normalize-space()="Halpa lokakuu, kallis talvi"]')->length);
        $previous = $strip->item(0)->previousSibling;
        while ($previous !== null && ! $previous instanceof \DOMElement) {
            $previous = $previous->previousSibling;
        }
        $this->assertSame('section', $previous?->nodeName);

        // Interactive charts as elsewhere on the site: Chart.js bars and the shared uPlot line chart,
        // each with its frozen payload and a data table.
        $this->assertSame(2, $xpath->query('//canvas[@data-review-chart="bill-outlook"]')->length);
        $this->assertSame(1, $xpath->query('//canvas[@data-review-chart="daily-drivers"]')->length);
        $this->assertSame(1, $xpath->query('//*[@data-line-chart="seller-set-index"]/script[@type="application/json"]')->length);
        // A frozen chart has no "nyt" label (user decision 2026-11-06).
        $this->assertSame(0, $xpath->query('//*[@data-line-chart][@data-chart-now]')->length);
        $this->assertSame(3, $xpath->query('//figure[contains(@class,"mr-figure")]//details//table')->length);
        $this->assertStringContainsString('market-review-charts', $content);
        $this->assertStringContainsString('href="/markkinakatsaus/2026-10.csv"', $content);
        $this->assertStringContainsString('Sähkömarkkinakatsaus lokakuu 2026: Halpa lokakuu, kallis talvi | Voltikka', $content);
    }

    public function test_the_frozen_index_chart_shows_the_review_month_day_by_day(): void
    {
        $index = MarketReviews::chartData('2026-10', 'kuva-3');

        // Daily values of October 2026 plus 30.9., the start value of the October change (user
        // decisions 2026-11-06). The page never reads the database, so the chart stays the same.
        $this->assertSame(['Voltikan hintaindeksi', 'Määräaikaiset', 'Toistaiseksi voimassa', 'Kvartaali- ja kuukausihintaiset'], array_column($index['series'], 'label'));
        $this->assertCount(32, $index['x']);
        $this->assertSame(86400, $index['x'][3] - $index['x'][2]);
        $this->assertSame('2026-09-30', date('Y-m-d', $index['x'][0] + 3 * 3600));
        $this->assertSame('2026-10-31', date('Y-m-d', end($index['x']) + 3 * 3600));
        $this->assertTrue($index['showPoints'], 'The lone 30.9. start value needs a marker.');
        foreach ($index['series'] as $series) {
            // 1.10. has no stored value: a gap, not a filled point.
            $this->assertNull($series['values'][1], $series['label']);
            $this->assertCount(32, $series['values']);
        }

        // The October changes on the page are 30.9. → 31.10. from the stored values.
        $changes = array_map(fn (array $series) => round((end($series['values']) / $series['values'][0] - 1) * 100, 1), $index['series']);
        $this->assertSame([12.0, 1.2, 10.5, 45.4], $changes);
    }

    public function test_the_video_block_needs_a_youtube_id_except_on_a_draft(): void
    {
        // Published review without a video: no block, no VideoObject.
        $this->get('/markkinakatsaus/2026-09')->assertOk()->assertDontSee('Katso videona')->assertDontSee('VideoObject');

        config()->set('market_reviews.editions.2026-09.video', $this->video(null));
        $this->get('/markkinakatsaus/2026-09')->assertOk()->assertDontSee('Katso videona');

        // A draft shows the poster with a note, so the place can be reviewed.
        config()->set('market_reviews.show_drafts', true);
        config()->set('market_reviews.editions.2026-10.video', $this->video(null));
        $this->get('/markkinakatsaus/2026-10')->assertOk()
            ->assertSee('Katso videona')
            ->assertSee('Luonnos: YouTube-tunnus puuttuu')
            ->assertDontSee('youtube.com/watch')
            ->assertDontSee('youtube-nocookie');
    }

    public function test_the_video_loads_youtube_only_after_a_click_and_has_a_video_object(): void
    {
        config()->set('market_reviews.editions.2026-09.video', $this->video('abcDEF12345', '2026-11-06'));

        $content = $this->get('/markkinakatsaus/2026-09')->assertOk()->getContent();

        $document = new \DOMDocument;
        @$document->loadHTML($content);
        $xpath = new \DOMXPath($document);

        // Directly after the Preferred Sources strip, inside the page column.
        $this->assertSame(1, $xpath->query('//*[@data-page-action-strip]/following-sibling::div[1]/section[contains(@class,"mr-video")]')->length);
        // Without JavaScript the poster is a link to YouTube; the player is only in an Alpine template.
        $this->assertSame(1, $xpath->query('//a[contains(@class,"mr-video-poster")][@href="https://www.youtube.com/watch?v=abcDEF12345"]')->length);
        $this->assertSame(0, $xpath->query('//iframe[not(ancestor::template)]')->length, 'No YouTube request before a click.');
        $this->assertSame(1, $xpath->query('//template[@x-if="playing"]/iframe')->length);
        $this->assertStringContainsString('https://www.youtube-nocookie.com/embed/abcDEF12345?autoplay=1', $content);

        $this->assertStringContainsString('"@type":"VideoObject"', str_replace(' ', '', $content));
        $this->assertStringContainsString('"uploadDate":"2026-11-06"', str_replace(' ', '', $content));
        $this->assertStringContainsString('"duration":"PT5M0S"', str_replace(' ', '', $content));
    }

    /** @return array<string, mixed> */
    private function video(?string $id, ?string $uploadedOn = null): array
    {
        return [
            'youtube_id' => $id,
            'uploaded_on' => $uploadedOn,
            'duration' => 'PT5M0S',
            'duration_label' => '5:00',
            'poster' => '/images/market-reviews/2026-10/video.webp',
            'poster_960' => '/images/market-reviews/2026-10/video-960.webp',
            'caption' => 'Testivideo.',
        ];
    }

    public function test_the_home_page_features_the_latest_published_review(): void
    {
        config()->set('market_reviews.editions.2026-09.teaser', 'Syyskuun lyhyt kuvaus.');
        config()->set('market_reviews.editions.2026-09.video', $this->video('abcDEF12345', '2026-11-06'));

        $content = $this->get('/')->assertOk()->getContent();

        $document = new \DOMDocument;
        @$document->loadHTML($content);
        $xpath = new \DOMXPath($document);
        // Directly under the Preferred Sources strip, before "Palvelut".
        $row = $xpath->query('//*[@data-page-action-strip]/following-sibling::div[1]/*[1][self::section][contains(@class,"mr-feature")]');
        $this->assertSame(1, $row->length);
        $this->assertStringContainsString('Uusin sähkömarkkinakatsaus · Syyskuu 2026', $row->item(0)->textContent);
        $this->assertStringContainsString('Syyskuun lyhyt kuvaus.', $row->item(0)->textContent);
        $this->assertSame(1, $xpath->query('//section[contains(@class,"mr-feature")]//a[@href="/markkinakatsaus#video"]')->length);

        // The draft October review is not featured while it is a draft.
        $this->assertStringNotContainsString('Halpa lokakuu, kallis talvi', $content);
    }

    public function test_the_home_page_has_no_review_row_without_a_published_review(): void
    {
        config()->set('market_reviews.editions', [
            '2026-10' => $this->edition('Lokakuu 2026', '2026-11-05', draft: true),
        ]);

        $this->get('/')->assertOk()->assertDontSee('Uusin sähkömarkkinakatsaus');
    }

    public function test_the_csv_downloads_the_frozen_key_figures_without_futures_values(): void
    {
        config()->set('market_reviews.show_drafts', true);

        $response = $this->get('/markkinakatsaus/2026-10.csv')->assertOk();

        $this->assertStringStartsWith('text/csv', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('voltikka-markkinakatsaus-2026-10.csv', $response->headers->get('Content-Disposition'));
        $csv = file_get_contents(MarketReviews::csvPath('2026-10'));
        $this->assertStringContainsString('spot_mean,Pörssisähkön keskihinta lokakuussa,4.77,c/kWh', $csv);
        // EEX terms: no futures settlement values in a downloadable file.
        $this->assertStringNotContainsStringIgnoringCase('futuuri ', $csv);
        $this->assertStringNotContainsString('11.33', $csv);
    }

    public function test_the_navigation_links_the_review_only_while_one_is_visible(): void
    {
        config()->set('market_reviews.editions', [
            '2026-10' => $this->edition('Lokakuu 2026', '2026-11-05', draft: true),
        ]);

        $this->get('/tietoa')->assertOk()->assertDontSee('Sähkömarkkinakatsaus');

        config()->set('market_reviews.show_drafts', true);
        $this->get('/tietoa')->assertOk()->assertSee('href="/markkinakatsaus"', false);
    }

    /** @return array<string, mixed> */
    private function edition(string $label, string $publishedOn, bool $draft): array
    {
        return [
            'label' => $label,
            'title' => $label === 'Lokakuu 2026' ? 'Halpa lokakuu, kallis talvi' : 'Testikatsaus',
            'lead' => 'Testi.',
            'description' => 'Testikuvaus.',
            'published_on' => $publishedOn,
            'next_on' => null,
            'draft' => $draft,
        ];
    }
}
