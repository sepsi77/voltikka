<div>
    {{-- Structured data: WebApplication + FAQPage (FAQPage generated from getFaqItemsProperty) --}}
    <x-schema-markup :schemas="[$jsonLd, $faqJsonLd]" />

    <!-- Hero Section - Dark slate background -->
    <section class="bg-slate-950 -mx-4 sm:-mx-6 lg:-mx-8 mb-8">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="py-12 lg:py-16 text-center">
                <h1 class="text-3xl md:text-4xl xl:text-5xl font-extrabold text-white tracking-tight leading-none mb-4">
                    Kannattaako <span class="text-coral-400">lämpöpumppu?</span>
                </h1>
                <p class="max-w-2xl mx-auto text-slate-300 md:text-lg">
                    Laske ilmaisella laskurilla, kannattaako maalämpö vai ilma-vesilämpöpumppu juuri sinun talossasi, ja paljonko säästät vuodessa.
                </p>
            </div>
        </div>
    </section>

    <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 py-8">

        <!-- Introduction -->
        <div class="mb-8 text-center max-w-2xl mx-auto">
            <p class="text-slate-600">
                Lämpöpumppulaskuri auttaa sinua vertailemaan eri lämpöpumppuvaihtoehtoja ja arvioimaan, paljonko voisit säästää lämmityskustannuksissa. Syötä rakennuksesi tiedot ja nykyinen lämmitystapa.
            </p>
        </div>

        <!-- Calculator Section -->
        <section class="bg-white rounded-2xl shadow-sm border border-slate-100 p-6 mb-8">

            <!-- Building Info -->
            <div class="mb-8">
                <h4 class="font-semibold text-slate-900 mb-4">Rakennuksen tiedot</h4>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1">Pinta-ala (m²)</label>
                        <input
                            type="number"
                            wire:model.blur="livingArea"
                            min="20"
                            max="500"
                            class="w-full px-4 py-3 border border-slate-300 rounded-lg focus:ring-2 focus:ring-coral-500 focus:border-coral-500"
                        >
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1">Huonekorkeus (m)</label>
                        <input
                            type="number"
                            wire:model.blur="roomHeight"
                            min="2.0"
                            max="4.0"
                            step="0.1"
                            class="w-full px-4 py-3 border border-slate-300 rounded-lg focus:ring-2 focus:ring-coral-500 focus:border-coral-500"
                        >
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1">Sijainti</label>
                        <select
                            wire:model.live="buildingRegion"
                            class="w-full px-4 py-3 border border-slate-300 rounded-lg focus:ring-2 focus:ring-coral-500 focus:border-coral-500"
                        >
                            @foreach ($regionLabels as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1">Rakennusvuosi / energiatehokkuus</label>
                        <select
                            wire:model.live="buildingEnergyEfficiency"
                            class="w-full px-4 py-3 border border-slate-300 rounded-lg focus:ring-2 focus:ring-coral-500 focus:border-coral-500"
                        >
                            @foreach ($buildingAgeLabels as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1">Asukkaiden määrä</label>
                        <input
                            type="number"
                            wire:model.blur="numPeople"
                            min="1"
                            max="10"
                            class="w-full px-4 py-3 border border-slate-300 rounded-lg focus:ring-2 focus:ring-coral-500 focus:border-coral-500"
                        >
                    </div>
                </div>
            </div>

            <!-- Input Mode Toggle -->
            <div class="mb-8">
                <h4 class="font-semibold text-slate-900 mb-4">Energiankulutuksen määritys</h4>
                <div class="grid grid-cols-2 gap-4">
                    <button
                        wire:click="$set('inputMode', 'model_based')"
                        class="p-4 border rounded-xl text-center transition-all {{ $inputMode === 'model_based' ? 'border-coral-500 bg-coral-50' : 'border-slate-200 hover:border-slate-300' }}"
                    >
                        <svg class="w-8 h-8 mx-auto mb-2 {{ $inputMode === 'model_based' ? 'text-coral-600' : 'text-slate-500' }}" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 7h6m0 10v-3m-3 3h.01M9 17h.01M9 14h.01M12 14h.01M15 11h.01M12 11h.01M9 11h.01M7 21h10a2 2 0 002-2V5a2 2 0 00-2-2H7a2 2 0 00-2 2v14a2 2 0 002 2z"></path>
                        </svg>
                        <span class="text-sm font-medium {{ $inputMode === 'model_based' ? 'text-coral-700' : 'text-slate-700' }}">Laskettu kulutus</span>
                        <span class="block text-xs {{ $inputMode === 'model_based' ? 'text-coral-600' : 'text-slate-500' }} mt-1">Rakennustietojen perusteella</span>
                    </button>
                    <button
                        wire:click="$set('inputMode', 'bill_based')"
                        class="p-4 border rounded-xl text-center transition-all {{ $inputMode === 'bill_based' ? 'border-coral-500 bg-coral-50' : 'border-slate-200 hover:border-slate-300' }}"
                    >
                        <svg class="w-8 h-8 mx-auto mb-2 {{ $inputMode === 'bill_based' ? 'text-coral-600' : 'text-slate-500' }}" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 14l6-6m-5.5.5h.01m4.99 5h.01M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16l3.5-2 3.5 2 3.5-2 3.5 2z"></path>
                        </svg>
                        <span class="text-sm font-medium {{ $inputMode === 'bill_based' ? 'text-coral-700' : 'text-slate-700' }}">Toteutunut kulutus</span>
                        <span class="block text-xs {{ $inputMode === 'bill_based' ? 'text-coral-600' : 'text-slate-500' }} mt-1">Laskun tai mittarilukeman perusteella</span>
                    </button>
                </div>
            </div>

            <!-- Current Heating Method -->
            <div class="mb-8">
                <h4 class="font-semibold text-slate-900 mb-4">Nykyinen lämmitystapa</h4>
                <div class="grid grid-cols-3 gap-4 mb-4">
                    @php
                        $heatingIcons = [
                            'electricity' => 'M13 10V3L4 14h7v7l9-11h-7z',
                            'oil' => 'M19.428 15.428a2 2 0 00-1.022-.547l-2.387-.477a6 6 0 00-3.86.517l-.318.158a6 6 0 01-3.86.517L6.05 15.21a2 2 0 00-1.806.547M8 4h8l-1 1v5.172a2 2 0 00.586 1.414l5 5c1.26 1.26.367 3.414-1.415 3.414H4.828c-1.782 0-2.674-2.154-1.414-3.414l5-5A2 2 0 009 10.172V5L8 4z',
                            'district_heating' => 'M17.657 18.657A8 8 0 016.343 7.343S7 9 9 10c0-2 .5-5 2.986-7C14 5 16.09 5.777 17.656 7.343A7.975 7.975 0 0120 13a7.975 7.975 0 01-2.343 5.657z',
                        ];
                    @endphp
                    @foreach ($heatingMethodLabels as $value => $label)
                        <button
                            wire:click="$set('currentHeatingMethod', '{{ $value }}')"
                            class="p-4 border rounded-xl text-center transition-all {{ $currentHeatingMethod === $value ? 'border-coral-500 bg-coral-50' : 'border-slate-200 hover:border-slate-300' }}"
                        >
                            <svg class="w-8 h-8 mx-auto mb-2 {{ $currentHeatingMethod === $value ? 'text-coral-600' : 'text-slate-500' }}" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="{{ $heatingIcons[$value] }}"></path>
                            </svg>
                            <span class="text-sm font-medium {{ $currentHeatingMethod === $value ? 'text-coral-700' : 'text-slate-700' }}">{{ $label }}</span>
                        </button>
                    @endforeach
                </div>

                <!-- Bill-based consumption input -->
                @if ($inputMode === 'bill_based')
                    <div class="mt-4 p-4 bg-slate-50 rounded-lg">
                        @if ($currentHeatingMethod === 'oil')
                            <label class="block text-sm font-medium text-slate-700 mb-1">Öljynkulutus (litraa/vuosi)</label>
                            <input
                                type="number"
                                wire:model.blur="oilLitersPerYear"
                                min="0"
                                max="10000"
                                placeholder="esim. 2000"
                                class="w-full px-4 py-3 border border-slate-300 rounded-lg focus:ring-2 focus:ring-coral-500 focus:border-coral-500"
                            >
                            <p class="mt-1 text-sm text-slate-500">Syötä vuotuinen öljynkulutus litroina</p>
                        @elseif ($currentHeatingMethod === 'electricity')
                            <label class="block text-sm font-medium text-slate-700 mb-1">Sähkönkulutus (kWh/vuosi)</label>
                            <input
                                type="number"
                                wire:model.blur="electricityKwhPerYear"
                                min="0"
                                max="100000"
                                placeholder="esim. 20000"
                                class="w-full px-4 py-3 border border-slate-300 rounded-lg focus:ring-2 focus:ring-coral-500 focus:border-coral-500"
                            >
                            <p class="mt-1 text-sm text-slate-500">Syötä vuotuinen sähkönkulutus (lämmitys + käyttösähkö)</p>
                        @elseif ($currentHeatingMethod === 'district_heating')
                            <label class="block text-sm font-medium text-slate-700 mb-1">Kaukolämpölasku (euroa/vuosi)</label>
                            <input
                                type="number"
                                wire:model.blur="districtHeatingEurosPerYear"
                                min="0"
                                max="20000"
                                placeholder="esim. 1500"
                                class="w-full px-4 py-3 border border-slate-300 rounded-lg focus:ring-2 focus:ring-coral-500 focus:border-coral-500"
                            >
                            <p class="mt-1 text-sm text-slate-500">Syötä vuotuinen kaukolämpölasku euroina (energia, ei perusmaksua)</p>
                        @endif
                    </div>
                @endif
            </div>

            <!-- Advanced Settings Accordion -->
            <div class="border-t border-slate-200 pt-6">
                <button
                    wire:click="toggleAdvancedSettings"
                    class="flex items-center justify-between w-full text-left"
                >
                    <span class="font-semibold text-slate-900">Lisäasetukset</span>
                    <svg class="w-5 h-5 text-slate-500 transition-transform {{ $showAdvancedSettings ? 'rotate-180' : '' }}" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                    </svg>
                </button>

                @if ($showAdvancedSettings)
                    <div class="mt-6 space-y-6">
                        <!-- Prices -->
                        <div>
                            <h5 class="text-sm font-medium text-slate-700 mb-3">Energian hinnat</h5>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-sm text-slate-600 mb-1">Sähkön hinta (c/kWh)</label>
                                    <input
                                        type="number"
                                        wire:model.blur="electricityPrice"
                                        min="1"
                                        max="50"
                                        step="0.1"
                                        class="w-full px-3 py-2 text-sm border border-slate-300 rounded-lg focus:ring-2 focus:ring-coral-500 focus:border-coral-500"
                                    >
                                </div>
                                <div>
                                    <label class="block text-sm text-slate-600 mb-1">Öljyn hinta (€/litra)</label>
                                    <input
                                        type="number"
                                        wire:model.blur="oilPrice"
                                        min="0.5"
                                        max="3"
                                        step="0.01"
                                        class="w-full px-3 py-2 text-sm border border-slate-300 rounded-lg focus:ring-2 focus:ring-coral-500 focus:border-coral-500"
                                    >
                                </div>
                                <div>
                                    <label class="block text-sm text-slate-600 mb-1">Kaukolämmön hinta (c/kWh)</label>
                                    <input
                                        type="number"
                                        wire:model.blur="districtHeatingPrice"
                                        min="1"
                                        max="30"
                                        step="0.1"
                                        class="w-full px-3 py-2 text-sm border border-slate-300 rounded-lg focus:ring-2 focus:ring-coral-500 focus:border-coral-500"
                                    >
                                </div>
                                <div>
                                    <label class="block text-sm text-slate-600 mb-1">Pelletin hinta (€/tonni)</label>
                                    <input
                                        type="number"
                                        wire:model.blur="pelletPrice"
                                        min="100"
                                        max="1000"
                                        step="10"
                                        class="w-full px-3 py-2 text-sm border border-slate-300 rounded-lg focus:ring-2 focus:ring-coral-500 focus:border-coral-500"
                                    >
                                </div>
                            </div>
                        </div>

                        <!-- Investments -->
                        <div>
                            <h5 class="text-sm font-medium text-slate-700 mb-3">Investointikustannukset (€)</h5>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
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
                                    <div>
                                        <label class="block text-sm text-slate-600 mb-1">{{ $label }}</label>
                                        <input
                                            type="number"
                                            wire:model.blur="investments.{{ $key }}"
                                            min="0"
                                            max="50000"
                                            step="100"
                                            class="w-full px-3 py-2 text-sm border border-slate-300 rounded-lg focus:ring-2 focus:ring-coral-500 focus:border-coral-500"
                                        >
                                    </div>
                                @endforeach
                            </div>
                        </div>

                        <!-- Financial parameters -->
                        <div>
                            <h5 class="text-sm font-medium text-slate-700 mb-3">Taloudelliset parametrit</h5>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-sm text-slate-600 mb-1">Laskentakorko (%)</label>
                                    <input
                                        type="number"
                                        wire:model.blur="interestRate"
                                        min="0"
                                        max="10"
                                        step="0.1"
                                        class="w-full px-3 py-2 text-sm border border-slate-300 rounded-lg focus:ring-2 focus:ring-coral-500 focus:border-coral-500"
                                    >
                                </div>
                                <div>
                                    <label class="block text-sm text-slate-600 mb-1">Laskentajakso (vuotta)</label>
                                    <input
                                        type="number"
                                        wire:model.blur="calculationPeriod"
                                        min="5"
                                        max="30"
                                        class="w-full px-3 py-2 text-sm border border-slate-300 rounded-lg focus:ring-2 focus:ring-coral-500 focus:border-coral-500"
                                    >
                                </div>
                            </div>
                        </div>
                    </div>
                @endif
            </div>

            <!-- Error Message -->
            @if ($errorMessage)
                <div class="mt-6 p-4 bg-red-50 border border-red-200 rounded-xl text-red-700">
                    {{ $errorMessage }}
                </div>
            @endif

        </section>

        <!-- Quiet, non-blocking recalculation status (replaces the old full-screen overlay) -->
        <div wire:loading.delay class="fixed bottom-4 right-4 z-40 inline-flex items-center gap-2 rounded-full bg-slate-900 text-white text-sm font-medium px-4 py-2 shadow-lg">
            <svg class="animate-spin h-4 w-4 text-coral-400" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
            </svg>
            Päivitetään laskelmaa…
        </div>

        <!-- Results Section -->
        @if ($this->hasResults)
            <div wire:loading.delay.class="opacity-50" class="transition-opacity duration-200 motion-reduce:transition-none">
            <!-- Energy Need Summary - quiet context strip -->
            <section class="mb-8">
                <div class="flex items-center gap-2 mb-3">
                    <h3 class="text-sm uppercase tracking-wide font-semibold text-slate-500">Arvioitu lämmitysenergian tarve</h3>
                </div>
                <dl class="flex flex-wrap gap-x-8 gap-y-2">
                    <div class="flex items-baseline gap-2">
                        <dd class="text-lg font-bold text-slate-900 tabular-nums">{{ number_format($this->heatingEnergyNeed, 0, ',', ' ') }}</dd>
                        <dt class="text-sm text-slate-500">kWh tilojen lämmitys</dt>
                    </div>
                    <div class="flex items-baseline gap-2">
                        <dd class="text-lg font-bold text-slate-900 tabular-nums">{{ number_format($this->hotWaterEnergyNeed, 0, ',', ' ') }}</dd>
                        <dt class="text-sm text-slate-500">kWh käyttövesi</dt>
                    </div>
                    <div class="flex items-baseline gap-2">
                        <dd class="text-lg font-bold text-slate-900 tabular-nums">{{ number_format($this->totalEnergyNeed, 0, ',', ' ') }}</dd>
                        <dt class="text-sm text-slate-500">kWh yhteensä</dt>
                    </div>
                </dl>
            </section>

            @php
                $recommended = $this->recommendedAlternative;
            @endphp

            <!-- The answer: recommended option, the focused dark moment -->
            @if ($recommended)
                <section class="bg-slate-950 rounded-2xl p-8 mb-8">
                    <p class="text-sm uppercase tracking-wide font-semibold text-coral-400">Suositeltu vaihtoehto talollesi</p>
                    <h3 class="text-2xl md:text-3xl font-extrabold text-white mt-2">{{ $recommended['label'] }}</h3>
                    <div class="mt-6 flex flex-wrap items-end gap-x-10 gap-y-5">
                        <div>
                            <p class="text-sm text-slate-300 mb-1">Säästät vuodessa</p>
                            <p class="text-4xl md:text-5xl font-extrabold text-coral-400 tabular-nums leading-none">{{ number_format($recommended['annualSavings'], 0, ',', ' ') }} €</p>
                        </div>
                        <div>
                            <p class="text-sm text-slate-300 mb-1">Takaisinmaksuaika</p>
                            <p class="text-2xl font-bold text-white tabular-nums">{{ $recommended['paybackYears'] ? number_format($recommended['paybackYears'], 1, ',', ' ') . ' v' : '–' }}</p>
                        </div>
                        <div>
                            <p class="text-sm text-slate-300 mb-1">Investointi</p>
                            <p class="text-2xl font-bold text-white tabular-nums">{{ number_format($recommended['investment'], 0, ',', ' ') }} €</p>
                        </div>
                    </div>
                    <p class="mt-5 text-sm text-slate-300">
                        Valitsimme tämän, koska sillä on edullisin kokonaiskustannus (energiakustannukset + investoinnin annuiteetti {{ $calculationPeriod }} vuodelle) niistä täysimittaisista lämmitysratkaisuista, jotka tuottavat säästöä nykyiseen verrattuna ({{ $this->currentSystem['label'] }}, {{ number_format($this->currentSystem['annualCost'], 0, ',', ' ') }} €/v).
                    </p>
                    <p class="mt-4 text-xs text-slate-400 leading-relaxed">
                        Luvut ovat suuntaa-antavia arvioita, eivät tae toteutuvista säästöistä eivätkä sijoitusneuvontaa. Säästö ja takaisinmaksuaika riippuvat energian hintojen kehityksestä, käyttötottumuksista ja asennuksesta — tarkan arvion saat pyytämällä tarjouksen lämpöpumppuasentajalta. Käytetyt hyötysuhteet (SPF), investointihinnat ja korko näkyvät lisäasetuksissa ja ovat muokattavissa.
                    </p>
                </section>
            @else
                <section class="rounded-2xl border border-slate-200 bg-slate-50 p-6 mb-8">
                    <h3 class="text-lg font-bold text-slate-900">Täysi lämmitysvaihto ei tuota säästöä näillä tiedoilla</h3>
                    <p class="mt-2 text-sm text-slate-600">
                        Maalämpö, ilma-vesilämpöpumppu tai pelletti ei tällä erää alita nykyisen lämmityksen kokonaiskustannusta {{ $calculationPeriod }} vuoden tarkastelujaksolla. Voit tarkistaa energian hinnat ja investoinnit lisäasetuksista.
                    </p>
                </section>
            @endif

            @php
                // Only full-replacement primary systems are shown (see HeatPumpCalculator::alternatives()).
                $recommendedKey = $recommended['key'] ?? null;
                $primary = collect($this->alternatives)
                    ->sortBy(fn($alt) => $alt['annualizedTotalCost'] ?? PHP_INT_MAX)
                    ->values();
            @endphp

            <!-- Current system - quiet baseline reference -->
            <section class="rounded-2xl border border-slate-200 p-6 mb-8">
                <div class="flex flex-wrap items-center justify-between gap-4">
                    <div>
                        <p class="text-sm uppercase tracking-wide font-semibold text-slate-500">Nykyinen järjestelmä</p>
                        <h3 class="text-xl font-bold text-slate-900 mt-1">{{ $this->currentSystem['label'] }}</h3>
                    </div>
                    <div class="flex gap-8">
                        <div>
                            <p class="text-sm text-slate-500 mb-1">Energiakustannus/v</p>
                            <p class="text-2xl font-bold text-slate-900 tabular-nums">{{ number_format($this->currentSystem['annualCost'], 0, ',', ' ') }} €</p>
                        </div>
                        <div>
                            <p class="text-sm text-slate-500 mb-1">CO₂-päästöt</p>
                            <p class="text-2xl font-bold text-slate-900 tabular-nums">{{ number_format($this->currentSystem['co2KgPerYear'], 0, ',', ' ') }} kg</p>
                        </div>
                    </div>
                </div>
            </section>

            <!-- Primary Heating Options (full replacement) -->
            @if ($primary->isNotEmpty())
                <section class="mb-8">
                    <div class="mb-5">
                        <h3 class="text-xl font-bold text-slate-900">Lämmitysratkaisut</h3>
                        <p class="text-sm text-slate-500 mt-1">Nämä järjestelmät korvaavat nykyisen lämmityksen kokonaan tai lähes kokonaan. Järjestys halvimman kokonaiskustannuksen mukaan.</p>
                    </div>
                    <div class="grid grid-cols-1 gap-5">
                        @foreach ($primary as $alt)
                            @include('livewire.partials.heat-pump-alternative-card', ['alt' => $alt, 'isRecommended' => $alt['key'] === $recommendedKey])
                        @endforeach
                    </div>
                </section>
            @endif

            <p class="text-xs text-slate-500 mb-8">* Kokonaiskustannus sisältää energiakustannukset (sähkö tai pelletti) ja investoinnin annuiteetin {{ $calculationPeriod }} vuodelle {{ number_format($interestRate, 1, ',', ' ') }}% korolla.</p>
            </div>
        @endif

        <!-- Info Section -->
        <section class="bg-slate-50 rounded-xl p-6 text-sm text-slate-600 mb-8">
            <h3 class="font-semibold text-slate-900 mb-2">Tietoa laskurista</h3>
            <ul class="list-disc list-inside space-y-1">
                <li>Lämmitysenergian tarve perustuu rakennuksen tilavuuteen ja energiatehokkuuteen</li>
                <li>Lämpöpumppujen hyötysuhteet (SPF/COP) ovat tyypillisiä suomalaisissa olosuhteissa</li>
                <li>Investointikustannukset ovat keskimääräisiä avaimet käteen -hintoja</li>
                <li>Todellinen säästö riippuu käyttötottumuksista ja energian hintojen kehityksestä</li>
            </ul>

            <h4 class="font-semibold text-slate-900 mt-4 mb-2">CO₂-päästöjen laskenta</h4>
            <p class="mb-2">Päästöt lasketaan vuositasolla käyttäen seuraavia päästökertoimia:</p>
            <ul class="list-disc list-inside space-y-1 mb-2">
                <li><strong>Sähkö: 80 g/kWh</strong> – Suomen sähköverkon keskiarvo 2024 (<a href="https://www.fingrid.fi/en/electricity-market-information/real-time-co2-emissions-estimate/" target="_blank" rel="noopener" class="text-coral-600 hover:underline">Fingrid</a>)</li>
                <li><strong>Kaukolämpö: 130 g/kWh</strong> – kolmen vuoden keskiarvo 2021–2023 (<a href="https://www.motiva.fi/ratkaisut/energiankaytto_suomessa/co2-paastokertoimet" target="_blank" rel="noopener" class="text-coral-600 hover:underline">Motiva</a>)</li>
                <li><strong>Öljy: 267 g/kWh</strong> – polttoaineen päästökerroin</li>
                <li><strong>Pelletit ja polttopuu: 30 g/kWh</strong> – biogeeninen hiili lasketaan hiilineutraaliksi, luku kattaa vain tuotannon ja kuljetuksen</li>
            </ul>
            <p class="text-xs text-slate-500">Suomen sähköntuotanto on erittäin vähäpäästöistä ydinvoiman (~40%) ja uusiutuvien (~52%) ansiosta. Jos sinulla on 100% uusiutuva sähkösopimus, lämpöpumppujen todelliset päästöt ovat vielä pienemmät.</p>
        </section>

        <!-- FAQ Section for SEO (visible FAQ + FAQPage JSON-LD share getFaqItemsProperty) -->
        <section class="mb-8">
            <h2 class="text-2xl font-bold text-slate-900 mb-6">Usein kysytyt kysymykset lämpöpumpuista</h2>

            <div class="space-y-4">
                @foreach ($this->faqItems as $faq)
                    <details class="bg-white rounded-xl border border-slate-200 p-4 group">
                        <summary class="font-semibold text-slate-900 cursor-pointer list-none flex justify-between items-center">
                            {{ $faq['question'] }}
                            <svg class="w-5 h-5 text-slate-500 group-open:rotate-180 transition-transform shrink-0 ml-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path></svg>
                        </summary>
                        <p class="mt-3 text-slate-600">{{ $faq['answer'] }}</p>
                    </details>
                @endforeach
            </div>
        </section>

        <!-- Additional SEO Content -->
        <section class="prose prose-slate max-w-none mb-8">
            <h2 class="text-2xl font-bold text-slate-900 mb-4">Kannattaako lämpöpumppu? {{ date('Y') }}</h2>
            <p class="text-slate-600 mb-4">
                Lämpöpumppu kannattaa useimmiten silloin, kun se korvaa suoran sähkölämmityksen tai
                öljylämmityksen. Lämpöpumppu siirtää lämpöä ulkoilmasta, maaperästä tai poistoilmasta taloon
                ja tuottaa yhdellä sähkökilowattitunnilla kaksi tai kolme kilowattituntia lämpöä. Kannattavuus
                riippuu talon koosta, nykyisestä lämmitystavasta, investoinnin hinnasta ja energian hinnoista,
                joten paras tapa selvittää asia on laskea oma tapauksesi yllä olevalla laskurilla.
            </p>

            <h3 class="text-xl font-bold text-slate-900 mt-6 mb-2">Kannattaako maalämpö?</h3>
            <p class="text-slate-600 mb-4">
                Maalämpö on energiatehokkain vaihtoehto. Vuotuinen hyötysuhde (SPF) on Suomen olosuhteissa
                tyypillisesti noin 2,9, eli se tuottaa lähes kolme kilowattituntia lämpöä jokaista
                sähkökilowattituntia kohden.
            </p>
            <p class="text-slate-600 mb-4">
                Investointi on suurin, usein luokkaa 20 000 euroa, joten maalämpö kannattaa parhaiten
                suuremmissa ja paljon lämmitettävissä taloissa. Pienemmässä talossa edullisempi
                ilma-vesilämpöpumppu voi tulla kokonaiskustannukseltaan halvemmaksi.
            </p>

            <h3 class="text-xl font-bold text-slate-900 mt-6 mb-2">Kannattaako ilma-vesilämpöpumppu?</h3>
            <p class="text-slate-600 mb-4">
                Ilma-vesilämpöpumppu sopii vesikiertoiseen lämmitykseen ja maksaa selvästi vähemmän kuin
                maalämpö, tyypillisesti noin 12 000 euroa. Sen vuotuinen hyötysuhde (SPF) on noin 2,3, ja
                se kattaa lämmöntarpeesta noin 80 prosenttia.
            </p>
            <p class="text-slate-600 mb-4">
                Loput noin 20 prosenttia tuotetaan yleensä suoralla sähköllä, etenkin kovilla pakkasilla.
                Kohtalaisesti lämmitettävässä talossa ilma-vesilämpöpumppu on usein kokonaiskustannukseltaan
                kannattavin ratkaisu.
            </p>

            <h3 class="text-xl font-bold text-slate-900 mt-6 mb-2">Milloin lämpöpumppu ei kannata?</h3>
            <p class="text-slate-600 mb-4">
                Kaukolämmön korvaaminen lämpöpumpulla on harvoin taloudellisesti kannattavaa, koska
                kaukolämpö on jo valmiiksi edullista energiaa. Myös hyvin pienen lämmitystarpeen taloissa
                suuri investointi voi maksaa itsensä takaisin hitaasti.
            </p>
            <p class="text-slate-600">
                Tämä laskuri huomioi talon koon, sijainnin, nykyisen lämmitystavan ja energian hinnat, ja
                kertoo suoraan, jos täysi lämmitysvaihto ei tuota säästöä sinun tiedoillasi. Tulokset ovat
                suuntaa antavia; tarkemman arvion saat pyytämällä tarjouksen lämpöpumppuasentajalta.
            </p>
        </section>

        <!-- Related links -->
        <section class="border-t border-slate-200 pt-6 mb-8">
            <h2 class="text-lg font-bold text-slate-900 mb-3">Aiheeseen liittyvää</h2>
            <ul class="space-y-2">
                <li><a href="{{ route('spot-price') }}" class="text-coral-600 hover:underline">Pörssisähkön hinta tänään ja huomenna</a></li>
                <li><a href="{{ route('calculator') }}" class="text-coral-600 hover:underline">Sähkönkulutuslaskuri: arvioi talosi vuosikulutus</a></li>
                <li><a href="{{ route('sahkosopimus.index') }}" class="text-coral-600 hover:underline">Vertaile sähkösopimuksia</a></li>
                <li><a href="{{ route('solar.calculator') }}" class="text-coral-600 hover:underline">Aurinkopaneelilaskuri</a></li>
            </ul>
        </section>

    </div>
</div>
