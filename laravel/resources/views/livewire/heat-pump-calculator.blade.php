<div>
    {{-- FAQ Schema for SEO --}}
    <script type="application/ld+json">
    {
        "@context": "https://schema.org",
        "@type": "FAQPage",
        "mainEntity": [
            {
                "@type": "Question",
                "name": "Mikä on paras lämpöpumppu omakotitaloon?",
                "acceptedAnswer": {
                    "@type": "Answer",
                    "text": "Paras lämpöpumppu riippuu talon koosta, nykyisestä lämmitystavasta ja budjetista. Maalämpöpumppu on tehokkain (SPF 2,8-3,2) ja sopii hyvin suuriin taloihin. Ilma-vesilämpöpumppu on edullisempi investointi ja sopii vesikiertoiseen lämmitykseen. Ilmalämpöpumppu on edullisin vaihtoehto täydentämään olemassa olevaa lämmitystä."
                }
            },
            {
                "@type": "Question",
                "name": "Mikä on lämpöpumpun takaisinmaksuaika?",
                "acceptedAnswer": {
                    "@type": "Answer",
                    "text": "Lämpöpumpun takaisinmaksuaika riippuu investoinnin hinnasta, nykyisestä lämmitystavasta ja energian hinnoista. Tyypillisesti öljylämmityksen korvaamisessa takaisinmaksuaika on 5-10 vuotta, suoran sähkölämmityksen korvaamisessa 8-15 vuotta."
                }
            },
            {
                "@type": "Question",
                "name": "Paljonko maalämpöpumppu säästää vuodessa?",
                "acceptedAnswer": {
                    "@type": "Answer",
                    "text": "Maalämpöpumppu voi säästää 50-70% lämmityskustannuksista verrattuna suoraan sähkölämmitykseen. 150 m² talossa, joka kuluttaa 20 000 kWh lämmitysenergiaa vuodessa, säästö voi olla 1500-2500 euroa vuodessa sähkön hinnasta riippuen."
                }
            },
            {
                "@type": "Question",
                "name": "Toimiiko ilmalämpöpumppu kovilla pakkasilla?",
                "acceptedAnswer": {
                    "@type": "Answer",
                    "text": "Nykyaikaiset ilmalämpöpumput toimivat jopa -25 asteen pakkasilla, mutta hyötysuhde heikkenee lämpötilan laskiessa. Alle -15 asteen pakkasilla ilmalämpöpumppu tarvitsee tuekseen muuta lämmitystä. Maalämpö toimii tehokkaasti kaikissa olosuhteissa."
                }
            }
        ]
    }
    </script>

    <!-- Hero Section - Dark slate background -->
    <section class="bg-slate-950 -mx-4 sm:-mx-6 lg:-mx-8 mb-8">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="py-12 lg:py-16 text-center">
                <h1 class="text-3xl md:text-4xl xl:text-5xl font-extrabold text-white tracking-tight leading-none mb-4">
                    Lämpöpumppu<span class="text-coral-400">laskuri</span>
                </h1>
                <p class="max-w-2xl mx-auto text-slate-300 md:text-lg">
                    Vertaile lämpöpumppuvaihtoehtoja ja laske säästöt nykyiseen lämmitysjärjestelmään verrattuna.
                </p>
            </div>
        </div>
    </section>

    <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 py-8">

        <!-- Introduction -->
        <div class="mb-8 text-center max-w-2xl mx-auto">
            <p class="text-slate-600 mb-4">
                Lämpöpumppulaskuri auttaa sinua vertailemaan eri lämpöpumppuvaihtoehtoja ja arvioimaan, paljonko voisit säästää lämmityskustannuksissa. Syötä rakennuksesi tiedot ja nykyinen lämmitystapa.
            </p>
            <p class="text-slate-500 text-sm">
                Laskuri käyttää Motivan ja energianeuvonnan vertailutietoja lämmitysenergian tarpeesta ja lämpöpumppujen hyötysuhteista.
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
                            wire:model.live.debounce.500ms="livingArea"
                            min="20"
                            max="500"
                            class="w-full px-4 py-3 border border-slate-300 rounded-lg focus:ring-2 focus:ring-coral-500 focus:border-coral-500"
                        >
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1">Huonekorkeus (m)</label>
                        <input
                            type="number"
                            wire:model.live.debounce.500ms="roomHeight"
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
                            wire:model.live.debounce.500ms="numPeople"
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
                                wire:model.live.debounce.500ms="oilLitersPerYear"
                                min="0"
                                max="10000"
                                placeholder="esim. 2000"
                                class="w-full px-4 py-3 border border-slate-300 rounded-lg focus:ring-2 focus:ring-coral-500 focus:border-coral-500"
                            >
                            <p class="mt-1 text-xs text-slate-500">Syötä vuotuinen öljynkulutus litroina</p>
                        @elseif ($currentHeatingMethod === 'electricity')
                            <label class="block text-sm font-medium text-slate-700 mb-1">Sähkönkulutus (kWh/vuosi)</label>
                            <input
                                type="number"
                                wire:model.live.debounce.500ms="electricityKwhPerYear"
                                min="0"
                                max="100000"
                                placeholder="esim. 20000"
                                class="w-full px-4 py-3 border border-slate-300 rounded-lg focus:ring-2 focus:ring-coral-500 focus:border-coral-500"
                            >
                            <p class="mt-1 text-xs text-slate-500">Syötä vuotuinen sähkönkulutus (lämmitys + käyttösähkö)</p>
                        @elseif ($currentHeatingMethod === 'district_heating')
                            <label class="block text-sm font-medium text-slate-700 mb-1">Kaukolämpölasku (euroa/vuosi)</label>
                            <input
                                type="number"
                                wire:model.live.debounce.500ms="districtHeatingEurosPerYear"
                                min="0"
                                max="20000"
                                placeholder="esim. 1500"
                                class="w-full px-4 py-3 border border-slate-300 rounded-lg focus:ring-2 focus:ring-coral-500 focus:border-coral-500"
                            >
                            <p class="mt-1 text-xs text-slate-500">Syötä vuotuinen kaukolämpölasku euroina (energia, ei perusmaksua)</p>
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
                                    <label class="block text-xs text-slate-600 mb-1">Sähkön hinta (c/kWh)</label>
                                    <input
                                        type="number"
                                        wire:model.live.debounce.500ms="electricityPrice"
                                        min="1"
                                        max="50"
                                        step="0.1"
                                        class="w-full px-3 py-2 text-sm border border-slate-300 rounded-lg focus:ring-2 focus:ring-coral-500 focus:border-coral-500"
                                    >
                                </div>
                                <div>
                                    <label class="block text-xs text-slate-600 mb-1">Öljyn hinta (€/litra)</label>
                                    <input
                                        type="number"
                                        wire:model.live.debounce.500ms="oilPrice"
                                        min="0.5"
                                        max="3"
                                        step="0.01"
                                        class="w-full px-3 py-2 text-sm border border-slate-300 rounded-lg focus:ring-2 focus:ring-coral-500 focus:border-coral-500"
                                    >
                                </div>
                                <div>
                                    <label class="block text-xs text-slate-600 mb-1">Kaukolämmön hinta (c/kWh)</label>
                                    <input
                                        type="number"
                                        wire:model.live.debounce.500ms="districtHeatingPrice"
                                        min="1"
                                        max="30"
                                        step="0.1"
                                        class="w-full px-3 py-2 text-sm border border-slate-300 rounded-lg focus:ring-2 focus:ring-coral-500 focus:border-coral-500"
                                    >
                                </div>
                                <div>
                                    <label class="block text-xs text-slate-600 mb-1">Pelletin hinta (€/tonni)</label>
                                    <input
                                        type="number"
                                        wire:model.live.debounce.500ms="pelletPrice"
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
                                        'pellets' => 'Pellettikattila',
                                    ];
                                @endphp
                                @foreach ($investmentLabels as $key => $label)
                                    <div>
                                        <label class="block text-xs text-slate-600 mb-1">{{ $label }}</label>
                                        <input
                                            type="number"
                                            wire:model.live.debounce.500ms="investments.{{ $key }}"
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
                                    <label class="block text-xs text-slate-600 mb-1">Laskentakorko (%)</label>
                                    <input
                                        type="number"
                                        wire:model.live.debounce.500ms="interestRate"
                                        min="0"
                                        max="10"
                                        step="0.1"
                                        class="w-full px-3 py-2 text-sm border border-slate-300 rounded-lg focus:ring-2 focus:ring-coral-500 focus:border-coral-500"
                                    >
                                </div>
                                <div>
                                    <label class="block text-xs text-slate-600 mb-1">Laskentajakso (vuotta)</label>
                                    <input
                                        type="number"
                                        wire:model.live.debounce.500ms="calculationPeriod"
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

        <!-- Loading Overlay for Results -->
        <div wire:loading.delay wire:target="livingArea, roomHeight, buildingRegion, buildingEnergyEfficiency, numPeople, inputMode, currentHeatingMethod, oilLitersPerYear, electricityKwhPerYear, districtHeatingEurosPerYear, electricityPrice, oilPrice, districtHeatingPrice, pelletPrice, investments, interestRate, calculationPeriod" class="fixed inset-0 bg-white/50 z-50 flex items-center justify-center">
            <div class="bg-white rounded-xl shadow-lg p-6 flex items-center space-x-3">
                <svg class="animate-spin h-6 w-6 text-coral-500" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                </svg>
                <span class="text-slate-700 font-medium">Lasketaan...</span>
            </div>
        </div>

        <!-- Results Section -->
        @if ($this->hasResults)
            <!-- Energy Need Summary -->
            <section class="bg-slate-100 rounded-2xl p-6 mb-8">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="font-semibold text-slate-900">Arvioitu lämmitysenergian tarve</h3>
                    <svg wire:loading wire:target="livingArea, roomHeight, buildingRegion, buildingEnergyEfficiency, numPeople, inputMode, currentHeatingMethod, oilLitersPerYear, electricityKwhPerYear, districtHeatingEurosPerYear" class="animate-spin h-5 w-5 text-coral-500" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                    </svg>
                </div>
                <div class="grid grid-cols-3 gap-4">
                    <div class="bg-white rounded-lg p-4 text-center">
                        <p class="text-sm text-slate-600 mb-1">Tilojen lämmitys</p>
                        <p class="text-2xl font-bold text-slate-900">{{ number_format($this->heatingEnergyNeed, 0, ',', ' ') }}</p>
                        <p class="text-xs text-slate-500">kWh/vuosi</p>
                    </div>
                    <div class="bg-white rounded-lg p-4 text-center">
                        <p class="text-sm text-slate-600 mb-1">Käyttövesi</p>
                        <p class="text-2xl font-bold text-slate-900">{{ number_format($this->hotWaterEnergyNeed, 0, ',', ' ') }}</p>
                        <p class="text-xs text-slate-500">kWh/vuosi</p>
                    </div>
                    <div class="bg-white rounded-lg p-4 text-center">
                        <p class="text-sm text-slate-600 mb-1">Yhteensä</p>
                        <p class="text-2xl font-bold text-coral-600">{{ number_format($this->totalEnergyNeed, 0, ',', ' ') }}</p>
                        <p class="text-xs text-slate-500">kWh/vuosi</p>
                    </div>
                </div>
            </section>

            <!-- Current System - Improved legibility -->
            <section class="rounded-2xl p-8 mb-8" style="background-color: #1e293b;">
                <div class="flex items-center justify-between mb-6">
                    <div>
                        <p class="text-sm uppercase tracking-wide font-medium" style="color: #94a3b8;">Nykyinen järjestelmä</p>
                        <h3 class="text-2xl font-bold mt-1" style="color: #ffffff;">{{ $this->currentSystem['label'] }}</h3>
                    </div>
                    <div class="rounded-full px-4 py-2" style="background-color: #334155;">
                        <span class="text-sm" style="color: #cbd5e1;">Vertailukohta</span>
                    </div>
                </div>
                <div class="grid grid-cols-2 gap-6">
                    <div class="rounded-xl p-5" style="background-color: #334155;">
                        <p class="text-sm mb-2" style="color: #94a3b8;">Vuosikustannus</p>
                        <p class="text-3xl font-bold" style="color: #ffffff;">{{ number_format($this->currentSystem['annualCost'], 0, ',', ' ') }} €</p>
                    </div>
                    <div class="rounded-xl p-5" style="background-color: #334155;">
                        <p class="text-sm mb-2" style="color: #94a3b8;">CO₂-päästöt</p>
                        <p class="text-3xl font-bold" style="color: #ffffff;">{{ number_format($this->currentSystem['co2KgPerYear'], 0, ',', ' ') }} kg</p>
                    </div>
                </div>
            </section>

            @php
                // Classify alternatives into primary (full replacement) and supplementary
                $primarySystems = ['ground_source_hp', 'air_to_water_hp', 'pellets'];
                $primary = collect($this->alternatives)->filter(fn($alt) => in_array($alt['key'], $primarySystems))->values();
                $supplementary = collect($this->alternatives)->filter(fn($alt) => !in_array($alt['key'], $primarySystems))->values();
            @endphp

            <!-- Primary Heating Options -->
            @if ($primary->isNotEmpty())
                <section class="mb-8">
                    <div class="mb-5">
                        <h3 class="text-xl font-bold text-slate-900">Päälämmitysratkaisut</h3>
                        <p class="text-sm text-slate-500 mt-1">Nämä järjestelmät korvaavat nykyisen lämmityksen kokonaan tai lähes kokonaan</p>
                    </div>
                    <div class="grid grid-cols-1 gap-5">
                        @foreach ($primary as $alt)
                            @php
                                $savingsPositive = $alt['annualSavings'] > 0;
                            @endphp
                            <div class="bg-white rounded-2xl border border-slate-200 p-6 hover:shadow-lg transition-shadow">
                                <h4 class="text-lg font-bold text-slate-900 mb-4">{{ $alt['label'] }}</h4>

                                <div class="space-y-3">
                                    <div class="flex justify-between items-center">
                                        <span class="text-slate-600">Vuosikustannus</span>
                                        <span class="text-lg font-bold text-slate-900 whitespace-nowrap">{{ number_format($alt['annualCost'], 0, ',', ' ') }} €</span>
                                    </div>
                                    <div class="flex justify-between items-center">
                                        <span class="text-slate-600">Vuosisäästö</span>
                                        <span class="text-lg font-bold whitespace-nowrap {{ $savingsPositive ? 'text-green-600' : 'text-red-600' }}">
                                            {{ $savingsPositive ? '+' : '' }}{{ number_format($alt['annualSavings'], 0, ',', ' ') }} €
                                        </span>
                                    </div>
                                    <div class="flex justify-between items-center">
                                        <span class="text-slate-600">Investointi</span>
                                        <span class="text-lg font-semibold text-slate-900 whitespace-nowrap">{{ number_format($alt['investment'], 0, ',', ' ') }} €</span>
                                    </div>
                                    <div class="flex justify-between items-center">
                                        <span class="text-slate-600">Takaisinmaksuaika</span>
                                        <span class="text-lg font-semibold text-slate-900 whitespace-nowrap">
                                            @if ($alt['paybackYears'])
                                                {{ number_format($alt['paybackYears'], 1, ',', ' ') }} v
                                            @else
                                                -
                                            @endif
                                        </span>
                                    </div>
                                    <div class="flex justify-between items-center">
                                        <span class="text-slate-600">CO₂-päästöt</span>
                                        <div class="text-right">
                                            <span class="text-lg font-semibold text-slate-900 whitespace-nowrap">{{ number_format($alt['co2KgPerYear'], 0, ',', ' ') }} kg</span>
                                            @php
                                                $co2Diff = $this->currentSystem['co2KgPerYear'] - $alt['co2KgPerYear'];
                                            @endphp
                                            @if ($co2Diff > 0)
                                                <span class="block text-sm text-green-600 font-medium">−{{ number_format($co2Diff, 0, ',', ' ') }} kg ({{ number_format(($co2Diff / $this->currentSystem['co2KgPerYear']) * 100, 0) }}%)</span>
                                            @elseif ($co2Diff < 0)
                                                <span class="block text-sm text-red-600 font-medium">+{{ number_format(abs($co2Diff), 0, ',', ' ') }} kg</span>
                                            @endif
                                        </div>
                                    </div>
                                    <div class="border-t border-slate-100 pt-3 mt-3">
                                        <div class="flex justify-between items-baseline gap-2">
                                            <span class="text-slate-700 font-medium text-sm">Kokonaiskustannus/v*</span>
                                            <span class="text-xl font-bold text-coral-600 whitespace-nowrap">{{ number_format($alt['annualizedTotalCost'], 0, ',', ' ') }} €</span>
                                        </div>
                                    </div>
                                </div>

                                @if ($alt['notes'])
                                    <p class="mt-4 text-sm text-slate-500 bg-slate-50 rounded-lg p-3">{{ $alt['notes'] }}</p>
                                @endif

                                {{-- Payback Curve Chart --}}
                                @if ($alt['annualSavings'] > 0)
                                    <div x-data="{ showChart: false }" class="mt-4">
                                        <button
                                            @click="showChart = !showChart"
                                            class="flex items-center gap-2 text-sm text-coral-600 hover:text-coral-700 font-medium"
                                        >
                                            <svg class="w-4 h-4 transition-transform" :class="{ 'rotate-90': showChart }" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                                            </svg>
                                            <span x-text="showChart ? 'Piilota takaisinmaksukäyrä' : 'Näytä takaisinmaksukäyrä'"></span>
                                        </button>

                                        <div x-show="showChart" x-collapse class="mt-4">
                                            @php
                                                $currentAnnual = $this->currentSystem['annualCost'];
                                                $altAnnual = $alt['annualCost'];
                                                $investment = $alt['investment'];
                                                $years = $calculationPeriod;
                                                $maxCost = max($currentAnnual * $years, $investment + $altAnnual * $years);

                                                // Generate points for SVG (width: 320, height: 180, padding: 40)
                                                $chartWidth = 320;
                                                $chartHeight = 180;
                                                $padding = 40;
                                                $graphWidth = $chartWidth - $padding * 2;
                                                $graphHeight = $chartHeight - $padding * 2;

                                                $currentPoints = [];
                                                $altPoints = [];
                                                for ($y = 0; $y <= $years; $y++) {
                                                    $x = $padding + ($y / $years) * $graphWidth;
                                                    $currentCost = $currentAnnual * $y;
                                                    $altCost = $investment + $altAnnual * $y;
                                                    $currentY = $padding + $graphHeight - ($currentCost / $maxCost) * $graphHeight;
                                                    $altY = $padding + $graphHeight - ($altCost / $maxCost) * $graphHeight;
                                                    $currentPoints[] = round($x, 1) . ',' . round($currentY, 1);
                                                    $altPoints[] = round($x, 1) . ',' . round($altY, 1);
                                                }
                                                $currentPath = implode(' ', $currentPoints);
                                                $altPath = implode(' ', $altPoints);

                                                // Calculate payback point coordinates
                                                $paybackX = null;
                                                $paybackY = null;
                                                if ($alt['paybackYears'] && $alt['paybackYears'] <= $years) {
                                                    $paybackX = $padding + ($alt['paybackYears'] / $years) * $graphWidth;
                                                    $paybackCost = $investment + $altAnnual * $alt['paybackYears'];
                                                    $paybackY = $padding + $graphHeight - ($paybackCost / $maxCost) * $graphHeight;
                                                }
                                            @endphp

                                            <div class="bg-slate-50 rounded-lg p-4">
                                                <svg viewBox="0 0 {{ $chartWidth }} {{ $chartHeight }}" class="w-full h-auto">
                                                    {{-- Grid lines --}}
                                                    @for ($i = 0; $i <= 4; $i++)
                                                        <line
                                                            x1="{{ $padding }}"
                                                            y1="{{ $padding + $i * $graphHeight / 4 }}"
                                                            x2="{{ $chartWidth - $padding }}"
                                                            y2="{{ $padding + $i * $graphHeight / 4 }}"
                                                            stroke="#e2e8f0"
                                                            stroke-width="1"
                                                        />
                                                    @endfor

                                                    {{-- Y-axis labels --}}
                                                    @for ($i = 0; $i <= 4; $i++)
                                                        <text
                                                            x="{{ $padding - 5 }}"
                                                            y="{{ $padding + $i * $graphHeight / 4 + 4 }}"
                                                            text-anchor="end"
                                                            class="text-xs fill-slate-500"
                                                            style="font-size: 9px;"
                                                        >{{ number_format($maxCost * (4 - $i) / 4 / 1000, 0) }}k</text>
                                                    @endfor

                                                    {{-- X-axis labels --}}
                                                    @foreach ([0, 5, 10, 15] as $yr)
                                                        @if ($yr <= $years)
                                                            <text
                                                                x="{{ $padding + ($yr / $years) * $graphWidth }}"
                                                                y="{{ $chartHeight - $padding + 15 }}"
                                                                text-anchor="middle"
                                                                class="text-xs fill-slate-500"
                                                                style="font-size: 9px;"
                                                            >{{ $yr }}v</text>
                                                        @endif
                                                    @endforeach

                                                    {{-- Current system line (red/coral) --}}
                                                    <polyline
                                                        points="{{ $currentPath }}"
                                                        fill="none"
                                                        stroke="#f97316"
                                                        stroke-width="2"
                                                    />

                                                    {{-- Alternative line (green) --}}
                                                    <polyline
                                                        points="{{ $altPath }}"
                                                        fill="none"
                                                        stroke="#22c55e"
                                                        stroke-width="2"
                                                    />

                                                    {{-- Payback point --}}
                                                    @if ($paybackX && $paybackY)
                                                        <circle cx="{{ round($paybackX, 1) }}" cy="{{ round($paybackY, 1) }}" r="5" fill="#22c55e" />
                                                        <line
                                                            x1="{{ round($paybackX, 1) }}"
                                                            y1="{{ $padding }}"
                                                            x2="{{ round($paybackX, 1) }}"
                                                            y2="{{ $chartHeight - $padding }}"
                                                            stroke="#22c55e"
                                                            stroke-width="1"
                                                            stroke-dasharray="4"
                                                        />
                                                    @endif
                                                </svg>

                                                {{-- Legend --}}
                                                <div class="flex flex-wrap gap-4 mt-3 text-xs">
                                                    <div class="flex items-center gap-1">
                                                        <span class="w-3 h-0.5 bg-orange-500"></span>
                                                        <span class="text-slate-600">Nykyinen ({{ $this->currentSystem['label'] }})</span>
                                                    </div>
                                                    <div class="flex items-center gap-1">
                                                        <span class="w-3 h-0.5 bg-green-500"></span>
                                                        <span class="text-slate-600">{{ $alt['label'] }}</span>
                                                    </div>
                                                    @if ($paybackX)
                                                        <div class="flex items-center gap-1">
                                                            <span class="w-2 h-2 rounded-full bg-green-500"></span>
                                                            <span class="text-slate-600">Takaisinmaksu {{ number_format($alt['paybackYears'], 1, ',', ' ') }} v</span>
                                                        </div>
                                                    @endif
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                @endif
                            </div>
                        @endforeach
                    </div>
                </section>
            @endif

            <!-- Supplementary Heating Options -->
            @if ($supplementary->isNotEmpty())
                <section class="mb-8">
                    <div class="mb-5">
                        <h3 class="text-xl font-bold text-slate-900">Täydentävät lämmitysvaihtoehdot</h3>
                        <p class="text-sm text-slate-500 mt-1">Nämä ratkaisut täydentävät nykyistä lämmitystä ja vähentävät kustannuksia</p>
                    </div>
                    <div class="grid grid-cols-1 gap-5">
                        @foreach ($supplementary as $alt)
                            @php
                                $savingsPositive = $alt['annualSavings'] > 0;
                            @endphp
                            <div class="bg-white rounded-2xl border border-slate-200 p-6 hover:shadow-lg transition-shadow">
                                <h4 class="text-lg font-bold text-slate-900 mb-4">{{ $alt['label'] }}</h4>

                                <div class="space-y-3">
                                    <div class="flex justify-between items-center">
                                        <span class="text-slate-600">Vuosikustannus</span>
                                        <span class="text-lg font-bold text-slate-900 whitespace-nowrap">{{ number_format($alt['annualCost'], 0, ',', ' ') }} €</span>
                                    </div>
                                    <div class="flex justify-between items-center">
                                        <span class="text-slate-600">Vuosisäästö</span>
                                        <span class="text-lg font-bold whitespace-nowrap {{ $savingsPositive ? 'text-green-600' : 'text-red-600' }}">
                                            {{ $savingsPositive ? '+' : '' }}{{ number_format($alt['annualSavings'], 0, ',', ' ') }} €
                                        </span>
                                    </div>
                                    <div class="flex justify-between items-center">
                                        <span class="text-slate-600">Investointi</span>
                                        <span class="text-lg font-semibold text-slate-900 whitespace-nowrap">{{ number_format($alt['investment'], 0, ',', ' ') }} €</span>
                                    </div>
                                    <div class="flex justify-between items-center">
                                        <span class="text-slate-600">Takaisinmaksuaika</span>
                                        <span class="text-lg font-semibold text-slate-900 whitespace-nowrap">
                                            @if ($alt['paybackYears'])
                                                {{ number_format($alt['paybackYears'], 1, ',', ' ') }} v
                                            @else
                                                -
                                            @endif
                                        </span>
                                    </div>
                                    <div class="flex justify-between items-center">
                                        <span class="text-slate-600">CO₂-päästöt</span>
                                        <div class="text-right">
                                            <span class="text-lg font-semibold text-slate-900 whitespace-nowrap">{{ number_format($alt['co2KgPerYear'], 0, ',', ' ') }} kg</span>
                                            @php
                                                $co2Diff = $this->currentSystem['co2KgPerYear'] - $alt['co2KgPerYear'];
                                            @endphp
                                            @if ($co2Diff > 0)
                                                <span class="block text-sm text-green-600 font-medium">−{{ number_format($co2Diff, 0, ',', ' ') }} kg ({{ number_format(($co2Diff / $this->currentSystem['co2KgPerYear']) * 100, 0) }}%)</span>
                                            @elseif ($co2Diff < 0)
                                                <span class="block text-sm text-red-600 font-medium">+{{ number_format(abs($co2Diff), 0, ',', ' ') }} kg</span>
                                            @endif
                                        </div>
                                    </div>
                                    <div class="border-t border-slate-100 pt-3 mt-3">
                                        <div class="flex justify-between items-baseline gap-2">
                                            <span class="text-slate-700 font-medium text-sm">Kokonaiskustannus/v*</span>
                                            <span class="text-xl font-bold text-coral-600 whitespace-nowrap">{{ number_format($alt['annualizedTotalCost'], 0, ',', ' ') }} €</span>
                                        </div>
                                    </div>
                                </div>

                                @if ($alt['notes'])
                                    <p class="mt-4 text-sm text-slate-500 bg-slate-50 rounded-lg p-3">{{ $alt['notes'] }}</p>
                                @endif

                                {{-- Payback Curve Chart --}}
                                @if ($alt['annualSavings'] > 0)
                                    <div x-data="{ showChart: false }" class="mt-4">
                                        <button
                                            @click="showChart = !showChart"
                                            class="flex items-center gap-2 text-sm text-coral-600 hover:text-coral-700 font-medium"
                                        >
                                            <svg class="w-4 h-4 transition-transform" :class="{ 'rotate-90': showChart }" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                                            </svg>
                                            <span x-text="showChart ? 'Piilota takaisinmaksukäyrä' : 'Näytä takaisinmaksukäyrä'"></span>
                                        </button>

                                        <div x-show="showChart" x-collapse class="mt-4">
                                            @php
                                                $currentAnnual = $this->currentSystem['annualCost'];
                                                $altAnnual = $alt['annualCost'];
                                                $investment = $alt['investment'];
                                                $years = $calculationPeriod;
                                                $maxCost = max($currentAnnual * $years, $investment + $altAnnual * $years);

                                                $chartWidth = 320;
                                                $chartHeight = 180;
                                                $padding = 40;
                                                $graphWidth = $chartWidth - $padding * 2;
                                                $graphHeight = $chartHeight - $padding * 2;

                                                $currentPoints = [];
                                                $altPoints = [];
                                                for ($y = 0; $y <= $years; $y++) {
                                                    $x = $padding + ($y / $years) * $graphWidth;
                                                    $currentCost = $currentAnnual * $y;
                                                    $altCost = $investment + $altAnnual * $y;
                                                    $currentY = $padding + $graphHeight - ($currentCost / $maxCost) * $graphHeight;
                                                    $altY = $padding + $graphHeight - ($altCost / $maxCost) * $graphHeight;
                                                    $currentPoints[] = round($x, 1) . ',' . round($currentY, 1);
                                                    $altPoints[] = round($x, 1) . ',' . round($altY, 1);
                                                }
                                                $currentPath = implode(' ', $currentPoints);
                                                $altPath = implode(' ', $altPoints);

                                                $paybackX = null;
                                                $paybackY = null;
                                                if ($alt['paybackYears'] && $alt['paybackYears'] <= $years) {
                                                    $paybackX = $padding + ($alt['paybackYears'] / $years) * $graphWidth;
                                                    $paybackCost = $investment + $altAnnual * $alt['paybackYears'];
                                                    $paybackY = $padding + $graphHeight - ($paybackCost / $maxCost) * $graphHeight;
                                                }
                                            @endphp

                                            <div class="bg-slate-50 rounded-lg p-4">
                                                <svg viewBox="0 0 {{ $chartWidth }} {{ $chartHeight }}" class="w-full h-auto">
                                                    @for ($i = 0; $i <= 4; $i++)
                                                        <line x1="{{ $padding }}" y1="{{ $padding + $i * $graphHeight / 4 }}" x2="{{ $chartWidth - $padding }}" y2="{{ $padding + $i * $graphHeight / 4 }}" stroke="#e2e8f0" stroke-width="1" />
                                                    @endfor
                                                    @for ($i = 0; $i <= 4; $i++)
                                                        <text x="{{ $padding - 5 }}" y="{{ $padding + $i * $graphHeight / 4 + 4 }}" text-anchor="end" style="font-size: 9px; fill: #64748b;">{{ number_format($maxCost * (4 - $i) / 4 / 1000, 0) }}k</text>
                                                    @endfor
                                                    @foreach ([0, 5, 10, 15] as $yr)
                                                        @if ($yr <= $years)
                                                            <text x="{{ $padding + ($yr / $years) * $graphWidth }}" y="{{ $chartHeight - $padding + 15 }}" text-anchor="middle" style="font-size: 9px; fill: #64748b;">{{ $yr }}v</text>
                                                        @endif
                                                    @endforeach
                                                    <polyline points="{{ $currentPath }}" fill="none" stroke="#f97316" stroke-width="2" />
                                                    <polyline points="{{ $altPath }}" fill="none" stroke="#22c55e" stroke-width="2" />
                                                    @if ($paybackX && $paybackY)
                                                        <circle cx="{{ round($paybackX, 1) }}" cy="{{ round($paybackY, 1) }}" r="5" fill="#22c55e" />
                                                        <line x1="{{ round($paybackX, 1) }}" y1="{{ $padding }}" x2="{{ round($paybackX, 1) }}" y2="{{ $chartHeight - $padding }}" stroke="#22c55e" stroke-width="1" stroke-dasharray="4" />
                                                    @endif
                                                </svg>
                                                <div class="flex flex-wrap gap-4 mt-3 text-xs">
                                                    <div class="flex items-center gap-1">
                                                        <span class="w-3 h-0.5 bg-orange-500"></span>
                                                        <span class="text-slate-600">Nykyinen</span>
                                                    </div>
                                                    <div class="flex items-center gap-1">
                                                        <span class="w-3 h-0.5 bg-green-500"></span>
                                                        <span class="text-slate-600">{{ $alt['label'] }}</span>
                                                    </div>
                                                    @if ($paybackX)
                                                        <div class="flex items-center gap-1">
                                                            <span class="w-2 h-2 rounded-full bg-green-500"></span>
                                                            <span class="text-slate-600">Takaisinmaksu {{ number_format($alt['paybackYears'], 1, ',', ' ') }} v</span>
                                                        </div>
                                                    @endif
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                @endif
                            </div>
                        @endforeach
                    </div>
                </section>
            @endif

            <p class="text-xs text-slate-500 mb-8">* Kokonaiskustannus sisältää käyttökustannukset ja investoinnin annuiteetin {{ $calculationPeriod }} vuodelle {{ number_format($interestRate, 1, ',', ' ') }}% korolla.</p>
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

        <!-- FAQ Section for SEO -->
        <section class="mb-8">
            <h2 class="text-2xl font-bold text-slate-900 mb-6">Usein kysytyt kysymykset lämpöpumpuista</h2>

            <div class="space-y-4">
                <details class="bg-white rounded-xl border border-slate-200 p-4 group">
                    <summary class="font-semibold text-slate-900 cursor-pointer list-none flex justify-between items-center">
                        Mikä on paras lämpöpumppu omakotitaloon?
                        <svg class="w-5 h-5 text-slate-500 group-open:rotate-180 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path></svg>
                    </summary>
                    <p class="mt-3 text-slate-600">Paras lämpöpumppu riippuu talon koosta, nykyisestä lämmitystavasta ja budjetista. Maalämpöpumppu on tehokkain (SPF 2,8-3,2) ja sopii hyvin suuriin taloihin. Ilma-vesilämpöpumppu on edullisempi investointi ja sopii vesikiertoiseen lämmitykseen. Ilmalämpöpumppu on edullisin vaihtoehto täydentämään olemassa olevaa lämmitystä.</p>
                </details>

                <details class="bg-white rounded-xl border border-slate-200 p-4 group">
                    <summary class="font-semibold text-slate-900 cursor-pointer list-none flex justify-between items-center">
                        Mikä on lämpöpumpun takaisinmaksuaika?
                        <svg class="w-5 h-5 text-slate-500 group-open:rotate-180 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path></svg>
                    </summary>
                    <p class="mt-3 text-slate-600">Lämpöpumpun takaisinmaksuaika riippuu investoinnin hinnasta, nykyisestä lämmitystavasta ja energian hinnoista. Tyypillisesti öljylämmityksen korvaamisessa takaisinmaksuaika on 5-10 vuotta, suoran sähkölämmityksen korvaamisessa 8-15 vuotta. Kaukolämmön korvaaminen on harvoin taloudellisesti kannattavaa.</p>
                </details>

                <details class="bg-white rounded-xl border border-slate-200 p-4 group">
                    <summary class="font-semibold text-slate-900 cursor-pointer list-none flex justify-between items-center">
                        Paljonko maalämpöpumppu säästää vuodessa?
                        <svg class="w-5 h-5 text-slate-500 group-open:rotate-180 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path></svg>
                    </summary>
                    <p class="mt-3 text-slate-600">Maalämpöpumppu voi säästää 50-70% lämmityskustannuksista verrattuna suoraan sähkölämmitykseen. 150 m² talossa, joka kuluttaa 20 000 kWh lämmitysenergiaa vuodessa, säästö voi olla 1500-2500 euroa vuodessa sähkön hinnasta riippuen.</p>
                </details>

                <details class="bg-white rounded-xl border border-slate-200 p-4 group">
                    <summary class="font-semibold text-slate-900 cursor-pointer list-none flex justify-between items-center">
                        Toimiiko ilmalämpöpumppu kovilla pakkasilla?
                        <svg class="w-5 h-5 text-slate-500 group-open:rotate-180 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path></svg>
                    </summary>
                    <p class="mt-3 text-slate-600">Nykyaikaiset ilmalämpöpumput toimivat jopa -25 asteen pakkasilla, mutta hyötysuhde heikkenee lämpötilan laskiessa. Alle -15 asteen pakkasilla ilmalämpöpumppu tarvitsee tuekseen muuta lämmitystä. Maalämpö toimii tehokkaasti kaikissa olosuhteissa, koska maaperän lämpötila pysyy tasaisena ympäri vuoden.</p>
                </details>

                <details class="bg-white rounded-xl border border-slate-200 p-4 group">
                    <summary class="font-semibold text-slate-900 cursor-pointer list-none flex justify-between items-center">
                        Miten lämpöpumppu vaikuttaa asunnon arvoon?
                        <svg class="w-5 h-5 text-slate-500 group-open:rotate-180 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path></svg>
                    </summary>
                    <p class="mt-3 text-slate-600">Lämpöpumppu nostaa asunnon arvoa ja houkuttelevuutta ostajien silmissä. Erityisesti maalämpö ja ilma-vesilämpöpumppu nähdään arvoa nostavina investointeina. Öljylämmitteisen talon myynti on vaikeampaa, joten lämmitystavan vaihto voi olla järkevää myös myyntiä ajatellen.</p>
                </details>
            </div>
        </section>

        <!-- Additional SEO Content -->
        <section class="prose prose-slate max-w-none mb-8">
            <h2 class="text-2xl font-bold text-slate-900 mb-4">Lämpöpumput Suomessa {{ date('Y') }}</h2>
            <p class="text-slate-600 mb-4">
                Lämpöpumput ovat nousseet suosituimmaksi lämmitysratkaisuksi uusissa suomalaisissa pientaloissa.
                Ne tarjoavat merkittäviä säästöjä lämmityskustannuksissa verrattuna suoraan sähkölämmitykseen
                tai öljylämmitykseen. Lämpöpumpun toimintaperiaate on yksinkertainen: se siirtää lämpöenergiaa
                ulkoilmasta, maaperästä tai poistoilmasta rakennuksen sisälle.
            </p>
            <p class="text-slate-600 mb-4">
                Maalämpöpumppu on tehokkain vaihtoehto, sillä se hyödyntää maaperän tasaista lämpötilaa.
                Sen vuotuinen hyötysuhde (SPF) on tyypillisesti 2,8-3,2, mikä tarkoittaa että jokaista
                käytettyä sähkökilowattituntia kohden saadaan lähes kolme kilowattituntia lämpöenergiaa.
            </p>
            <p class="text-slate-600">
                Tämä lämpöpumppulaskuri auttaa vertailemaan eri vaihtoehtoja ja arvioimaan investoinnin
                kannattavuutta. Laskuri huomioi rakennuksen koon, sijainnin, nykyisen lämmitystavan
                ja energian hinnat. Tulokset ovat suuntaa antavia, ja tarkemman arvion saat pyytämällä
                tarjouksen lämpöpumppuasennusyrityksiltä.
            </p>
        </section>

    </div>
</div>
