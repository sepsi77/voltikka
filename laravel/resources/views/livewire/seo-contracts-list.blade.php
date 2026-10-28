<div>
    {{-- JSON-LD Structured Data --}}
    @if(!empty($seoData['jsonLd']))
    <script type="application/ld+json">
        {!! json_encode($seoData['jsonLd'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) !!}
    </script>
    @endif

    @php
        // Page-type hero (SeoContractsList::heroArt(), outside the prepared cache). City
        // pages and /porssisahko have the fox painted into the plate (Paper boards 5BI-0,
        // 3C8-0), so it fills the right side and the price index moves inline under the
        // lead (spec §5 "Page patterns"). Every other listing keeps the village hero.
        $heroArt = $heroArt ?? 'listing-hero';
        $heroTone = \App\Support\Season::heroTone($heroArt);
        $seoHero = match (true) {
            $heroArt === 'hero-porssisahko' => 'river',
            str_starts_with($heroArt, 'hero-city') => 'city',
            default => null,
        };
        $interludeArt = match ($seoHero) {
            'river' => 'interlude-icefishing',
            'city' => 'interlude-seaswim',
            default => 'interlude-walk',
        };
    @endphp
    @if ($seoHero)
        <x-site.hero :art="$heroArt" :tone="$heroTone" class="vk-seo-hero vk-seo-hero--{{ $seoHero }} vk-seo-hero--{{ $heroArt }} vk-seo-title-fit" data-comparison-hero>
            @include('partials.listing-hero-crumbs')
            <h1 class="vk-hero-h1">{{ $pageHeading }}</h1>
            <p class="vk-hero-lead">{{ $seoIntroText }}</p>
            <x-site.price-index :insight="$marketInsight ?? null" class="vk-seo-hero-index" />
        </x-site.hero>
    @else
        {{-- Picture-book listing hero (Paper board "Sähkösopimukset — /sahkosopimus v4", "Village hero").
             The aside is the energy price index on the sky. --}}
        <x-site.hero art="listing-hero" fox="fox-walk-lantern" :tone="$heroTone" class="vk-listing-hero vk-seo-title-fit" data-comparison-hero>
            @include('partials.listing-hero-crumbs')
            @if ($isBusinessPage)
                <p class="vk-index-kicker mb-2">Yrityksille</p>
            @endif
            <h1 class="vk-hero-h1">{{ $pageHeading }}</h1>
            <p class="vk-hero-lead">{{ $seoIntroText }}</p>

            <x-slot:aside>
                <x-site.price-index :insight="$marketInsight ?? null" />
            </x-slot:aside>
        </x-site.hero>
    @endif

    <x-page-action-strip />

    <div data-contract-comparison-content class="vk-wrap vk-listing">

    {{-- Energy source statistics: key numbers on the page, no boxes. --}}
    @if($isEnergySourcePage && !empty($energySourceStats))
    <section class="vk-block pt-2" aria-labelledby="energy-source-stats-title">
        <x-site.section-heading id="energy-source-stats-title" size="sub">Energialähteiden tilastot</x-site.section-heading>
        <dl class="vk-figures">
            <div class="flex flex-col-reverse">
                <dt class="vk-figure-label">Uusiutuva keskiarvo</dt>
                <dd class="vk-figure-number" style="color: var(--color-aurora)">{{ $energySourceStats['avg_renewable'] ?? 0 }}&nbsp;%</dd>
            </div>
            @if(($energySourceStats['avg_wind'] ?? 0) > 0)
            <div class="flex flex-col-reverse">
                <dt class="vk-figure-label">Tuulivoima keskiarvo</dt>
                <dd class="vk-figure-number">{{ $energySourceStats['avg_wind'] }}&nbsp;%</dd>
            </div>
            @endif
            @if(($energySourceStats['avg_solar'] ?? 0) > 0)
            <div class="flex flex-col-reverse">
                <dt class="vk-figure-label">Aurinkovoima keskiarvo</dt>
                <dd class="vk-figure-number">{{ $energySourceStats['avg_solar'] }}&nbsp;%</dd>
            </div>
            @endif
            @if(($energySourceStats['avg_hydro'] ?? 0) > 0)
            <div class="flex flex-col-reverse">
                <dt class="vk-figure-label">Vesivoima keskiarvo</dt>
                <dd class="vk-figure-number">{{ $energySourceStats['avg_hydro'] }}&nbsp;%</dd>
            </div>
            @endif
            <div class="flex flex-col-reverse">
                <dt class="vk-figure-label">Sopimusta yhteensä</dt>
                <dd class="vk-figure-number">{{ $energySourceStats['total_contracts'] ?? 0 }}</dd>
            </div>
            <div class="flex flex-col-reverse">
                <dt class="vk-figure-label">Fossiilivapaa</dt>
                <dd class="vk-figure-number">{{ $energySourceStats['fossil_free_count'] ?? 0 }}</dd>
            </div>
        </dl>
    </section>
    @endif

    {{-- Environmental impact: a short text block with an inked mark, no tinted panel. --}}
    @if($isEnergySourcePage && $environmentalInfo)
    <section class="flex max-w-[760px] items-start gap-3">
        <x-ink.sparkle size="18" color="var(--color-aurora)" class="mt-1" />
        <div>
            <h3 class="text-base font-extrabold leading-6 text-ink">Ympäristövaikutus</h3>
            <p class="vk-body mt-1">{{ $environmentalInfo }}</p>
        </div>
    </section>
    @endif

    {{-- Controls block (Paper "R4 · calm top"): the consumption row, then one disclosure row
         (price type, availability, filters). Disclosure panels open below the row, without
         frames. The bill comparison is not a filter; it sits beside the results count. --}}
    <div class="vk-controls" data-listing-controls>
        @include('partials.contract-consumption-selector', [
            'isBusinessPage' => $isBusinessPage,
            'showCalculatorTab' => $showCalculatorTab ?? true,
            'showCurrentConsumption' => false,
        ])

        <div class="vk-disclosures">
            <span class="vk-disclosures-spacer hidden lg:block" aria-hidden="true"></span>

            {{-- Pricing-type choices: the first disclosure, its trigger states the selection. --}}
            @include('partials.pricing-bucket-pills')

            @include('partials.contract-postcode-selector')

            {{-- Filters disclosure (shared partial) --}}
            @include('partials.contract-filters')
        </div>
    </div>

    {{-- Local Contracts Section (for city pages, page 1 only) --}}
    @if($isCityPage && $citySlug && $contracts->currentPage() === 1 && $localContractsData['has_content'])
        <livewire:local-contracts-section
            :city-name="$cityInfo['name']"
            :city-locative="$cityInfo['locative']"
            :consumption="$consumption"
            :local-company-contracts="$localContractsData['local_companies']"
            :regional-contracts="$localContractsData['regional_contracts']"
            wire:key="local-contracts-{{ $citySlug }}-{{ $consumption }}-{{ $postcodeFilter ?: 'national' }}"
        />
    @endif

    @if ($offerType === 'promotion')
        {{-- Independence note: the offers page is where affiliate suspicion is highest --}}
        <div class="flex max-w-[860px] items-start gap-2.5 text-[15px] leading-[22px] text-ink-body">
            <x-ink.sparkle size="14" color="var(--color-fox)" class="mt-1" />
            <p>
                Voltikka ei saa palkkiota sähköyhtiöiltä tarjousten näyttämisestä eikä rahoita toimintaansa mainoksilla. Tarjoukset järjestetään arvioidun 12 kk kokonaiskustannuksen mukaan, kaikille yhtiöille samalla logiikalla.
                <a href="/tietoa" class="vk-text-link whitespace-nowrap">Tietoa Voltikasta</a>
            </p>
        </div>
    @endif

    @if ($rankedResultsHeading)
        <x-site.section-heading size="compact">{{ $rankedResultsHeading }}</x-site.section-heading>
    @endif

    {{-- Results area (Paper "R4 · Bill comparison beside results"): the results heading with the
         bill comparison action at its right. The action opens the torn-paper bill below the
         heading. In bill mode the "Sinun sopimuksesi" anchor replaces the heading, and the bill
         stays open above it so the visitor can edit or clear it. --}}
    <div class="vk-results-area" x-data="{ billOpen: @js($showBillComparison && $this->isBillModeActive()) }">
        @if ($this->isBillModeActive() && $this->billSummary)
            {{-- Current-contract anchor while the visitor decides: big numbers on the page and
                 inked marks, no dark card. The saving is the one coloured number. --}}
            @php $bs = $this->billSummary; @endphp
            <div class="vk-bill-mode-head">
                @include('partials.listing-bill-trigger')
            </div>
            @include('partials.listing-bill-panel')
            <section class="vk-bill-anchor" aria-label="Sinun sopimuksesi">
                <p class="vk-kicker"><x-ink.sparkle size="13" color="var(--color-fox)" />Sinun sopimuksesi</p>
                <div class="vk-bill-figures">
                    <div>
                        <p class="vk-bill-label">Maksoit laskutusjaksollasi noin</p>
                        <p class="vk-bill-number">{{ number_format($bs['user_monthly_cost'], 1, ',', ' ') }}<span class="vk-big-unit">€/kk</span></p>
                        <p class="vk-bill-label mt-1">Sijalla <strong class="font-extrabold text-ink">{{ $bs['user_rank'] }} / {{ $bs['total_ranked'] }}</strong> vertailluista sopimuksista</p>
                    </div>
                    @if ($bs['cheapest_monthly_saving'] > 0.5)
                        <div class="sm:text-right">
                            <p class="vk-bill-label">Halvimmalla sopimuksella säästäisit</p>
                            <p class="vk-bill-number vk-bill-number--saving">{{ number_format($bs['cheapest_monthly_saving'], 0, ',', ' ') }}<span class="vk-big-unit">€/kk</span></p>
                        </div>
                    @else
                        <div class="sm:text-right">
                            <p class="font-display text-[22px] font-extrabold leading-[26px] text-ink">Sopimuksesi on jo kilpailukykyinen.</p>
                            <p class="vk-bill-label mt-1">Halvempaa ei juuri löydy tällä jaksolla.</p>
                        </div>
                    @endif
                </div>
                <p class="vk-fine-print">
                    Vertailu perustuu laskutusjaksosi ({{ $billStartDate }} – {{ $billEndDate }}) toteutuneisiin hintoihin samalla kulutuksella. Pörssisopimuksilla käytetään jakson todellisia tuntihintoja. {{ $targetGroup === 'Company' ? 'Yrityssopimusten hinnat ilman ALV:tä. Molemmille asiakasryhmille tarkoitetut hinnat sisältävät ALV:n.' : 'Hinnat sisältävät ALV:n.' }} Siirtomaksu ei sisälly.
                </p>
            </section>
        @else
            {{-- Results heading (Paper "R4 · calm top"): the count in Bricolage, the ranking
                 promise, then the method note. No category legend: every entry names its category. --}}
            <div class="vk-results-head">
                {{-- A pricing-type page shows its category glyph large beside the count
                     (Paper 3PD-0 "Heading + big glyph"; SeoContractsList::resultsGlyph()). --}}
                <div @class(['min-w-0', 'vk-seo-results-lead' => $resultsGlyph ?? null])>
                    @if ($resultsGlyph ?? null)
                        <x-ink.glyph :bucket="$resultsGlyph" size="76" class="vk-seo-results-glyph" />
                    @endif
                    <div class="min-w-0">
                        <h2 class="vk-results-count">{{ $contracts->total() }} sopimusta.</h2>
                        <p class="vk-results-promise">Järjestys perustuu 12 kk hintaan. Yhtiöt eivät voi maksaa paremmasta sijoituksesta.</p>
                        <p class="vk-results-note">
                            12 kk arvio sisältää tarjoukset. {{ $targetGroup === 'Company' ? 'Yrityssopimusten hinnat ilman ALV:tä. Molemmille asiakasryhmille tarkoitetut hinnat sisältävät ALV:n.' : 'Hinnat sisältävät ALV:n.' }} Siirtomaksu ei sisälly.
                            <a href="/tietoa#menetelma" class="vk-text-link whitespace-nowrap">Näin laskemme &rarr;</a>
                        </p>
                    </div>
                </div>
                @if ($showBillComparison)
                    @include('partials.listing-bill-trigger')
                @endif
            </div>
            @if ($showBillComparison)
                @include('partials.listing-bill-panel')
            @endif
        @endif
    </div>

    {{-- Non-blocking recompute feedback (filters, consumption, bill entry). --}}
    <div wire:loading.delay class="vk-toast">
        <x-spinner size="h-4 w-4" color="text-fox" label="Päivitetään vertailua" />
        <span>Päivitetään…</span>
    </div>

    {{-- Contracts list (ledger list contract: entries end with a vk-ledger-sep hairline). --}}
    {{-- data-feedback-facts: the listing state the feedback entry sends with its vote. --}}
    <div class="vk-ledger transition-opacity duration-200 motion-reduce:transition-none" wire:loading.delay.class="opacity-50"
        data-feedback-facts="{{ json_encode([
            'consumption_kwh' => $this->isBillModeActive() ? ($this->billSummary['annual_kwh'] ?? null) : $consumption,
            'pricing_filter' => $pricingBucketFilter !== '' ? $pricingBucketFilter : null,
            'result_count' => $contracts->total(),
            'postcode_selected' => $postcodeFilter !== '',
            'bill_mode' => $this->isBillModeActive(),
            'listing_page' => $contracts->currentPage(),
        ]) }}">
        @forelse ($contracts as $index => $contract)
            @php
                // Calculate the overall rank based on current page
                $overallRank = (($contracts->currentPage() - 1) * $contracts->perPage()) + $index + 1;
            @endphp

            @if ($this->isBillModeActive())
                <x-contract-card
                    :contract="$contract"
                    :rank="$overallRank"
                    :featured="false"
                    :consumption="$consumption"
                    :prices="$this->getLatestPrices($contract)"
                    :percentiles="$this->getPercentiles()"
                    :billMode="true"
                    :periodComparison="$contract->period_comparison ?? null"
                    :detailConsumption="$this->billSummary['annual_kwh'] ?? null"
                    :showRank="true"
                    :showEmissions="true"
                    :showEnergyBadges="true"
                    :showSpotBadge="true"
                />
            @elseif ($overallRank === 1)
                <x-featured-contract-card
                    :contract="$contract"
                    :consumption="$consumption"
                    :prices="$this->getLatestPrices($contract)"
                />
            @else
                <x-contract-card
                    :contract="$contract"
                    :rank="$overallRank"
                    :featured="$overallRank <= 3"
                    :consumption="$consumption"
                    :prices="$this->getLatestPrices($contract)"
                    :percentiles="$this->getPercentiles()"
                    :showRank="true"
                    :showEmissions="true"
                    :showEnergyBadges="true"
                    :showSpotBadge="true"
                />
            @endif
            {{-- "Löysitkö etsimäsi?" takes ledger slot 5 (after entry 4) on every page. --}}
            @if ($loop->iteration === 4)
                <x-page-feedback page-type="listing" :ledger="true" />
            @endif
        @empty
            <p class="py-10 text-center font-display text-[22px] font-extrabold leading-[26px] text-ink-muted">Ei sopimuksia saatavilla.</p>
        @endforelse
        @if ($contracts->count() < 4)
            <x-page-feedback page-type="listing" :ledger="true" />
        @endif
    </div>

    {{-- Pagination Links --}}
    @if ($contracts->lastPage() > 1)
        {{ $contracts->links('livewire.partials.pagination') }}
    @endif

    @isset($fixedTermComparison)
        @include('partials.fixed-term-seo-guide')
    @endisset

    {{-- Solar potential (city pages). A lazy non-contract block after the list, so the
         contracts stay high on the page (spec §1 "Listing pages put contracts high"). --}}
    @if($isCityPage && $municipality)
        <livewire:city-solar-estimate
            :municipality-id="$municipality->id"
            :city-locative="$cityInfo['locative'] ?? $citySlug"
            :city-slug="$citySlug"
            lazy
        />
    @endif

    {{-- Internal Links Section (for SEO) --}}
    @if($hasSeoFilter)
        {{-- Villager interlude: a pause between the list and the link columns. Only after a
             non-contract block (pagination or the guide), never right after an entry. --}}
        @if ($contracts->lastPage() > 1 || isset($fixedTermComparison))
            <x-site.interlude :art="$interludeArt" />
        @endif

    <section class="vk-block" aria-labelledby="see-also-title">
        <x-site.section-heading id="see-also-title" size="compact">Katso myös</x-site.section-heading>

        <x-site.link-columns :columns="[
            [
                ['heading' => 'Hinnoittelumalli', 'links' => [
                    ['/sahkosopimus/porssisahko', 'Pörssisähkösopimukset'],
                    ['/sahkosopimus/kiintea-hinta', 'Kiinteähintaiset sähkösopimukset'],
                    ['/sahkosopimus/kvartaalisahko', 'Kvartaalisähkösopimukset'],
                    ['/sahkosopimus/aikasahko', 'Aikasähkösopimukset'],
                    ['/sahkosopimus/kausisahko', 'Kausisähkösopimukset'],
                    ['/sahkosopimus/joustosahko', 'Joustosähkösopimukset'],
                    ['/sahkosopimus/yleissahko', 'Yleissähkösopimukset'],
                    ['/sahkosopimus/kulutusvaikutus', 'Kulutusvaikutukselliset sähkösopimukset'],
                ]],
            ],
            [
                ['heading' => 'Asumismuoto & sopimustyyppi', 'links' => [
                    ['/sahkosopimus/omakotitalo', 'Omakotitalon sähkösopimukset'],
                    ['/sahkosopimus/kerrostalo', 'Kerrostalon sähkösopimukset'],
                    ['/sahkosopimus/rivitalo', 'Rivitalon sähkösopimukset'],
                    ['/sahkosopimus/maaraaikainen', 'Määräaikaiset sopimukset'],
                    ['/sahkosopimus/maaraaikainen-6-kk', '6 kk määräaikaiset sopimukset'],
                    ['/sahkosopimus/maaraaikainen-12-kk', '12 kk määräaikaiset sopimukset'],
                    ['/sahkosopimus/maaraaikainen-24-kk', '24 kk määräaikaiset sopimukset'],
                    ['/sahkosopimus/toistaiseksi', 'Toistaiseksi voimassa olevat'],
                ]],
            ],
            [
                ['heading' => 'Energialähteittäin', 'links' => [
                    ['/sahkosopimus/tuulisahko', 'Tuulisähkösopimukset'],
                    ['/sahkosopimus/aurinkosahko', 'Aurinkosähkösopimukset'],
                    ['/sahkosopimus/vihrea-sahko', 'Vihreä sähkö'],
                    ['/sahkosopimus/fossiiliton', 'Fossiiliton sähkö'],
                    ['/sahkosopimus/uusiutuva-sahko', 'Uusiutuva sähkö'],
                    ['/sahkosopimus/ydinvoima', 'Ydinvoimasähkö'],
                ]],
            ],
            [
                ['heading' => 'Kulutustason mukaan', 'links' => [
                    ['/sahkosopimus/kulutus/2000-kwh', '2 000 kWh sopimukset'],
                    ['/sahkosopimus/kulutus/5000-kwh', '5 000 kWh sopimukset'],
                    ['/sahkosopimus/kulutus/10000-kwh', '10 000 kWh sopimukset'],
                    ['/sahkosopimus/kulutus/18000-kwh', '18 000 kWh sopimukset'],
                    ['/sahkosopimus/kulutus/20000-kwh', '20 000 kWh sopimukset'],
                ]],
                ['heading' => 'Muut palvelut', 'links' => [
                    ['/sahkosopimus', 'Vertaile sopimuksia'],
                    ['/sahkosopimus/halvin-sahkosopimus', 'Halvimmat sopimukset'],
                    ['/spot-price', 'Pörssisähkön hinta'],
                    [route('locations'), 'Paikkakunnat'],
                ]],
            ],
        ]" />
    </section>
    @endif
    </div>
</div>
