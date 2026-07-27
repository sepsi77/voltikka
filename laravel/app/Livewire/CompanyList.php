<?php

namespace App\Livewire;

use App\Http\Middleware\SetPublicCacheHeaders;
use App\Services\CompanyListCacheService;
use Illuminate\Support\Collection;
use Livewire\Component;

class CompanyList extends Component
{
    /**
     * Editorial size/type classification for the companies on this page.
     *
     * Company size cannot be derived from our data: `companies` holds no customer
     * count or turnover, `contractCount` measures product breadth rather than size,
     * and `postal_name` is unreliable (Fortum stores "FORTUM", Lankosken Sähkö stores
     * its own company name). So the judgement is static and the rendering is dynamic:
     * only companies still present in the live list are shown, so a company that
     * leaves the market disappears from the section on its own.
     *
     * Groups: `local` = small municipal/regional utility, `challenger` = small
     * independent seller with no grid of its own, `national` = large nationwide brand,
     * `regional` = large city or regional utility. Only `local` and `challenger`
     * render in the "pienet sähköyhtiöt" section. A company missing from this map is
     * simply left out of that section, never guessed into a group.
     *
     * @var array<string, string>
     */
    private const COMPANY_SIZE_GROUPS = [
        'Fortum Markets Oy' => 'national',
        'Helen Oy' => 'national',
        'Vattenfall Oy' => 'national',
        'Oomi Oy' => 'national',
        'Lumme Energia' => 'national',
        'Nordic Green Energy' => 'national',

        'Vaasan Sähkö Myynti Oy' => 'regional',
        'Turku Energia Oy' => 'regional',
        'Pohjois-Karjalan Sähkö Oy' => 'regional',

        'Alajärven Sähkö Oy' => 'local',
        'Herrfors Oy Ab' => 'local',
        'Iin Energia Oy' => 'local',
        'Imatran Seudun Sähkö Oy' => 'local',
        'Keravan Energia Oy' => 'local',
        'Keuruun Sähkö Oy' => 'local',
        'Koillis-Satakunnan Sähkö Oy' => 'local',
        'Kokkolan Energia Oy' => 'local',
        'Korpelan Energia Oy' => 'local',
        'Kuoreveden Sähkö Oy' => 'local',
        'Köyliön-Säkylän Sähkö Oy' => 'local',
        'Lammaisten Energia Oy' => 'local',
        'Lankosken Sähkö Oy' => 'local',
        'Nurmijärven Sähkö Oy' => 'local',
        'Omavoima Oy' => 'local',
        'Paneliankosken Voima Oy' => 'local',
        'Parikkalan Valo Oy' => 'local',
        'Porvoon Energia Oy' => 'local',
        'Seinäjoen Energia Oy' => 'local',
        'Vimpelin Voima Oy' => 'local',
        'Äänekosken Energia Oy' => 'local',

        'Aalto energia Oyj' => 'challenger',
        'Cheap Energy Finland Oy' => 'challenger',
        'Hehku Energia Oy' => 'challenger',
        'Sähkötytöt Oy' => 'challenger',
        'Vihreä Älyenergia Oy' => 'challenger',
    ];

    /**
     * Search filter for company names.
     */
    public string $search = '';

    /**
     * Reference consumption for price calculations (kWh/year).
     */
    public int $consumption = 5000;

    /**
     * Cache for company data with metrics.
     */
    protected ?Collection $cachedCompanies = null;

    /**
     * Get all companies with cached metrics.
     */
    public function getCompaniesProperty(): Collection
    {
        if ($this->cachedCompanies !== null) {
            return $this->cachedCompanies;
        }

        return $this->cachedCompanies = app(CompanyListCacheService::class)
            ->getCachedCompanies($this->consumption);
    }

    /**
     * Get companies filtered by search term.
     */
    public function getFilteredCompaniesProperty(): Collection
    {
        $companies = $this->companies;

        if ($this->search !== '') {
            $search = mb_strtolower($this->search);
            $companies = $companies->filter(function ($data) use ($search) {
                return str_contains(mb_strtolower($data['company']->name), $search);
            });
        }

        return $companies;
    }

    /**
     * Get total count of companies with contracts.
     */
    public function getCompanyCountProperty(): int
    {
        return $this->companies->count();
    }

