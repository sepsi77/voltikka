<div>
    {{-- Schema.org structured data --}}
    <x-schema-markup :schemas="$schemas" />

    {{-- Seller band (Paper board AX2-0 "Hero · farmland band", seasons EBR-0). The fox stands at
         the far left edge of the painting; the company name, H1 and lead sit on the right. The
         company facts (logo, figures, address) follow below the band. --}}
    <x-site.company-hero art="company-band" variant="band" :tone="\App\Support\Season::heroTone('company-band')">
        <nav class="vk-co-hero-crumbs" aria-label="Breadcrumb">
            <ol>
                <li><a href="/" class="vk-focus">Etusivu</a></li>
                <li aria-hidden="true">/</li>
                <li><a href="/sahkosopimus/sahkoyhtiot" class="vk-focus">Sähköyhtiöt</a></li>
                <li aria-hidden="true">/</li>
                <li aria-current="page">{{ $company->name }}</li>
            </ol>
        </nav>

        <h1 class="vk-co-hero-h1">{{ $h1 }}</h1>

        {{-- Hero description with company-specific SEO content --}}
        <p class="vk-co-hero-sub">{{ $heroDescription }}</p>
    </x-site.company-hero>

    <x-page-action-strip />

    @php
        $fmt = fn ($value, int $decimals = 0) => number_format($value, $decimals, ',', ' ');
        $detailUrl = function ($contract) use ($consumption) {
            $url = route('contract.detail', ['contractId' => $contract->id]);

            return $consumption === 5000 ? $url : $url . '?kulutus=' . $consumption;
        };
    @endphp

    <div class="vk-wrap vk-co-page vk-co-detail">

    {{-- Company facts (Paper "Company facts"): logo, the page note, update date and contact
         address at the left; the three key figures as big numbers at the right. --}}
    <section class="vk-co-facts" aria-label="{{ $company->name }}: yhtiön tiedot">
        <div class="vk-co-facts-text">
            <x-company-logo
                :company="$company"
                class="vk-co-logo vk-co-logo--facts"
                img-class="vk-co-logo-img"
                data-logo="{{ $company->name_slug }}"
            />

            @if ($companyStats['contract_count'] > 0)
                <p class="vk-co-facts-note">
                    Tällä sivulla näet yhtiön sähkösopimukset, hinnat, tarjoukset ja pörssisähkön myyjäkohtaiset kulut.
                </p>
            @endif

            <div class="vk-co-facts-small">
                @if ($updatedAt)
                    <p class="vk-co-facts-updated">
                        <x-ink.sparkle size="11" color="currentColor" />
                        Päivitetty {{ $updatedAt->translatedFormat('j.n.Y') }}
                    </p>
                @endif

                @if ($company->street_address || $company->postal_code || $company->postal_name || $company->company_url)
                    <p class="vk-co-facts-contact">
                        @if ($company->street_address || $company->postal_code || $company->postal_name)
                            <span>Yhtiön ilmoittama osoite: {{ $company->street_address }}@if($company->postal_code || $company->postal_name), {{ $company->postal_code }} {{ $company->postal_name }}@endif</span>
                        @endif
                        @if ($company->company_url)
                            @if ($company->street_address || $company->postal_code || $company->postal_name)
                                <span aria-hidden="true">·</span>
                            @endif
                            <a href="{{ $company->company_url }}" target="_blank" rel="noopener noreferrer" class="vk-co-facts-url vk-focus">{{ $company->company_url }}</a>
                        @endif
                    </p>
                @endif
            </div>
        </div>

        @if ($companyStats['contract_count'] > 0)
            <dl class="vk-co-figures">
                <div class="vk-co-figure">
                    <dt class="vk-co-figure-label">sopimusta</dt>
                    <dd class="vk-co-figure-number">{{ $companyStats['contract_count'] }}</dd>
                </div>
                @if ($companyStats['avg_renewable_percent'] !== null)
                    <div class="vk-co-figure">
                        <dt class="vk-co-figure-label">uusiutuvaa</dt>
                        <dd @class(['vk-co-figure-number', 'vk-co-figure-number--green' => $companyStats['avg_renewable_percent'] > 0, 'vk-co-figure-number--muted' => $companyStats['avg_renewable_percent'] <= 0])>{{ $fmt($companyStats['avg_renewable_percent']) }}%</dd>
                    </div>
                @endif
                @if ($companyStats['min_price'] !== null)
                    <div class="vk-co-figure">
                        <dt class="vk-co-figure-label">{{ "\u{20AC}" }}/v alkaen</dt>
                        <dd class="vk-co-figure-number vk-co-figure-number--price">{{ $fmt($companyStats['min_price']) }}</dd>
                    </div>
                @endif
            </dl>
        @endif
    </section>

    {{-- Consumption selector (Paper "Vuosikulutus"): the same choice row as the comparison
         page, with radio marks and the season's home art. On a phone it collapses behind
         "Vaihda". Behaviour: selectPreset, directConsumption (wire:model.live.blur, min 0). --}}
    @php
        $presetArt = [
            'small_apartment' => 'home-studio',
            'large_apartment' => 'home-apartment',
            'row_house' => 'home-rowhouse',
            'large_house_electric' => 'home-house',
        ];
        $isDirect = $selectedPreset === null;
    @endphp
    <section x-data="{ panelOpen: false }" class="vk-co-consumption" aria-label="Vuosikulutus">
        <div class="vk-ctl-row vk-co-consumption-row">
            <div class="flex w-full items-center justify-between gap-3 lg:w-auto">
                <p class="vk-ctl-label">Vuosikulutus</p>
                <button
                    type="button"
                    @click="panelOpen = !panelOpen"
                    class="vk-disclosure-trigger lg:hidden"
                    :aria-expanded="panelOpen ? 'true' : 'false'"
                >
                    <span class="font-bold tabular-nums text-ink-body">{{ $fmt($consumption) }} kWh/v</span>
                    <span class="vk-disclosure-title" x-text="panelOpen ? 'Piilota' : 'Vaihda'">Vaihda</span>
                    <svg class="vk-chevron" viewBox="0 0 18 18" aria-hidden="true" focusable="false"><path d="M4 7l5 5 5-5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" /></svg>
                </button>
            </div>

            <div class="w-full flex-wrap items-center gap-y-2.5 lg:flex lg:w-auto lg:flex-1" :class="panelOpen ? 'flex' : 'hidden lg:flex'">
                <div class="vk-presets" role="group" aria-label="Kulutusprofiilit">
                    @foreach ($presets as $key => $preset)
                        @php $isSelected = $selectedPreset === $key; @endphp
                        <button
                            type="button"
                            wire:click="selectPreset('{{ $key }}')"
                            aria-pressed="{{ $isSelected ? 'true' : 'false' }}"
                            class="vk-preset"
                        >
                            @if ($isSelected)
                                <svg class="vk-mark" viewBox="0 0 22 22" aria-hidden="true" focusable="false"><circle cx="11" cy="11" r="9.5" style="fill: var(--color-fox)" /><path d="M6.5 11.5 L9.6 14.3 L15.6 7.8" fill="none" style="stroke: var(--color-snow-white)" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" /></svg>
                            @else
                                <svg class="vk-mark" viewBox="0 0 22 22" aria-hidden="true" focusable="false"><circle cx="11" cy="11" r="9" style="fill: var(--color-snow-white); stroke: var(--color-field-edge)" stroke-width="2" /></svg>
                            @endif
                            @isset($presetArt[$key])
                                <img src="{{ \App\Support\Season::art($presetArt[$key]) }}" alt="" width="44" height="44" class="vk-preset-art" loading="lazy" decoding="async">
                            @endisset
                            <span class="min-w-0">
                                <span class="vk-preset-name">{{ $preset['label'] }}</span>
                                <span class="vk-preset-desc">{{ $preset['description'] }}</span>
                                <span class="vk-preset-kwh">{{ $fmt($preset['consumption']) }} kWh/v</span>
                            </span>
                        </button>
                    @endforeach
                </div>

                <div class="vk-own vk-co-own">
                    <div class="flex flex-col gap-1.5">
                        <div @class(['vk-own-field', 'vk-co-own-field', 'vk-own-field--active' => $isDirect])>
                            <label for="company-direct-consumption" class="vk-co-own-label">Tiedän kulutukseni</label>
                            <input
                                id="company-direct-consumption"
                                type="number"
                                min="0"
                                step="100"
                                inputmode="numeric"
                                wire:model.live.blur="directConsumption"
                                placeholder="esim. 7000"
                                @if ($directConsumptionNotice) aria-invalid="true" aria-describedby="company-direct-consumption-notice" @endif
                            >
                            <span class="vk-unit">kWh/v</span>
                        </div>
                        @if ($directConsumptionNotice)
                            <p id="company-direct-consumption-notice" role="alert" class="vk-notice max-w-[260px]">{{ $directConsumptionNotice }}</p>
                        @endif
                        <a href="/sahkosopimus/laskuri" class="vk-inked-link vk-focus w-fit">En tiedä – arvioi laskurilla →</a>
                    </div>
                </div>
            </div>
        </div>
    </section>

    {{-- Company Statistics Detail Section (Paper "Hinnat lyhyesti"): key figures on the page. --}}
    @if ($companyStats['contract_count'] > 0)
        <section class="vk-co-section vk-co-brief" aria-labelledby="hinnat-lyhyesti-title">
            <div class="vk-co-title-wrap">
                <h2 id="hinnat-lyhyesti-title" class="vk-co-title">{{ $company->name }}: hinnat lyhyesti</h2>
                <x-ink.wave width="300" stroke="3.5" class="max-w-full" />
            </div>

            <dl class="vk-co-brief-grid">
                {{-- Average Price --}}
                @if ($companyStats['avg_price'] !== null)
                    <div class="vk-co-brief-item">
                        <dt class="vk-co-caps">Keskihinta</dt>
                        <dd class="vk-co-brief-number">{{ $fmt($companyStats['avg_price']) }} {{ "\u{20AC}" }}/v</dd>
                        <dd class="vk-co-brief-note">{{ $fmt($consumption) }} kWh kulutuksella</dd>
                    </div>
                @endif

                {{-- Price Range: the cheapest and dearest total on an inked line, the average as a tick. --}}
                @if ($companyStats['min_price'] !== null && $companyStats['max_price'] !== null)
                    @php
                        $rangeSpan = (float) $companyStats['max_price'] - (float) $companyStats['min_price'];
                        $avgX = $companyStats['avg_price'] !== null && $rangeSpan > 0
                            ? 8 + max(0, min(1, ((float) $companyStats['avg_price'] - (float) $companyStats['min_price']) / $rangeSpan)) * 404
                            : null;
                    @endphp
                    <div class="vk-co-brief-item vk-co-brief-item--range">
                        <dt class="vk-co-caps">Hintahaarukka</dt>
                        <dd class="vk-co-brief-number">{{ $fmt($companyStats['min_price']) }} – {{ $fmt($companyStats['max_price']) }} {{ "\u{20AC}" }}/v</dd>
                        <dd class="vk-co-brief-note vk-co-brief-note--range">halvin – kallein</dd>
                        <dd aria-hidden="true">
                            <svg class="vk-co-range" viewBox="0 0 420 34" preserveAspectRatio="none" focusable="false">
                                <path d="M8 14 C 120 12.5, 290 15.5, 412 13.6" fill="none" style="stroke: var(--color-ink)" stroke-width="2" stroke-linecap="round" vector-effect="non-scaling-stroke" />
                                <circle cx="8" cy="14" r="6" style="fill: var(--color-aurora)" />
                                <circle cx="412" cy="14" r="6" style="fill: var(--color-price-high)" />
                                @if ($avgX !== null)
                                    <path d="M{{ round($avgX, 1) }} 6 V22" fill="none" style="stroke: var(--color-granite)" stroke-width="2.4" stroke-linecap="round" vector-effect="non-scaling-stroke" />
                                @endif
                            </svg>
                            <span class="vk-co-range-labels">
                                <span class="text-aurora">halvin</span>
                                @if ($avgX !== null)
                                    <span class="text-granite" style="left: {{ round(($avgX / 420) * 100, 2) }}%">ka {{ $fmt($companyStats['avg_price']) }}</span>
                                @endif
                                <span class="text-price-high">kallein</span>
                            </span>
                        </dd>
                    </div>
                @endif

                {{-- Average Emission Factor --}}
                @if ($companyStats['avg_emission_factor'] !== null)
                    <div class="vk-co-brief-item">
                        <dt class="vk-co-caps">Päästökerroin</dt>
                        <dd @class(['vk-co-brief-number', 'text-aurora' => $companyStats['avg_emission_factor'] == 0])>{{ $fmt($companyStats['avg_emission_factor']) }} gCO2/kWh</dd>
                        <dd class="vk-co-brief-note">keskiarvo</dd>
                    </div>
                @endif

                {{-- Contract Types --}}
                <div class="vk-co-brief-item">
                    <dt class="vk-co-caps">Sopimustyypit</dt>
                    @if ($companyStats['spot_contract_count'] > 0)
                        <dd class="vk-co-brief-type">
                            <x-ink.glyph bucket="porssisahko" size="34" />
                            {{ $companyStats['spot_contract_count'] }} pörssisähkösopimus{{ $companyStats['spot_contract_count'] > 1 ? 'ta' : '' }}
                        </dd>
                    @endif
                    @if ($companyStats['fixed_price_contract_count'] > 0)
                        <dd class="vk-co-brief-type">
                            <x-ink.glyph bucket="kiintea" size="34" />
                            {{ $companyStats['fixed_price_contract_count'] }} {{ $companyStats['fixed_price_contract_count'] > 1 ? 'kiinteähintaista' : 'kiinteähintainen' }}
                        </dd>
                    @endif
                </div>
            </dl>

            <div class="vk-co-footnote-row vk-co-footnote-row--ruled">
                <p class="vk-co-footnote">
                    Luvut perustuvat Voltikan riippumattomaan vertailuun: arvioidut 12 kk kulut {{ $fmt($consumption) }} kWh vuosikulutuksella, hinnat sis. alv 25,5 % (siirtomaksu ei sisälly).
                </p>
                <a href="/tietoa#menetelma" class="vk-co-ink-link vk-focus">Näin laskemme &rarr;</a>
            </div>
        </section>
    @endif

    {{-- Promotions (Paper "Tarjoukset") --}}
    <section id="tarjoukset" class="vk-co-section" aria-labelledby="tarjoukset-title">
        <div class="vk-co-head vk-co-head--split">
            <div class="vk-co-title-wrap">
                <h2 id="tarjoukset-title" class="vk-co-title">{{ $company->name }} tarjoukset</h2>
                <x-ink.wave width="270" stroke="3.5" class="max-w-full" />
            </div>
            @if ($promotionContracts->isNotEmpty())
                <p class="vk-co-count vk-co-count--side">
                    <span class="vk-co-count-number">{{ $promotionContracts->count() }}</span> sopimusta kampanjahinnalla.
                </p>
            @endif
        </div>

        @if ($promotionContracts->isEmpty())
            <p class="vk-co-text">
                Vertailussa ei ole nyt kampanjahintaista sopimusta. Voltikka päivittää sopimustiedot päivittäin.
            </p>
            <a href="/sahkosopimus/sahkotarjous" class="vk-action vk-focus">
                <span>Katso kaikki voimassa olevat sähkötarjoukset &rarr;</span>
                <x-ink.wave width="full" stroke="2.4" />
            </a>
        @else
            <p class="vk-co-text">
                Tarjous kertoo kampanjahinnan ja sen keston. Säästö vertaa kampanjahintaa saman sopimuksen normaalihintaan {{ $fmt($consumption) }} kWh kulutuksella.
                Lyhyessä määräaikaisessa sopimuksessa säästö koskee todellista sopimuskautta.
            </p>

            <table class="vk-co-table vk-co-table--offers">
                <thead>
                    <tr>
                        <th scope="col">Sopimus</th>
                        <th scope="col">Tarjous</th>
                        <th scope="col">Säästö</th>
                        <th scope="col">12 kk:n vertailuhinta</th>
                        <th scope="col" class="vk-co-table-end">Tiedot</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($promotionContracts as $contract)
                        @php
                            $offerFact = $contract->offer_fact;
                        @endphp
                        <tr>
                            <td class="vk-co-table-name">{{ $contract->name }}</td>
                            <td class="vk-co-table-offer">
                                <x-ink.sparkle size="12" color="currentColor" />
                                {{ $offerFact['label'] }}
                            </td>
                            <td>
                                <span class="vk-co-cell-label">Säästö</span>
                                @if (($offerFact['benefit_text'] ?? null) !== null)
                                    <span class="vk-co-table-saving">{{ ($offerFact['benefit_is_estimate'] ?? false) ? 'Arvio ' : '' }}{{ $offerFact['benefit_text'] }}</span>
                                    @if ($offerFact['benefit_is_estimate'] ?? false)
                                        <span class="vk-co-table-note">Normaalihinta voi muuttua. Säästö ei ole taattu.</span>
                                    @endif
                                    <span class="vk-co-table-note">{{ $offerFact['basis_label'] }}</span>
                                @else
                                    <span class="vk-co-table-dash" title="Kampanjan euromääräistä vaikutusta ei voi laskea luotettavasti tästä sopimuksesta.">&ndash;</span>
                                @endif
                            </td>
                            <td>
                                <span class="vk-co-cell-label">12 kk:n vertailuhinta</span>
                                @if (($contract->calculated_cost['total_cost'] ?? null) !== null)
                                    <span class="vk-co-table-figure">{{ $fmt($contract->calculated_cost['total_cost']) }} {{ "\u{20AC}" }}/v</span>
                                @else
                                    <span class="vk-co-table-note">Ei laskettavissa</span>
                                @endif
                            </td>
                            <td class="vk-co-table-end">
                                <a href="{{ $detailUrl($contract) }}" class="vk-co-ink-link vk-co-ink-link--ui vk-focus">Tiedot &rarr;</a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>

            <div class="vk-co-footnote-row">
                <p class="vk-co-footnote">
                    Viiva säästösarakkeessa tarkoittaa, ettei säästöä voi laskea luotettavasti.
                    Hinnat sis. alv 25,5 %, siirtomaksu ei sisälly.
                </p>
                <a href="/sahkosopimus/sahkotarjous" class="vk-action vk-action--small vk-focus">
                    <span>Vertaa kaikkia sähkötarjouksia &rarr;</span>
                    <x-ink.wave width="full" stroke="2.4" />
                </a>
            </div>
        @endif
    </section>

    {{-- Market comparison --}}
    @if ($marketComparison)
        @include('partials.company-market-comparison', [
            'company' => $company,
            'marketComparison' => $marketComparison,
        ])
    @else
        <section id="hintavertailu" class="vk-co-section" aria-labelledby="hintavertailu-title">
            <div class="vk-co-title-wrap">
                <h2 id="hintavertailu-title" class="vk-co-title">{{ $company->name }}: sähkön hinta</h2>
                <x-ink.wave width="280" stroke="3.5" class="max-w-full" />
            </div>
            <p class="vk-co-text">
                Yhtiön ja markkinan vertailukelpoista hintatietoa ei ole nyt saatavilla. Katso nykyiset sopimushinnat alta.
            </p>
        </section>
    @endif

    {{-- Spot contracts (Paper "Pörssisähkö marginaali"): the spot glyph as the object vignette
         at the left of the heading (spec §5 "Seller pages": no villager here). --}}
    <section id="porssisahko" class="vk-co-section" aria-labelledby="porssisahko-title">
        @php
            $spotCount = $spotContracts->count();
        @endphp
        <div class="vk-co-head vk-co-head--split vk-co-head--top">
            <div class="vk-co-vignette-head">
                <x-ink.glyph bucket="porssisahko" size="56" class="vk-co-vignette" />
                <div class="vk-co-title-wrap">
                    <h2 id="porssisahko-title" class="vk-co-title">{{ $company->name }}: pörssisähkö, marginaali ja perusmaksu</h2>
                    <x-ink.wave width="300" stroke="3.5" class="max-w-full" />
                </div>
            </div>

            @if ($spotContracts->isNotEmpty())
                @php
                    $spotCountText = $spotCount === 1
                        ? '1 pörssisähkösopimus'
                        : $spotCount . ' pörssisähkösopimusta';
                    $spotMarginMedian = $spotBenchmarks['spot_margin']['median'] ?? null;
                    $spotMonthlyFeeMedian = $spotBenchmarks['monthly_fee']['median'] ?? null;
                @endphp
                <div class="vk-co-intro">
                    <p class="vk-co-intro-lead">{{ $company->name }} myy pörssisähköä. Vertailussa on {{ $spotCountText }}.</p>
                    <p class="vk-co-intro-text">
                        Myyjän itse määrittämät kulut ovat marginaali ja kuukausittainen perusmaksu.
                        Nord Poolin markkinahinta on kaikille pörssisähkötuotteille yhteinen, joten se ei kerro myyjän kilpailukyvystä.
                    </p>
                    <p class="vk-co-intro-small">
                        Nykyinen vuosihinta arvioidaan ensisijaisesti seuraavan 12 kuukauden tukkumarkkinan ennakkohinnoista ja historiallisesta päivä–yö-erosta. Jos ennakkohintoja ei voi käyttää, laskelma näyttää erikseen merkityn edeltävän 12 kuukauden toteutuneeseen pörssihintaan perustuvan arvion.
                    </p>
                </div>
            @endif
        </div>

        @if ($spotContracts->isNotEmpty())
            <table class="vk-co-table vk-co-table--spot">
                <thead>
                    <tr>
                        <th scope="col">Sopimus</th>
                        <th scope="col">Marginaali</th>
                        <th scope="col">Perusmaksu</th>
                        <th scope="col">Vuosihinta (arvio)</th>
                        <th scope="col" class="vk-co-table-end">Tiedot</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($spotContracts as $contract)
                        @php
                            $margin = $contract->calculated_cost['spot_price_margin'] ?? null;
                            $fee = $contract->calculated_cost['monthly_fixed_fee'] ?? null;
                            $total = $contract->calculated_cost['total_cost'] ?? null;
                            $marginDelta = $margin !== null && $spotMarginMedian !== null
                                ? (float) $margin - (float) $spotMarginMedian
                                : null;
                            $feeDelta = $fee !== null && $spotMonthlyFeeMedian !== null
                                ? (float) $fee - (float) $spotMonthlyFeeMedian
                                : null;
                        @endphp
                        <tr>
                            <td class="vk-co-table-name">{{ $contract->name }}</td>
                            <td>
                                <span class="vk-co-cell-label">Marginaali</span>
                                <span class="vk-co-table-figure">{{ $margin !== null ? $fmt($margin, 2) . ' c/kWh' : '–' }}</span>
                                @if ($marginDelta !== null)
                                    @if (abs($marginDelta) < 0.005)
                                        <span class="vk-co-delta">Sama kuin markkinan mediaani</span>
                                    @else
                                        <span @class(['vk-co-delta', 'vk-co-delta--down' => $marginDelta < 0, 'vk-co-delta--up' => $marginDelta >= 0])><span aria-hidden="true">{{ $marginDelta < 0 ? '↓' : '↑' }}</span> {{ $fmt(abs($marginDelta), 2) }} c/kWh {{ $marginDelta < 0 ? 'alle' : 'yli' }} markkinan mediaanin</span>
                                    @endif
                                @endif
                            </td>
                            <td>
                                <span class="vk-co-cell-label">Perusmaksu</span>
                                <span class="vk-co-table-figure">{{ $fee !== null ? $fmt($fee, 2) . ' ' . "\u{20AC}" . '/kk' : '–' }}</span>
                                @if ($feeDelta !== null)
                                    @if (abs($feeDelta) < 0.005)
                                        <span class="vk-co-delta">Sama kuin markkinan mediaani</span>
                                    @else
                                        <span @class(['vk-co-delta', 'vk-co-delta--down' => $feeDelta < 0, 'vk-co-delta--up' => $feeDelta >= 0])><span aria-hidden="true">{{ $feeDelta < 0 ? '↓' : '↑' }}</span> {{ $fmt(abs($feeDelta), 2) }} {{ "\u{20AC}" }}/kk {{ $feeDelta < 0 ? 'alle' : 'yli' }} markkinan mediaanin</span>
                                    @endif
                                @endif
                            </td>
                            <td>
                                <span class="vk-co-cell-label">Vuosihinta (arvio)</span>
                                <span class="vk-co-table-figure">{{ $total !== null ? $fmt($total) . ' ' . "\u{20AC}" . '/v' : '–' }}</span>
                            </td>
                            <td class="vk-co-table-end">
                                <a href="{{ $detailUrl($contract) }}" class="vk-co-ink-link vk-co-ink-link--ui vk-focus">Tiedot &rarr;</a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>

            <div class="vk-co-footnote-row">
                <p class="vk-co-footnote">
                    Hinnat sis. alv 25,5 %, siirtomaksu ei sisälly. Vuosihinta on laskettu {{ $fmt($consumption) }} kWh vuosikulutuksella.
                    Markkinavertailu koskee vain myyjän marginaalia ja perusmaksua; Nord Poolin hinta ei ole myyjäkohtainen.
                    @if ($spotBenchmarks !== null)
                        Mediaanit perustuvat {{ \Illuminate\Support\Carbon::parse($spotBenchmarks['stat_date'])->translatedFormat('j.n.Y') }}
                        {{ $spotBenchmarks['pricing_basis'] === 'canonical_calculation' ? 'Voltikan laskemiin nykyhintoihin' : 'myyjiltä havaittuihin hintoihin' }}.
                    @endif
                </p>
                <a href="/sahkosopimus/porssisahko" class="vk-action vk-action--small vk-focus">
                    <span>Vertaa kaikkia pörssisähkösopimuksia &rarr;</span>
                    <x-ink.wave width="full" stroke="2.4" />
                </a>
            </div>
        @else
            <p class="vk-co-text">
                {{ $company->name }} ei tarjoa tällä hetkellä kotitalouksille pörssisähkösopimusta Voltikan vertailussa.
            </p>
            <a href="/sahkosopimus/porssisahko" class="vk-action vk-action--small vk-focus">
                <span>Vertaa kaikkia pörssisähkösopimuksia &rarr;</span>
                <x-ink.wave width="full" stroke="2.4" />
            </a>
        @endif
    </section>

    <!-- Contracts Section (Paper "Kotitalouksien sopimukset"): the shared ledger entry, without
         the rank and logo lane (every entry is this company's). -->
    <section class="vk-co-section vk-co-contracts" aria-labelledby="sopimukset-title">
        <div class="vk-co-count-head vk-co-count-head--ruled">
            <div class="vk-co-title-wrap">
                <h2 id="sopimukset-title" class="vk-co-title vk-co-title--xl">{{ $company->name }} sähkösopimukset</h2>
                <x-ink.wave width="340" stroke="3.5" class="max-w-full" />
            </div>
            <p class="vk-co-count">
                @if ($contracts->count() === 1)
                    <span class="vk-co-count-number">1</span> kotitalouksille sopiva sopimus saatavilla
                @else
                    <span class="vk-co-count-number">{{ $contracts->count() }}</span> kotitalouksille sopivaa sopimusta saatavilla
                @endif
            </p>
        </div>

        <div class="vk-ledger vk-co-ledger-entries">
            @forelse ($contracts as $index => $contract)
                <x-contract-card
                    :contract="$contract"
                    :rank="$index + 1"
                    :featured="$index === 0"
                    :consumption="$consumption"
                    :showRank="false"
                    :showEmissions="true"
                    :showEnergyBadges="true"
                    :showSpotBadge="true"
                />
            @empty
                <p class="vk-co-empty">Ei sähkösopimuksia saatavilla.</p>
            @endforelse
        </div>
    </section>

    @if ($businessContracts->isNotEmpty())
        <section id="yrityksille" class="vk-co-section" aria-labelledby="yrityksille-title">
            <div class="vk-co-count-head">
                <div class="vk-co-title-wrap">
                    <h2 id="yrityksille-title" class="vk-co-title">{{ $company->name }} sähkösopimukset yrityksille</h2>
                    <x-ink.wave width="340" stroke="3.5" color="var(--color-lake)" class="max-w-full" />
                </div>
                <p class="vk-co-count vk-co-count--business">
                    @if ($businessContracts->count() === 1)
                        <span class="vk-co-count-number">1</span> yrityksille sopiva sopimus saatavilla
                    @else
                        <span class="vk-co-count-number">{{ $businessContracts->count() }}</span> yrityksille sopivaa sopimusta saatavilla
                    @endif
                </p>
            </div>

            <ul class="vk-co-compact-list">
                @foreach ($businessContracts as $contract)
                    <x-site.company-compact-entry :contract="$contract" :consumption="$consumption" />
                @endforeach
            </ul>
        </section>
    @endif

    {{-- Back to Companies Link --}}
    <div class="vk-co-back">
        <a href="/sahkosopimus/sahkoyhtiot" class="vk-action vk-focus">
            <span>&larr; Takaisin sähköyhtiöihin</span>
            <x-ink.wave width="full" stroke="2.4" />
        </a>
    </div>
    </div>
</div>
