<?php

namespace App\Livewire;

use App\Models\Company;
use App\Models\ElectricityContract;
use App\Models\SpotPriceAverage;
use App\Support\Season;
use Illuminate\Support\Facades\Cache;
use Livewire\Component;

/**
 * /tietoa — publisher identity, funding disclosure, and cost-calculation methodology.
 *
 * This page is the trust/E-E-A-T anchor for the site: it names who is responsible for the
 * comparisons (Seppo Puusa's self-funded hobby project, operated by his company Breezily OÜ),
 * how the service is funded (it isn't — no commissions, no ads), and exactly how annual costs
 * are calculated. The methodology section
 * (#menetelma) is linked from the comparison views.
 *
 * Copy constraint: never name the upstream contract-data intermediary or any regulator; contract
 * prices are attributed only as "sähköyhtiöiden julkisesti saatavilla olevista hinnastoista".
 */
class AboutPage extends Component
{
    public const CONTACT_EMAIL = 'voltikka7@gmail.com';

    // Named maintainer and operating company (user decision 2026-10-31; earlier copy kept the
    // maintainer anonymous). /tietoa, /tietosuoja, /kayttoehdot, the footer and the JSON-LD
    // read these values.
    public const MAINTAINER_NAME = 'Seppo Puusa';

    public const MAINTAINER_LINKEDIN = 'https://www.linkedin.com/in/seppopuusa/';

    public const COMPANY_NAME = 'Breezily OÜ';

    public const COMPANY_REGISTRATION_CODE = '16564064';

    public const COMPANY_VAT_ID = 'EE102541218';

    public const COMPANY_STREET = 'Sepapaja tn 6';

    public const COMPANY_POSTAL_CODE = '15551';

    public const COMPANY_CITY = 'Tallinn';

    public function render()
    {
        return view('livewire.about-page', [
            'contractCount' => self::cachedContractCount(),
            'companyCount' => self::cachedCompanyCount(),
            'spotAvg' => $this->spotAverageWithTax(),
            'jsonLdSchema' => $this->getJsonLdSchemaProperty(),
        ])->layout('layouts.app', [
            // Painted hero: the top bar sits over it in the same tone (outside any cache).
            'heroTone' => Season::heroTone('tietoa-hero'),
            'title' => 'Tietoa Voltikasta – riippumaton sähkövertailu ja menetelmä | Voltikka',
            'metaDescription' => 'Kuka Voltikkaa ylläpitää, miten palvelu rahoitetaan ja miten sähkösopimusten vuosikustannukset lasketaan. Seppo Puusan riippumaton harrasteprojekti ilman provisioita ja mainosrahaa.',
            'canonical' => config('app.url').'/tietoa',
        ]);
    }

    /**
     * Trailing 12-month realized spot average (c/kWh, incl. VAT) used for the "· arvio"
     * spot annual-cost estimates, so the methodology can show the real assumed figure.
     */
    private function spotAverageWithTax(): ?float
    {
        $avg = SpotPriceAverage::latestRolling365Days();

        return $avg?->avg_price_with_tax !== null
            ? round((float) $avg->avg_price_with_tax, 2)
            : null;
    }

    /**
     * Cached live counts shared with the layout footer tagline so neither adds an
     * uncached COUNT to page renders. Short TTL keeps the figures fresh after imports.
     */
    public static function cachedContractCount(): int
    {
        return (int) Cache::remember(
            'about:contract-count',
            now()->addHours(6),
            fn () => ElectricityContract::active()->count(),
        );
    }

    public static function cachedCompanyCount(): int
    {
        return (int) Cache::remember(
            'about:company-count',
            now()->addHours(6),
            fn () => Company::count(),
        );
    }

    /**
     * Organization schema so search engines can attribute the comparisons to a named publisher.
     */
    public function getJsonLdSchemaProperty(): array
    {
        return [
            '@context' => 'https://schema.org',
            '@type' => 'Organization',
            'name' => 'Voltikka',
            'url' => config('app.url'),
            // Email is intentionally omitted from structured data; the on-page address is
            // click-to-reveal via JS so it is not harvested from the HTML source by scrapers.
            'description' => 'Voltikka on Seppo Puusan riippumaton sähkösopimusten ja energian vertailupalvelu. Harrasteprojekti, joka ei ota provisiota sähköyhtiöiltä eikä rahoita toimintaansa mainoksilla.',
            'foundingDate' => '2026',
            'founder' => self::maintainerSchema(),
            'parentOrganization' => self::companySchema(),
        ];
    }

    /**
     * The maintainer as a schema.org Person; also the author of the editorial articles.
     *
     * @return array<string,mixed>
     */
    public static function maintainerSchema(): array
    {
        return [
            '@type' => 'Person',
            'name' => self::MAINTAINER_NAME,
            'url' => config('app.url').'/tietoa#yllapito',
            'sameAs' => [self::MAINTAINER_LINKEDIN],
        ];
    }

    /**
     * The operating company as a schema.org Organization.
     *
     * @return array<string,mixed>
     */
    public static function companySchema(): array
    {
        return [
            '@type' => 'Organization',
            'name' => self::COMPANY_NAME,
            'identifier' => self::COMPANY_REGISTRATION_CODE,
            'vatID' => self::COMPANY_VAT_ID,
            'address' => [
                '@type' => 'PostalAddress',
                'streetAddress' => self::COMPANY_STREET,
                'postalCode' => self::COMPANY_POSTAL_CODE,
                'addressLocality' => self::COMPANY_CITY,
                'addressCountry' => 'EE',
            ],
        ];
    }
}
