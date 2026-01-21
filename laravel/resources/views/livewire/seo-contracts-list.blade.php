<div>
    {{-- JSON-LD Structured Data --}}
    @if(!empty($seoData['jsonLd']))
    <script type="application/ld+json">
        {!! json_encode($seoData['jsonLd'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) !!}
    </script>
    @endif

    {{-- SEO Hero Section - Dark slate background with gradient --}}
    <section class="bg-gradient-to-br from-slate-900 via-slate-900 to-slate-950 -mx-4 sm:-mx-6 lg:-mx-8 mb-8 relative overflow-hidden">
        {{-- Decorative gradient blobs --}}
        <div class="absolute inset-0 pointer-events-none">
            <div class="absolute top-0 right-1/4 w-96 h-96 bg-coral-500 rounded-full blur-3xl opacity-20"></div>
            <div class="absolute bottom-0 left-0 w-72 h-72 bg-coral-400 rounded-full blur-3xl opacity-10 -translate-x-1/2"></div>
        </div>

        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 relative">
            <div class="grid max-w-screen-xl py-12 mx-auto lg:gap-8 xl:gap-0 lg:py-20 lg:grid-cols-12">
                <div class="mx-auto place-self-center col-12 lg:col-span-7">
                    <div class="inline-flex items-center gap-2 bg-coral-500/20 backdrop-blur-sm px-4 py-2 rounded-full text-sm font-medium text-coral-300 mb-6 border border-coral-500/20">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/>
                        </svg>
                        Vertaile älykkäästi
                    </div>
                    <h1 class="max-w-2xl mb-4 text-4xl font-extrabold text-white tracking-tight leading-tight md:text-5xl xl:text-6xl">
                        {{ $pageHeading }}
                    </h1>
                    <p class="max-w-2xl mb-6 text-slate-300 lg:mb-8 md:text-lg lg:text-xl">
                        {{ $seoIntroText }}
                    </p>
                </div>
                <div class="lg:mt-0 col-12 lg:col-span-5 lg:flex mx-auto mt-8 lg:mt-0">
                    {{-- Decorative element placeholder --}}
                </div>
            </div>
        </div>
    </section>

    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">

    {{-- Breadcrumb Navigation --}}
    @if($hasSeoFilter)
    <nav class="mb-6" aria-label="Breadcrumb">
        <ol class="flex items-center space-x-2 text-sm text-slate-500">
            <li>
                <a href="/" class="hover:text-coral-600">Etusivu</a>
            </li>
            <li>
                <svg class="w-4 h-4 mx-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                </svg>
            </li>
            <li>
                <a href="/" class="hover:text-coral-600">Sähkösopimukset</a>
            </li>
            <li>
                <svg class="w-4 h-4 mx-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                </svg>
            </li>
            <li class="font-medium text-slate-900" aria-current="page">
                {{ $pageHeading }}
            </li>
        </ol>
    </nav>
    @endif

    {{-- Energy Source Statistics Section --}}
    @if($isEnergySourcePage && !empty($energySourceStats))
    <section class="bg-white rounded-2xl shadow-sm border border-slate-100 p-6 mb-8">
        <h2 class="text-xl font-bold text-slate-900 mb-4">Energialähteiden tilastot</h2>
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
            <div class="text-center p-4 bg-green-50 rounded-lg">
                <div class="text-2xl font-bold text-green-600">{{ $energySourceStats['avg_renewable'] ?? 0 }}%</div>
                <div class="text-sm text-slate-600">Uusiutuva keskiarvo</div>
            </div>
            @if(($energySourceStats['avg_wind'] ?? 0) > 0)
            <div class="text-center p-4 bg-blue-50 rounded-lg">
                <div class="text-2xl font-bold text-blue-600">{{ $energySourceStats['avg_wind'] }}%</div>
                <div class="text-sm text-slate-600">Tuulivoima keskiarvo</div>
            </div>
            @endif
            @if(($energySourceStats['avg_solar'] ?? 0) > 0)
            <div class="text-center p-4 bg-yellow-50 rounded-lg">
                <div class="text-2xl font-bold text-yellow-600">{{ $energySourceStats['avg_solar'] }}%</div>
                <div class="text-sm text-slate-600">Aurinkovoima keskiarvo</div>
            </div>
            @endif
            @if(($energySourceStats['avg_hydro'] ?? 0) > 0)
            <div class="text-center p-4 bg-coral-50 rounded-lg">
                <div class="text-2xl font-bold text-coral-600">{{ $energySourceStats['avg_hydro'] }}%</div>
                <div class="text-sm text-slate-600">Vesivoima keskiarvo</div>
            </div>
            @endif
            <div class="text-center p-4 bg-slate-50 rounded-lg">
                <div class="text-2xl font-bold text-slate-700">{{ $energySourceStats['total_contracts'] ?? 0 }}</div>
                <div class="text-sm text-slate-600">Sopimusta yhteensä</div>
            </div>
            <div class="text-center p-4 bg-green-50 rounded-lg">
                <div class="text-2xl font-bold text-green-600">{{ $energySourceStats['fossil_free_count'] ?? 0 }}</div>
                <div class="text-sm text-slate-600">Fossiilivapaa</div>
            </div>
        </div>
    </section>
    @endif

    {{-- Environmental Impact Section --}}
    @if($isEnergySourcePage && $environmentalInfo)
    <section class="bg-gradient-to-r from-green-50 to-emerald-50 rounded-lg border border-green-200 p-6 mb-8">
        <div class="flex items-start">
            <div class="flex-shrink-0">
                <svg class="w-8 h-8 text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3.055 11H5a2 2 0 012 2v1a2 2 0 002 2 2 2 0 012 2v2.945M8 3.935V5.5A2.5 2.5 0 0010.5 8h.5a2 2 0 012 2 2 2 0 104 0 2 2 0 012-2h1.064M15 20.488V18a2 2 0 012-2h3.064M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                </svg>
            </div>
            <div class="ml-4">
                <h3 class="text-lg font-semibold text-slate-900 mb-2">Ympäristövaikutus</h3>
                <p class="text-slate-700">{{ $environmentalInfo }}</p>
            </div>
        </div>
    </section>
    @endif

    {{-- Consumption Preset Selector --}}
    <section class="bg-transparent text-center mb-8">
        <h3 class="max-w-2xl mb-4 mx-auto text-3xl font-extrabold tracking-tight leading-none">
            Valitse kulutustaso
        </h3>

        {{-- Tab Toggle --}}
        <div class="flex justify-center mb-6">
            <div class="inline-flex rounded-full bg-slate-100 p-1">
                <button
                    wire:click="setActiveTab('presets')"
                    class="px-6 py-2 text-sm font-medium rounded-full transition-colors {{ $activeTab === 'presets' ? 'bg-white text-slate-900 shadow' : 'text-slate-500 hover:text-slate-700' }}"
                >
                    Valmiit profiilit
                </button>
                <button
                    wire:click="setActiveTab('calculator')"
                    class="px-6 py-2 text-sm font-medium rounded-full transition-colors {{ $activeTab === 'calculator' ? 'bg-white text-slate-900 shadow' : 'text-slate-500 hover:text-slate-700' }}"
                >
                    Laskuri
                </button>
            </div>
        </div>

        {{-- Presets Tab --}}
        @if ($activeTab === 'presets')
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-4 max-w-6xl mx-auto">
                @foreach ($presets as $key => $preset)
                    <button
                        wire:click="selectPreset('{{ $key }}')"
                        class="p-5 border-2 rounded-2xl transition-all text-left {{ $selectedPreset === $key ? 'bg-gradient-to-r from-coral-500 to-coral-600 border-coral-500 shadow-coral' : 'bg-white border-slate-200 hover:border-coral-400' }}"
                    >
                        <div class="flex items-start">
                            <span class="{{ $selectedPreset === $key ? 'bg-white/20' : 'bg-slate-100' }} p-2 rounded-xl mr-3 flex-shrink-0">
                                @if ($preset['icon'] === 'apartment')
                                    <svg class="w-6 h-6 {{ $selectedPreset === $key ? 'text-white' : 'text-slate-600' }}" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"></path>
                                    </svg>
                                @else
                                    <svg class="w-6 h-6 {{ $selectedPreset === $key ? 'text-white' : 'text-slate-600' }}" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"></path>
                                    </svg>
                                @endif
                            </span>
                            <div class="flex-1 min-w-0">
                                <h5 class="font-semibold {{ $selectedPreset === $key ? 'text-white' : 'text-slate-900' }} truncate">{{ $preset['label'] }}</h5>
                                <p class="text-sm {{ $selectedPreset === $key ? 'text-white/80' : 'text-slate-500' }}">{{ $preset['description'] }}</p>
                            </div>
                            <svg class="w-6 h-6 flex-shrink-0 ml-2 {{ $selectedPreset === $key ? 'text-white' : 'text-slate-300' }}" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"></path>
                            </svg>
                        </div>
                        <div class="mt-3 text-right">
                            <span class="text-xl font-bold {{ $selectedPreset === $key ? 'text-white' : 'text-slate-900' }}">{{ number_format($preset['consumption'], 0, ',', ' ') }}</span>
                            <span class="{{ $selectedPreset === $key ? 'text-white/80' : 'text-slate-500' }} text-sm ml-1">kWh/v</span>
                        </div>
                    </button>
                @endforeach
            </div>
        @endif

        {{-- Calculator Tab --}}
        @if ($activeTab === 'calculator')
            <div class="max-w-4xl mx-auto">
                <div class="bg-white rounded-2xl shadow-sm border border-slate-100 p-6 text-left">
                    {{-- Row 1: Basic Inputs --}}
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
                        {{-- Living Area --}}
                        <div>
                            <label for="calc-living-area" class="block text-sm font-medium text-slate-700 mb-2">
                                <span class="flex items-center">
                                    <svg class="w-5 h-5 mr-2 text-slate-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"></path>
                                    </svg>
                                    Asuinpinta-ala (m²)
                                </span>
                            </label>
                            <input
                                type="number"
                                id="calc-living-area"
                                wire:model.live.debounce.300ms="calcLivingArea"
                                min="10"
                                max="500"
                                class="w-full px-4 py-3 border border-slate-300 rounded-lg focus:ring-2 focus:ring-coral-500 focus:border-coral-500"
                            >
                        </div>

                        {{-- Number of People --}}
                        <div>
                            <label for="calc-num-people" class="block text-sm font-medium text-slate-700 mb-2">
                                <span class="flex items-center">
                                    <svg class="w-5 h-5 mr-2 text-slate-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"></path>
                                    </svg>
                                    Asukkaiden määrä
                                </span>
                            </label>
                            <input
                                type="number"
                                id="calc-num-people"
                                wire:model.live.debounce.300ms="calcNumPeople"
                                min="1"
                                max="10"
                                class="w-full px-4 py-3 border border-slate-300 rounded-lg focus:ring-2 focus:ring-coral-500 focus:border-coral-500"
                            >
                        </div>
                    </div>

                    {{-- Row 2: Housing Type Cards --}}
                    <div class="mb-6">
                        <label class="block text-sm font-medium text-slate-700 mb-3">Asuntotyyppi</label>
                        <div class="grid grid-cols-3 gap-4">
                            {{-- Detached House --}}
                            <button
                                wire:click="selectBuildingType('detached_house')"
                                class="p-4 rounded-xl border-2 transition-all flex flex-col items-center {{ $calcBuildingType === 'detached_house' ? 'border-coral-500 bg-coral-50' : 'border-slate-100 hover:border-slate-300 bg-white' }}"
                            >
                                <svg class="w-10 h-10 mb-2 {{ $calcBuildingType === 'detached_house' ? 'text-coral-600' : 'text-slate-500' }}" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"></path>
                                </svg>
                                <span class="text-sm font-medium {{ $calcBuildingType === 'detached_house' ? 'text-coral-700' : 'text-slate-700' }}">Omakotitalo</span>
                            </button>

                            {{-- Row House --}}
                            <button
                                wire:click="selectBuildingType('row_house')"
                                class="p-4 rounded-xl border-2 transition-all flex flex-col items-center {{ $calcBuildingType === 'row_house' ? 'border-coral-500 bg-coral-50' : 'border-slate-100 hover:border-slate-300 bg-white' }}"
                            >
                                <svg class="w-10 h-10 mb-2 {{ $calcBuildingType === 'row_house' ? 'text-coral-600' : 'text-slate-500' }}" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 14v3m4-3v3m4-3v3M3 21h18M3 10h18M3 7l9-4 9 4M4 10h16v11H4V10z"></path>
                                </svg>
                                <span class="text-sm font-medium {{ $calcBuildingType === 'row_house' ? 'text-coral-700' : 'text-slate-700' }}">Rivitalo</span>
                            </button>

                            {{-- Apartment --}}
                            <button
                                wire:click="selectBuildingType('apartment')"
                                class="p-4 rounded-xl border-2 transition-all flex flex-col items-center {{ $calcBuildingType === 'apartment' ? 'border-coral-500 bg-coral-50' : 'border-slate-100 hover:border-slate-300 bg-white' }}"
                            >
                                <svg class="w-10 h-10 mb-2 {{ $calcBuildingType === 'apartment' ? 'text-coral-600' : 'text-slate-500' }}" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"></path>
                                </svg>
                                <span class="text-sm font-medium {{ $calcBuildingType === 'apartment' ? 'text-coral-700' : 'text-slate-700' }}">Kerrostalo</span>
                            </button>
                        </div>
                    </div>

                    {{-- Row 3: Include Heating Toggle --}}
                    <div class="bg-slate-50 rounded-xl p-4 mb-6">
                        <label class="flex items-center cursor-pointer">
                            <div class="relative">
                                <input
                                    type="checkbox"
                                    wire:model.live="calcIncludeHeating"
                                    class="sr-only peer"
                                >
                                <div class="w-11 h-6 bg-slate-300 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-coral-300 rounded-full peer peer-checked:after:translate-x-full rtl:peer-checked:after:-translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:start-[2px] after:bg-white after:border-slate-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-coral-500"></div>
                            </div>
                            <span class="ml-3 text-sm font-medium text-slate-900">
                                Sisällytä lämmitys
                            </span>
                        </label>
                        <p class="mt-2 text-sm text-slate-500">
                            Ota käyttöön, jos asuntosi lämmitetään sähköllä tai lämpöpumpulla.
                        </p>
                    </div>

                    {{-- Row 4: Heating Options (shown when heating enabled) --}}
                    @if ($calcIncludeHeating)
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-6 p-4 bg-coral-50 rounded-xl border border-coral-200">
                            {{-- Heating Method --}}
                            <div>
                                <label for="calc-heating-method" class="block text-sm font-medium text-slate-700 mb-2">
                                    <span class="flex items-center">
                                        <svg class="w-4 h-4 mr-2 text-slate-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 18.657A8 8 0 016.343 7.343S7 9 9 10c0-2 .5-5 2.986-7C14 5 16.09 5.777 17.656 7.343A7.975 7.975 0 0120 13a7.975 7.975 0 01-2.343 5.657z"></path>
                                        </svg>
                                        Lämmitysmuoto
                                    </span>
                                </label>
                                <select
                                    id="calc-heating-method"
                                    wire:model.live="calcHeatingMethod"
                                    class="w-full px-4 py-3 border border-slate-300 rounded-lg focus:ring-2 focus:ring-coral-500 focus:border-coral-500 bg-white"
                                >
                                    @foreach ($heatingMethods as $value => $label)
                                        <option value="{{ $value }}">{{ $label }}</option>
                                    @endforeach
                                </select>
                            </div>

                            {{-- Building Region --}}
                            <div>
                                <label for="calc-building-region" class="block text-sm font-medium text-slate-700 mb-2">
                                    <span class="flex items-center">
                                        <svg class="w-4 h-4 mr-2 text-slate-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"></path>
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"></path>
                                        </svg>
                                        Sijainti
                                    </span>
                                </label>
                                <select
                                    id="calc-building-region"
                                    wire:model.live="calcBuildingRegion"
                                    class="w-full px-4 py-3 border border-slate-300 rounded-lg focus:ring-2 focus:ring-coral-500 focus:border-coral-500 bg-white"
                                >
                                    @foreach ($buildingRegions as $value => $label)
                                        <option value="{{ $value }}">{{ $label }}</option>
                                    @endforeach
                                </select>
                            </div>

                            {{-- Building Energy Efficiency --}}
                            <div>
                                <label for="calc-energy-efficiency" class="block text-sm font-medium text-slate-700 mb-2">
                                    <span class="flex items-center">
                                        <svg class="w-4 h-4 mr-2 text-slate-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"></path>
                                        </svg>
                                        Energiatehokkuus
                                    </span>
                                </label>
                                <select
                                    id="calc-energy-efficiency"
                                    wire:model.live="calcBuildingEnergyEfficiency"
                                    class="w-full px-4 py-3 border border-slate-300 rounded-lg focus:ring-2 focus:ring-coral-500 focus:border-coral-500 bg-white"
                                >
                                    @foreach ($energyRatings as $value => $label)
                                        <option value="{{ $value }}">{{ $label }}</option>
                                    @endforeach
                                </select>
                            </div>

                            {{-- Supplementary Heating --}}
                            <div>
                                <label for="calc-supplementary-heating" class="block text-sm font-medium text-slate-700 mb-2">
                                    <span class="flex items-center">
                                        <svg class="w-4 h-4 mr-2 text-slate-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6V4m0 2a2 2 0 100 4m0-4a2 2 0 110 4m-6 8a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4m6 6v10m6-2a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4"></path>
                                        </svg>
                                        Lisälämmitys
                                    </span>
                                </label>
                                <select
                                    id="calc-supplementary-heating"
                                    wire:model.live="calcSupplementaryHeating"
                                    class="w-full px-4 py-3 border border-slate-300 rounded-lg focus:ring-2 focus:ring-coral-500 focus:border-coral-500 bg-white"
                                >
                                    <option value="">Ei lisälämmitystä</option>
                                    @foreach ($supplementaryHeatingMethods as $value => $label)
                                        <option value="{{ $value }}">{{ $label }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>
                    @endif

                    {{-- Row 5: Extras Section --}}
                    <div class="mb-6">
                        <label class="block text-sm font-medium text-slate-700 mb-3">Lisävarusteet</label>
                        <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
                            {{-- Underfloor Heating --}}
                            <button
                                wire:click="toggleExtra('underfloor')"
                                class="p-4 rounded-xl border-2 transition-all flex flex-col items-center {{ $calcUnderfloorHeatingEnabled ? 'border-coral-500 bg-coral-50' : 'border-slate-100 hover:border-slate-300 bg-white' }}"
                            >
                                <svg class="w-8 h-8 mb-2 {{ $calcUnderfloorHeatingEnabled ? 'text-coral-600' : 'text-slate-400' }}" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 5a1 1 0 011-1h14a1 1 0 011 1v2a1 1 0 01-1 1H5a1 1 0 01-1-1V5zM4 13a1 1 0 011-1h6a1 1 0 011 1v6a1 1 0 01-1 1H5a1 1 0 01-1-1v-6zM16 13a1 1 0 011-1h2a1 1 0 011 1v6a1 1 0 01-1 1h-2a1 1 0 01-1-1v-6z"></path>
                                </svg>
                                <span class="text-xs font-medium text-center {{ $calcUnderfloorHeatingEnabled ? 'text-coral-700' : 'text-slate-600' }}">Lattialämmitys</span>
                            </button>

                            {{-- Sauna --}}
                            <button
                                wire:click="toggleExtra('sauna')"
                                class="p-4 rounded-xl border-2 transition-all flex flex-col items-center {{ $calcSaunaEnabled ? 'border-coral-500 bg-coral-50' : 'border-slate-100 hover:border-slate-300 bg-white' }}"
                            >
                                <svg class="w-8 h-8 mb-2 {{ $calcSaunaEnabled ? 'text-coral-600' : 'text-slate-400' }}" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 18.657A8 8 0 016.343 7.343S7 9 9 10c0-2 .5-5 2.986-7C14 5 16.09 5.777 17.656 7.343A7.975 7.975 0 0120 13a7.975 7.975 0 01-2.343 5.657z"></path>
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.879 16.121A3 3 0 1012.015 11L11 14H9c0 .768.293 1.536.879 2.121z"></path>
                                </svg>
                                <span class="text-xs font-medium text-center {{ $calcSaunaEnabled ? 'text-coral-700' : 'text-slate-600' }}">Sauna</span>
                            </button>

                            {{-- Electric Vehicle --}}
                            <button
                                wire:click="toggleExtra('ev')"
                                class="p-4 rounded-xl border-2 transition-all flex flex-col items-center {{ $calcElectricVehicleEnabled ? 'border-coral-500 bg-coral-50' : 'border-slate-100 hover:border-slate-300 bg-white' }}"
                            >
                                <svg class="w-8 h-8 mb-2 {{ $calcElectricVehicleEnabled ? 'text-coral-600' : 'text-slate-400' }}" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"></path>
                                </svg>
                                <span class="text-xs font-medium text-center {{ $calcElectricVehicleEnabled ? 'text-coral-700' : 'text-slate-600' }}">Sähköauto</span>
                            </button>

                            {{-- Cooling --}}
                            <button
                                wire:click="toggleExtra('cooling')"
                                class="p-4 rounded-xl border-2 transition-all flex flex-col items-center {{ $calcCooling ? 'border-coral-500 bg-coral-50' : 'border-slate-100 hover:border-slate-300 bg-white' }}"
                            >
                                <svg class="w-8 h-8 mb-2 {{ $calcCooling ? 'text-coral-600' : 'text-slate-400' }}" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"></path>
                                </svg>
                                <span class="text-xs font-medium text-center {{ $calcCooling ? 'text-coral-700' : 'text-slate-600' }}">Jäähdytys</span>
                            </button>
                        </div>
                    </div>

                    {{-- Row 6: Extra Input Fields (conditionally shown) --}}
                    @if ($calcUnderfloorHeatingEnabled || $calcSaunaEnabled || $calcElectricVehicleEnabled)
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6 p-4 bg-slate-50 rounded-xl">
                            @if ($calcUnderfloorHeatingEnabled)
                                <div>
                                    <label for="calc-bathroom-heating" class="block text-sm font-medium text-slate-700 mb-2">
                                        Lämmitetty lattia-ala (m²)
                                    </label>
                                    <input
                                        type="number"
                                        id="calc-bathroom-heating"
                                        wire:model.live.debounce.300ms="calcBathroomHeatingArea"
                                        min="0"
                                        max="100"
                                        placeholder="esim. 10"
                                        class="w-full px-4 py-3 border border-slate-300 rounded-lg focus:ring-2 focus:ring-coral-500 focus:border-coral-500"
                                    >
                                </div>
                            @endif

                            @if ($calcSaunaEnabled)
                                <div>
                                    <label for="calc-sauna-usage" class="block text-sm font-medium text-slate-700 mb-2">
                                        Saunakertoja viikossa
                                    </label>
                                    <input
                                        type="number"
                                        id="calc-sauna-usage"
                                        wire:model.live.debounce.300ms="calcSaunaUsagePerWeek"
                                        min="0"
                                        max="14"
                                        placeholder="esim. 2"
                                        class="w-full px-4 py-3 border border-slate-300 rounded-lg focus:ring-2 focus:ring-coral-500 focus:border-coral-500"
                                    >
                                </div>
                            @endif

                            @if ($calcElectricVehicleEnabled)
                                <div>
                                    <label for="calc-ev-kms" class="block text-sm font-medium text-slate-700 mb-2">
                                        Ajokilometrit viikossa
                                    </label>
                                    <input
                                        type="number"
                                        id="calc-ev-kms"
                                        wire:model.live.debounce.300ms="calcElectricVehicleKmsPerWeek"
                                        min="0"
                                        max="2000"
                                        placeholder="esim. 200"
                                        class="w-full px-4 py-3 border border-slate-300 rounded-lg focus:ring-2 focus:ring-coral-500 focus:border-coral-500"
                                    >
                                </div>
                            @endif
                        </div>
                    @endif

                    {{-- Calculated Result --}}
                    <div class="bg-coral-50 rounded-xl p-4 border border-coral-200 mb-4">
                        <div class="flex items-center justify-between">
                            <div>
                                <h4 class="font-semibold text-slate-900">Arvioitu vuosikulutus</h4>
                                <p class="text-sm text-slate-500 mt-1">
                                    @if ($calcIncludeHeating)
                                        Sisältää peruskulutuksen ja lämmityksen
                                    @else
                                        Peruskulutus (ilman lämmitystä)
                                    @endif
                                    @if ($calcSaunaEnabled || $calcElectricVehicleEnabled || $calcUnderfloorHeatingEnabled || $calcCooling)
                                        + lisävarusteet
                                    @endif
                                </p>
                            </div>
                            <div class="text-right">
                                <span class="text-3xl font-bold text-coral-600">{{ number_format($consumption, 0, ',', ' ') }}</span>
                                <span class="text-slate-500 ml-1">kWh/v</span>
                            </div>
                        </div>
                    </div>

                    {{-- Row 7: CTA Button --}}
                    <button
                        wire:click="setActiveTab('presets')"
                        class="w-full bg-gradient-to-r from-coral-500 to-coral-600 hover:from-coral-400 hover:to-coral-500 text-white font-semibold py-4 px-6 rounded-xl transition-colors flex items-center justify-center shadow-sm"
                    >
                        Vertaa sähkösopimuksia
                        <svg class="w-5 h-5 ml-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 8l4 4m0 0l-4 4m4-4H3"></path>
                        </svg>
                    </button>
                </div>
            </div>
        @endif

        {{-- Current Selection Display --}}
        <div class="mt-6">
            <div class="inline-flex items-center bg-coral-50 border border-coral-200 rounded-full px-6 py-3">
                <svg class="w-5 h-5 text-coral-600 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"></path>
                </svg>
                <span class="text-coral-700 font-medium">Vertailu kulutuksella:</span>
                <span class="text-coral-900 font-bold ml-2">{{ number_format($consumption, 0, ',', ' ') }} kWh/v</span>
            </div>
        </div>
    </section>

    {{-- Filter Section --}}
    <div class="bg-white rounded-2xl py-5 border border-slate-200 mb-8" x-data="{ filtersOpen: false }">
        {{-- Mobile Accordion Trigger --}}
        <button
            @click="filtersOpen = !filtersOpen"
            class="lg:hidden w-full px-4 py-2 flex items-center justify-between text-left font-semibold text-slate-900"
        >
            <span>Suodattimet</span>
            <svg class="w-5 h-5 transform transition-transform" :class="{ 'rotate-180': filtersOpen }" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
            </svg>
        </button>

        {{-- Filter Content --}}
        @php
            // Only use links for SEO when no filters are active (to prevent URL explosion)
            $useLinks = !$this->hasActiveFilters();
        @endphp
        <div class="lg:flex flex-wrap" :class="{ 'hidden': !filtersOpen }" x-bind:class="{ 'hidden lg:flex': !filtersOpen }">
            {{-- Pricing Model Filters --}}
            <div class="flex flex-col px-4">
                <h4 class="font-semibold text-slate-900 mb-2">Hinnoittelumalli</h4>
                <div class="flex flex-col lg:flex-row gap-2">
                    @foreach ($pricingModels as $model => $label)
                        @php
                            $icons = [
                                'FixedPrice' => 'M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z',
                                'Spot' => 'M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z',
                                'Hybrid' => 'M8 9l3 3-3 3m5 0h3M5 20h14a2 2 0 002-2V6a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z',
                            ];
                            $icon = $icons[$model] ?? $icons['FixedPrice'];
                            $isActive = $pricingModelFilter === $model;
                        @endphp
                        @if ($useLinks && !$isActive)
                            <a
                                href="/?pricingModelFilter={{ $model }}"
                                wire:click.prevent="setPricingModelFilter('{{ $model }}')"
                                class="flex items-center border focus:outline-none font-medium rounded-lg text-sm px-4 py-2 transition-all bg-slate-50 border-slate-200 text-slate-600 hover:border-slate-300"
                            >
                                <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="{{ $icon }}"></path>
                                </svg>
                                {{ $label }}
                            </a>
                        @else
                            <button
                                wire:click="setPricingModelFilter('{{ $model }}')"
                                class="flex items-center border focus:outline-none font-medium rounded-lg text-sm px-4 py-2 transition-all {{ $isActive ? 'bg-slate-950 border-slate-950 text-white' : 'bg-slate-50 border-slate-200 text-slate-600 hover:border-slate-300' }}"
                            >
                                <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="{{ $icon }}"></path>
                                </svg>
                                {{ $label }}
                            </button>
                        @endif
                    @endforeach
                </div>
            </div>

            {{-- Contract Duration Filters --}}
            <div class="flex flex-col border-t lg:border-t-0 lg:border-l border-slate-200 px-4 mt-4 pt-4 lg:mt-0 lg:pt-0">
                <h4 class="font-semibold text-slate-900 mb-2">Sopimuksen kesto</h4>
                <div class="flex flex-col lg:flex-row gap-2">
                    @foreach ($contractTypes as $type => $label)
                        @php
                            $icons = [
                                'OpenEnded' => 'M13 5l7 7-7 7M5 5l7 7-7 7',
                                'FixedTerm' => 'M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z',
                            ];
                            $icon = $icons[$type] ?? $icons['OpenEnded'];
                            $isActive = $contractTypeFilter === $type;
                        @endphp
                        @if ($useLinks && !$isActive)
                            <a
                                href="/?contractTypeFilter={{ $type }}"
                                wire:click.prevent="setContractTypeFilter('{{ $type }}')"
                                class="flex items-center border focus:outline-none font-medium rounded-lg text-sm px-4 py-2 transition-all bg-slate-50 border-slate-200 text-slate-600 hover:border-slate-300"
                            >
                                <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="{{ $icon }}"></path>
                                </svg>
                                {{ $label }}
                            </a>
                        @else
                            <button
                                wire:click="setContractTypeFilter('{{ $type }}')"
                                class="flex items-center border focus:outline-none font-medium rounded-lg text-sm px-4 py-2 transition-all {{ $isActive ? 'bg-slate-950 border-slate-950 text-white' : 'bg-slate-50 border-slate-200 text-slate-600 hover:border-slate-300' }}"
                            >
                                <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="{{ $icon }}"></path>
                                </svg>
                                {{ $label }}
                            </button>
                        @endif
                    @endforeach
                </div>
            </div>

            {{-- Energy Source Filters --}}
            <div class="flex flex-col border-t lg:border-t-0 lg:border-l border-slate-200 px-4 mt-4 pt-4 lg:mt-0 lg:pt-0">
                <h4 class="font-semibold text-slate-900 mb-2">Energialähde</h4>
                <div class="flex flex-col lg:flex-row gap-2">
                    @if ($useLinks && !$fossilFreeFilter)
                        <a
                            href="/?fossilFreeFilter=1"
                            wire:click.prevent="$toggle('fossilFreeFilter')"
                            class="flex items-center border focus:outline-none font-medium rounded-lg text-sm px-4 py-2 transition-all bg-slate-50 border-slate-200 text-slate-600 hover:border-slate-300"
                        >
                            <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 3v4M3 5h4M6 17v4m-2-2h4m5-16l2.286 6.857L21 12l-5.714 2.143L13 21l-2.286-6.857L5 12l5.714-2.143L13 3z"></path>
                            </svg>
                            Fossiiliton
                        </a>
                    @else
                        <button
                            wire:click="$toggle('fossilFreeFilter')"
                            class="flex items-center border focus:outline-none font-medium rounded-lg text-sm px-4 py-2 transition-all {{ $fossilFreeFilter ? 'bg-slate-950 border-slate-950 text-white' : 'bg-slate-50 border-slate-200 text-slate-600 hover:border-slate-300' }}"
                        >
                            <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 3v4M3 5h4M6 17v4m-2-2h4m5-16l2.286 6.857L21 12l-5.714 2.143L13 21l-2.286-6.857L5 12l5.714-2.143L13 3z"></path>
                            </svg>
                            Fossiiliton
                        </button>
                    @endif
                    @if ($useLinks && !$renewableFilter)
                        <a
                            href="/?renewableFilter=1"
                            wire:click.prevent="$toggle('renewableFilter')"
                            class="flex items-center border focus:outline-none font-medium rounded-lg text-sm px-4 py-2 transition-all bg-slate-50 border-slate-200 text-slate-600 hover:border-slate-300"
                        >
                            <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364 6.364l-.707-.707M6.343 6.343l-.707-.707m12.728 0l-.707.707M6.343 17.657l-.707.707M16 12a4 4 0 11-8 0 4 4 0 018 0z"></path>
                            </svg>
                            Uusiutuva
                        </a>
                    @else
                        <button
                            wire:click="$toggle('renewableFilter')"
                            class="flex items-center border focus:outline-none font-medium rounded-lg text-sm px-4 py-2 transition-all {{ $renewableFilter ? 'bg-slate-950 border-slate-950 text-white' : 'bg-slate-50 border-slate-200 text-slate-600 hover:border-slate-300' }}"
                        >
                            <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364 6.364l-.707-.707M6.343 6.343l-.707-.707m12.728 0l-.707.707M6.343 17.657l-.707.707M16 12a4 4 0 11-8 0 4 4 0 018 0z"></path>
                            </svg>
                            Uusiutuva
                        </button>
                    @endif
                    @if ($useLinks && !$nuclearFilter)
                        <a
                            href="/?nuclearFilter=1"
                            wire:click.prevent="$toggle('nuclearFilter')"
                            class="flex items-center border focus:outline-none font-medium rounded-lg text-sm px-4 py-2 transition-all bg-slate-50 border-slate-200 text-slate-600 hover:border-slate-300"
                        >
                            <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"></path>
                            </svg>
                            Ydinvoima
                        </a>
                    @else
                        <button
                            wire:click="$toggle('nuclearFilter')"
                            class="flex items-center border focus:outline-none font-medium rounded-lg text-sm px-4 py-2 transition-all {{ $nuclearFilter ? 'bg-slate-950 border-slate-950 text-white' : 'bg-slate-50 border-slate-200 text-slate-600 hover:border-slate-300' }}"
                        >
                            <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"></path>
                            </svg>
                            Ydinvoima
                        </button>
                    @endif
                </div>
            </div>
        </div>

        {{-- Clear Filters --}}
        @if ($this->hasActiveFilters())
            <div class="px-4 mt-4">
                <button
                    wire:click="resetFilters"
                    class="text-sm text-coral-600 hover:text-coral-700 font-medium"
                >
                    Tyhjennä suodattimet
                </button>
            </div>
        @endif
    </div>

    {{-- Results Count --}}
    <div class="mb-4 text-sm text-slate-600">
        <span class="font-semibold">{{ $contracts->count() }}</span> sopimusta löytyi
        @if ($this->hasActiveFilters() || $hasSeoFilter)
            suodattimilla
        @endif
    </div>

    {{-- Contracts List --}}
    <div class="space-y-4">
        @forelse ($contracts as $contract)
            <x-contract-card
                :contract="$contract"
                :consumption="$consumption"
                :prices="$this->getLatestPrices($contract)"
                :showRank="false"
                :showEmissions="true"
                :showEnergyBadges="true"
                :showSpotBadge="true"
            />
        @empty
            <div class="bg-white rounded-2xl shadow-sm border border-slate-100 p-12 text-center">
                <p class="text-slate-500">Ei sopimuksia saatavilla.</p>
            </div>
        @endforelse
    </div>

    {{-- Internal Links Section (for SEO) --}}
    @if($hasSeoFilter)
    <section class="mt-12 bg-white rounded-2xl shadow-sm border border-slate-100 p-8">
        <h2 class="text-2xl font-bold text-slate-900 mb-6">Katso myös</h2>

        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
            {{-- Pricing Types --}}
            <div>
                <h3 class="font-semibold text-slate-900 mb-3">Hinnoittelumalli</h3>
                <ul class="space-y-2 text-slate-600">
                    <li>
                        <a href="/sahkosopimus/porssisahko" class="hover:text-coral-600">Pörssisähkösopimukset</a>
                    </li>
                    <li>
                        <a href="/sahkosopimus/kiintea-hinta" class="hover:text-coral-600">Kiinteähintaiset sopimukset</a>
                    </li>
                </ul>
            </div>

            {{-- Housing Types --}}
            <div>
                <h3 class="font-semibold text-slate-900 mb-3">Asumismuodoittain</h3>
                <ul class="space-y-2 text-slate-600">
                    <li>
                        <a href="/sahkosopimus/omakotitalo" class="hover:text-coral-600">Omakotitalon sähkösopimukset</a>
                    </li>
                    <li>
                        <a href="/sahkosopimus/kerrostalo" class="hover:text-coral-600">Kerrostalon sähkösopimukset</a>
                    </li>
                    <li>
                        <a href="/sahkosopimus/rivitalo" class="hover:text-coral-600">Rivitalon sähkösopimukset</a>
                    </li>
                </ul>
            </div>

            {{-- Energy Sources --}}
            <div>
                <h3 class="font-semibold text-slate-900 mb-3">Energialähteittäin</h3>
                <ul class="space-y-2 text-slate-600">
                    <li>
                        <a href="/sahkosopimus/tuulisahko" class="hover:text-coral-600">Tuulisähkösopimukset</a>
                    </li>
                    <li>
                        <a href="/sahkosopimus/aurinkosahko" class="hover:text-coral-600">Aurinkosähkösopimukset</a>
                    </li>
                    <li>
                        <a href="/sahkosopimus/vihrea-sahko" class="hover:text-coral-600">Vihreä sähkö</a>
                    </li>
                </ul>
            </div>

            {{-- Related Links --}}
            <div>
                <h3 class="font-semibold text-slate-900 mb-3">Muut palvelut</h3>
                <ul class="space-y-2 text-slate-600">
                    <li>
                        <a href="/sahkosopimus" class="hover:text-coral-600">Vertaile sopimuksia</a>
                    </li>
                    <li>
                        <a href="/sahkosopimus/halvin-sahkosopimus" class="hover:text-coral-600">Halvimmat sopimukset</a>
                    </li>
                    <li>
                        <a href="/" class="hover:text-coral-600">Kaikki sähkösopimukset</a>
                    </li>
                    <li>
                        <a href="/spot-price" class="hover:text-coral-600">Pörssisähkön hinta</a>
                    </li>
                    <li>
                        <a href="{{ route('locations') }}" class="hover:text-coral-600">Paikkakunnat</a>
                    </li>
                </ul>
            </div>
        </div>
    </section>
    @endif
    </div>
</div>
