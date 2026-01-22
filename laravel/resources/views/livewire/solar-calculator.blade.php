<div>
    {{-- FAQ Schema for SEO --}}
    <script type="application/ld+json">
    {
        "@context": "https://schema.org",
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

    <!-- Hero Section - Dark slate background -->
    <section class="bg-slate-950 -mx-4 sm:-mx-6 lg:-mx-8 mb-8">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="py-12 lg:py-16 text-center">
                <h1 class="text-3xl md:text-4xl xl:text-5xl font-extrabold text-white tracking-tight leading-none mb-4">
                    Aurinkopaneeli<span class="text-coral-400">laskuri</span>
                </h1>
                <p class="max-w-2xl mx-auto text-slate-300 md:text-lg">
                    Laske aurinkopaneelien arvioitu vuosituotto osoitteesi perusteella.
                </p>
            </div>
        </div>
    </section>

    <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 py-8">

        <!-- Introduction -->
        <div class="mb-8 text-center max-w-2xl mx-auto">
            <p class="text-slate-600 mb-4">
                Aurinkopaneelilaskuri auttaa sinua arvioimaan, paljonko aurinkopaneelit tuottaisivat sähköä kotonasi. Syötä osoitteesi, valitse järjestelmän koko ja mahdollinen varjostus - laskuri laskee arvion automaattisesti.
            </p>
            <p class="text-slate-500 text-sm">
                Laskuri käyttää Euroopan komission PVGIS-tietokantaa, joka sisältää tarkat auringonsäteilytiedot kaikille Suomen sijainnille.
            </p>
        </div>

        <!-- Calculator Section -->
        <section class="bg-white rounded-2xl shadow-sm border border-slate-100 p-6 mb-8">

            <!-- Address Input -->
            <div class="mb-8">
                <h4 class="font-semibold text-slate-900 mb-4">Osoite</h4>
                <div class="relative">
                    <div class="flex items-center">
                        <div class="relative flex-1">
                            <input
                                type="text"
                                wire:model.live.debounce.300ms="addressQuery"
                                wire:keydown.escape="hideSuggestions"
                                placeholder="Kirjoita osoite..."
                                class="w-full px-4 py-3 pr-10 border border-slate-300 rounded-lg focus:ring-2 focus:ring-coral-500 focus:border-coral-500"
                                autocomplete="off"
                            >
                            {{-- Loading spinner for address search --}}
                            <div wire:loading wire:target="addressQuery" class="absolute right-3 top-1/2 -translate-y-1/2">
                                <svg class="animate-spin h-5 w-5 text-coral-500" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                </svg>
                            </div>
                            @if ($selectedLabel)
                                <button
                                    wire:click="clearAddress"
                                    wire:loading.remove
                                    wire:target="addressQuery"
                                    class="absolute right-3 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-600"
                                    title="Tyhjennä"
                                >
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                                    </svg>
                                </button>
                            @endif
                        </div>
                    </div>

                    <!-- Suggestions Dropdown -->
                    @if ($showSuggestions && count($addressSuggestions) > 0)
                        <div class="absolute z-10 w-full mt-1 bg-white border border-slate-200 rounded-lg shadow-lg max-h-60 overflow-y-auto">
                            @foreach ($addressSuggestions as $suggestion)
                                <button
                                    wire:click="selectAddress('{{ addslashes($suggestion['label']) }}', {{ $suggestion['lat'] }}, {{ $suggestion['lon'] }})"
                                    wire:loading.attr="disabled"
                                    wire:loading.class="opacity-50 cursor-wait"
                                    class="w-full px-4 py-3 text-left hover:bg-slate-50 border-b border-slate-100 last:border-0 transition-colors"
                                >
                                    <div class="flex items-center">
                                        <svg wire:loading.remove wire:target="selectAddress" class="w-5 h-5 mr-3 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"></path>
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"></path>
                                        </svg>
                                        <svg wire:loading wire:target="selectAddress" class="animate-spin w-5 h-5 mr-3 text-coral-500" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                        </svg>
                                        <span class="text-slate-700">{{ $suggestion['label'] }}</span>
                                    </div>
                                </button>
                            @endforeach
                        </div>
                    @endif
                </div>

                @if ($selectedLabel)
                    <div class="mt-3 flex items-center text-sm text-green-600">
                        <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                        </svg>
                        Sijainti valittu: {{ number_format($selectedLat, 4, ',', ' ') }}°N, {{ number_format($selectedLon, 4, ',', ' ') }}°E
                    </div>
                @endif
            </div>

            <!-- System Size Selection -->
            <div class="mb-8">
                <div class="flex items-center justify-between mb-4">
                    <h4 class="font-semibold text-slate-900">Järjestelmän koko</h4>
                    <svg wire:loading wire:target="systemKwp" class="animate-spin h-4 w-4 text-coral-500" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                    </svg>
                </div>
                {{-- Preset buttons + custom input in one row --}}
                <div class="flex flex-wrap items-center gap-2">
                    @foreach ([3, 5, 8, 10, 15] as $preset)
                        <button
                            wire:click="$set('systemKwp', {{ $preset }})"
                            wire:loading.attr="disabled"
                            wire:loading.class="opacity-50"
                            wire:target="systemKwp"
                            class="py-2 px-4 border rounded-lg text-center transition-all text-sm font-medium {{ $systemKwp == $preset ? 'border-coral-500 bg-coral-50 text-coral-700' : 'border-slate-200 hover:border-slate-300 text-slate-700' }}"
                        >
                            {{ $preset }}
                        </button>
                    @endforeach
                    <div class="flex items-center border border-slate-200 rounded-lg overflow-hidden">
                        <input
                            type="number"
                            wire:model.live.debounce.500ms="systemKwp"
                            min="0.5"
                            max="50"
                            step="0.5"
                            class="w-16 px-2 py-2 border-0 focus:ring-0 text-sm text-center"
                            placeholder="Muu"
                        >
                        <span class="px-2 py-2 bg-slate-50 text-sm text-slate-500 border-l border-slate-200">kWp</span>
                    </div>
                </div>
                <p class="text-sm text-slate-500 mt-3">
                    Tyypillinen kotitalousjärjestelmä on 5–10 kWp. 1 kWp ≈ 5 m² kattopinta-alaa.
                </p>
            </div>

            <!-- Shading Selection -->
            <div class="mb-8">
                <div class="flex items-center justify-between mb-4">
                    <h4 class="font-semibold text-slate-900">Varjostus</h4>
                    <svg wire:loading wire:target="shadingLevel" class="animate-spin h-4 w-4 text-coral-500" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                    </svg>
                </div>
                <div class="grid grid-cols-3 gap-4">
                    @foreach ($shadingLabels as $level => $label)
                        @php
                            $icons = [
                                'none' => 'M12 3v1m0 16v1m9-9h-1M4 12H3m15.364 6.364l-.707-.707M6.343 6.343l-.707-.707m12.728 0l-.707.707M6.343 17.657l-.707.707M16 12a4 4 0 11-8 0 4 4 0 018 0z',
                                'some' => 'M3 15a4 4 0 004 4h9a5 5 0 10-.1-9.999 5.002 5.002 0 10-9.78 2.096A4.001 4.001 0 003 15z',
                                'heavy' => 'M3 15a4 4 0 004 4h9a5 5 0 10-.1-9.999 5.002 5.002 0 10-9.78 2.096A4.001 4.001 0 003 15zM9.75 15L6 18.75M13.5 15L8.25 20.25M17.25 15L15 17.25',
                            ];
                        @endphp
                        <button
                            wire:click="$set('shadingLevel', '{{ $level }}')"
                            wire:loading.attr="disabled"
                            wire:loading.class="opacity-50"
                            wire:target="shadingLevel"
                            class="p-4 border rounded-xl text-center transition-all {{ $shadingLevel === $level ? 'border-coral-500 bg-coral-50' : 'border-slate-100 hover:border-slate-300' }}"
                        >
                            <svg class="w-8 h-8 mx-auto mb-2 {{ $shadingLevel === $level ? 'text-coral-600' : 'text-slate-500' }}" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="{{ $icons[$level] }}"></path>
                            </svg>
                            <span class="text-sm font-medium {{ $shadingLevel === $level ? 'text-coral-700' : 'text-slate-700' }}">{{ $label }}</span>
                        </button>
                    @endforeach
                </div>
            </div>

            <!-- Savings Calculation Section -->
            <div class="border-t border-slate-200 pt-8 mt-8">
                <h3 class="text-lg font-semibold text-slate-900 mb-6">Säästölaskuri</h3>

                <!-- Price Input -->
                <div class="mb-6">
                    <div class="flex items-center justify-between mb-2">
                        <label class="block font-semibold text-slate-900">Sähkön hinta (c/kWh)</label>
                        <svg wire:loading wire:target="manualPrice" class="animate-spin h-4 w-4 text-coral-500" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                        </svg>
                    </div>
                    <input
                        type="number"
                        wire:model.live.debounce.300ms="manualPrice"
                        min="0"
                        max="100"
                        step="0.1"
                        class="w-full px-4 py-3 border border-slate-300 rounded-lg focus:ring-2 focus:ring-coral-500 focus:border-coral-500"
                    >
                    <p class="mt-1 text-sm text-slate-500">
                        Oletusarvo on viimeisen vuoden spot-hinnan keskiarvo (sis. ALV). Voit muuttaa hinnan sähkösopimuksesi mukaiseksi.
                    </p>
                </div>

                <!-- Self-Consumption Slider -->
                <div class="mb-6">
                    <div class="flex items-center justify-between mb-2">
                        <h4 class="font-semibold text-slate-900">Oman käytön osuus</h4>
                        <div class="flex items-center gap-2">
                            <svg wire:loading wire:target="selfConsumptionPercent" class="animate-spin h-4 w-4 text-coral-500" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                            </svg>
                            <span class="text-coral-600 font-bold text-lg">{{ $selfConsumptionPercent }}%</span>
                        </div>
                    </div>
                    <input
                        type="range"
                        wire:model.live.debounce.200ms="selfConsumptionPercent"
                        min="10"
                        max="80"
                        step="5"
                        class="w-full h-2 bg-slate-200 rounded-lg appearance-none cursor-pointer accent-coral-500"
                    >
                    <div class="flex justify-between text-xs text-slate-500 mt-1">
                        <span>10%</span>
                        <span>80%</span>
                    </div>
                    <p class="text-sm text-slate-500 mt-2">
                        Kuinka suuren osan tuotetusta sähköstä käytät itse. Tyypillisesti 20-40%.
                    </p>
                </div>
            </div>

            <!-- Error Message -->
            @if ($errorMessage)
                <div class="mb-6 p-4 bg-red-50 border border-red-200 rounded-xl text-red-700">
                    {{ $errorMessage }}
                </div>
            @endif

        </section>

        <!-- Results Section -->
        @if ($this->hasResults)
            <section class="bg-gradient-to-br from-coral-500 to-coral-600 rounded-2xl shadow-lg p-6 text-white mb-8 relative">
                {{-- Loading overlay for results --}}
                <div
                    wire:loading
                    wire:target="systemKwp, shadingLevel, selectAddress"
                    class="absolute inset-0 bg-coral-600/80 rounded-2xl flex items-center justify-center z-10"
                >
                    <div class="flex items-center gap-3">
                        <svg class="animate-spin h-6 w-6 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                        </svg>
                        <span class="text-white font-medium">Lasketaan...</span>
                    </div>
                </div>
                <div class="text-center mb-6">
                    <p class="text-coral-100 text-sm mb-1">Arvioitu vuosituotto</p>
                    <p class="text-5xl font-bold">
                        {{ number_format($this->annualKwh, 0, ',', ' ') }}
                        <span class="text-2xl font-normal">kWh</span>
                    </p>
                </div>

                <!-- Monthly Breakdown Chart -->
                @if (count($this->monthlyKwh) === 12)
                    <div class="mb-6">
                        <p class="text-coral-100 text-sm mb-3 text-center">Kuukausituotto</p>
                        {{-- Inline bar chart with fixed pixel heights and Alpine.js tooltips --}}
                        <div
                            x-data="{ activeBar: null }"
                            x-on:click.outside="activeBar = null"
                            class="relative"
                            style="display: flex; align-items: flex-end; gap: 4px; height: 80px;"
                        >
                            @foreach ($this->monthlyKwh as $index => $kwh)
                                @php
                                    $barHeight = $this->maxMonthlyKwh > 0
                                        ? max(4, round(($kwh / $this->maxMonthlyKwh) * 76))
                                        : 4;
                                @endphp
                                <div
                                    class="relative"
                                    style="flex: 1; height: {{ $barHeight }}px; background-color: rgba(255,255,255,0.5); border-radius: 2px 2px 0 0; cursor: pointer;"
                                    x-on:mouseenter="activeBar = {{ $index }}"
                                    x-on:mouseleave="activeBar = null"
                                    x-on:click="activeBar = activeBar === {{ $index }} ? null : {{ $index }}"
                                >
                                    {{-- Tooltip - positioned above the chart --}}
                                    <div
                                        x-show="activeBar === {{ $index }}"
                                        x-transition:enter="transition ease-out duration-100"
                                        x-transition:enter-start="opacity-0"
                                        x-transition:enter-end="opacity-100"
                                        class="absolute left-1/2 -translate-x-1/2 px-3 py-2 text-sm font-medium rounded-lg shadow-lg whitespace-nowrap pointer-events-none z-10"
                                        style="background-color: #1e293b; color: white; bottom: 90px;"
                                    >
                                        {{ $monthNamesFull[$index] }}: {{ number_format($kwh, 0, ',', ' ') }} kWh
                                    </div>
                                </div>
                            @endforeach
                        </div>
                        {{-- Month labels with kWh values --}}
                        <div style="display: flex; gap: 4px; margin-top: 4px;">
                            @foreach ($this->monthlyKwh as $index => $kwh)
                                <div style="flex: 1; text-align: center;">
                                    <span class="text-[10px] text-coral-100 font-medium">{{ $index + 1 }}</span>
                                    <span class="block text-[8px] text-coral-100/70">{{ number_format($kwh, 0) }}</span>
                                </div>
                            @endforeach
                        </div>
                        <p class="text-coral-100/70 text-[10px] text-center mt-1">Kuukausi / kWh</p>
                    </div>
                @endif

                <!-- Summary Stats -->
                <div class="grid grid-cols-2 sm:grid-cols-3 gap-4 mb-6">
                    <div class="bg-white/10 rounded-lg p-3">
                        <p class="text-coral-100 text-xs">Järjestelmän koko</p>
                        <p class="text-lg font-semibold">{{ number_format($systemKwp, 1, ',', ' ') }} kWp</p>
                    </div>

                    <div class="bg-white/10 rounded-lg p-3">
                        <p class="text-coral-100 text-xs">Keskituotto/kk</p>
                        <p class="text-lg font-semibold">{{ number_format($this->annualKwh / 12, 0, ',', ' ') }} kWh</p>
                    </div>

                    <div class="bg-white/10 rounded-lg p-3">
                        <p class="text-coral-100 text-xs">Tuotto/kWp</p>
                        <p class="text-lg font-semibold">{{ number_format($this->annualKwh / max(0.1, $systemKwp), 0, ',', ' ') }} kWh</p>
                    </div>
                </div>

                <!-- Assumptions -->
                @if (!empty($calculationResult['assumptions']))
                    <div class="bg-white/10 rounded-lg p-3 text-sm">
                        <p class="text-coral-100 text-xs mb-2">Laskenta-arvot</p>
                        <ul class="text-coral-50 space-y-1">
                            @if (isset($calculationResult['assumptions']['tilt']))
                                <li>Kaltevuus: {{ $calculationResult['assumptions']['tilt'] }}°</li>
                            @endif
                            @if (isset($calculationResult['assumptions']['azimuth']))
                                <li>Suuntaus: {{ $calculationResult['assumptions']['azimuth'] }}° (0° = etelä)</li>
                            @endif
                            @if (isset($calculationResult['assumptions']['loss_percent']))
                                <li>Häviöt: {{ $calculationResult['assumptions']['loss_percent'] }}%</li>
                            @endif
                        </ul>
                    </div>
                @endif
            </section>

            <!-- Savings Section -->
            @if ($this->hasSavings)
                <section class="rounded-2xl shadow-lg p-6 mb-8 relative" style="background-color: #16a34a; color: white;">
                    {{-- Loading overlay for savings --}}
                    <div
                        wire:loading
                        wire:target="systemKwp, shadingLevel, selectAddress, manualPrice, selfConsumptionPercent"
                        class="absolute inset-0 rounded-2xl flex items-center justify-center z-10"
                        style="background-color: rgba(22, 163, 74, 0.9);"
                    >
                        <div class="flex items-center gap-3">
                            <svg class="animate-spin h-6 w-6 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                            </svg>
                            <span class="text-white font-medium">Lasketaan...</span>
                        </div>
                    </div>
                    <div class="text-center mb-4">
                        <p class="text-sm mb-1" style="color: rgba(255,255,255,0.8);">Arvioitu säästö</p>
                        <p class="text-4xl font-bold" style="color: white;">
                            {{ number_format($this->annualSavings, 0, ',', ' ') }}
                            <span class="text-xl font-normal">€/vuosi</span>
                        </p>
                    </div>

                    <div class="grid grid-cols-2 gap-4 mb-4">
                        <div class="rounded-lg p-3" style="background-color: #15803d;">
                            <p class="text-xs" style="color: rgba(255,255,255,0.7);">Oma käyttö</p>
                            <p class="text-lg font-semibold" style="color: white;">{{ $selfConsumptionPercent }}%</p>
                        </div>
                        <div class="rounded-lg p-3" style="background-color: #15803d;">
                            <p class="text-xs" style="color: rgba(255,255,255,0.7);">Sähkön hinta</p>
                            <p class="text-lg font-semibold" style="color: white;">{{ number_format($this->effectivePrice, 2, ',', ' ') }} c/kWh</p>
                        </div>
                    </div>

                    <p class="text-xs text-center" style="color: rgba(255,255,255,0.7);">
                        Säästö = {{ number_format($this->annualKwh, 0, ',', ' ') }} kWh × {{ $selfConsumptionPercent }}% × {{ number_format($this->effectivePrice, 2, ',', ' ') }} c/kWh
                    </p>
                </section>
            @endif
        @elseif ($isCalculating)
            <section class="bg-slate-100 rounded-2xl p-6 mb-8 text-center">
                <div class="animate-pulse">
                    <div class="h-8 w-48 bg-slate-300 rounded mx-auto mb-4"></div>
                    <div class="h-4 w-32 bg-slate-200 rounded mx-auto"></div>
                </div>
            </section>
        @elseif (!$selectedLabel)
            <section class="bg-slate-100 rounded-2xl p-6 mb-8 text-center text-slate-600">
                <svg class="w-12 h-12 mx-auto mb-4 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"></path>
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"></path>
                </svg>
                <p>Syötä osoite aloittaaksesi laskennan</p>
            </section>
        @endif

        <!-- Info Section -->
        <section class="bg-slate-50 rounded-xl p-6 text-sm text-slate-600 mb-8">
            <h3 class="font-semibold text-slate-900 mb-2">Tietoa laskurista</h3>
            <ul class="list-disc list-inside space-y-1">
                <li>Tuottoarvio perustuu PVGIS-tietokantaan (EU Joint Research Centre)</li>
                <li>Tyypillinen suomalainen aurinkopaneelijärjestelmä tuottaa 800-1000 kWh/kWp vuodessa</li>
                <li>Tuotanto vaihtelee huomattavasti vuodenajan mukaan: kesällä jopa 10x enemmän kuin talvella</li>
                <li>Optimi kaltevuus Suomessa on noin 40-45° ja suuntaus etelään</li>
                <li>Varjostus, lumi ja pöly vähentävät todellista tuottoa arviosta</li>
            </ul>
        </section>

        <!-- FAQ Section for SEO -->
        <section class="mb-8">
            <h2 class="text-2xl font-bold text-slate-900 mb-6">Usein kysytyt kysymykset aurinkopaneeleista</h2>

            <div class="space-y-4">
                <details class="bg-white rounded-xl border border-slate-200 p-4 group">
                    <summary class="font-semibold text-slate-900 cursor-pointer list-none flex justify-between items-center">
                        Paljonko aurinkopaneelit tuottavat Suomessa?
                        <svg class="w-5 h-5 text-slate-500 group-open:rotate-180 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path></svg>
                    </summary>
                    <p class="mt-3 text-slate-600">Suomessa aurinkopaneelit tuottavat tyypillisesti 800–1000 kWh vuodessa jokaista asennettua kilowattipiikkiä (kWp) kohden. Etelä-Suomessa tuotto on hieman korkeampi kuin Pohjois-Suomessa. Esimerkiksi 5 kWp:n järjestelmä tuottaa Helsingissä noin 4500–5000 kWh vuodessa.</p>
                </details>

                <details class="bg-white rounded-xl border border-slate-200 p-4 group">
                    <summary class="font-semibold text-slate-900 cursor-pointer list-none flex justify-between items-center">
                        Kuinka paljon aurinkopaneelit säästävät sähkölaskussa?
                        <svg class="w-5 h-5 text-slate-500 group-open:rotate-180 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path></svg>
                    </summary>
                    <p class="mt-3 text-slate-600">Säästö riippuu sähkön hinnasta ja siitä, kuinka suuren osan tuotetusta sähköstä käytät itse. Tyypillisesti kotitalous käyttää itse 20–40% aurinkopaneelien tuottamasta sähköstä. Jos sähkön hinta on 10 c/kWh ja 5 kWp:n järjestelmä tuottaa 4500 kWh vuodessa, 30% omakäytöllä säästö on noin 135 €/vuosi.</p>
                </details>

                <details class="bg-white rounded-xl border border-slate-200 p-4 group">
                    <summary class="font-semibold text-slate-900 cursor-pointer list-none flex justify-between items-center">
                        Mikä on paras kattokaltevuus aurinkopaneeleille?
                        <svg class="w-5 h-5 text-slate-500 group-open:rotate-180 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path></svg>
                    </summary>
                    <p class="mt-3 text-slate-600">Suomessa optimaalinen kattokaltevuus aurinkopaneeleille on noin 40–45 astetta etelään suunnattuna. Tämä kaltevuus maksimoi vuosituoton ja auttaa myös lumen valumisessa talvella. Loivemmat tai jyrkemmät kaltevuudet toimivat myös, mutta tuotto voi olla 5–15% pienempi.</p>
                </details>

                <details class="bg-white rounded-xl border border-slate-200 p-4 group">
                    <summary class="font-semibold text-slate-900 cursor-pointer list-none flex justify-between items-center">
                        Miten varjostus vaikuttaa aurinkopaneelien tuottoon?
                        <svg class="w-5 h-5 text-slate-500 group-open:rotate-180 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path></svg>
                    </summary>
                    <p class="mt-3 text-slate-600">Varjostus voi vähentää aurinkopaneelien tuottoa merkittävästi. Jo yhden paneelin osittainen varjostus voi vaikuttaa koko sarjan tuottoon. Lähellä olevat puut, rakennukset tai savupiiput voivat aiheuttaa 5–20% tuottotappion. Modernit mikroinvertterit ja optimoijat voivat minimoida varjostuksen vaikutusta.</p>
                </details>

                <details class="bg-white rounded-xl border border-slate-200 p-4 group">
                    <summary class="font-semibold text-slate-900 cursor-pointer list-none flex justify-between items-center">
                        Kuinka suuri aurinkopaneelijärjestelmä tarvitaan omakotitaloon?
                        <svg class="w-5 h-5 text-slate-500 group-open:rotate-180 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path></svg>
                    </summary>
                    <p class="mt-3 text-slate-600">Tyypillinen omakotitalon aurinkopaneelijärjestelmä on 5–10 kWp. Järjestelmän koko riippuu sähkönkulutuksesta, käytettävissä olevasta kattopinta-alasta ja budjetista. 1 kWp vaatii noin 5–6 m² kattopinta-alaa. Sähkölämmitteisessä talossa suurempi järjestelmä (8–15 kWp) voi olla järkevä.</p>
                </details>

                <details class="bg-white rounded-xl border border-slate-200 p-4 group">
                    <summary class="font-semibold text-slate-900 cursor-pointer list-none flex justify-between items-center">
                        Toimivatko aurinkopaneelit talvella Suomessa?
                        <svg class="w-5 h-5 text-slate-500 group-open:rotate-180 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path></svg>
                    </summary>
                    <p class="mt-3 text-slate-600">Kyllä, aurinkopaneelit toimivat myös talvella, mutta tuotto on huomattavasti pienempi kuin kesällä. Joulukuussa tuotto voi olla vain 5–10% kesäkuun tuotosta. Lumi paneelien päällä estää tuotannon, mutta sopivalla kaltevuudella lumi usein valuu itsestään pois. Paneelit toimivat parhaiten kylmässä – hyötysuhde on jopa parempi pakkasella kuin helteellä.</p>
                </details>
            </div>
        </section>

        <!-- Additional SEO Content -->
        <section class="prose prose-slate max-w-none mb-8">
            <h2 class="text-2xl font-bold text-slate-900 mb-4">Aurinkopaneelit Suomessa {{ date('Y') }}</h2>
            <p class="text-slate-600 mb-4">
                Aurinkopaneelit ovat yleistyneet nopeasti Suomessa viime vuosina. Vaikka Suomi sijaitsee pohjoisessa,
                aurinkopaneelit tuottavat yllättävän hyvin erityisesti keväällä ja kesällä. Pitkät kesäpäivät kompensoivat
                talven pimeyttä, ja vuosituotto on vertailukelpoinen Keski-Euroopan maiden kanssa.
            </p>
            <p class="text-slate-600 mb-4">
                Aurinkopaneelijärjestelmän kannattavuus riippuu useasta tekijästä: sähkön hinnasta, omakäyttöasteesta,
                järjestelmän hinnasta ja käytettävissä olevista tuista. Nykyisillä sähkön hinnoilla ja
                paneelien alhaisemmilla kustannuksilla takaisinmaksuaika on tyypillisesti 8–15 vuotta.
            </p>
            <p class="text-slate-600">
                Tämä aurinkopaneelilaskuri käyttää Euroopan komission PVGIS-tietokantaa, joka sisältää tarkat
                auringonsäteilytiedot kaikille Suomen sijainneille. Laskuri huomioi sijainnin, järjestelmän koon
                ja varjostuksen vaikutuksen tuottoarvioon.
            </p>
        </section>

    </div>
</div>
