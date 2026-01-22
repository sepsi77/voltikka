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

        <!-- Results Section -->
        @if ($this->hasResults)
            <!-- Energy Need Summary -->
            <section class="bg-slate-100 rounded-2xl p-6 mb-8">
                <h3 class="font-semibold text-slate-900 mb-4">Arvioitu lämmitysenergian tarve</h3>
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

            <!-- Current System -->
            <section class="bg-slate-800 rounded-2xl p-6 mb-8 text-white">
                <h3 class="font-semibold mb-4">Nykyinen järjestelmä: {{ $this->currentSystem['label'] }}</h3>
                <div class="grid grid-cols-2 sm:grid-cols-3 gap-4">
                    <div>
                        <p class="text-slate-400 text-sm">Vuosikustannus</p>
                        <p class="text-2xl font-bold">{{ number_format($this->currentSystem['annualCost'], 0, ',', ' ') }} €</p>
                    </div>
                    <div>
                        <p class="text-slate-400 text-sm">CO₂-päästöt</p>
                        <p class="text-2xl font-bold">{{ number_format($this->currentSystem['co2KgPerYear'], 0, ',', ' ') }} kg</p>
                    </div>
                    <div>
                        <p class="text-slate-400 text-sm">Vertailukohta</p>
                        <p class="text-lg font-medium text-slate-300">Säästöt lasketaan tähän verrattuna</p>
                    </div>
                </div>
            </section>

            <!-- Alternatives -->
            <section class="mb-8">
                <h3 class="font-semibold text-slate-900 mb-4">Vaihtoehdot</h3>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    @foreach ($this->alternatives as $index => $alt)
                        @php
                            $isBest = $index === 0;
                            $savingsPositive = $alt['annualSavings'] > 0;
                        @endphp
                        <div class="bg-white rounded-xl border {{ $isBest ? 'border-green-500 ring-2 ring-green-100' : 'border-slate-200' }} p-5 relative">
                            @if ($isBest)
                                <span class="absolute -top-3 left-4 bg-green-500 text-white text-xs font-semibold px-2 py-1 rounded">Suositeltu</span>
                            @endif
                            <h4 class="font-semibold text-slate-900 mb-3">{{ $alt['label'] }}</h4>

                            <div class="space-y-2 text-sm">
                                <div class="flex justify-between">
                                    <span class="text-slate-600">Vuosikustannus</span>
                                    <span class="font-semibold">{{ number_format($alt['annualCost'], 0, ',', ' ') }} €</span>
                                </div>
                                <div class="flex justify-between">
                                    <span class="text-slate-600">Vuosisäästö</span>
                                    <span class="font-semibold {{ $savingsPositive ? 'text-green-600' : 'text-red-600' }}">
                                        {{ $savingsPositive ? '+' : '' }}{{ number_format($alt['annualSavings'], 0, ',', ' ') }} €
                                    </span>
                                </div>
                                <div class="flex justify-between">
                                    <span class="text-slate-600">Investointi</span>
                                    <span class="font-semibold">{{ number_format($alt['investment'], 0, ',', ' ') }} €</span>
                                </div>
                                <div class="flex justify-between">
                                    <span class="text-slate-600">Takaisinmaksuaika</span>
                                    <span class="font-semibold">
                                        @if ($alt['paybackYears'])
                                            {{ number_format($alt['paybackYears'], 1, ',', ' ') }} v
                                        @else
                                            -
                                        @endif
                                    </span>
                                </div>
                                <div class="flex justify-between">
                                    <span class="text-slate-600">CO₂-päästöt</span>
                                    <span class="font-semibold">{{ number_format($alt['co2KgPerYear'], 0, ',', ' ') }} kg</span>
                                </div>
                                <div class="flex justify-between border-t border-slate-100 pt-2 mt-2">
                                    <span class="text-slate-600">Kokonaiskustannus/v*</span>
                                    <span class="font-bold text-coral-600">{{ number_format($alt['annualizedTotalCost'], 0, ',', ' ') }} €</span>
                                </div>
                            </div>

                            @if ($alt['notes'])
                                <p class="mt-3 text-xs text-slate-500 italic">{{ $alt['notes'] }}</p>
                            @endif
                        </div>
                    @endforeach
                </div>
                <p class="mt-3 text-xs text-slate-500">* Kokonaiskustannus sisältää käyttökustannukset ja investoinnin annuiteetin {{ $calculationPeriod }} vuodelle {{ number_format($interestRate, 1, ',', ' ') }}% korolla.</p>
            </section>
        @endif

        <!-- Info Section -->
        <section class="bg-slate-50 rounded-xl p-6 text-sm text-slate-600 mb-8">
            <h3 class="font-semibold text-slate-900 mb-2">Tietoa laskurista</h3>
            <ul class="list-disc list-inside space-y-1">
                <li>Lämmitysenergian tarve perustuu rakennuksen tilavuuteen ja energiatehokkuuteen</li>
                <li>Lämpöpumppujen hyötysuhteet (SPF/COP) ovat tyypillisiä suomalaisissa olosuhteissa</li>
                <li>Investointikustannukset ovat keskimääräisiä avaimet käteen -hintoja</li>
                <li>Todellinen säästö riippuu käyttötottumuksista ja energian hintojen kehityksestä</li>
                <li>CO₂-päästöt perustuvat Suomen keskimääräisiin päästökertoimiin</li>
            </ul>
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
