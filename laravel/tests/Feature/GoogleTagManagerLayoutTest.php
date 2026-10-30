<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GoogleTagManagerLayoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_layout_loads_the_configured_container_and_no_direct_plausible_script(): void
    {
        config()->set('services.google_tag_manager.container_id', 'GTM-TEST123');

        $html = $this->get('/tietosuoja')->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, 'https://www.googletagmanager.com/gtm.js?id='));
        $this->assertStringContainsString("(window,document,'script','dataLayer','GTM-TEST123')", $html);
        $this->assertStringNotContainsString('plausible.io/js/', $html);
        $this->assertStringNotContainsString('plausible.init', $html);
    }

    public function test_layout_has_no_container_script_without_an_id(): void
    {
        config()->set('services.google_tag_manager.container_id', null);

        $html = $this->get('/tietosuoja')->assertOk()->getContent();

        $this->assertStringNotContainsString('googletagmanager.com', $html);
    }

    public function test_privacy_policy_names_google_tag_manager(): void
    {
        $this->get('/tietosuoja')
            ->assertOk()
            ->assertSee('Googlen Tag Manager -työkalun avulla');
    }
}
