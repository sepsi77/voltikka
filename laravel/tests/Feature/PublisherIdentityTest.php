<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The site names its maintainer and operating company (user decision 2026-10-31).
 */
class PublisherIdentityTest extends TestCase
{
    use RefreshDatabase;

    public function test_about_page_names_maintainer_company_and_linkedin(): void
    {
        $response = $this->get('/tietoa')->assertOk();

        $response->assertSee('Voltikan on tehnyt <strong>Seppo Puusa</strong>', false);
        $response->assertSee('href="https://www.linkedin.com/in/seppopuusa/"', false);
        $response->assertSee('Breezily OÜ');
        $response->assertSee('Rekisterikoodi 16564064');
        $response->assertSee('ALV-numero EE102541218');
        $response->assertSee('Sepapaja tn 6, 15551 Tallinn, Viro');
        $response->assertDontSee('yksityishenkilö');
        $response->assertDontSee('emme julkaise henkilön nimeä');
    }

    public function test_about_page_schema_has_founder_and_parent_organization(): void
    {
        $content = $this->get('/tietoa')->assertOk()->getContent();
        preg_match_all('#<script type="application/ld\+json">(.*?)</script>#s', $content, $matches);
        $organization = collect($matches[1])
            ->map(fn (string $json) => json_decode($json, true))
            ->first(fn ($schema) => ($schema['@type'] ?? null) === 'Organization' && ($schema['name'] ?? null) === 'Voltikka');

        $this->assertNotNull($organization);
        $this->assertSame('Person', $organization['founder']['@type']);
        $this->assertSame('Seppo Puusa', $organization['founder']['name']);
        $this->assertSame(['https://www.linkedin.com/in/seppopuusa/'], $organization['founder']['sameAs']);
        $this->assertSame('Breezily OÜ', $organization['parentOrganization']['name']);
        $this->assertSame('16564064', $organization['parentOrganization']['identifier']);
        $this->assertSame('EE102541218', $organization['parentOrganization']['vatID']);
        $this->assertSame('EE', $organization['parentOrganization']['address']['addressCountry']);
    }

    public function test_privacy_policy_names_the_data_controller(): void
    {
        $response = $this->get('/tietosuoja')->assertOk();

        $response->assertSee('id="rekisterinpitaja"', false);
        $response->assertSee('Breezily OÜ');
        $response->assertSee('Rekisterikoodi 16564064');
        $response->assertSee('Yhteyshenkilö on Seppo Puusa');
    }

    public function test_terms_name_the_service_provider(): void
    {
        $this->get('/kayttoehdot')->assertOk()
            ->assertSee('Palvelun tarjoaja:')
            ->assertSee('Breezily OÜ')
            ->assertSee('Rekisterikoodi 16564064');
    }

    public function test_footer_names_maintainer_and_company(): void
    {
        $this->get('/tietoa')->assertOk()
            ->assertSee('Voltikka on Seppo Puusan riippumaton energiavertailupalvelu.')
            ->assertSee('Breezily OÜ, rekisterikoodi 16564064');
    }
}
