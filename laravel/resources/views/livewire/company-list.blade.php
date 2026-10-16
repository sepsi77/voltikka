<div>
    <!-- JSON-LD Structured Data -->
    <script type="application/ld+json">
        {!! json_encode($jsonLd, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) !!}
    </script>

    {{-- Picture-book directory hero (Paper board 6R6-0 "Hero · village street", seasons EBQ-0).
         The fox walks in the painting at the bottom left, below the text. --}}
    <x-site.company-hero art="companies-hero" variant="list" :tone="\App\Support\Season::heroTone('companies-hero')">
        <a href="{{ route('sahkosopimus.index') }}" class="vk-co-hero-back vk-focus">
            <span aria-hidden="true">←</span> Takaisin sopimuksiin
        </a>
        <h1 class="vk-co-hero-h1">{{ $pageHeading }}</h1>
        <p class="vk-co-hero-sub">Vertaile {{ $companyCount }} sähköyhtiön {{ $contractCount }} sopimusta, hintaa ja energialähdettä.</p>
        <p class="vk-co-hero-lead">
            Mukana ovat kaikki sähköä myyvät energiayhtiöt suurista valtakunnallisista sähkönmyyjistä
            pieniin paikallisiin yhtiöihin – emme rajaa vertailua yhteistyökumppaneihimme.
        </p>
    </x-site.company-hero>

    <x-page-action-strip />

    @php
        $companyUrl = fn ($company) => '/sahkosopimus/sahkoyhtiot/'.$company->name_slug;

        // Leaderboards (Paper "Leaderboards row 1/2"): title, inked icon, rows, value text and
        // the accent of the first row (its rank and value). A board without rows is not shown.
        $leaderboards = [
            [
                'title' => 'Halvimmat',
                'icon' => 'coins',
                'accent' => 'var(--color-fox-deep)',
                'rows' => $cheapestCompanies,
                'value' => fn ($data) => number_format($data['lowestPrice'], 0, ',', ' ').' EUR/v',
            ],
            [
                'title' => 'Vihreimmät',
                'icon' => 'leaf',
                'accent' => 'var(--color-aurora)',
                'rows' => $greenestCompanies,
                'value' => fn ($data) => number_format($data['avgRenewable'], 0).'% uusiutuva',
            ],
            [
                'title' => 'Puhtaimmat päästöt',
                'icon' => 'cloud',
                'accent' => 'var(--color-lake)',
                'rows' => $cleanestEmissionsCompanies,
                'value' => fn ($data) => number_format($data['avgEmissions'], 0).' gCO2/kWh',
            ],
            [
                'title' => 'Eniten sopimuksia',
                'icon' => 'papers',
                'accent' => 'var(--color-ink)',
                'rows' => $mostContractsCompanies,
                'value' => fn ($data) => $data['contractCount'].' sopimusta',
            ],
            [
                'title' => 'Parhaat pörssimarginaalit',
                'icon' => 'spot',
                'accent' => 'var(--color-aurora)',
                'rows' => $bestSpotMarginsCompanies,
                'value' => fn ($data) => number_format($data['lowestSpotMargin'], 2, ',', ' ').' c/kWh',
            ],
            [
                'title' => 'Pienimmät perusmaksut',
                'icon' => 'fee',
                'accent' => 'var(--color-fox-deep)',
                'rows' => $lowestMonthlyFeesCompanies,
                'value' => fn ($data) => number_format($data['lowestMonthlyFee'], 2, ',', ' ').' EUR/kk',
            ],
        ];
    @endphp

    <div class="vk-wrap vk-co-page">

        {{-- Ranking categories (Paper "Parhaat heading" + leaderboards): ruled lists, no cards. --}}
        <section class="vk-co-section vk-co-section--first" aria-labelledby="parhaat-title">
            <div class="vk-co-head vk-co-head--split">
                <div class="vk-co-title-wrap">
                    <h2 id="parhaat-title" class="vk-co-title vk-co-title--xl">Parhaat sähköyhtiöt</h2>
                    <x-ink.wave width="300" stroke="3.5" />
                </div>
                <div class="vk-co-head-note">
                    <p>Sijoitukset perustuvat Voltikan riippumattomaan vertailuun: hinta-arviot lasketaan 5&nbsp;000 kWh vuosikulutuksella samalla logiikalla kaikille yhtiöille, hinnat sis. alv 25,5 % (siirtomaksu ei sisälly).</p>
                    <a href="/tietoa#menetelma" class="vk-co-ink-link vk-focus">Näin laskemme &rarr;</a>
                </div>
            </div>

            <div class="vk-co-boards">
                @foreach ($leaderboards as $board)
                    @continue($board['rows']->isEmpty())
                    <div class="vk-co-board">
                        <h3 class="vk-co-board-head">
                            <x-site.company-icon :icon="$board['icon']" />
                            <span>{{ $board['title'] }}</span>
                        </h3>
                        <ol class="vk-co-board-list">
                            @foreach ($board['rows']->take(5) as $index => $data)
                                <li @class(['vk-co-board-row', 'vk-co-board-row--first' => $index === 0]) style="--co-accent: {{ $board['accent'] }}">
                                    <span class="vk-co-board-rank">{{ $index + 1 }}.</span>
                                    <a href="{{ $companyUrl($data['company']) }}" class="vk-co-board-name vk-focus">{{ $data['company']->name }}</a>
                                    <span class="vk-co-board-value">
                                        @if ($index === 0)
                                            <x-ink.sparkle size="12" color="currentColor" />
                                        @endif
                                        {{ $board['value']($data) }}
                                    </span>
                                </li>
                            @endforeach
                        </ol>
                    </div>
                @endforeach
            </div>
        </section>

        <!-- 100% Renewable Section -->
        @if ($fullyRenewableCompanies->isNotEmpty())
            <section class="vk-co-section" aria-labelledby="uusiutuva-title">
                <div class="vk-co-head vk-co-head--split">
                    <div class="vk-co-title-wrap">
                        <h2 id="uusiutuva-title" class="vk-co-title">100% uusiutuvaa energiaa tarjoavat yhtiöt</h2>
                        <x-ink.wave width="420" stroke="3.5" color="var(--color-aurora)" class="max-w-full" />
                    </div>
                    <p class="vk-co-head-note vk-co-head-note--wide">Nämä yhtiöt tarjoavat vähintään yhden täysin uusiutuvalla energialla tuotetun sähkösopimuksen.</p>
                </div>

                <ul class="vk-co-logos">
                    @foreach ($fullyRenewableCompanies->take(10) as $data)
                        <li>
                            <a href="{{ $companyUrl($data['company']) }}" class="vk-co-logo-cell vk-focus">
                                <x-company-logo
                                    :company="$data['company']"
                                    class="vk-co-logo vk-co-logo--cell"
                                    data-logo="{{ $data['company']->name_slug }}"
                                    img-class="vk-co-logo-img"
                                />
                                <span class="vk-co-logo-name">{{ $data['company']->name }}</span>
                                <span class="vk-co-logo-tag"><x-ink.sparkle size="11" color="currentColor" />100% uusiutuva</span>
                            </a>
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif

        <!-- Small and Local Companies Section -->
        @if ($this->smallCompanies->isNotEmpty())
            <section class="vk-co-section" aria-labelledby="pienet-title">
                <div class="vk-co-small-head">
                    <div class="vk-co-title-wrap lg:w-[420px] lg:shrink-0">
                        <h2 id="pienet-title" class="vk-co-title">Pienet ja paikalliset sähköyhtiöt</h2>
                        <x-ink.wave width="300" stroke="3.5" />
                    </div>
                    <div class="vk-co-small-intro">
                        <p class="vk-co-pull">Pieni sähköyhtiö myy täsmälleen samaa sähköä kuin suuri, koska sähkö tulee samasta verkosta.</p>
                        <p class="vk-co-small-body">
                            Ero on hinnassa ja palvelussa, ei sähkön laadussa. Moni pieni paikallinen energiayhtiö pärjää
                            hintavertailussa hyvin, koska se ei hanki asiakkaita kalliilla markkinoinnilla.
                        </p>
                    </div>
                </div>

                <div class="vk-co-small-groups">
                    @php($localCompanies = $this->smallCompaniesOfGroup('local'))
                    @if ($localCompanies->isNotEmpty())
                        <div class="vk-co-small-group vk-co-small-group--local">
                            <h3 class="vk-co-subtitle">Paikalliset energiayhtiöt</h3>
                            <p class="vk-co-small-text">
                                Kaupungin tai kunnan alueella toimivia yhtiöitä, joilla on usein oma sähköverkko.
                                Useimmat myyvät sähköä myös oman alueensa ulkopuolelle.
                            </p>
                            <ul class="vk-co-name-list vk-co-name-list--columns">
                                @foreach ($localCompanies as $row)
                                    <li><a href="{{ $companyUrl($row['company']) }}" class="vk-co-name-link vk-focus">{{ $row['company']->name }}</a></li>
                                @endforeach
                            </ul>
                        </div>
                    @endif

                    @php($challengerCompanies = $this->smallCompaniesOfGroup('challenger'))
                    @if ($challengerCompanies->isNotEmpty())
                        <div class="vk-co-small-group vk-co-small-group--challenger">
                            <h3 class="vk-co-subtitle">Pienet haastajat</h3>
                            <p class="vk-co-small-text">
                                Yhtiöitä, jotka myyvät sähköä koko Suomeen ilman omaa sähköverkkoa.
                                Ne kilpailevat yleensä hinnalla tai kapealla erikoistumisella.
                            </p>
                            <ul class="vk-co-name-list">
                                @foreach ($challengerCompanies as $row)
                                    <li><a href="{{ $companyUrl($row['company']) }}" class="vk-co-name-link vk-focus">{{ $row['company']->name }}</a></li>
                                @endforeach
                            </ul>
                        </div>
                    @endif
                </div>
            </section>
        @endif

        <!-- All Companies Section -->
        <section class="vk-co-section vk-co-section--all" aria-labelledby="kaikki-title">
            <div class="vk-co-all-head">
                <div class="vk-co-count-head">
                    <div class="vk-co-title-wrap">
                        <h2 id="kaikki-title" class="vk-co-title vk-co-title--xl">Kaikki sähköyhtiöt</h2>
                        <x-ink.wave width="300" stroke="3.5" />
                    </div>
                    <p class="vk-co-count">
                        @if ($search)
                            Löytyi <span class="vk-co-count-number">{{ $filteredCompanies->count() }}</span> yhtiötä hakusanalla "{{ $search }}"
                        @else
                            <span class="vk-co-count-number">{{ $companyCount }}</span> sähköyhtiötä vertailussa
                        @endif
                    </p>
                </div>

                <!-- Search -->
                <div class="vk-co-search">
                    <label for="company-search" class="sr-only">Hae yhtiötä</label>
                    <svg class="vk-co-search-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" /></svg>
                    <input
                        id="company-search"
                        type="text"
                        wire:model.live.debounce.300ms="search"
                        data-search-input
                        placeholder="Hae yhtiötä..."
                    >
                </div>
            </div>

            @if ($filteredCompanies->isEmpty())
                <p class="vk-co-empty">Ei yhtiöitä hakuehdoilla.</p>
            @else
                {{-- Two ruled columns on a laptop: the first half of the list, then the second. --}}
                <div class="vk-co-ledger">
                    @foreach ($filteredCompanies->chunk((int) ceil($filteredCompanies->count() / 2)) as $half)
                        <ul class="vk-co-ledger-col">
                            @foreach ($half as $data)
                                <li>
                                    <a href="{{ $companyUrl($data['company']) }}" class="vk-co-row vk-focus">
                                        <x-company-logo
                                            :company="$data['company']"
                                            class="vk-co-logo vk-co-logo--row"
                                            data-logo="{{ $data['company']->name_slug }}"
                                            img-class="vk-co-logo-img"
                                        />
                                        <span class="vk-co-row-main">
                                            <span class="vk-co-row-name">{{ $data['company']->name }}</span>
                                            <span class="vk-co-row-meta">
                                                <span>{{ $data['contractCount'] }} sopimusta</span>
                                                @if ($data['hasFullyRenewable'])
                                                    <span class="vk-co-row-green"><x-ink.sparkle size="11" color="currentColor" />100% uusiutuva</span>
                                                @endif
                                                @if ($data['hasSpotContracts'])
                                                    <span class="vk-co-row-spot"><span aria-hidden="true">·</span> Pörssisähkö</span>
                                                @endif
                                            </span>
                                        </span>
                                        <span class="vk-co-row-price">
                                            @if ($data['lowestPrice'] !== null)
                                                <span class="vk-co-row-amount">{{ number_format($data['lowestPrice'], 0, ',', ' ') }} EUR</span>
                                                <span class="vk-co-row-unit">alkaen/vuosi</span>
                                            @else
                                                <span class="vk-co-row-unit">Hinta ei saatavilla</span>
                                            @endif
                                        </span>
                                    </a>
                                </li>
                            @endforeach
                        </ul>
                    @endforeach
                </div>
            @endif
        </section>

        <!-- FAQ Section -->
        <section class="vk-co-section vk-co-faq-section" aria-labelledby="faq-title">
            <div class="vk-co-title-wrap">
                <h2 id="faq-title" class="vk-co-title">Usein kysyttyä sähköyhtiöistä</h2>
                <x-ink.wave width="300" stroke="3.5" />
            </div>
            <div class="vk-co-faq">
                @foreach ($this->faqItems as $faq)
                    <details class="group vk-co-faq-row" @if ($loop->first) open @endif>
                        <summary class="vk-focus vk-co-faq-q">
                            <h3>{{ $faq['question'] }}</h3>
                            <svg width="18" height="18" viewBox="0 0 18 18" class="shrink-0 transition-transform group-open:rotate-180 motion-reduce:transition-none" aria-hidden="true" focusable="false"><path d="M4 7l5 5 5-5" fill="none" style="stroke: var(--color-ink)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" /></svg>
                        </summary>
                        <p class="vk-co-faq-a">{{ $faq['answer'] }}</p>
                    </details>
                @endforeach
            </div>
        </section>

        {{-- Villager interlude (Paper "Villager interlude · spruce"): a pause after the FAQ, far
             from every seller block. --}}
        <x-site.interlude art="companies-interlude" class="vk-co-interlude" />
    </div>
</div>
