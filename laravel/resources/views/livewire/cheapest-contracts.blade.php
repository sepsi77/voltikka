<div>
    {{-- JSON-LD Structured Data --}}
    @if(!empty($seoData['jsonLd']))
    <script type="application/ld+json">
        {!! json_encode($seoData['jsonLd'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) !!}
    </script>
    @endif

    {{-- Picture-book cheapest hero (Paper board 5VI-0 "Hero · sled hill", seasons FHX-0): the
         listing hero geometry with the sled-hill plate and the fox on a sled. The aside is the
         energy price index on the sky (same facts as the old market-insight pills). --}}
    <x-site.hero
        art="cheapest-hero"
        fox="cheapest-fox-sled"
        fox-class="cheapest-hero-fox"
        :tone="\App\Support\Season::heroTone('cheapest-hero')"
        data-comparison-hero
    >
        @include('partials.listing-hero-crumbs', ['hasSeoFilter' => true])
        <p class="cheapest-hero-kicker">
            <x-ink.sparkle size="13" color="var(--tone-accent)" />
            Edullisimmat sopimukset
        </p>
        <h1 class="vk-hero-h1">{{ $pageHeading }}</h1>
        <p class="vk-hero-lead">{{ $seoIntroText }}</p>

        <x-slot:aside>
            <x-site.price-index :insight="$marketInsight ?? null" />
        </x-slot:aside>
    </x-site.hero>

    <x-page-action-strip />

    <div data-contract-comparison-content class="vk-wrap vk-listing">

    {{-- Controls block (Paper "R4 · calm top"): the consumption row, then the disclosure row
         (price type, availability). This page has no filter accordion; the pricing-type
         choices narrow the cheapest ranking. --}}
    <div class="vk-controls" data-listing-controls>
        @include('partials.contract-consumption-selector', [
            'isBusinessPage' => false,
            'showCalculatorTab' => true,
            'showCurrentConsumption' => false,
        ])

        <div class="vk-disclosures">
            <span class="vk-disclosures-spacer hidden lg:block" aria-hidden="true"></span>

            @include('partials.pricing-bucket-pills')

            @include('partials.contract-postcode-selector')
        </div>
    </div>

    {{-- Non-blocking recompute feedback (consumption, pricing type, postcode). --}}
    <div wire:loading.delay class="vk-toast">
        <x-spinner size="h-4 w-4" color="text-fox" label="Päivitetään vertailua" />
        <span>Päivitetään…</span>
    </div>

    <div class="transition-opacity duration-200 motion-reduce:transition-none" wire:loading.delay.class="opacity-50">
        {{-- Featured Contract (#1 Cheapest): the podium entry (spec §5 "Podium entry"). --}}
        @if ($featuredContract)
            <section class="vk-ledger cheapest-podium" aria-label="Halvin sähkösopimus">
                <x-featured-contract-card
                    :contract="$featuredContract"
                    :consumption="$consumption"
                    :prices="$this->getLatestPrices($featuredContract)"
                />
            </section>
        @endif

        {{-- Remaining Contracts (#2-11) --}}
        <section class="cheapest-rest" aria-labelledby="cheapest-rest-title">
            <x-site.section-heading id="cheapest-rest-title" class="cheapest-rest-heading">Seuraavaksi edullisimmat</x-site.section-heading>
            {{-- Ledger list contract: entries end with a vk-ledger-sep hairline. --}}
            <div class="vk-ledger">
                @forelse ($contracts as $index => $contract)
                    <x-contract-card
                        :contract="$contract"
                        :rank="$index + 2"
                        :consumption="$consumption"
                        :prices="$this->getLatestPrices($contract)"
                        :percentiles="$this->getPercentiles()"
                        :showRank="true"
                        :showEmissions="true"
                        :showEnergyBadges="true"
                        :showSpotBadge="true"
                    />
                @empty
                    <p class="py-10 text-center font-display text-[22px] font-extrabold leading-[26px] text-ink-muted">Ei sopimuksia saatavilla.</p>
                @endforelse
            </div>
        </section>
    </div>

    {{-- Villager interlude (toboggan in talvi): a pause between the list and the link columns. --}}
    <x-site.interlude art="cheapest-interlude" class="cheapest-interlude" />

    {{-- Internal Links Section (for SEO) --}}
    <section class="vk-block" aria-labelledby="see-also-title">
        <x-site.section-heading id="see-also-title" size="compact">Katso myös</x-site.section-heading>

        <x-site.link-columns :columns="[
            [
                ['heading' => 'Hinnoittelumalli', 'links' => [
                    ['/sahkosopimus/porssisahko', 'Pörssisähkösopimukset'],
                    ['/sahkosopimus/yleissahko', 'Yleissähkösopimukset'],
                ]],
            ],
            [
                ['heading' => 'Asumismuodoittain', 'links' => [
                    ['/sahkosopimus/omakotitalo', 'Omakotitalon sähkösopimukset'],
                    ['/sahkosopimus/kerrostalo', 'Kerrostalon sähkösopimukset'],
                    ['/sahkosopimus/rivitalo', 'Rivitalon sähkösopimukset'],
                ]],
            ],
            [
                ['heading' => 'Energialähteittäin', 'links' => [
                    ['/sahkosopimus/tuulisahko', 'Tuulisähkösopimukset'],
                    ['/sahkosopimus/aurinkosahko', 'Aurinkosähkösopimukset'],
                    ['/sahkosopimus/vihrea-sahko', 'Vihreä sähkö'],
                ]],
            ],
            [
                ['heading' => 'Muut palvelut', 'links' => [
                    ['/sahkosopimus', 'Vertaile sopimuksia'],
                    ['/', 'Kaikki sähkösopimukset'],
                    ['/spot-price', 'Pörssisähkön hinta'],
                    [route('locations'), 'Paikkakunnat'],
                ]],
            ],
        ]" />
    </section>
    </div>
</div>