    /**
     * Get the total number of active contracts across all listed companies.
     *
     * This is the page's real differentiator against competing list pages, which
     * publish longer *name* lists (55-71 entries) that include sellers no longer on
     * the market and quote no price at all. Every contract counted here is on sale
     * now and priced on the same basis.
     */
    public function getContractCountProperty(): int
    {
        return (int) $this->companies->sum('contractCount');
    }

    /**
     * Get the small local utilities and small independent sellers on this page.
     *
     * @return Collection<int, array{company: \App\Models\Company, group: string}>
     */
    public function getSmallCompaniesProperty(): Collection
    {
        return $this->companies
            ->map(function (array $data) {
                $group = self::COMPANY_SIZE_GROUPS[$data['company']->name] ?? null;

                return in_array($group, ['local', 'challenger'], true)
                    ? ['company' => $data['company'], 'group' => $group]
                    : null;
            })
            ->filter()
            ->sortBy(fn (array $row) => $row['company']->name)
            ->values();
    }

    /**
     * Get the small companies of one group.
     *
     * @return Collection<int, array{company: \App\Models\Company, group: string}>
     */
    public function smallCompaniesOfGroup(string $group): Collection
    {
        return $this->smallCompanies->where('group', $group)->values();
    }

    /**
     * Get the cheapest company by its lowest annual cost at the reference consumption.
     *
     * @return array{name: string, slug: string, price: float}|null
     */
    public function getCheapestCompanyProperty(): ?array
    {
        $cheapest = $this->companies
            ->filter(fn (array $data) => $data['lowestPrice'] !== null)
            ->sortBy('lowestPrice')
            ->first();

        if (! $cheapest) {
            return null;
        }

        return [
            'name' => $cheapest['company']->name,
            'slug' => $cheapest['company']->name_slug,
            'price' => (float) $cheapest['lowestPrice'],
        ];
    }

    /**
     * Get the FAQ entries, which feed both the visible list and the FAQPage schema.
     *
     * Scope decision (2026-07): the questions cover market structure, the
     * sähköyhtiö/energiayhtiö/sähkönmyyjä wording, small companies, and price.
     * They deliberately do **not** answer "mikä on paras/luotettavin sähköyhtiö",
     * because Voltikka holds no customer-satisfaction or review data and will not
     * rank companies on a quality claim it cannot substantiate.
     *
     * @return array<int, array{question: string, answer: string}>
     */
    public function getFaqItemsProperty(): array
    {
        $items = [
            [
                'question' => 'Kuinka monta sähköyhtiötä Suomessa on?',
                'answer' => "Voltikan vertailussa on {$this->companyCount} sähköyhtiötä ja {$this->contractCount} sopimusta, "
                    .'jotka ovat tällä hetkellä myynnissä kuluttajille tai yrityksille. Luku on pienempi kuin monissa '
                    .'sähköyhtiölistoissa, koska mukana ovat vain yhtiöt, jotka myyvät sähkösopimusta juuri nyt. '
                    .'Listaamme kaikki markkinoilla olevat sopimukset emmekä rajaa vertailua yhteistyökumppaneihin.',
            ],
            [
                'question' => 'Mitä eroa on sähköyhtiöllä ja energiayhtiöllä?',
                'answer' => 'Sanoja käytetään arkikielessä samassa merkityksessä. Energiayhtiö on laajempi sana: se voi myydä '
                    .'sähkön lisäksi esimerkiksi kaukolämpöä tai maakaasua. Sähköyhtiö eli sähkönmyyjä myy sinulle sähköenergian. '
                    .'Tässä vertailussa ovat mukana kaikki sähköä myyvät yhtiöt riippumatta siitä, kumpaa nimeä ne itse käyttävät.',
            ],
            [
                'question' => 'Mitä eroa on sähkön myynnillä ja sähkön siirrolla?',
                'answer' => 'Sähkönmyyjän voit valita vapaasti ja vaihtaa milloin haluat. Sähkön siirrosta vastaa aina asuinpaikkasi '
                    .'paikallinen verkkoyhtiö, esimerkiksi Caruna tai Elenia, eikä siirtoa voi kilpailuttaa. Saat siksi usein kaksi '
                    .'laskua. Tämän sivun hinnat koskevat vain sähköenergiaa, eivät siirtoa.',
            ],
            [
                'question' => 'Voiko sähköyhtiön valita vapaasti asuinpaikasta riippumatta?',
                'answer' => 'Kyllä. Valtakunnalliset sähköyhtiöt myyvät sähköä koko Suomeen, joten voit ostaa sähkösi myös oman '
                    .'paikkakuntasi ulkopuolelta. Osa pienistä paikallisista energiayhtiöistä myy sopimuksia vain omalle alueelleen, '
                    .'ja se näkyy sopimuksen tiedoissa.',
            ],
            [
                'question' => 'Kannattaako valita pieni sähköyhtiö?',
                'answer' => 'Pieni paikallinen energiayhtiö on usein hinnaltaan kilpailukykyinen, koska se ei osta asiakkaita kalliilla '
                    .'markkinoinnilla. Sähkön laatu on täsmälleen sama riippumatta myyjästä, koska sähkö tulee samasta verkosta. '
                    .'Vertaa siis hintaa, sopimustyyppiä ja perusmaksua, älä yhtiön kokoa.',
            ],
        ];

        if ($cheapest = $this->cheapestCompany) {
            $price = number_format(round($cheapest['price'] / 10) * 10, 0, ',', ' ');

            $items[] = [
                'question' => 'Mikä sähköyhtiö on halvin?',
                'answer' => "Tällä hetkellä halvimman sopimuksen tarjoaa {$cheapest['name']}: noin {$price} € vuodessa, "
                    .'kun vuosikulutus on 5 000 kWh. Halvin yhtiö vaihtuu kulutuksen mukaan, koska perusmaksun osuus on '
                    .'pienessä kulutuksessa suuri. Hinta sisältää alv 25,5 % eikä siihen kuulu sähkön siirtoa.',
            ];
        }

        return $items;
    }

