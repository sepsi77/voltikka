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
            {{-- Segmented control: one bordered group with divided segments, so the
                 consumption picker reads as a single control instead of a row of
                 separate cards. Each segment keeps its profile label + kWh. --}}
            <div class="flex flex-col overflow-hidden rounded-xl border border-slate-200 bg-white divide-y divide-slate-200 sm:flex-row sm:divide-y-0 sm:divide-x">
                @foreach ($presets as $key => $preset)
                    @php $isSel = $selectedPreset === $key; @endphp
                    <button
                        type="button"
                        wire:click="selectPreset('{{ $key }}')"
                        aria-pressed="{{ $isSel ? 'true' : 'false' }}"
                        class="flex flex-1 flex-col items-start px-3 py-2.5 text-left transition-colors {{ $isSel ? 'bg-gradient-to-br from-coral-500 to-coral-600' : 'hover:bg-coral-50/60' }}"
                    >
                        <span class="text-sm font-semibold leading-tight {{ $isSel ? 'text-white' : 'text-slate-900' }}">{{ $preset['label'] }}</span>
                        <span class="text-xs leading-snug mt-0.5 {{ $isSel ? 'text-white/80' : 'text-slate-500' }}">{{ $preset['description'] }}</span>
                        <span class="mt-1 text-sm font-bold tabular-nums {{ $isSel ? 'text-white' : 'text-slate-900' }}">{{ number_format($preset['consumption'], 0, ',', ' ') }} kWh/v</span>
                    </button>
                @endforeach

                {{-- Direct entry segment (highlighted when consumption is custom) --}}
                @php $isDirect = $selectedPreset === null; @endphp
                <div class="flex flex-1 flex-col justify-center px-3 py-2.5 transition-colors {{ $isDirect ? 'bg-coral-50' : '' }}">
                    <label for="direct-consumption" class="text-xs leading-snug {{ $isDirect ? 'text-coral-600 font-semibold' : 'text-slate-500 font-medium' }}">Tiedän kulutukseni</label>
                    <div class="flex items-baseline gap-1 mt-1">
                        <input
                            id="direct-consumption"
                            type="number"
                            min="0"
                            step="100"
                            inputmode="numeric"
                            wire:model.blur="directConsumption"
                            placeholder="esim. 7000"
                            @if ($directConsumptionNotice) aria-invalid="true" aria-describedby="direct-consumption-notice" @endif
                            class="w-full min-w-0 bg-transparent text-sm font-bold text-slate-900 placeholder:font-normal placeholder:text-slate-500 focus:outline-none tabular-nums"
                        >
                        <span class="text-xs text-slate-500 shrink-0">kWh/v</span>
                    </div>
                    @if ($directConsumptionNotice)
                        <p id="direct-consumption-notice" role="alert" class="mt-1 text-xs text-red-600">{{ $directConsumptionNotice }}</p>
                    @endif
                </div>
            </div>
        @endif

        {{-- Calculator Tab --}}
        @if (($showCalculatorTab ?? true) && $activeTab === 'calculator')
            <div class="max-w-4xl mx-auto">
                <div class="bg-white rounded-2xl shadow-sm border border-slate-100 p-6 text-left">
                    @if ($calculatorInputNotice)
                        <p role="alert" class="mb-4 text-sm text-red-600">{{ $calculatorInputNotice }}</p>
                    @endif

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
                                wire:model.blur="calcLivingArea"
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
                                wire:model.blur="calcNumPeople"
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
                                        wire:model.blur="calcBathroomHeatingArea"
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
                                        wire:model.blur="calcSaunaUsagePerWeek"
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
                                        wire:model.blur="calcElectricVehicleKmsPerWeek"
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

