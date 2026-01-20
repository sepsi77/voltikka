<div>
    <!-- Hero Section - Dark slate background -->
    <section class="bg-slate-950 -mx-4 sm:-mx-6 lg:-mx-8 mb-8">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="grid max-w-screen-xl py-12 mx-auto lg:gap-8 xl:gap-0 lg:py-20 lg:grid-cols-12">
                <div class="mx-auto place-self-center col-12 lg:col-span-7">
                    <p class="bg-coral-50 w-fit text-center mb-4 text-coral-700 text-xs font-semibold px-4 py-2 rounded-full">
                        Vertaile ja säästä
                    </p>
                    <h1 class="max-w-2xl mb-4 text-4xl font-extrabold text-white tracking-tight leading-tight md:text-5xl xl:text-6xl">
                        Löydä paras<br>
                        <span class="text-coral-400">sähkösopimus</span>
                    </h1>
                    <p class="max-w-2xl mb-6 text-slate-300 lg:mb-8 md:text-lg lg:text-xl">
                        Vertaile hintoja, sopimusehtoja ja energialähteitä yhdestä paikasta. Löydä edullisin vaihtoehto juuri sinulle.
                    </p>
                </div>
                <div class="lg:mt-0 col-12 lg:col-span-5 lg:flex mx-auto mt-8 lg:mt-0">
                    <!-- Decorative element placeholder -->
                </div>
            </div>
        </div>
    </section>

    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">

    <!-- Consumption Selection Section -->
    <section class="bg-transparent text-center mb-8">
        <h3 class="max-w-2xl mb-4 mx-auto text-3xl font-extrabold tracking-tight leading-none text-slate-900">
            Valitse kulutustaso
        </h3>

        <!-- Tab Toggle -->
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

        <!-- Presets Tab -->
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

        <!-- Calculator Tab -->
        @if ($activeTab === 'calculator')
            <div class="max-w-4xl mx-auto">
                <div class="bg-white rounded-2xl shadow-sm border border-slate-200 p-6 text-left">
                    <!-- Row 1: Basic Inputs -->
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
                        <!-- Living Area -->
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

                        <!-- Number of People -->
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

                    <!-- Row 2: Housing Type Cards -->
                    <div class="mb-6">
                        <label class="block text-sm font-medium text-slate-700 mb-3">Asuntotyyppi</label>
                        <div class="grid grid-cols-3 gap-4">
                            <!-- Detached House -->
                            <button
                                wire:click="selectBuildingType('detached_house')"
                                class="p-4 rounded-xl border-2 transition-all flex flex-col items-center {{ $calcBuildingType === 'detached_house' ? 'border-coral-500 bg-coral-50' : 'border-slate-200 hover:border-coral-300 bg-white' }}"
                            >
                                <svg class="w-10 h-10 mb-2 {{ $calcBuildingType === 'detached_house' ? 'text-coral-600' : 'text-slate-500' }}" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"></path>
                                </svg>
                                <span class="text-sm font-medium {{ $calcBuildingType === 'detached_house' ? 'text-coral-700' : 'text-slate-700' }}">Omakotitalo</span>
                            </button>

                            <!-- Row House -->
                            <button
                                wire:click="selectBuildingType('row_house')"
                                class="p-4 rounded-xl border-2 transition-all flex flex-col items-center {{ $calcBuildingType === 'row_house' ? 'border-coral-500 bg-coral-50' : 'border-slate-200 hover:border-coral-300 bg-white' }}"
                            >
                                <svg class="w-10 h-10 mb-2 {{ $calcBuildingType === 'row_house' ? 'text-coral-600' : 'text-slate-500' }}" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 14v3m4-3v3m4-3v3M3 21h18M3 10h18M3 7l9-4 9 4M4 10h16v11H4V10z"></path>
                                </svg>
                                <span class="text-sm font-medium {{ $calcBuildingType === 'row_house' ? 'text-coral-700' : 'text-slate-700' }}">Rivitalo</span>
                            </button>

                            <!-- Apartment -->
                            <button
                                wire:click="selectBuildingType('apartment')"
                                class="p-4 rounded-xl border-2 transition-all flex flex-col items-center {{ $calcBuildingType === 'apartment' ? 'border-coral-500 bg-coral-50' : 'border-slate-200 hover:border-coral-300 bg-white' }}"
                            >
                                <svg class="w-10 h-10 mb-2 {{ $calcBuildingType === 'apartment' ? 'text-coral-600' : 'text-slate-500' }}" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"></path>
                                </svg>
                                <span class="text-sm font-medium {{ $calcBuildingType === 'apartment' ? 'text-coral-700' : 'text-slate-700' }}">Kerrostalo</span>
                            </button>
                        </div>
                    </div>

                    <!-- Row 3: Include Heating Toggle -->
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

                    <!-- Row 4: Heating Options (shown when heating enabled) -->
                    @if ($calcIncludeHeating)
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-6 p-4 bg-coral-50 rounded-xl border border-coral-200">
                            <!-- Heating Method -->
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

                            <!-- Building Region -->
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

                            <!-- Building Energy Efficiency -->
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

                            <!-- Supplementary Heating -->
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

                    <!-- Row 5: Extras Section -->
                    <div class="mb-6">
                        <label class="block text-sm font-medium text-slate-700 mb-3">Lisävarusteet</label>
                        <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
                            <!-- Underfloor Heating -->
                            <button
                                wire:click="toggleExtra('underfloor')"
                                class="p-4 rounded-xl border-2 transition-all flex flex-col items-center {{ $calcUnderfloorHeatingEnabled ? 'border-coral-500 bg-coral-50' : 'border-slate-200 hover:border-coral-300 bg-white' }}"
                            >
                                <svg class="w-8 h-8 mb-2 {{ $calcUnderfloorHeatingEnabled ? 'text-coral-600' : 'text-slate-400' }}" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 5a1 1 0 011-1h14a1 1 0 011 1v2a1 1 0 01-1 1H5a1 1 0 01-1-1V5zM4 13a1 1 0 011-1h6a1 1 0 011 1v6a1 1 0 01-1 1H5a1 1 0 01-1-1v-6zM16 13a1 1 0 011-1h2a1 1 0 011 1v6a1 1 0 01-1 1h-2a1 1 0 01-1-1v-6z"></path>
                                </svg>
                                <span class="text-xs font-medium text-center {{ $calcUnderfloorHeatingEnabled ? 'text-coral-700' : 'text-slate-600' }}">Lattialämmitys</span>
                            </button>

                            <!-- Sauna -->
                            <button
                                wire:click="toggleExtra('sauna')"
                                class="p-4 rounded-xl border-2 transition-all flex flex-col items-center {{ $calcSaunaEnabled ? 'border-coral-500 bg-coral-50' : 'border-slate-200 hover:border-coral-300 bg-white' }}"
                            >
                                <svg class="w-8 h-8 mb-2 {{ $calcSaunaEnabled ? 'text-coral-600' : 'text-slate-400' }}" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 18.657A8 8 0 016.343 7.343S7 9 9 10c0-2 .5-5 2.986-7C14 5 16.09 5.777 17.656 7.343A7.975 7.975 0 0120 13a7.975 7.975 0 01-2.343 5.657z"></path>
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.879 16.121A3 3 0 1012.015 11L11 14H9c0 .768.293 1.536.879 2.121z"></path>
                                </svg>
                                <span class="text-xs font-medium text-center {{ $calcSaunaEnabled ? 'text-coral-700' : 'text-slate-600' }}">Sauna</span>
                            </button>

                            <!-- Electric Vehicle -->
                            <button
                                wire:click="toggleExtra('ev')"
                                class="p-4 rounded-xl border-2 transition-all flex flex-col items-center {{ $calcElectricVehicleEnabled ? 'border-coral-500 bg-coral-50' : 'border-slate-200 hover:border-coral-300 bg-white' }}"
                            >
                                <svg class="w-8 h-8 mb-2 {{ $calcElectricVehicleEnabled ? 'text-coral-600' : 'text-slate-400' }}" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"></path>
                                </svg>
                                <span class="text-xs font-medium text-center {{ $calcElectricVehicleEnabled ? 'text-coral-700' : 'text-slate-600' }}">Sähköauto</span>
                            </button>

                            <!-- Cooling -->
                            <button
                                wire:click="toggleExtra('cooling')"
                                class="p-4 rounded-xl border-2 transition-all flex flex-col items-center {{ $calcCooling ? 'border-coral-500 bg-coral-50' : 'border-slate-200 hover:border-coral-300 bg-white' }}"
                            >
                                <svg class="w-8 h-8 mb-2 {{ $calcCooling ? 'text-coral-600' : 'text-slate-400' }}" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"></path>
                                </svg>
                                <span class="text-xs font-medium text-center {{ $calcCooling ? 'text-coral-700' : 'text-slate-600' }}">Jäähdytys</span>
                            </button>
                        </div>
                    </div>

                    <!-- Row 6: Extra Input Fields (conditionally shown) -->
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

                    <!-- Calculated Result -->
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

                    <!-- Row 7: CTA Button -->
                    <button
                        wire:click="setActiveTab('presets')"
                        class="w-full bg-gradient-to-r from-coral-500 to-coral-600 hover:from-coral-400 hover:to-coral-500 text-white font-bold py-4 px-6 rounded-xl transition-all shadow-lg shadow-coral-500/20 flex items-center justify-center"
                    >
                        Vertaa sähkösopimuksia
                        <svg class="w-5 h-5 ml-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 8l4 4m0 0l-4 4m4-4H3"></path>
                        </svg>
                    </button>
                </div>
            </div>
        @endif

        <!-- Current Selection Display -->
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

    <!-- Filter Section -->
    <div class="bg-white rounded-2xl py-5 border border-slate-200 mb-8" x-data="{ filtersOpen: false }">
        <!-- Mobile Accordion Trigger -->
        <button
            @click="filtersOpen = !filtersOpen"
            class="lg:hidden w-full px-4 py-2 flex items-center justify-between text-left font-semibold text-slate-900"
        >
            <span>Suodattimet</span>
            <svg class="w-5 h-5 transform transition-transform" :class="{ 'rotate-180': filtersOpen }" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
            </svg>
        </button>

        <!-- Filter Content -->
        <div class="lg:flex" :class="{ 'hidden': !filtersOpen }" x-bind:class="{ 'hidden lg:flex': !filtersOpen }">
            <!-- Contract Type Filters -->
            <div class="flex flex-col px-4">
                <h4 class="font-semibold text-slate-900 mb-2">Sopimustyyppi</h4>
                <div class="flex flex-col lg:flex-row gap-2">
                    @foreach ($contractTypes as $type => $label)
                        @php
                            $icons = [
                                'OpenEnded' => 'M13 5l7 7-7 7M5 5l7 7-7 7',
                                'FixedTerm' => 'M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z',
                                'Spot' => 'M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z',
                                'Hybrid' => 'M8 9l3 3-3 3m5 0h3M5 20h14a2 2 0 002-2V6a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z',
                            ];
                            $icon = $icons[$type] ?? $icons['OpenEnded'];
                        @endphp
                        <button
                            wire:click="setContractTypeFilter('{{ $type }}')"
                            class="flex items-center border focus:outline-none font-medium rounded-lg text-sm px-4 py-2 transition-all {{ $contractTypeFilter === $type ? 'bg-slate-950 border-slate-950 text-white' : 'bg-slate-50 border-slate-200 text-slate-600 hover:border-slate-300' }}"
                        >
                            <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="{{ $icon }}"></path>
                            </svg>
                            {{ $label }}
                        </button>
                    @endforeach
                </div>
            </div>

            <!-- Energy Source Filters -->
            <div class="flex flex-col border-t lg:border-t-0 lg:border-l border-slate-200 px-4 mt-4 pt-4 lg:mt-0 lg:pt-0">
                <h4 class="font-semibold text-slate-900 mb-2">Energialähde</h4>
                <div class="flex flex-col lg:flex-row gap-2">
                    <button
                        wire:click="$toggle('fossilFreeFilter')"
                        class="flex items-center border focus:outline-none font-medium rounded-lg text-sm px-4 py-2 transition-all {{ $fossilFreeFilter ? 'bg-slate-950 border-slate-950 text-white' : 'bg-slate-50 border-slate-200 text-slate-600 hover:border-slate-300' }}"
                    >
                        <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 3v4M3 5h4M6 17v4m-2-2h4m5-16l2.286 6.857L21 12l-5.714 2.143L13 21l-2.286-6.857L5 12l5.714-2.143L13 3z"></path>
                        </svg>
                        Fossiiliton
                    </button>
                    <button
                        wire:click="$toggle('renewableFilter')"
                        class="flex items-center border focus:outline-none font-medium rounded-lg text-sm px-4 py-2 transition-all {{ $renewableFilter ? 'bg-slate-950 border-slate-950 text-white' : 'bg-slate-50 border-slate-200 text-slate-600 hover:border-slate-300' }}"
                    >
                        <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364 6.364l-.707-.707M6.343 6.343l-.707-.707m12.728 0l-.707.707M6.343 17.657l-.707.707M16 12a4 4 0 11-8 0 4 4 0 018 0z"></path>
                        </svg>
                        Uusiutuva
                    </button>
                    <button
                        wire:click="$toggle('nuclearFilter')"
                        class="flex items-center border focus:outline-none font-medium rounded-lg text-sm px-4 py-2 transition-all {{ $nuclearFilter ? 'bg-slate-950 border-slate-950 text-white' : 'bg-slate-50 border-slate-200 text-slate-600 hover:border-slate-300' }}"
                    >
                        <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"></path>
                        </svg>
                        Ydinvoima
                    </button>
                </div>
            </div>
        </div>

        <!-- Clear Filters -->
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

    <!-- Results Count -->
    <div class="mb-4 text-sm text-slate-600">
        <span class="font-semibold text-slate-900">{{ $contracts->count() }}</span> sopimusta löytyi
        @if ($this->hasActiveFilters())
            suodattimilla
        @endif
    </div>

    <!-- Contracts List -->
    <div class="space-y-4">
        @forelse ($contracts as $index => $contract)
            @php
                $prices = $this->getLatestPrices($contract);
                $generalPrice = $prices['General']['price'] ?? null;
                $monthlyFee = $prices['Monthly']['price'] ?? 0;
                $totalCost = $contract->calculated_cost['total_cost'] ?? 0;
                $isSpotContract = $contract->calculated_cost['is_spot_contract'] ?? false;
                $spotMargin = $contract->calculated_cost['spot_price_margin'] ?? null;
                $spotDayAvg = $contract->calculated_cost['spot_price_day_avg'] ?? null;
                $source = $contract->electricitySource;
                $rank = $index + 1;
                $isFeatured = $rank === 1;

                // Determine emissions color for left border
                // Using fossil_total as emissions indicator (higher fossil = higher emissions)
                $fossilPercent = $source?->fossil_total ?? 0;
                if ($isFeatured) {
                    $borderColorClass = 'border-l-coral-500';
                    $borderWidth = 'border-l-[6px]';
                } elseif ($fossilPercent == 0) {
                    $borderColorClass = 'border-l-emissions-low';
                    $borderWidth = 'border-l-4';
                } elseif ($fossilPercent <= 30) {
                    $borderColorClass = 'border-l-emissions-medium';
                    $borderWidth = 'border-l-4';
                } else {
                    $borderColorClass = 'border-l-emissions-high';
                    $borderWidth = 'border-l-4';
                }
            @endphp
            <div class="group relative w-full p-6 bg-white border border-slate-100 rounded-2xl {{ $borderWidth }} {{ $borderColorClass }} {{ $isFeatured ? 'border-coral-200' : '' }} transition-all duration-200 hover:-translate-y-0.5 hover:shadow-card-hover">
                <div class="flex flex-col lg:flex-row items-start lg:items-center gap-4">
                    <!-- Rank Number -->
                    <div class="hidden lg:block flex-shrink-0 w-12">
                        <span class="text-4xl font-extrabold {{ $isFeatured ? 'text-coral-500' : 'text-slate-200' }}">
                            {{ str_pad($rank, 2, '0', STR_PAD_LEFT) }}
                        </span>
                    </div>

                    <!-- Company Logo and Contract Name -->
                    <div class="flex items-center gap-4 flex-1 min-w-0">
                        @if ($contract->company?->getLogoUrl())
                            <img
                                src="{{ $contract->company->getLogoUrl() }}"
                                alt="{{ $contract->company->name }}"
                                class="w-16 h-12 object-contain flex-shrink-0"
                                onerror="this.onerror=null; this.src='https://placehold.co/64x48?text=logo'"
                            >
                        @else
                            <div class="w-16 h-12 bg-slate-100 rounded-lg flex items-center justify-center flex-shrink-0">
                                <span class="text-slate-500 text-xs font-bold">{{ substr($contract->company?->name ?? 'N/A', 0, 3) }}</span>
                            </div>
                        @endif
                        <div class="flex flex-col min-w-0">
                            <h5 class="text-lg font-bold text-slate-900 truncate">
                                {{ $contract->name }}
                            </h5>
                            <p class="text-sm text-slate-500">
                                {{ $contract->company?->name }}
                            </p>
                        </div>
                    </div>

                    <!-- Pricing Grid -->
                    <div class="flex flex-wrap lg:flex-nowrap items-center gap-4 lg:gap-6 w-full lg:w-auto">
                        @if ($isSpotContract && $spotMargin !== null)
                            {{-- Spot contract: show margin --}}
                            <div class="text-left">
                                <div class="text-lg font-bold text-slate-900 tabular-nums">
                                    {{ number_format($spotMargin, 2, ',', ' ') }} <span class="text-sm font-normal text-slate-400">c/kWh</span>
                                </div>
                                <p class="text-xs text-slate-500 uppercase tracking-wide">Marginaali</p>
                            </div>
                        @elseif ($generalPrice !== null)
                            {{-- Fixed price contract --}}
                            <div class="text-left">
                                <div class="text-lg font-bold text-slate-900 tabular-nums">
                                    {{ number_format($generalPrice, 2, ',', ' ') }} <span class="text-sm font-normal text-slate-400">c/kWh</span>
                                </div>
                                <p class="text-xs text-slate-500 uppercase tracking-wide">Energia</p>
                            </div>
                        @endif

                        <div class="text-left">
                            <div class="text-lg font-bold text-slate-900 tabular-nums">
                                {{ number_format($monthlyFee, 2, ',', ' ') }} <span class="text-sm font-normal text-slate-400">€/kk</span>
                            </div>
                            <p class="text-xs text-slate-500 uppercase tracking-wide">Perusmaksu</p>
                        </div>

                        <!-- Total Cost - Featured gets coral color -->
                        <div class="text-left lg:border-l lg:border-slate-200 lg:pl-6">
                            <div class="text-2xl font-extrabold {{ $isFeatured ? 'text-coral-600' : 'text-slate-900' }} tabular-nums">
                                {{ number_format($totalCost, 0, ',', ' ') }} <span class="text-sm font-normal text-slate-400">€/v</span>
                            </div>
                            <p class="text-xs text-slate-500 uppercase tracking-wide">
                                Vuosikustannus
                                @if ($isSpotContract)
                                    <span class="normal-case">(arvio)</span>
                                @endif
                            </p>
                        </div>

                        <!-- CTA Button -->
                        <a
                            href="{{ route('contract.detail', $contract->id) }}"
                            class="hidden lg:inline-flex items-center gap-2 bg-gradient-to-r from-coral-500 to-coral-600 hover:from-coral-400 hover:to-coral-500 text-white font-bold px-5 py-3 rounded-xl transition-all shadow-lg shadow-coral-500/20"
                        >
                            Katso
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 8l4 4m0 0l-4 4m4-4H3"></path>
                            </svg>
                        </a>
                    </div>
                </div>

                <!-- Energy Source Badges and Mobile CTA -->
                <div class="flex flex-wrap items-center gap-2 mt-4 pt-4 border-t border-slate-100">
                    @if ($source)
                        @if ($source->renewable_total && $source->renewable_total > 0)
                            <span class="inline-block px-3 py-1.5 bg-green-100 text-green-700 text-xs font-bold rounded-lg uppercase">
                                Uusiutuva {{ number_format($source->renewable_total, 0) }}%
                            </span>
                        @endif
                        @if ($source->nuclear_total && $source->nuclear_total > 0)
                            <span class="inline-block px-3 py-1.5 bg-green-100 text-green-700 text-xs font-bold rounded-lg uppercase">
                                Ydinvoima {{ number_format($source->nuclear_total, 0) }}%
                            </span>
                        @endif
                        @if ($source->fossil_total && $source->fossil_total > 0)
                            <span class="inline-block px-3 py-1.5 bg-slate-100 text-slate-600 text-xs font-medium rounded-lg uppercase">
                                Fossiilinen {{ number_format($source->fossil_total, 0) }}%
                            </span>
                        @endif
                    @endif

                    @if ($isSpotContract)
                        <span class="inline-block px-3 py-1.5 bg-coral-50 text-coral-700 text-xs font-bold rounded-lg uppercase">
                            Pörssi
                        </span>
                    @endif

                    <!-- Mobile CTA -->
                    <a
                        href="{{ route('contract.detail', $contract->id) }}"
                        class="lg:hidden w-full mt-2 flex items-center justify-center gap-2 bg-gradient-to-r from-coral-500 to-coral-600 hover:from-coral-400 hover:to-coral-500 text-white font-bold px-5 py-3 rounded-xl transition-all shadow-lg shadow-coral-500/20"
                    >
                        Katso sopimus
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 8l4 4m0 0l-4 4m4-4H3"></path>
                        </svg>
                    </a>
                </div>

                @if ($isFeatured)
                    <div class="absolute top-0 right-0 bg-gradient-to-r from-coral-500 to-coral-600 text-white text-xs font-bold px-3 py-1.5 rounded-bl-xl rounded-tr-2xl">
                        Suositus
                    </div>
                @endif
            </div>
        @empty
            <div class="bg-white rounded-2xl border border-slate-200 p-12 text-center">
                <p class="text-slate-500">Ei sopimuksia saatavilla.</p>
            </div>
        @endforelse
    </div>
    </div>
</div>