    /**
     * Get top 5 cheapest companies by lowest price.
     */
    public function getCheapestCompaniesProperty(): Collection
    {
        return $this->companies
            ->filter(fn (array $data) => $data['lowestPrice'] !== null)
            ->sortBy('lowestPrice')
            ->take(5)
            ->values();
    }

    /**
     * Get top 5 greenest companies by highest average renewable percentage.
     */
    public function getGreenestCompaniesProperty(): Collection
    {
        return $this->companies
            ->sortByDesc('avgRenewable')
            ->take(5)
            ->values();
    }

    /**
     * Get top 5 companies with cleanest emissions (lowest average emission factor).
     */
    public function getCleanestEmissionsCompaniesProperty(): Collection
    {
        return $this->companies
            ->sortBy('avgEmissions')
            ->take(5)
            ->values();
    }

    /**
     * Get top 5 companies with most contracts.
     */
    public function getMostContractsCompaniesProperty(): Collection
    {
        return $this->companies
            ->sortByDesc('contractCount')
            ->take(5)
            ->values();
    }

    /**
     * Get top 5 companies with best (lowest) spot margins.
     */
    public function getBestSpotMarginsCompaniesProperty(): Collection
    {
        return $this->companies
            ->filter(fn ($data) => $data['hasSpotContracts'] && $data['lowestSpotMargin'] !== null)
            ->sortBy('lowestSpotMargin')
            ->take(5)
            ->values();
    }

    /**
     * Get top 5 companies with lowest monthly fees.
     */
    public function getLowestMonthlyFeesCompaniesProperty(): Collection
    {
        return $this->companies
            ->filter(fn ($data) => $data['lowestMonthlyFee'] !== null)
            ->sortBy('lowestMonthlyFee')
            ->take(5)
            ->values();
    }

    /**
     * Get companies that offer 100% renewable contracts.
     */
    public function getFullyRenewableCompaniesProperty(): Collection
    {
        return $this->companies
            ->filter(fn ($data) => $data['hasFullyRenewable'])
            ->sortByDesc('maxRenewable')
            ->values();
    }

