<div>
    {{-- JSON-LD Structured Data --}}
    @if(!empty($seoData['jsonLd']))
    <script type="application/ld+json">
        {!! json_encode($seoData['jsonLd'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) !!}
    </script>
    @endif

    {{-- SEO Hero Section - Dark slate background with gradient --}}
    <section class="bg-gradient-to-br from-slate-900 via-slate-900 to-slate-950 -mx-4 sm:-mx-6 lg:-mx-8 mb-6 relative overflow-hidden">
        {{-- Decorative gradient blobs --}}
        <div class="absolute inset-0 pointer-events-none">
            <div class="absolute top-0 right-1/4 w-96 h-96 bg-coral-500 rounded-full blur-3xl opacity-20"></div>
            <div class="absolute bottom-0 left-0 w-72 h-72 bg-coral-400 rounded-full blur-3xl opacity-10 -translate-x-1/2"></div>
        </div>

        <div class="max-w-7xl mx-auto px-6 sm:px-8 lg:px-10 relative">
            <div class="grid max-w-screen-xl py-8 mx-auto lg:gap-8 xl:gap-0 lg:py-12 lg:grid-cols-12">
                <div class="mx-auto place-self-center col-12 lg:col-span-8">
                    <div class="inline-flex items-center gap-2 bg-coral-500/20 backdrop-blur-sm px-3 py-1.5 rounded-full text-xs font-medium text-coral-300 mb-3 border border-coral-500/20">
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/>
                        </svg>
                        {{ $isBusinessPage ? 'Yrityksille' : 'Vertaile älykkäästi' }}
                    </div>
                    <h1 class="max-w-2xl mb-3 text-3xl font-extrabold text-white tracking-tight leading-tight md:text-4xl xl:text-5xl">
                        {{ $pageHeading }}
                    </h1>
                    <p class="max-w-2xl mb-4 text-slate-300 md:text-base lg:text-lg">
                        {{ $seoIntroText }}
                    </p>
                    <x-contract-market-insight-pills :insight="$marketInsight ?? null" class="mb-1" />
                </div>
                <div class="hidden lg:flex col-12 lg:col-span-4 mx-auto">
                    {{-- Decorative element placeholder --}}
                </div>
            </div>
        </div>
    </section>

    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6">

    {{-- Breadcrumb Navigation --}}
    @if($hasSeoFilter)
    <nav class="mb-6" aria-label="Breadcrumb">
        <ol class="flex flex-wrap items-center gap-x-2 gap-y-1 text-sm text-slate-500 min-w-0">
            <li>
                <a href="/" class="hover:text-coral-600">Etusivu</a>
            </li>
            <li>
                <svg class="w-4 h-4 mx-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                </svg>
            </li>
            <li>
                <a href="/sahkosopimus" class="hover:text-coral-600">Sähkösopimukset</a>
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

    {{-- Solar Potential Snippet (for city pages) --}}
    @if($isCityPage && $municipality)
        <livewire:city-solar-estimate
            :municipality-id="$municipality->id"
            :city-locative="$cityInfo['locative'] ?? $citySlug"
            :city-slug="$citySlug"
            lazy
        />
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
    <section x-data="{ panelOpen: false }" class="bg-transparent mb-6">
        {{-- Compact header: label + calculator toggle (desktop) + collapse (mobile) --}}
        <div class="flex items-center justify-between gap-3 mb-3">
            <h3 class="text-sm font-bold text-slate-700 tracking-tight">
                {{ $isBusinessPage ? 'Yrityksen vuosikulutus' : 'Vuosikulutus' }}
            </h3>
            <div class="flex items-center gap-3">
                @if($showCalculatorTab ?? true)
                    <button
                        type="button"
                        wire:click="setActiveTab('{{ $activeTab === 'calculator' ? 'presets' : 'calculator' }}')"
                        class="hidden lg:inline-flex items-center gap-1.5 text-sm font-semibold transition-colors {{ $activeTab === 'calculator' ? 'text-coral-600' : 'text-slate-500 hover:text-slate-700' }}"
                    >
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 7h6m-6 4h6m-6 4h4M5 3h14a2 2 0 012 2v14a2 2 0 01-2 2H5a2 2 0 01-2-2V5a2 2 0 012-2z"></path>
                        </svg>
                        {{ $activeTab === 'calculator' ? 'Sulje laskuri' : 'En tiedä – arvioi laskurilla' }}
                    </button>
                @endif
                {{-- Mobile collapse toggle --}}
                <button
                    type="button"
                    @click="panelOpen = !panelOpen"
                    class="lg:hidden inline-flex items-center gap-1.5 text-sm font-semibold text-slate-600"
                    :aria-expanded="panelOpen ? 'true' : 'false'"
                >
                    <span x-text="panelOpen ? 'Piilota' : 'Vaihda'"></span>
                    <svg class="w-4 h-4 transition-transform" :class="panelOpen && 'rotate-180'" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                    </svg>
                </button>
            </div>
        </div>

        {{-- Collapsible on mobile; always shown on desktop --}}
        <div :class="panelOpen ? 'block' : 'hidden lg:block'">

        {{-- Mobile calculator toggle (desktop uses the header toggle above) --}}
        @if($showCalculatorTab ?? true)
            <button
                type="button"
                wire:click="setActiveTab('{{ $activeTab === 'calculator' ? 'presets' : 'calculator' }}')"
                class="lg:hidden w-full mb-3 inline-flex items-center justify-center gap-1.5 rounded-xl border border-slate-200 py-2.5 text-sm font-semibold {{ $activeTab === 'calculator' ? 'text-coral-600 border-coral-300' : 'text-slate-600' }}"
            >
                {{ $activeTab === 'calculator' ? 'Sulje laskuri ja valitse profiili' : 'En tiedä – arvioi laskurilla' }}
            </button>
        @endif

        {{-- Presets + direct input: compact info cards in a single row so the
             contract list stays high on the page. Each preset keeps its label,
             description and kWh; the last tile is a free-text input for visitors
             who know their own consumption. Full calculator is one click away
             via the header toggle. --}}
        @if ($activeTab === 'presets')
            <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-2">
                @foreach ($presets as $key => $preset)
                    @php $isSel = $selectedPreset === $key; @endphp
                    <button
                        type="button"
                        wire:click="selectPreset('{{ $key }}')"
                        aria-pressed="{{ $isSel ? 'true' : 'false' }}"
                        class="flex flex-col items-start text-left px-3 py-2.5 rounded-xl border transition-all {{ $isSel ? 'bg-gradient-to-br from-coral-500 to-coral-600 border-coral-600 shadow-coral' : 'bg-white border-slate-200 hover:border-coral-400' }}"
                    >
                        <span class="text-sm font-semibold leading-tight {{ $isSel ? 'text-white' : 'text-slate-900' }}">{{ $preset['label'] }}</span>
                        <span class="text-xs leading-snug mt-0.5 {{ $isSel ? 'text-white/80' : 'text-slate-500' }}">{{ $preset['description'] }}</span>
                        <span class="mt-1 text-sm font-bold tabular-nums {{ $isSel ? 'text-white' : 'text-slate-900' }}">{{ number_format($preset['consumption'], 0, ',', ' ') }} kWh/v</span>
                    </button>
                @endforeach

                {{-- Direct entry tile (highlighted when consumption is custom) --}}
                @php $isDirect = $selectedPreset === null; @endphp
                <div class="flex flex-col justify-center px-3 py-2.5 rounded-xl border bg-white {{ $isDirect ? 'border-coral-500 ring-1 ring-coral-500' : 'border-slate-200' }}">
                    <label for="direct-consumption" class="text-xs leading-snug {{ $isDirect ? 'text-coral-600 font-semibold' : 'text-slate-500 font-medium' }}">Tiedän kulutukseni</label>
                    <div class="flex items-baseline gap-1 mt-1">
                        <input
                            id="direct-consumption"
                            type="number"
                            min="0"
                            step="100"
                            inputmode="numeric"
                            wire:model.live.debounce.700ms="directConsumption"
                            placeholder="esim. 7000"
                            class="w-full min-w-0 bg-transparent text-sm font-bold text-slate-900 placeholder:font-normal placeholder:text-slate-500 focus:outline-none tabular-nums"
                        >
                        <span class="text-xs text-slate-500 shrink-0">kWh/v</span>
                    </div>
                </div>
            </div>
        @endif

        {{-- Calculator Tab --}}
        @if (($showCalculatorTab ?? true) && $activeTab === 'calculator')
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

        </div> {{-- /collapsible panel --}}

        {{-- Current Selection Display. In presets mode on desktop the highlighted
             chip already confirms the consumption, so this is hidden there to save
             vertical space (lg:hidden). It still shows on mobile (where the chips
             collapse behind "Vaihda kulutusta") and in calculator mode on all
             sizes (where there is no chip to confirm the value). --}}
        <div class="mt-5 {{ (($showCalculatorTab ?? true) && $activeTab === 'calculator') ? '' : 'lg:hidden' }}">
            <div class="inline-flex items-center bg-coral-50 border border-coral-200 rounded-full px-5 py-2.5">
                <svg class="w-5 h-5 text-coral-600 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"></path>
                </svg>
                <span class="text-coral-700 font-medium">Vertailu kulutuksella:</span>
                <span class="text-coral-900 font-bold ml-2">{{ number_format($consumption, 0, ',', ' ') }} kWh/v</span>
            </div>
        </div>
    </section>

    {{-- Bill comparison ("Maksatko liikaa") entry — proven on /sahkosopimus first. --}}
    @if ($showBillComparison)
        {{-- Bill comparison is a collapsed disclosure so it does not push the
             contract list down for everyone; opens automatically once a bill is
             entered (bill mode active). --}}
        <section class="mb-6" x-data="{ billOpen: @js($this->isBillModeActive()) }">
            <div class="bg-white rounded-2xl border border-slate-200 overflow-hidden">
                <button
                    type="button"
                    @click="billOpen = !billOpen"
                    :aria-expanded="billOpen ? 'true' : 'false'"
                    class="w-full flex items-center gap-3 p-4 sm:p-5 text-left hover:bg-slate-50 transition-colors"
                >
                    <span class="flex-shrink-0 flex items-center justify-center w-10 h-10 rounded-xl bg-coral-50">
                        <svg class="w-5 h-5 text-coral-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                        </svg>
                    </span>
                    <span class="min-w-0 flex-1">
                        <span class="block text-base font-bold text-slate-900">Maksatko nykyisestä sopimuksestasi liikaa?</span>
                        <span class="block text-sm text-slate-600 mt-0.5">Syötä yhden sähkölaskusi tiedot, niin näet mitä säästäisit vaihtamalla.</span>
                    </span>
                    <svg class="w-5 h-5 flex-shrink-0 text-slate-400 transition-transform" :class="billOpen && 'rotate-180'" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                    </svg>
                </button>

                <div x-show="billOpen" x-collapse x-cloak class="border-t border-slate-100 p-4 sm:p-6">
                {{-- Billing period preset chips --}}
                <div class="flex flex-wrap gap-2 mb-4">
                    @foreach ($billPresetLabels as $key => $label)
                        <button
                            type="button"
                            wire:click="setBillPeriodPreset('{{ $key }}')"
                            aria-pressed="{{ $billPeriodPreset === $key ? 'true' : 'false' }}"
                            class="px-4 py-2 rounded-full text-sm font-medium border transition-colors {{ $billPeriodPreset === $key ? 'bg-slate-950 text-white border-slate-950' : 'bg-slate-50 text-slate-600 border-slate-200 hover:border-slate-300' }}"
                        >
                            {{ $label }}
                        </button>
                    @endforeach
                </div>

                {{-- Custom date range --}}
                @if ($billPeriodPreset === 'custom')
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-4">
                        <div>
                            <label for="bill-start" class="block text-sm font-medium text-slate-700 mb-1.5">Laskutusjakson alku</label>
                            <input type="date" id="bill-start" wire:model.live="billStartDate" class="w-full px-4 py-3 border border-slate-300 rounded-lg focus:ring-2 focus:ring-coral-500 focus:border-coral-500">
                        </div>
                        <div>
                            <label for="bill-end" class="block text-sm font-medium text-slate-700 mb-1.5">Laskutusjakson loppu</label>
                            <input type="date" id="bill-end" wire:model.live="billEndDate" class="w-full px-4 py-3 border border-slate-300 rounded-lg focus:ring-2 focus:ring-coral-500 focus:border-coral-500">
                        </div>
                    </div>
                @endif

                {{-- Required inputs: kWh + total paid --}}
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label for="bill-kwh" class="block text-sm font-medium text-slate-700 mb-1.5">Kulutus jaksolla (kWh)</label>
                        <input type="number" id="bill-kwh" min="0" step="any" inputmode="decimal" wire:model.live.debounce.500ms="billKwh" placeholder="esim. 400" class="w-full px-4 py-3 border border-slate-300 rounded-lg focus:ring-2 focus:ring-coral-500 focus:border-coral-500 tabular-nums">
                    </div>
                    <div>
                        <label for="bill-total" class="block text-sm font-medium text-slate-700 mb-1.5">Maksoit sähköstä (€)</label>
                        <input type="number" id="bill-total" min="0" step="any" inputmode="decimal" wire:model.live.debounce.500ms="billTotalEur" placeholder="esim. 35" class="w-full px-4 py-3 border border-slate-300 rounded-lg focus:ring-2 focus:ring-coral-500 focus:border-coral-500 tabular-nums">
                        <p class="text-xs text-slate-500 mt-1">Vain sähkösopimuksen osuus, ei sähkön siirtoa.</p>
                    </div>
                </div>

                {{-- VAT basis toggle --}}
                <label class="flex items-start justify-between gap-3 mt-4 p-3 rounded-lg border border-slate-200 cursor-pointer hover:bg-slate-50 sm:max-w-md">
                    <span class="min-w-0">
                        <span class="block text-sm font-medium text-slate-700">Hinta sisältää arvonlisäveron</span>
                        <span class="block text-xs text-slate-500">Useimmissa laskuissa kyllä (alv 25,5 %).</span>
                    </span>
                    <span class="relative inline-flex shrink-0 mt-0.5">
                        <input type="checkbox" role="switch" wire:model.live="billIncludesVat" class="peer sr-only">
                        <span aria-hidden="true" class="block h-6 w-11 rounded-full bg-slate-300 transition-colors peer-checked:bg-coral-500"></span>
                        <span aria-hidden="true" class="pointer-events-none absolute left-0.5 top-0.5 h-5 w-5 rounded-full bg-white shadow transition-transform peer-checked:translate-x-5"></span>
                    </span>
                </label>

                @if ($this->isBillModeActive())
                    <div class="mt-4">
                        <button type="button" wire:click="clearBill" class="text-sm font-medium text-slate-500 hover:text-slate-700 underline underline-offset-2">
                            Tyhjennä ja palaa normaaliin vertailuun
                        </button>
                    </div>
                @endif
                </div>
            </div>
        </section>
    @endif

    {{-- Filter Section (shared partial) --}}
    @include('partials.contract-filters')

    {{-- Local Contracts Section (for city pages) --}}
    @if($isCityPage && $citySlug && $localContractsData['has_content'])
        <livewire:local-contracts-section
            :city-name="$cityInfo['name']"
            :city-locative="$cityInfo['locative']"
            :consumption="$consumption"
            :local-company-contracts="$localContractsData['local_companies']"
            :regional-contracts="$localContractsData['regional_contracts']"
            wire:key="local-contracts-{{ $citySlug }}-{{ $consumption }}"
        />
    @endif

    @if ($offerType === 'promotion')
        {{-- Independence note: the offers page is where affiliate suspicion is highest --}}
        <div class="flex items-start gap-2.5 mb-4 text-sm text-slate-600">
            <svg class="w-5 h-5 text-coral-600 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m7.5 2a9.5 9.5 0 11-19 0 9.5 9.5 0 0119 0z"/>
            </svg>
            <p>
                Voltikka ei saa palkkiota sähköyhtiöiltä tarjousten näyttämisestä eikä rahoita toimintaansa mainoksilla. Tarjoukset järjestetään arvioidun 12 kk kokonaiskustannuksen mukaan, kaikille yhtiöille samalla logiikalla.
                <a href="/tietoa" class="text-coral-600 hover:text-coral-700 underline underline-offset-2 font-medium whitespace-nowrap">Tietoa Voltikasta</a>
            </p>
        </div>
    @endif

    @if ($this->isBillModeActive() && $this->billSummary)
        {{-- Current-contract anchor: the dark "focused moment" while the user --}}
        {{-- decides. Coral reserved for the one savings number. --}}
        @php $bs = $this->billSummary; @endphp
        <div class="bg-slate-950 rounded-2xl p-6 mb-6 text-white">
            <p class="text-sm font-semibold uppercase tracking-wide text-coral-400 mb-3">Sinun sopimuksesi</p>
            <div class="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-5">
                <div>
                    <p class="text-slate-300 text-sm mb-1">Maksoit laskutusjaksollasi noin</p>
                    <p class="text-3xl font-extrabold tabular-nums">{{ number_format($bs['user_monthly_cost'], 1, ',', ' ') }}<span class="text-lg font-medium text-slate-300 ml-1">€/kk</span></p>
                    <p class="text-sm text-slate-300 mt-1">Sijalla {{ $bs['user_rank'] }} / {{ $bs['total_ranked'] }} vertailluista sopimuksista</p>
                </div>
                @if ($bs['cheapest_monthly_saving'] > 0.5)
                    <div class="sm:text-right">
                        <p class="text-slate-300 text-sm mb-1">Halvimmalla sopimuksella säästäisit</p>
                        <p class="text-3xl font-extrabold text-coral-400 tabular-nums">{{ number_format($bs['cheapest_monthly_saving'], 0, ',', ' ') }}<span class="text-lg font-medium text-coral-300 ml-1">€/kk</span></p>
                    </div>
                @else
                    <div class="sm:text-right">
                        <p class="text-base font-semibold text-white">Sopimuksesi on jo kilpailukykyinen.</p>
                        <p class="text-sm text-slate-300 mt-1">Halvempaa ei juuri löydy tällä jaksolla.</p>
                    </div>
                @endif
            </div>
            <p class="text-xs text-slate-300 mt-4">
                Vertailu perustuu laskutusjaksosi ({{ $billStartDate }} – {{ $billEndDate }}) toteutuneisiin hintoihin samalla kulutuksella. Pörssisopimuksilla käytetään jakson todellisia tuntihintoja. Hinnat sis. alv 25,5 %, siirtomaksu ei sisälly.
            </p>
        </div>
    @else
        {{-- Results Credibility Bar --}}
        <div class="bg-slate-50 rounded-xl border border-slate-200 p-4 mb-6">
            <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">
                <div class="flex items-center gap-3">
                    <svg class="w-5 h-5 text-coral-600 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                    <p class="text-sm text-slate-700">
                        <span class="font-semibold">{{ $contracts->total() }} sopimusta</span> vertailussa. Lasketut 12 kk kulut sisältäen tarjoukset, hinnat sis. alv 25,5 % (siirtomaksu ei sisälly).
                        <a href="/tietoa#menetelma" class="text-coral-600 hover:text-coral-700 underline underline-offset-2 font-medium whitespace-nowrap">Näin laskemme &rarr;</a>
                    </p>
                </div>
                <div class="flex items-center gap-4 text-xs text-slate-600">
                    <span class="flex items-center gap-1.5">
                        <span class="w-3 h-3 rounded-full bg-emerald-500"></span>
                        Päästötön
                    </span>
                    <span class="flex items-center gap-1.5">
                        <span class="w-3 h-3 rounded-full bg-yellow-500"></span>
                        Vähäpäästöinen
                    </span>
                    <span class="flex items-center gap-1.5">
                        <span class="w-3 h-3 rounded-full bg-red-500"></span>
                        Fossiilinen
                    </span>
                </div>
            </div>
        </div>
    @endif

    {{-- Non-blocking recompute feedback (filters, consumption, bill entry). --}}
    <div wire:loading.delay class="fixed bottom-4 right-4 z-40 inline-flex items-center gap-2 rounded-full bg-slate-900 text-white text-sm font-medium px-4 py-2 shadow-lg">
        <x-spinner size="h-4 w-4" color="text-coral-400" label="Päivitetään vertailua" />
        <span>Päivitetään…</span>
    </div>

    {{-- Contracts List --}}
    <div class="space-y-4 transition-opacity duration-200 motion-reduce:transition-none" wire:loading.delay.class="opacity-50">
        @forelse ($contracts as $index => $contract)
            @php
                // Calculate the overall rank based on current page
                $overallRank = (($contracts->currentPage() - 1) * $contracts->perPage()) + $index + 1;
            @endphp

            @if ($this->isBillModeActive())
                <x-contract-card
                    :contract="$contract"
                    :rank="$overallRank"
                    :featured="false"
                    :consumption="$consumption"
                    :prices="$this->getLatestPrices($contract)"
                    :percentiles="$this->getPercentiles()"
                    :billMode="true"
                    :periodComparison="$contract->period_comparison ?? null"
                    :detailConsumption="$this->billSummary['annual_kwh'] ?? null"
                    :showRank="true"
                    :showEmissions="true"
                    :showEnergyBadges="true"
                    :showSpotBadge="true"
                />
            @elseif ($overallRank === 1)
                <x-featured-contract-card
                    :contract="$contract"
                    :consumption="$consumption"
                    :prices="$this->getLatestPrices($contract)"
                />
            @else
                <x-contract-card
                    :contract="$contract"
                    :rank="$overallRank"
                    :featured="$overallRank <= 3"
                    :consumption="$consumption"
                    :prices="$this->getLatestPrices($contract)"
                    :percentiles="$this->getPercentiles()"
                    :showRank="true"
                    :showEmissions="true"
                    :showEnergyBadges="true"
                    :showSpotBadge="true"
                />
            @endif
        @empty
            <div class="bg-white rounded-2xl shadow-sm border border-slate-100 p-12 text-center">
                <p class="text-slate-500">Ei sopimuksia saatavilla.</p>
            </div>
        @endforelse
    </div>

    {{-- Pagination Links --}}
    @if ($contracts->lastPage() > 1)
        <div class="mt-8">
            {{ $contracts->links('livewire.partials.pagination') }}
        </div>
    @endif

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
                        <a href="/sahkosopimus/kiintea-hinta" class="hover:text-coral-600">Kiinteähintaiset sähkösopimukset</a>
                    </li>
                    <li>
                        <a href="/sahkosopimus/kvartaalisahko" class="hover:text-coral-600">Kvartaalisähkösopimukset</a>
                    </li>
                    <li>
                        <a href="/sahkosopimus/aikasahko" class="hover:text-coral-600">Aikasähkösopimukset</a>
                    </li>
                    <li>
                        <a href="/sahkosopimus/kausisahko" class="hover:text-coral-600">Kausisähkösopimukset</a>
                    </li>
                    <li>
                        <a href="/sahkosopimus/joustosahko" class="hover:text-coral-600">Joustosähkösopimukset</a>
                    </li>
                    <li>
                        <a href="/sahkosopimus/yleissahko" class="hover:text-coral-600">Yleissähkösopimukset</a>
                    </li>
                </ul>
            </div>

            {{-- Housing Types & Contract Duration --}}
            <div>
                <h3 class="font-semibold text-slate-900 mb-3">Asumismuoto & sopimustyyppi</h3>
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
                    <li>
                        <a href="/sahkosopimus/maaraaikainen" class="hover:text-coral-600">Määräaikaiset sopimukset</a>
                    </li>
                    <li>
                        <a href="/sahkosopimus/toistaiseksi" class="hover:text-coral-600">Toistaiseksi voimassa olevat</a>
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
                    <li>
                        <a href="/sahkosopimus/fossiiliton" class="hover:text-coral-600">Fossiiliton sähkö</a>
                    </li>
                    <li>
                        <a href="/sahkosopimus/uusiutuva-sahko" class="hover:text-coral-600">Uusiutuva sähkö</a>
                    </li>
                    <li>
                        <a href="/sahkosopimus/ydinvoima" class="hover:text-coral-600">Ydinvoimasähkö</a>
                    </li>
                </ul>
            </div>

            {{-- Consumption Levels & Related Links --}}
            <div>
                <h3 class="font-semibold text-slate-900 mb-3">Kulutustason mukaan</h3>
                <ul class="space-y-2 text-slate-600">
                    <li>
                        <a href="/sahkosopimus/kulutus/2000-kwh" class="hover:text-coral-600">2 000 kWh sopimukset</a>
                    </li>
                    <li>
                        <a href="/sahkosopimus/kulutus/5000-kwh" class="hover:text-coral-600">5 000 kWh sopimukset</a>
                    </li>
                    <li>
                        <a href="/sahkosopimus/kulutus/10000-kwh" class="hover:text-coral-600">10 000 kWh sopimukset</a>
                    </li>
                    <li>
                        <a href="/sahkosopimus/kulutus/18000-kwh" class="hover:text-coral-600">18 000 kWh sopimukset</a>
                    </li>
                    <li>
                        <a href="/sahkosopimus/kulutus/20000-kwh" class="hover:text-coral-600">20 000 kWh sopimukset</a>
                    </li>
                </ul>
                <h3 class="font-semibold text-slate-900 mb-3 mt-6">Muut palvelut</h3>
                <ul class="space-y-2 text-slate-600">
                    <li>
                        <a href="/sahkosopimus" class="hover:text-coral-600">Vertaile sopimuksia</a>
                    </li>
                    <li>
                        <a href="/sahkosopimus/halvin-sahkosopimus" class="hover:text-coral-600">Halvimmat sopimukset</a>
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
