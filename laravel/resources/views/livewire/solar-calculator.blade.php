<div>
    {{-- FAQ Schema for SEO --}}
    <script type="application/ld+json">
    {
        "@@context": "https://schema.org",
        "@type": "FAQPage",
        "mainEntity": [
            {
                "@type": "Question",
                "name": "Paljonko aurinkopaneelit tuottavat Suomessa?",
                "acceptedAnswer": {
                    "@type": "Answer",
                    "text": "Suomessa aurinkopaneelit tuottavat tyypillisesti 800–1000 kWh vuodessa jokaista asennettua kilowattipiikkiä (kWp) kohden. Etelä-Suomessa tuotto on hieman korkeampi kuin Pohjois-Suomessa. Esimerkiksi 5 kWp:n järjestelmä tuottaa Helsingissä noin 4500–5000 kWh vuodessa."
                }
            },
            {
                "@type": "Question",
                "name": "Kuinka paljon aurinkopaneelit säästävät sähkölaskussa?",
                "acceptedAnswer": {
                    "@type": "Answer",
                    "text": "Säästö riippuu sähkön hinnasta ja siitä, kuinka suuren osan tuotetusta sähköstä käytät itse. Tyypillisesti kotitalous käyttää itse 20–40% aurinkopaneelien tuottamasta sähköstä. Jos sähkön hinta on 10 c/kWh ja 5 kWp:n järjestelmä tuottaa 4500 kWh vuodessa, 30% omakäytöllä säästö on noin 135 €/vuosi."
                }
            },
            {
                "@type": "Question",
                "name": "Mikä on paras kattokaltevuus aurinkopaneeleille?",
                "acceptedAnswer": {
                    "@type": "Answer",
                    "text": "Suomessa optimaalinen kattokaltevuus aurinkopaneeleille on noin 40–45 astetta etelään suunnattuna. Tämä kaltevuus maksimoi vuosituoton ja auttaa myös lumen valumisessa talvella."
                }
            },
            {
                "@type": "Question",
                "name": "Toimivatko aurinkopaneelit talvella Suomessa?",
                "acceptedAnswer": {
                    "@type": "Answer",
                    "text": "Kyllä, aurinkopaneelit toimivat myös talvella, mutta tuotto on huomattavasti pienempi kuin kesällä. Joulukuussa tuotto voi olla vain 5–10% kesäkuun tuotosta. Paneelit toimivat parhaiten kylmässä – hyötysuhde on jopa parempi pakkasella kuin helteellä."
                }
            },
            {
                "@type": "Question",
                "name": "Kuinka suuri aurinkopaneelijärjestelmä tarvitaan omakotitaloon?",
                "acceptedAnswer": {
                    "@type": "Answer",
                    "text": "Tyypillinen omakotitalon aurinkopaneelijärjestelmä on 5–10 kWp. Järjestelmän koko riippuu sähkönkulutuksesta, käytettävissä olevasta kattopinta-alasta ja budjetista. 1 kWp vaatii noin 5–6 m² kattopinta-alaa."
                }
            }
        ]
    }
    </script>


    @php
        $heroTone = \App\Support\Season::heroTone('solar-hero');
        // Small inked spinner (wire:loading targets stay as before the redesign).
        $spinner = '<svg class="energy-spinner" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><circle cx="12" cy="12" r="9" fill="none" stroke="currentColor" stroke-width="3" opacity=".25"/><path d="M21 12a9 9 0 0 0-9-9" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round"/></svg>';
        // Drawn radio mark (design spec §5: the selected radio is fox with a white check).
        $radio = fn (bool $on) => $on
            ? '<svg class="energy-radio" viewBox="0 0 22 22" aria-hidden="true" focusable="false"><circle cx="11" cy="11" r="9.5" style="fill: var(--color-fox)"/><path d="M6.5 11.5 L9.6 14.3 L15.6 7.8" fill="none" style="stroke: var(--color-snow-white)" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"/></svg>'
            : '<svg class="energy-radio" viewBox="0 0 22 22" aria-hidden="true" focusable="false"><circle cx="11" cy="11" r="9.2" style="fill: var(--color-snow-white); stroke: var(--color-field-edge)" stroke-width="2"/></svg>';
    @endphp

    {{-- Hero: the midnight-sun cottage (the fox is painted on the roof). The address is the one
         input an estimate needs, so it sits in the hero (Paper board 3WH-0). --}}
    <x-site.energy-hero art="solar-hero" :tone="$heroTone">
        <div class="energy-hero-copy">
            <p class="energy-kicker">Voltikka · Aurinkopaneelit</p>
            <h1 class="energy-hero-h1">Aurinkopaneeli<wbr>laskuri</h1>
            <p class="energy-hero-lead">
                Laske, paljonko aurinkopaneelit tuottaisivat ja säästäisivät kotonasi. Tarvitset vain osoitteesi.
            </p>

            {{-- Step 1 (primary): Address. The only input needed for an estimate. --}}
            <div class="energy-address">
                <p class="energy-start">
                    Aloita tästä
                    <svg width="44" height="14" viewBox="0 0 44 14" aria-hidden="true" focusable="false"><path d="M2 8 C 12 3, 24 12, 36 6 M30 2.5 L37 6 L31.5 11" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" /></svg>
                </p>
                <h2 class="energy-address-title">Mikä on osoitteesi?</h2>
                <div class="relative">
                    <div class="energy-search">
                        <svg class="energy-search-pin" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                            <path d="M12 21.5 C 8 16.5, 5 13, 5 9.5 A 7 7 0 0 1 19 9.5 C 19 13, 16 16.5, 12 21.5 Z" fill="none" style="stroke: var(--color-ink)" stroke-width="1.8" stroke-linejoin="round" />
                            <circle cx="12" cy="9.5" r="2.6" fill="none" style="stroke: var(--color-ink)" stroke-width="1.8" />
                        </svg>
                        <input
                            type="text"
                            wire:model.live.debounce.300ms="addressQuery"
                            data-search-input
                            wire:keydown.escape="hideSuggestions"
                            placeholder="Kirjoita osoite..."
                            aria-label="Osoitteesi"
                            class="energy-search-input"
                            autocomplete="off"
                        >
                        {{-- Loading spinner for address search --}}
                        <div wire:loading wire:target="addressQuery" class="energy-search-status">{!! $spinner !!}</div>
                        @if ($selectedLabel)
                            <button
                                wire:click="clearAddress"
                                wire:loading.remove
                                wire:target="addressQuery"
                                class="energy-search-clear vk-focus"
                                title="Tyhjennä"
                            >
                                <svg width="20" height="20" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M6 18L18 6M6 6l12 12" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" /></svg>
                                <span class="sr-only">Tyhjennä</span>
                            </button>
                        @endif
                    </div>

                    <!-- Suggestions Dropdown -->
                    @if ($showSuggestions && count($addressSuggestions) > 0)
                        <div class="energy-suggestions">
                            @foreach ($addressSuggestions as $suggestion)
                                <button
                                    wire:click="selectAddress('{{ addslashes($suggestion['label']) }}', {{ $suggestion['lat'] }}, {{ $suggestion['lon'] }})"
                                    wire:loading.attr="disabled"
                                    wire:loading.class="opacity-50 cursor-wait"
                                    class="energy-suggestion"
                                >
                                    <svg wire:loading.remove wire:target="selectAddress" class="energy-suggestion-pin" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                                        <path d="M12 21.5 C 8 16.5, 5 13, 5 9.5 A 7 7 0 0 1 19 9.5 C 19 13, 16 16.5, 12 21.5 Z" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round" />
                                        <circle cx="12" cy="9.5" r="2.6" fill="none" stroke="currentColor" stroke-width="1.8" />
                                    </svg>
                                    <span wire:loading wire:target="selectAddress" class="energy-suggestion-pin">{!! $spinner !!}</span>
                                    <span>{{ $suggestion['label'] }}</span>
                                </button>
                            @endforeach
                        </div>
                    @endif
                </div>

                @if ($selectedLabel)
                    <p class="energy-located">
                        <svg width="16" height="16" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M5 13l4 4L19 7" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round" /></svg>
                        Sijainti valittu: {{ number_format($selectedLat, 4, ',', ' ') }}°N, {{ number_format($selectedLon, 4, ',', ' ') }}°E
                    </p>
                @endif

                @if ($addressNotice && !$selectedLabel)
                    <p wire:loading.remove wire:target="addressQuery" class="energy-hero-notice">{{ $addressNotice }}</p>
                @endif

                <p class="energy-address-help">Pelkkä osoite riittää arvioon. Olemme valinneet muut asetukset valmiiksi.</p>
                <p class="energy-address-note">
                    Arvio perustuu Euroopan komission PVGIS-aurinkosäteilytietoihin. Syötä osoitteesi, niin laskemme tuoton ja säästön juuri sinun sijainnillesi.
                </p>
            </div>
        </div>
    </x-site.energy-hero>

    <x-page-action-strip />

    <div class="vk-wrap energy-page energy-page--solar">

        {{-- Step 2 (optional): pre-filled details the visitor can fine-tune. --}}
        <section class="energy-settings" aria-labelledby="solar-settings-title">
            <div class="energy-settings-head">
                <div class="flex min-w-0 flex-col gap-0.5">
                    <h3 id="solar-settings-title" class="vk-section-heading-text">Säädä asetuksia <span class="energy-optional">(valinnaista)</span></h3>
                    <x-ink.wave :width="250" stroke="3.5" class="max-w-full" />
                </div>
                <p class="energy-settings-intro">Oletukset sopivat useimmille kotitalouksille. Voit muuttaa järjestelmän kokoa, varjostusta ja säästöoletuksia.</p>
            </div>

            <!-- System Size Selection -->
            <div class="energy-row">
                <div class="energy-row-label">
                    <h4 class="energy-row-title">Järjestelmän koko</h4>
                    <span wire:loading wire:target="systemKwp" class="energy-row-status">{!! $spinner !!}</span>
                </div>
                <div class="energy-sizes">
                    {{-- Preset buttons + custom input in one row --}}
                    @foreach ([3, 5, 8, 10, 15] as $preset)
                        @php $isSize = $systemKwp == $preset; @endphp
                        <button
                            wire:click="$set('systemKwp', {{ $preset }})"
                            @click="$track('Solar System Size Changed', { size_kwp: {{ $preset }}, method: 'preset' })"
                            wire:loading.attr="disabled"
                            wire:loading.class="opacity-50"
                            wire:target="systemKwp"
                            aria-pressed="{{ $isSize ? 'true' : 'false' }}"
                            @class(['energy-size vk-focus', 'is-selected' => $isSize])
                        >
                            @if ($isSize)
                                <svg class="energy-size-loop" viewBox="0 0 64 56" aria-hidden="true" focusable="false"><path d="M46 7 C 34 1, 14 3, 7 15 C 1 27, 7 46, 26 51 C 45 55, 60 43, 59 27 C 58 15, 52 9, 40 5" fill="none" style="stroke: var(--color-fox)" stroke-width="2.6" stroke-linecap="round" /></svg>
                            @endif
                            <span class="relative">{{ $preset }}</span>
                            <span class="sr-only">kWp</span>
                        </button>
                    @endforeach
                    <label class="energy-size-custom">
                        <input
                            type="number"
                            wire:model.live.blur="systemKwp"
                            min="0.5"
                            max="20"
                            step="0.5"
                            aria-label="Muu järjestelmän koko (kWp)"
                            class="energy-field-input energy-field-input--small"
                            placeholder="Muu"
                        >
                    </label>
                    <span class="energy-size-unit">kWp</span>
                </div>
                <div class="energy-row-help">
                    <p>kWp on järjestelmän huipputeho. Tyypillinen kotitalous on 5–10 kWp, eli noin 25–50 m² paneeleita.</p>
                    @if ($systemKwpNotice)
                        <p class="energy-notice">{{ $systemKwpNotice }}</p>
                    @endif
                </div>
            </div>

            <!-- Shading Selection -->
            <div class="energy-row">
                <div class="energy-row-label">
                    <h4 class="energy-row-title">Varjostus</h4>
                    <span wire:loading wire:target="shadingLevel" class="energy-row-status">{!! $spinner !!}</span>
                </div>
                <div class="energy-choices">
                    @foreach ($shadingLabels as $level => $label)
                        @php $isShade = $shadingLevel === $level; @endphp
                        <button
                            wire:click="$set('shadingLevel', '{{ $level }}')"
                            wire:loading.attr="disabled"
                            wire:loading.class="opacity-50"
                            wire:target="shadingLevel"
                            aria-pressed="{{ $isShade ? 'true' : 'false' }}"
                            @class(['energy-choice vk-focus', 'is-selected' => $isShade])
                        >
                            {!! $radio($isShade) !!}
                            <svg class="energy-choice-icon" viewBox="0 0 40 40" aria-hidden="true" focusable="false">
                                @switch($level)
                                    @case('none')
                                        <circle cx="20" cy="18" r="7" style="fill: var(--color-star); opacity: .75" />
                                        <circle cx="20" cy="18" r="7" fill="none" style="stroke: var(--color-ink)" stroke-width="1.3" />
                                        <path d="M20 5v4M20 27v4M7 18h4M29 18h4M10.5 8.5l2.8 2.8M26.7 24.7l2.8 2.8M10.5 27.5l2.8-2.8M26.7 11.3l2.8-2.8" fill="none" style="stroke: var(--color-ink)" stroke-width="1.3" stroke-linecap="round" />
                                        @break
                                    @case('some')
                                        <circle cx="15" cy="15" r="6" style="fill: var(--color-star); opacity: .75" />
                                        <circle cx="15" cy="15" r="6" fill="none" style="stroke: var(--color-ink)" stroke-width="1.3" />
                                        <path d="M15 4v3M4 15h3M7.2 7.2l2 2M22.8 7.2l-2 2" fill="none" style="stroke: var(--color-ink)" stroke-width="1.3" stroke-linecap="round" />
                                        <path d="M30 36 V28 M30 13 C 24 18, 23 24, 25 28 L 35 28 C 37 24, 36 18, 30 13 Z" style="fill: var(--color-aurora); fill-opacity: .55; stroke: var(--color-ink)" stroke-width="1.3" stroke-linejoin="round" />
                                        @break
                                    @default
                                        <circle cx="12" cy="12" r="5" style="fill: var(--color-star); opacity: .5" />
                                        <circle cx="12" cy="12" r="5" fill="none" style="stroke: var(--color-ink)" stroke-width="1.3" />
                                        <path d="M14 36 V30 M14 14 C 7 20, 6 26, 8 30 L 20 30 C 22 26, 21 20, 14 14 Z" style="fill: var(--color-aurora); fill-opacity: .55; stroke: var(--color-ink)" stroke-width="1.3" stroke-linejoin="round" />
                                        <path d="M28 36 V27 M28 6 C 20 13, 19 21, 21 27 L 35 27 C 37 21, 36 13, 28 6 Z" style="fill: var(--color-aurora); fill-opacity: .7; stroke: var(--color-ink)" stroke-width="1.3" stroke-linejoin="round" />
                                @endswitch
                                <path d="M3 36.5 C 14 35.6, 26 37.2, 37 36.2" fill="none" style="stroke: var(--color-ink)" stroke-width="1.5" stroke-linecap="round" />
                            </svg>
                            <span class="energy-choice-name">{{ $label }}</span>
                        </button>
                    @endforeach
                </div>
            </div>

            {{-- Savings inputs (still optional) --}}
            <!-- Price Input -->
            <div class="energy-row">
                <div class="energy-row-label">
                    <h4 class="energy-row-title">Säästölaskelma</h4>
                    <label for="solar-price" class="energy-row-sub">Sähkön hinta (c/kWh)</label>
                    <span wire:loading wire:target="manualPrice" class="energy-row-status">{!! $spinner !!}</span>
                </div>
                <div class="energy-field energy-field--price">
                    <input
                        id="solar-price"
                        type="number"
                        wire:model.live.blur="manualPrice"
                        min="0"
                        max="50"
                        step="0.1"
                        @if ($manualPriceNotice) aria-invalid="true" aria-describedby="solar-price-notice" @endif
                        class="energy-field-input"
                    >
                    <span class="energy-field-unit" aria-hidden="true">c/kWh</span>
                </div>
                <div class="energy-row-help">
                    @if ($manualPriceNotice)
                        <p id="solar-price-notice" role="alert" class="energy-notice">{{ $manualPriceNotice }}</p>
                    @endif
                    <p>Oletusarvo on viimeisen vuoden spot-hinnan keskiarvo (sis. ALV). Voit muuttaa hinnan sähkösopimuksesi mukaiseksi.</p>
                </div>
            </div>

            <!-- Self-Consumption Scenario Selection -->
            <div class="energy-row energy-row--top energy-row--last">
                <div class="energy-row-label">
                    <h4 class="energy-row-title">Oman käytön osuus</h4>
                    <p class="energy-row-desc">
                        Kuinka suuren osan tuottamastasi sähköstä käytät itse. Loppu myydään verkkoon selvästi halvemmalla, joten mitä suurempi oma käyttö, sitä suurempi säästö.
                    </p>
                    <span wire:loading wire:target="selfConsumptionScenario" class="energy-row-status">{!! $spinner !!}</span>
                </div>
                <div class="energy-choices">
                    @foreach ($selfConsumptionScenarios as $scenario => $data)
                        @php $isScenario = $selfConsumptionScenario === $scenario; @endphp
                        <button
                            wire:click="$set('selfConsumptionScenario', '{{ $scenario }}')"
                            wire:loading.attr="disabled"
                            wire:loading.class="opacity-50"
                            wire:target="selfConsumptionScenario"
                            aria-pressed="{{ $isScenario ? 'true' : 'false' }}"
                            @class(['energy-choice energy-choice--share vk-focus', 'is-selected' => $isScenario])
                        >
                            {!! $radio($isScenario) !!}
                            <span class="flex flex-col gap-0.5">
                                <span class="energy-share">{{ $data['percent'] }}%</span>
                                <span class="energy-choice-name">{{ $data['label'] }}</span>
                                @if ($isScenario)
                                    <span class="energy-choice-desc">{{ $data['description'] }}</span>
                                @endif
                            </span>
                        </button>
                    @endforeach
                </div>
            </div>

            <!-- Error Message -->
            @if ($errorMessage)
                <p class="energy-error" role="alert"><span aria-hidden="true">!</span> {{ $errorMessage }}</p>
            @endif
        </section>

        <!-- Results Section -->
        @if ($this->hasResults)
            @include('livewire.partials.solar-result', [
                'annualKwh' => $this->annualKwh,
                'monthlyKwh' => $this->monthlyKwh,
                'systemKwp' => $this->effectiveSystemKwp,
                'monthNamesFull' => $monthNamesFull,
                'assumptions' => $calculationResult['assumptions'] ?? [],
                'showSavings' => $this->hasSavings,
                'selfConsumptionPercent' => $this->selfConsumptionPercent,
                'selfConsumptionLabel' => $this->selfConsumptionLabel,
                'effectivePrice' => $this->effectivePrice,
                'annualSavings' => $this->annualSavings,
                'live' => true,
                'isExample' => false,
            ])
        @elseif ($isCalculating)
            <section class="energy-calculating" aria-live="polite">
                {!! $spinner !!}
                <span>Lasketaan...</span>
            </section>
        @elseif ($this->hasExample && !$selectedLabel)
            {{-- Default-location worked example so first-time visitors see the payoff
                 before committing their own address. --}}
            @include('livewire.partials.solar-result', [
                'annualKwh' => $this->exampleAnnualKwh,
                'monthlyKwh' => $this->exampleMonthlyKwh,
                'systemKwp' => $this->effectiveSystemKwp,
                'monthNamesFull' => $monthNamesFull,
                'assumptions' => $exampleResult['assumptions'] ?? [],
                'showSavings' => $this->effectivePrice !== null && $this->effectivePrice > 0,
                'selfConsumptionPercent' => $this->selfConsumptionPercent,
                'selfConsumptionLabel' => $this->selfConsumptionLabel,
                'effectivePrice' => $this->effectivePrice,
                'annualSavings' => $this->exampleAnnualSavings,
                'live' => false,
                'isExample' => true,
            ])
        @endif

        <x-site.spot-paw-divider />

        <!-- Info Section -->
        <section class="energy-info">
            <div class="energy-info-head">
                <x-site.section-heading :level="3" :wave="230">Tietoa laskurista</x-site.section-heading>
            </div>
            <ul class="energy-info-list">
                @foreach ([
                    'Tuottoarvio perustuu PVGIS-tietokantaan (EU Joint Research Centre)',
                    'Tyypillinen suomalainen aurinkopaneelijärjestelmä tuottaa 800–1000 kWh/kWp vuodessa',
                    'Tuotanto vaihtelee huomattavasti vuodenajan mukaan: kesällä jopa 10× enemmän kuin talvella',
                    'Optimi kaltevuus Suomessa on noin 40–45° ja suuntaus etelään',
                    'Varjostus, lumi ja pöly vähentävät todellista tuottoa arviosta',
                ] as $fact)
                    <li>
                        <svg class="energy-sun-mark" viewBox="0 0 18 18" aria-hidden="true" focusable="false"><circle cx="9" cy="9" r="3.6" style="fill: var(--color-star); stroke: var(--color-ink)" stroke-width="1.1" /><path d="M9 1.5v2.5M9 14v2.5M1.5 9h2.5M14 9h2.5" fill="none" style="stroke: var(--color-ink)" stroke-width="1.1" stroke-linecap="round" /></svg>
                        <span>{{ $fact }}</span>
                    </li>
                @endforeach
            </ul>
        </section>

        <x-site.interlude art="solar-interlude" />

        <!-- FAQ Section for SEO -->
        <section class="energy-faq-block">
            <x-site.section-heading :wave="300">Usein kysytyt kysymykset aurinkopaneeleista</x-site.section-heading>

            <div class="spot-faq">
                @foreach ([
                    ['Paljonko aurinkopaneelit tuottavat Suomessa?', 'Suomessa aurinkopaneelit tuottavat tyypillisesti 800–1000 kWh vuodessa jokaista asennettua kilowattipiikkiä (kWp) kohden. Etelä-Suomessa tuotto on hieman korkeampi kuin Pohjois-Suomessa. Esimerkiksi 5 kWp:n järjestelmä tuottaa Helsingissä noin 4500–5000 kWh vuodessa.'],
                    ['Kuinka paljon aurinkopaneelit säästävät sähkölaskussa?', 'Säästö riippuu sähkön hinnasta ja siitä, kuinka suuren osan tuotetusta sähköstä käytät itse. Tyypillisesti kotitalous käyttää itse 20–40% aurinkopaneelien tuottamasta sähköstä. Jos sähkön hinta on 10 c/kWh ja 5 kWp:n järjestelmä tuottaa 4500 kWh vuodessa, 30% omakäytöllä säästö on noin 135 €/vuosi.'],
                    ['Mikä on paras kattokaltevuus aurinkopaneeleille?', 'Suomessa optimaalinen kattokaltevuus aurinkopaneeleille on noin 40–45 astetta etelään suunnattuna. Tämä kaltevuus maksimoi vuosituoton ja auttaa myös lumen valumisessa talvella. Loivemmat tai jyrkemmät kaltevuudet toimivat myös, mutta tuotto voi olla 5–15% pienempi.'],
                    ['Miten varjostus vaikuttaa aurinkopaneelien tuottoon?', 'Varjostus voi vähentää aurinkopaneelien tuottoa merkittävästi. Jo yhden paneelin osittainen varjostus voi vaikuttaa koko sarjan tuottoon. Lähellä olevat puut, rakennukset tai savupiiput voivat aiheuttaa 5–20% tuottotappion. Modernit mikroinvertterit ja optimoijat voivat minimoida varjostuksen vaikutusta.'],
                    ['Kuinka suuri aurinkopaneelijärjestelmä tarvitaan omakotitaloon?', 'Tyypillinen omakotitalon aurinkopaneelijärjestelmä on 5–10 kWp. Järjestelmän koko riippuu sähkönkulutuksesta, käytettävissä olevasta kattopinta-alasta ja budjetista. 1 kWp vaatii noin 5–6 m² kattopinta-alaa. Sähkölämmitteisessä talossa suurempi järjestelmä (8–15 kWp) voi olla järkevä.'],
                    ['Toimivatko aurinkopaneelit talvella Suomessa?', 'Kyllä, aurinkopaneelit toimivat myös talvella, mutta tuotto on huomattavasti pienempi kuin kesällä. Joulukuussa tuotto voi olla vain 5–10% kesäkuun tuotosta. Lumi paneelien päällä estää tuotannon, mutta sopivalla kaltevuudella lumi usein valuu itsestään pois. Paneelit toimivat parhaiten kylmässä – hyötysuhde on jopa parempi pakkasella kuin helteellä.'],
                ] as [$question, $answer])
                    <details class="group spot-faq-row">
                        <summary class="vk-focus flex cursor-pointer list-none items-center justify-between gap-3 py-4 [&::-webkit-details-marker]:hidden">
                            <span class="text-[17px] font-extrabold leading-[22px] text-ink md:text-[18px] md:leading-6">{{ $question }}</span>
                            <svg width="18" height="18" viewBox="0 0 18 18" class="shrink-0 transition-transform group-open:rotate-180 motion-reduce:transition-none" aria-hidden="true" focusable="false"><path d="M4 7l5 5 5-5" fill="none" style="stroke: var(--color-ink)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" /></svg>
                        </summary>
                        <p class="max-w-[680px] pb-4 pt-1 text-[16px] leading-[25px] text-ink-body">{{ $answer }}</p>
                    </details>
                @endforeach
            </div>
        </section>

        <!-- Additional SEO Content -->
        <section class="energy-article">
            <x-site.section-heading :wave="260">Aurinkopaneelit Suomessa {{ date('Y') }}</x-site.section-heading>
            <div class="energy-article-columns">
                <p class="energy-dropcap">
                    Aurinkopaneelit ovat yleistyneet nopeasti Suomessa viime vuosina. Vaikka Suomi sijaitsee pohjoisessa,
                    aurinkopaneelit tuottavat yllättävän hyvin erityisesti keväällä ja kesällä. Pitkät kesäpäivät kompensoivat
                    talven pimeyttä, ja vuosituotto on vertailukelpoinen Keski-Euroopan maiden kanssa.
                </p>
                <p>
                    Aurinkopaneelijärjestelmän kannattavuus riippuu useasta tekijästä: sähkön hinnasta, omakäyttöasteesta,
                    järjestelmän hinnasta ja käytettävissä olevista tuista. Nykyisillä sähkön hinnoilla ja
                    paneelien alhaisemmilla kustannuksilla takaisinmaksuaika on tyypillisesti 8–15 vuotta.
                </p>
                <p>
                    Tämä aurinkopaneelilaskuri käyttää Euroopan komission PVGIS-tietokantaa, joka sisältää tarkat
                    auringonsäteilytiedot kaikille Suomen sijainneille. Laskuri huomioi sijainnin, järjestelmän koon
                    ja varjostuksen vaikutuksen tuottoarvioon.
                </p>
            </div>
        </section>

    </div>
</div>