    /**
     * Generate JSON-LD schema for SEO.
     */
    public function getJsonLdProperty(): array
    {
        $canonical = $this->canonicalUrl;

        $listItems = $this->companies->map(function ($data, $index) {
            return [
                '@type' => 'ListItem',
                'position' => $index + 1,
                'item' => [
                    '@type' => 'Organization',
                    'name' => $data['company']->name,
                    'url' => config('app.url').'/sahkosopimus/sahkoyhtiot/'.$data['company']->name_slug,
                ],
            ];
        })->values()->toArray();

        $faqEntities = array_map(fn (array $faq): array => [
            '@type' => 'Question',
            'name' => $faq['question'],
            'acceptedAnswer' => [
                '@type' => 'Answer',
                'text' => $faq['answer'],
            ],
        ], $this->faqItems);

        return [
            '@context' => 'https://schema.org',
            '@graph' => [
                [
                    '@type' => 'ItemList',
                    '@id' => $canonical.'#companies',
                    'name' => 'Sähköyhtiöiden vertailu Suomessa',
                    'description' => 'Vertaile ja kilpailuta suomalaisia sähköyhtiöitä – hinnat, sopimukset ja päästötiedot',
                    'numberOfItems' => $this->companyCount,
                    'itemListElement' => $listItems,
                ],
                [
                    '@type' => 'FAQPage',
                    '@id' => $canonical.'#faq',
                    'mainEntity' => $faqEntities,
                ],
            ],
        ];
    }

    /**
     * Get the visible H1, which is deliberately separate from the SEO title.
     */
    public function getPageHeadingProperty(): string
    {
        return 'Kaikki sähköyhtiöt Suomessa';
    }

    /**
     * Get page title.
     *
     * Tuned for the list-intent cluster ("sähköyhtiöt", "sähköyhtiöt suomessa",
     * "suomalaiset sähköyhtiöt", "kaikki sähköyhtiöt"), which is where this page
     * already has impressions. The contract count leads the differentiator because
     * competing pages publish a longer *name* list without a single price: our claim
     * is completeness of the live market, not the longest roster. Keep the year
     * dynamic; every competing title in this SERP carries one.
     *
     * The brand suffix is deliberately absent: Google prints the site name beside the
     * title anyway and truncated the old 77-character title.
     */
    public function getPageTitleProperty(): string
    {
        return 'Sähköyhtiöt Suomessa '.now()->year
            ." – {$this->companyCount} yhtiötä ja kaikki {$this->contractCount} sopimusta";
    }

    /**
     * Get meta description.
     */
    public function getMetaDescriptionProperty(): string
    {
        return "Vertaa kaikkien {$this->companyCount} sähköyhtiön hinnat samalla 5 000 kWh kulutuksella: "
            ."{$this->contractCount} sopimusta, myös pienet paikalliset energiayhtiöt. "
            .'Emme rajaa vertailua kumppaneihin.';
    }

    /**
     * Get canonical URL.
     */
    public function getCanonicalUrlProperty(): string
    {
        return config('app.url').'/sahkosopimus/sahkoyhtiot';
    }

    public function render()
    {
        $this->enableBackButtonCache();

        return view('livewire.company-list', [
            'companies' => $this->companies,
            'filteredCompanies' => $this->filteredCompanies,
            'companyCount' => $this->companyCount,
            'cheapestCompanies' => $this->cheapestCompanies,
            'greenestCompanies' => $this->greenestCompanies,
            'cleanestEmissionsCompanies' => $this->cleanestEmissionsCompanies,
            'mostContractsCompanies' => $this->mostContractsCompanies,
            'bestSpotMarginsCompanies' => $this->bestSpotMarginsCompanies,
            'lowestMonthlyFeesCompanies' => $this->lowestMonthlyFeesCompanies,
            'fullyRenewableCompanies' => $this->fullyRenewableCompanies,
            'jsonLd' => $this->jsonLd,
            'pageTitle' => $this->pageTitle,
            'pageHeading' => $this->pageHeading,
            'contractCount' => $this->contractCount,
            'metaDescription' => $this->metaDescription,
        ])->layout('layouts.app', [
            'title' => $this->pageTitle,
            'metaDescription' => $this->metaDescription,
            'canonical' => $this->canonicalUrl,
        ])->response(function ($response) {
            app(SetPublicCacheHeaders::class)->applyCacheHeaders($response);
        });
    }
}
