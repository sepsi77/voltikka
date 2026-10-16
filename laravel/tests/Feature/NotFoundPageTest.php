<?php

namespace Tests\Feature;

use App\Livewire\AboutPage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class NotFoundPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_missing_url_returns_404_with_the_redesigned_finnish_page(): void
    {
        $response = $this->get('/tama-sivu-ei-ole-olemassa');

        $response->assertNotFound();
        $response->assertSee('data-not-found-page', false);
        $response->assertSee('Sivua ei löytynyt');
        $response->assertSee('Etsimäsi sivu on siirtynyt tai sitä ei ole enää.');
        $response->assertSee('Mihin olit menossa?');
        $response->assertSee('href="/sahkosopimus"', false);
        $response->assertSee('href="/spot-price"', false);
        $response->assertSee('href="/maksatko-liikaa"', false);
        $response->assertSee('<meta name="robots" content="noindex, follow">', false);
        // The site shell (top bar and footer) renders around it.
        $response->assertSee('site-desktop-nav', false);
        $response->assertSee('Tietoa Voltikasta');
        $response->assertDontSee('Not Found');
    }

    public function test_404_page_still_renders_when_the_layout_cannot_read_the_database(): void
    {
        // The footer reads a cached contract count; without the table and the cache it fails.
        Cache::forget('about:contract-count');
        Schema::drop('electricity_contracts');

        $response = $this->get('/tama-sivu-ei-ole-olemassa');

        $response->assertNotFound();
        $response->assertSee('Sivua ei löytynyt');
        $response->assertSee('Mihin olit menossa?');
        $response->assertDontSee('site-desktop-nav', false);
        $this->assertSame(0, substr_count($response->getContent(), 'data-page-action-strip'));
        $this->assertTrue(class_exists(AboutPage::class));
    }
}
