<div>
    {{-- Structured data: WebApplication + FAQPage (FAQPage generated from getFaqItemsProperty) --}}
    <x-schema-markup :schemas="[$jsonLd, $faqJsonLd]" />

    @php
        $fmt = fn ($value, int $decimals = 0) => number_format((float) $value, $decimals, ',', ' ');
        // Drawn radio mark (design spec §5: the selected radio is fox with a white check).
        $radio = fn (bool $on) => $on
            ? '<svg class="energy-radio" viewBox="0 0 22 22" aria-hidden="true" focusable="false"><circle cx="11" cy="11" r="9" style="fill: var(--color-fox)"/><path d="M6.5 11.2l3 3 6-6.4" fill="none" style="stroke: var(--color-snow-white)" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/></svg>'
            : '<svg class="energy-radio" viewBox="0 0 22 22" aria-hidden="true" focusable="false"><circle cx="11" cy="11" r="9" style="fill: var(--color-snow-white); stroke: var(--color-field-edge)" stroke-width="2"/></svg>';
        $chevron = '<svg width="16" height="16" viewBox="0 0 16 16" aria-hidden="true" focusable="false"><path d="M3.5 6l4.5 4.5L12.5 6" fill="none" style="stroke: var(--color-ink)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>';
    @endphp

    {{-- Hero: the fox warms its paws at the heat pump in the scene (Paper board C1D-0). --}}
    <x-site.energy-hero art="heatpump-hero" variant="heatpump" :tone="\App\Support\Season::heroTone('heatpump-hero')">
        <div class="energy-hero-copy energy-hero-copy--heatpump">
            <h1 class="energy-hero-h1">Kannattaako lämpöpumppu?</h1>
            <p class="energy-hero-lead">
                Laske ilmaisella laskurilla, kannattaako maalämpö vai ilma-vesilämpöpumppu juuri sinun talossasi, ja paljonko säästät vuodessa.
            </p>
        </div>
    </x-site.energy-hero>

    <x-page-action-strip />

    <div class="vk-wrap energy-page energy-page--heatpump">

        <!-- Introduction -->
        <p class="energy-intro">
            Lämpöpumppulaskuri auttaa sinua vertailemaan eri lämpöpumppuvaihtoehtoja ja arvioimaan, paljonko voisit säästää lämmityskustannuksissa. Syötä rakennuksesi tiedot ja nykyinen lämmitystapa.
        </p>

        <!-- Calculator: inputs at the left, the answer at the right (no panel) -->
        <div @class(['energy-calculator', 'has-results' => $this->hasResults])>
            <section class="energy-inputs" aria-label="Laskurin tiedot">

                <!-- Building Info -->
                <div class="energy-group">
                    <h4 class="energy-group-title">Rakennuksen tiedot</h4>
                    <div class="energy-grid">
                        <x-site.energy-field id="hp-living-area" label="Pinta-ala (m²)" model="livingArea" unit="m²" min="20" max="500" />
                        <x-site.energy-field id="hp-room-height" label="Huonekorkeus (m)" model="roomHeight" unit="m" min="2.0" max="4.0" step="0.1" />
                        <div class="energy-labelled energy-labelled--lg">
                            <label for="hp-region" class="energy-label">Sijainti</label>
                            <div class="energy-field energy-field--select">
                                <select id="hp-region" wire:model.live="buildingRegion" class="energy-select">
                                    @foreach ($regionLabels as $value => $label)
                                        <option value="{{ $value }}">{{ $label }}</option>
                                    @endforeach
                                </select>
                                {!! $chevron !!}
                            </div>
                        </div>
                        <div class="energy-labelled energy-labelled--lg">
                            <label for="hp-building-age" class="energy-label">Rakennusvuosi / energiatehokkuus</label>
                            <div class="energy-field energy-field--select">
                                <select id="hp-building-age" wire:model.live="buildingEnergyEfficiency" class="energy-select">
                                    @foreach ($buildingAgeLabels as $value => $label)
                                        <option value="{{ $value }}">{{ $label }}</option>
                                    @endforeach
                                </select>
                                {!! $chevron !!}
                            </div>
                        </div>
                        <x-site.energy-field id="hp-people" label="Asukkaiden määrä" model="numPeople" unit="hlö" min="1" max="10" />
                    </div>
                </div>

                <!-- Input Mode Toggle -->
                <div class="energy-group">
                    <h4 class="energy-group-title">Energiankulutuksen määritys</h4>
                    <div class="energy-modes">
                        @foreach ([
                            'model_based' => ['Laskettu kulutus', 'Rakennustietojen perusteella'],
                            'bill_based' => ['Toteutunut kulutus', 'Laskun tai mittarilukeman perusteella'],
                        ] as $mode => [$modeName, $modeHint])
                            @php $isMode = $inputMode === $mode; @endphp
                            <button
                                wire:click="$set('inputMode', '{{ $mode }}')"
                                aria-pressed="{{ $isMode ? 'true' : 'false' }}"
                                @class(['energy-mode vk-focus', 'is-selected' => $isMode])
                            >
                                {!! $radio($isMode) !!}
                                <span class="flex flex-col">
                                    <span class="energy-mode-name">{{ $modeName }}</span>
                                    <span class="energy-mode-hint">{{ $modeHint }}</span>
                                </span>
                            </button>
                        @endforeach
                    </div>
                </div>

                <!-- Current Heating Method -->
                <div class="energy-group">
                    <h4 class="energy-group-title">Nykyinen lämmitystapa</h4>
                    <div class="energy-heating">
                        @foreach ($heatingMethodLabels as $value => $label)
                            @php $isHeating = $currentHeatingMethod === $value; @endphp
                            <button
                                wire:click="$set('currentHeatingMethod', '{{ $value }}')"
                                aria-pressed="{{ $isHeating ? 'true' : 'false' }}"
                                @class(['energy-heating-option vk-focus', 'is-selected' => $isHeating])
                            >
                                <svg width="40" height="40" viewBox="0 0 40 40" aria-hidden="true" focusable="false">
                                    @switch($value)
                                        @case('electricity')
                                            <rect x="7" y="9" width="26" height="22" rx="3" style="fill: var(--color-fox); opacity: .3" />
                                            <path d="M6 9.5 C 15 8.8, 25 9.4, 34 9 L 34 31 C 25 31.4, 15 30.8, 6 31.2 Z M11 13 V27 M16 13 V27 M21 13 V27 M26 13 V27 M30 13 V27" fill="none" style="stroke: var(--color-ink)" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" />
                                            <path d="M14 35 H26" fill="none" style="stroke: var(--color-ink)" stroke-width="1.6" stroke-linecap="round" />
                                            @break
                                        @case('oil')
                                            <path d="M20 5 C 25 13, 31 19, 31 25.5 C 31 31.5, 26 35.5, 20 35.5 C 14 35.5, 9 31.5, 9 25.5 C 9 19, 15 13, 20 5 Z" style="fill: var(--color-granite); opacity: .3" />
                                            <path d="M20 4.5 C 25.5 12.8, 31.5 18.8, 31.4 25.6 C 31.3 31.8, 26.2 36, 20 35.9 C 13.8 35.8, 8.8 31.6, 8.7 25.5 C 8.6 18.9, 14.6 12.7, 20 4.5 Z" fill="none" style="stroke: var(--color-ink)" stroke-width="1.6" />
                                            <path d="M14 27 C 14.5 30, 16.5 31.5, 19 31.8" fill="none" style="stroke: var(--color-ink)" stroke-width="1.4" stroke-linecap="round" />
                                            @break
                                        @default
                                            <path d="M4 26 H14 C 17 26, 18 24, 18 21 V12 C 18 10, 19.5 9, 21 9 H36" fill="none" style="stroke: var(--color-lake); opacity: .35" stroke-width="5" stroke-linecap="round" />
                                            <path d="M4 26.5 H14 C 17.2 26.4, 18.4 24.2, 18.3 21 V12 C 18.4 9.8, 19.8 8.6, 21.4 8.7 H36" fill="none" style="stroke: var(--color-ink)" stroke-width="1.6" stroke-linecap="round" />
                                            <path d="M26 31 C 27 29, 25 27.5, 26 25.5 M31 31 C 32 29, 30 27.5, 31 25.5" fill="none" style="stroke: var(--color-fox)" stroke-width="1.6" stroke-linecap="round" />
                                    @endswitch
                                </svg>
                                <span class="energy-heating-name">{{ $label }}</span>
                            </button>
                        @endforeach
                    </div>

                    <!-- Bill-based consumption input -->
                    @if ($inputMode === 'bill_based')
                        <div class="energy-bill">
                            @if ($currentHeatingMethod === 'oil')
                                <x-site.energy-field id="hp-oil-liters" label="Öljynkulutus (litraa/vuosi)" model="oilLitersPerYear" unit="l/v" min="0" max="10000" placeholder="esim. 2000" />
                                <p class="energy-hint">Syötä vuotuinen öljynkulutus litroina</p>
                            @elseif ($currentHeatingMethod === 'electricity')
                                <x-site.energy-field id="hp-electricity-kwh" label="Sähkönkulutus (kWh/vuosi)" model="electricityKwhPerYear" unit="kWh/v" min="0" max="100000" placeholder="esim. 20000" />
                                <p class="energy-hint">Syötä vuotuinen sähkönkulutus (lämmitys + käyttösähkö)</p>
                            @elseif ($currentHeatingMethod === 'district_heating')
                                <x-site.energy-field id="hp-district-euros" label="Kaukolämpölasku (euroa/vuosi)" model="districtHeatingEurosPerYear" unit="€/v" min="0" max="20000" placeholder="esim. 1500" />
                                <p class="energy-hint">Syötä vuotuinen kaukolämpölasku euroina (energia, ei perusmaksua)</p>
                            @endif
                        </div>
                    @endif
                </div>

                <!-- Advanced Settings Accordion -->
                <div class="energy-advanced">
                    <button
                        wire:click="toggleAdvancedSettings"
                        aria-expanded="{{ $showAdvancedSettings ? 'true' : 'false' }}"
                        class="energy-advanced-trigger vk-focus"
                    >
                        <span>Lisäasetukset</span>
                        <svg width="16" height="16" viewBox="0 0 16 16" @class(['transition-transform motion-reduce:transition-none', 'rotate-180' => $showAdvancedSettings]) aria-hidden="true" focusable="false"><path d="M3.5 6l4.5 4.5L12.5 6" fill="none" style="stroke: var(--color-ink)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" /></svg>
                    </button>

                    @if ($showAdvancedSettings)
                        <div class="energy-advanced-panel">
                            <!-- Prices -->
                            <div>
                                <h5 class="energy-subgroup-title">Energian hinnat</h5>
                                <div class="energy-grid">
                                    <x-site.energy-field size="sm" id="hp-electricity-price" label="Sähkön hinta (c/kWh)" model="electricityPrice" min="1" max="50" step="0.1" />
                                    <x-site.energy-field size="sm" id="hp-oil-price" label="Öljyn hinta (€/litra)" model="oilPrice" min="0.5" max="3" step="0.01" />
                                    <x-site.energy-field size="sm" id="hp-district-price" label="Kaukolämmön hinta (c/kWh)" model="districtHeatingPrice" min="1" max="30" step="0.1" />
                                    <x-site.energy-field size="sm" id="hp-pellet-price" label="Pelletin hinta (€/tonni)" model="pelletPrice" min="100" max="1000" step="10" />
                                </div>
                            </div>

                            <!-- Investments -->
                            <div>
                                <h5 class="energy-subgroup-title">Investointikustannukset (€)</h5>
                                <div class="energy-grid">
                                    @php
                                        $investmentLabels = [
                                            'ground_source_hp' => 'Maalämpöpumppu',
                                            'air_to_water_hp' => 'Ilma-vesilämpöpumppu',
                                            'air_to_air_hp' => 'Ilmalämpöpumppu',
                                            'exhaust_air_hp' => 'Poistoilmalämpöpumppu',
                                            'ilp_fireplace' => 'Ilmalämpöpumppu + tulisija',
                                            'exhaust_air_hp_fireplace' => 'Poistoilmalämpöpumppu + tulisija',
                                            'pellets' => 'Pellettikattila',
                                        ];
                                    @endphp
                                    @foreach ($investmentLabels as $key => $label)
                                        <x-site.energy-field size="sm" :id="'hp-investment-'.$key" :label="$label" :model="'investments.'.$key" min="0" max="50000" step="100" />
                                    @endforeach
                                </div>
                            </div>

                            <!-- Financial parameters -->
                            <div>
                                <h5 class="energy-subgroup-title">Taloudelliset parametrit</h5>
                                <div class="energy-grid">
                                    <x-site.energy-field size="sm" id="hp-interest" label="Laskentakorko (%)" model="interestRate" min="0" max="10" step="0.1" />
                                    <x-site.energy-field size="sm" id="hp-period" label="Laskentajakso (vuotta)" model="calculationPeriod" min="5" max="30" />
                                </div>
                            </div>
                        </div>
                    @endif
                </div>

                <!-- Error Message -->
                @if ($errorMessage)
                    <p class="energy-error" role="alert"><span aria-hidden="true">!</span> {{ $errorMessage }}</p>
                @endif
            </section>

            @if ($this->hasResults)
                @php
                    $recommended = $this->recommendedAlternative;
                    $needTotal = max(1, $this->totalEnergyNeed);
                    $heatingShare = round($this->heatingEnergyNeed / $needTotal * 100, 2);
                @endphp
                <section class="energy-answer" wire:loading.delay.class="opacity-50" aria-label="Laskelman tulos">
                    <!-- Energy Need Summary -->
                    <div class="energy-need">
                        <h3 class="energy-caps">Arvioitu lämmitysenergian tarve</h3>
                        <p class="energy-need-total">
                            <span class="energy-need-number">{{ $fmt($this->totalEnergyNeed) }}</span>
                            <span class="energy-need-unit">kWh yhteensä</span>
                        </p>
                        <div class="energy-need-bar" aria-hidden="true">
                            <span class="energy-need-heating" style="width: {{ $heatingShare }}%"></span>
                            <span class="energy-need-water"></span>
                        </div>
                        <dl class="energy-need-parts">
                            <div>
                                <dd class="energy-need-part energy-need-part--heating">{{ $fmt($this->heatingEnergyNeed) }}</dd>
                                <dt>kWh tilojen lämmitys</dt>
                            </div>
                            <div>
                                <dd class="energy-need-part energy-need-part--water">{{ $fmt($this->hotWaterEnergyNeed) }}</dd>
                                <dt>kWh käyttövesi</dt>
                            </div>
                        </dl>
                    </div>

                    <!-- The answer: recommended option -->
                    @if ($recommended)
                        <div class="energy-recommendation">
                            <p class="energy-caps energy-caps--good">
                                <x-ink.sparkle size="12" color="var(--color-aurora)" />
                                Suositeltu vaihtoehto talollesi
                            </p>
                            <h3 class="energy-recommendation-name">{{ $recommended['label'] }}</h3>
                            <div class="energy-recommendation-figures">
                                <div>
                                    <p class="energy-small-label">Säästät vuodessa</p>
                                    <p class="energy-recommendation-saving">{{ $fmt($recommended['annualSavings']) }} €</p>
                                    <x-ink.wave :width="200" stroke="3" color="var(--color-aurora)" class="max-w-full" />
                                </div>
                                <div class="pb-3.5">
                                    <p class="energy-small-label">Takaisinmaksuaika</p>
                                    <p class="energy-recommendation-figure">{{ $recommended['paybackYears'] ? number_format($recommended['paybackYears'], 1, ',', ' ') . ' v' : '–' }}</p>
                                </div>
                                <div class="pb-3.5">
                                    <p class="energy-small-label">Investointi</p>
                                    <p class="energy-recommendation-figure">{{ $fmt($recommended['investment']) }} €</p>
                                </div>
                            </div>
                            <p class="energy-recommendation-why">
                                Valitsimme tämän, koska sillä on edullisin kokonaiskustannus (energiakustannukset + investoinnin annuiteetti {{ $calculationPeriod }} vuodelle) niistä täysimittaisista lämmitysratkaisuista, jotka tuottavat säästöä nykyiseen verrattuna ({{ $this->currentSystem['label'] }}, {{ $fmt($this->currentSystem['annualCost']) }} €/v).
                            </p>
                            <p class="energy-disclaimer">
                                Luvut ovat suuntaa-antavia arvioita, eivät tae toteutuvista säästöistä eivätkä sijoitusneuvontaa. Säästö ja takaisinmaksuaika riippuvat energian hintojen kehityksestä, käyttötottumuksista ja asennuksesta — tarkan arvion saat pyytämällä tarjouksen lämpöpumppuasentajalta. Käytetyt hyötysuhteet (SPF), investointihinnat ja korko näkyvät lisäasetuksissa ja ovat muokattavissa.
                            </p>
                        </div>
                    @else
                        <div class="energy-recommendation">
                            <h3 class="energy-recommendation-none">Täysi lämmitysvaihto ei tuota säästöä näillä tiedoilla</h3>
                            <p class="energy-recommendation-why">
                                Maalämpö, ilma-vesilämpöpumppu tai pelletti ei tällä erää alita nykyisen lämmityksen kokonaiskustannusta {{ $calculationPeriod }} vuoden tarkastelujaksolla. Voit tarkistaa energian hinnat ja investoinnit lisäasetuksista.
                            </p>
                        </div>
                    @endif
                </section>
            @endif
        </div>

        <!-- Quiet, non-blocking recalculation status -->
        <div wire:loading.delay class="energy-updating" role="status">
            <svg class="energy-spinner" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><circle cx="12" cy="12" r="9" fill="none" stroke="currentColor" stroke-width="3" opacity=".25"/><path d="M21 12a9 9 0 0 0-9-9" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round"/></svg>
            Päivitetään laskelmaa…
        </div>

        <!-- Results Section -->
        @if ($this->hasResults)
            @php
                // Only full-replacement primary systems are shown (see HeatPumpCalculator::alternatives()).
                $recommendedKey = $recommended['key'] ?? null;
                $primary = collect($this->alternatives)
                    ->sortBy(fn($alt) => $alt['annualizedTotalCost'] ?? PHP_INT_MAX)
                    ->values();
                $currentCost = (float) ($this->currentSystem['annualCost'] ?? 0);
                $costScale = max($currentCost, (float) $primary->max('annualizedTotalCost'), 1);
            @endphp

            <x-site.spot-paw-divider />

            <div wire:loading.delay.class="opacity-50" class="transition-opacity duration-200 motion-reduce:transition-none">
                <!-- Primary Heating Options (full replacement) -->
                <section class="energy-options">
                    <div class="energy-options-head">
                        <x-site.section-heading :level="3" :wave="260">Lämmitysratkaisut</x-site.section-heading>

                        <!-- Current system - the baseline the options are compared with -->
                        <dl class="energy-current">
                            <div>
                                <dt class="energy-caps">Nykyinen järjestelmä</dt>
                                <dd class="energy-current-name">{{ $this->currentSystem['label'] }}</dd>
                            </div>
                            <div>
                                <dt class="energy-small-label">Energiakustannus/v</dt>
                                <dd class="energy-current-figure energy-current-figure--cost">{{ $fmt($this->currentSystem['annualCost']) }} €</dd>
                            </div>
                            <div>
                                <dt class="energy-small-label">CO₂-päästöt</dt>
                                <dd class="energy-current-figure">{{ $fmt($this->currentSystem['co2KgPerYear']) }} kg</dd>
                            </div>
                        </dl>
                    </div>

                    @if ($primary->isNotEmpty())
                        <p class="energy-options-intro">Nämä järjestelmät korvaavat nykyisen lämmityksen kokonaan tai lähes kokonaan. Järjestys halvimman kokonaiskustannuksen mukaan.</p>

                        {{-- Total cost per year: energy + investment share, against the current cost. The longest bar
                             is 600 of the 640px lane (93.75%, board scale). --}}
                        <div class="energy-totals" aria-hidden="true">
                            @foreach ($primary as $alt)
                                @php
                                    $energyPart = max(0, (float) $alt['annualCost']);
                                    $totalPart = max($energyPart, (float) $alt['annualizedTotalCost']);
                                @endphp
                                <div class="energy-totals-row">
                                    <span class="energy-totals-name">{{ $alt['label'] }}</span>
                                    <span class="energy-totals-track">
                                        <span class="energy-totals-bar" style="width: {{ round($totalPart / $costScale * 93.75, 2) }}%">
                                            <span class="energy-totals-energy" style="width: {{ $totalPart > 0 ? round($energyPart / $totalPart * 100, 2) : 0 }}%"></span>
                                        </span>
                                        <span class="energy-totals-current" style="left: {{ round($currentCost / $costScale * 93.75, 2) }}%"></span>
                                    </span>
                                    <span class="energy-totals-value">{{ $fmt($alt['annualizedTotalCost']) }} €</span>
                                    <span class="energy-totals-tag">
                                        @if ($alt['key'] === $recommendedKey)
                                            <x-ink.sparkle size="13" color="var(--color-aurora)" /> suositeltu
                                        @endif
                                    </span>
                                </div>
                            @endforeach
                            <div class="energy-totals-legend">
                                <span><i class="energy-swatch energy-swatch--energy"></i>Energiakustannus/v</span>
                                <span><i class="energy-swatch energy-swatch--investment"></i>Investoinnin osuus/v</span>
                                <span><i class="energy-swatch energy-swatch--current"></i>Nykyinen {{ $fmt($currentCost) }} €/v</span>
                            </div>
                        </div>

                        <ol class="energy-alternatives">
                            @foreach ($primary as $alt)
                                @include('livewire.partials.heat-pump-alternative-card', ['alt' => $alt, 'isRecommended' => $alt['key'] === $recommendedKey, 'rank' => $loop->iteration])
                            @endforeach
                        </ol>
                    @endif

                    <p class="energy-footnote">* Kokonaiskustannus sisältää energiakustannukset (sähkö tai pelletti) ja investoinnin annuiteetin {{ $calculationPeriod }} vuodelle {{ number_format($interestRate, 1, ',', ' ') }}% korolla.</p>
                </section>
            </div>

            {{-- "Löysitkö etsimäsi?" only after a result. --}}
            <x-page-feedback page-type="heat_pump_calculator" />
        @endif

        <!-- Info Section -->
        <section class="energy-notes">
            <div class="energy-notes-col">
                <h3 class="energy-notes-title">Tietoa laskurista</h3>
                <ul class="energy-notes-list">
                    @foreach ([
                        'Lämmitysenergian tarve perustuu rakennuksen tilavuuteen ja energiatehokkuuteen',
                        'Lämpöpumppujen hyötysuhteet (SPF/COP) ovat tyypillisiä suomalaisissa olosuhteissa',
                        'Investointikustannukset ovat keskimääräisiä avaimet käteen -hintoja',
                        'Todellinen säästö riippuu käyttötottumuksista ja energian hintojen kehityksestä',
                    ] as $note)
                        <li><x-ink.sparkle size="12" color="var(--color-fox)" class="mt-[6px]" /><span>{{ $note }}</span></li>
                    @endforeach
                </ul>
            </div>

            <div class="energy-notes-col energy-notes-col--co2">
                <h4 class="energy-notes-title">CO₂-päästöjen laskenta</h4>
                <p>Päästöt lasketaan vuositasolla käyttäen seuraavia päästökertoimia:</p>
                <ul class="energy-factors">
                    <li>
                        <span class="energy-factor-name">Sähkö</span>
                        <span class="energy-factor-bar" style="width: {{ round(80 / 267 * 138) }}px" aria-hidden="true"></span>
                        <span class="energy-factor-text"><strong>80 g/kWh</strong> – Suomen sähköverkon keskiarvo 2024 (<a href="https://www.fingrid.fi/en/electricity-market-information/real-time-co2-emissions-estimate/" target="_blank" rel="noopener" class="energy-link vk-focus">Fingrid</a>)</span>
                    </li>
                    <li>
                        <span class="energy-factor-name">Kaukolämpö</span>
                        <span class="energy-factor-bar" style="width: {{ round(130 / 267 * 138) }}px" aria-hidden="true"></span>
                        <span class="energy-factor-text"><strong>130 g/kWh</strong> – kolmen vuoden keskiarvo 2021–2023 (<a href="https://www.motiva.fi/ratkaisut/energiankaytto_suomessa/co2-paastokertoimet" target="_blank" rel="noopener" class="energy-link vk-focus">Motiva</a>)</span>
                    </li>
                    <li>
                        <span class="energy-factor-name">Öljy</span>
                        <span class="energy-factor-bar" style="width: 138px" aria-hidden="true"></span>
                        <span class="energy-factor-text"><strong>267 g/kWh</strong> – polttoaineen päästökerroin</span>
                    </li>
                    <li>
                        <span class="energy-factor-name">Pelletit ja polttopuu</span>
                        <span class="energy-factor-bar" style="width: {{ round(30 / 267 * 138) }}px" aria-hidden="true"></span>
                        <span class="energy-factor-text"><strong>30 g/kWh</strong> – biogeeninen hiili lasketaan hiilineutraaliksi, luku kattaa vain tuotannon ja kuljetuksen</span>
                    </li>
                </ul>
                <p class="energy-notes-foot">Suomen sähköntuotanto on erittäin vähäpäästöistä ydinvoiman (~40%) ja uusiutuvien (~52%) ansiosta. Jos sinulla on 100% uusiutuva sähkösopimus, lämpöpumppujen todelliset päästöt ovat vielä pienemmät.</p>
            </div>
        </section>

        <x-site.interlude art="heatpump-interlude" />

        <div class="energy-faq-related">
            <!-- FAQ Section for SEO (visible FAQ + FAQPage JSON-LD share getFaqItemsProperty) -->
            <section class="energy-faq-block energy-faq-block--narrow">
                <x-site.section-heading size="compact" :wave="300">Usein kysytyt kysymykset lämpöpumpuista</x-site.section-heading>

                <div class="spot-faq">
                    @foreach ($this->faqItems as $faq)
                        <details class="group spot-faq-row">
                            <summary class="vk-focus flex cursor-pointer list-none items-center justify-between gap-3 py-4 [&::-webkit-details-marker]:hidden">
                                <span class="text-[17px] font-extrabold leading-[22px] text-ink md:text-[18px] md:leading-6">{{ $faq['question'] }}</span>
                                <svg width="18" height="18" viewBox="0 0 18 18" class="shrink-0 transition-transform group-open:rotate-180 motion-reduce:transition-none" aria-hidden="true" focusable="false"><path d="M4 7l5 5 5-5" fill="none" style="stroke: var(--color-ink)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" /></svg>
                            </summary>
                            <p class="max-w-[680px] pb-4 pt-1 text-[16px] leading-[25px] text-ink-body">{{ $faq['answer'] }}</p>
                        </details>
                    @endforeach
                </div>
            </section>

            <!-- Related links -->
            <section class="energy-related">
                <h2 class="energy-related-title">Aiheeseen liittyvää</h2>
                <ul>
                    <li><a href="{{ route('spot-price') }}" class="energy-related-link vk-focus">Pörssisähkön hinta tänään ja huomenna →</a></li>
                    <li><a href="{{ route('calculator') }}" class="energy-related-link vk-focus">Sähkönkulutuslaskuri: arvioi talosi vuosikulutus →</a></li>
                    <li><a href="{{ route('sahkosopimus.index') }}" class="energy-related-link vk-focus">Vertaile sähkösopimuksia →</a></li>
                    <li><a href="{{ route('solar.calculator') }}" class="energy-related-link vk-focus">Aurinkopaneelilaskuri →</a></li>
                </ul>
            </section>
        </div>

        <!-- Additional SEO Content -->
        <section class="energy-longread">
            <x-site.section-heading :wave="320">Kannattaako lämpöpumppu? {{ date('Y') }}</x-site.section-heading>
            <p>
                Lämpöpumppu kannattaa useimmiten silloin, kun se korvaa suoran sähkölämmityksen tai
                öljylämmityksen. Lämpöpumppu siirtää lämpöä ulkoilmasta, maaperästä tai poistoilmasta taloon
                ja tuottaa yhdellä sähkökilowattitunnilla kaksi tai kolme kilowattituntia lämpöä. Kannattavuus
                riippuu talon koosta, nykyisestä lämmitystavasta, investoinnin hinnasta ja energian hinnoista,
                joten paras tapa selvittää asia on laskea oma tapauksesi yllä olevalla laskurilla.
            </p>

            <h3>Kannattaako maalämpö?</h3>
            <p>
                Maalämpö on energiatehokkain vaihtoehto. Vuotuinen hyötysuhde (SPF) on Suomen olosuhteissa
                tyypillisesti noin 2,9, eli se tuottaa lähes kolme kilowattituntia lämpöä jokaista
                sähkökilowattituntia kohden.
            </p>
            <p>
                Investointi on suurin, usein luokkaa 20 000 euroa, joten maalämpö kannattaa parhaiten
                suuremmissa ja paljon lämmitettävissä taloissa. Pienemmässä talossa edullisempi
                ilma-vesilämpöpumppu voi tulla kokonaiskustannukseltaan halvemmaksi.
            </p>

            <h3>Kannattaako ilma-vesilämpöpumppu?</h3>
            <p>
                Ilma-vesilämpöpumppu sopii vesikiertoiseen lämmitykseen ja maksaa selvästi vähemmän kuin
                maalämpö, tyypillisesti noin 12 000 euroa. Sen vuotuinen hyötysuhde (SPF) on noin 2,3, ja
                se kattaa lämmöntarpeesta noin 80 prosenttia.
            </p>
            <p>
                Loput noin 20 prosenttia tuotetaan yleensä suoralla sähköllä, etenkin kovilla pakkasilla.
                Kohtalaisesti lämmitettävässä talossa ilma-vesilämpöpumppu on usein kokonaiskustannukseltaan
                kannattavin ratkaisu.
            </p>

            <h3>Milloin lämpöpumppu ei kannata?</h3>
            <p>
                Kaukolämmön korvaaminen lämpöpumpulla on harvoin taloudellisesti kannattavaa, koska
                kaukolämpö on jo valmiiksi edullista energiaa. Myös hyvin pienen lämmitystarpeen taloissa
                suuri investointi voi maksaa itsensä takaisin hitaasti.
            </p>
            <p>
                Tämä laskuri huomioi talon koon, sijainnin, nykyisen lämmitystavan ja energian hinnat, ja
                kertoo suoraan, jos täysi lämmitysvaihto ei tuota säästöä sinun tiedoillasi. Tulokset ovat
                suuntaa antavia; tarkemman arvion saat pyytämällä tarjouksen lämpöpumppuasentajalta.
            </p>
        </section>

    </div>
</div>
