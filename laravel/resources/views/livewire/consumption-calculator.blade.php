<div>
    <!-- Hero Section - Dark slate background -->
    <section class="bg-slate-950 -mx-4 sm:-mx-6 lg:-mx-8 mb-8">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="py-12 lg:py-16 text-center">
                <h1 class="text-3xl md:text-4xl xl:text-5xl font-extrabold text-white tracking-tight leading-none mb-4">
                    Sähkönkulutus<span class="text-coral-400">laskuri</span>
                </h1>
                <p class="max-w-2xl mx-auto text-slate-300 md:text-lg">
                    Laske kotitaloutesi arvioitu sähkönkulutus ja vertaile sähkösopimuksia.
                </p>
            </div>
        </div>
    </section>

    <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 py-8">

    <!-- Introduction -->
    <div class="mb-8 text-center max-w-2xl mx-auto">
        <p class="text-slate-600 mb-4">
            Sähkönkulutuslaskuri auttaa sinua arvioimaan kotitaloutesi vuotuisen sähkönkulutuksen. Syötä asuntosi tiedot ja laskuri laskee kulutuksen automaattisesti huomioiden asunnon koon, asukkaiden määrän sekä mahdolliset lisäkuluttajat kuten saunan tai sähköauton.
        </p>
        <p class="text-slate-500 text-sm">
            Kun olet saanut kulutusarvion, voit siirtyä vertailemaan sähkösopimuksia juuri sinun kulutuksellesi lasketuilla hinnoilla. Näet heti, mikä sopimus on edullisin ja paljonko vuodessa maksaisit.
        </p>
    </div>

    <!-- Calculator Section -->
    <section class="bg-white rounded-2xl shadow-sm border border-slate-100 p-6 mb-8">
            <!-- Building Type Selection -->
            <div class="mb-8">
                <h4 class="font-semibold text-slate-900 mb-4">Asuntotyyppi</h4>
                <div class="grid grid-cols-3 gap-4">
                    @foreach ($buildingTypeLabels as $type => $label)
                        @php
                            $icons = [
                                'apartment' => 'M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4',
                                'row_house' => 'M8 14v3m4-3v3m4-3v3M3 21h18M3 10h18M3 7l9-4 9 4M4 10h16v11H4V10z',
                                'detached_house' => 'M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6',
                            ];
                        @endphp
                        <button
                            wire:click="selectBuildingType('{{ $type }}')"
                            class="p-4 border rounded-xl text-center transition-all {{ $buildingType === $type ? 'border-coral-500 bg-coral-50' : 'border-slate-100 hover:border-slate-300' }}"
                        >
                            <svg class="w-8 h-8 mx-auto mb-2 {{ $buildingType === $type ? 'text-coral-600' : 'text-slate-500' }}" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="{{ $icons[$type] }}"></path>
                            </svg>
                            <span class="text-sm font-medium {{ $buildingType === $type ? 'text-coral-700' : 'text-slate-700' }}">{{ $label }}</span>
                        </button>
                    @endforeach
                </div>
            </div>

            <!-- Basic Info -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-8">
                <div>
                    <label for="living-area" class="block text-sm font-medium text-slate-700 mb-2">
                        Asuinpinta-ala (m²)
                    </label>
                    <input
                        type="number"
                        id="living-area"
                        wire:model.live.debounce.300ms="livingArea"
                        min="10"
                        max="500"
                        class="w-full px-4 py-3 border border-slate-300 rounded-lg focus:ring-2 focus:ring-coral-500 focus:border-coral-500"
                    >
                </div>
                <div>
                    <label for="num-people" class="block text-sm font-medium text-slate-700 mb-2">
                        Asukkaiden lukumäärä
                    </label>
                    <input
                        type="number"
                        id="num-people"
                        wire:model.live.debounce.300ms="numPeople"
                        min="1"
                        max="10"
                        class="w-full px-4 py-3 border border-slate-300 rounded-lg focus:ring-2 focus:ring-coral-500 focus:border-coral-500"
                    >
                </div>
            </div>

            <!-- Heating Toggle -->
            <div class="mb-8">
                <div class="flex items-center justify-between p-4 bg-slate-50 rounded-xl">
                    <div>
                        <h4 class="font-semibold text-slate-900">Sisällytä lämmitys</h4>
                        <p class="text-sm text-slate-500">Laske myös sähkölämmityksen kulutus</p>
                    </div>
                    <button
                        wire:click="toggleIncludeHeating"
                        class="relative inline-flex h-6 w-11 flex-shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors duration-200 ease-in-out focus:outline-none focus:ring-2 focus:ring-coral-500 focus:ring-offset-2 {{ $includeHeating ? 'bg-coral-500' : 'bg-slate-200' }}"
                    >
                        <span class="sr-only">Sisällytä lämmitys</span>
                        <span
                            class="pointer-events-none inline-block h-5 w-5 transform rounded-full bg-white shadow ring-0 transition duration-200 ease-in-out {{ $includeHeating ? 'translate-x-5' : 'translate-x-0' }}"
                        ></span>
                    </button>
                </div>
            </div>

            <!-- Heating Options (shown when includeHeating is true) -->
            @if ($includeHeating)
                <div class="mb-8 p-4 bg-blue-50 rounded-xl border border-blue-100">
                    <h4 class="font-semibold text-slate-900 mb-4">Lämmitysasetukset</h4>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                        <div>
                            <label for="heating-method" class="block text-sm font-medium text-slate-700 mb-2">
                                Päälämmitysmuoto
                            </label>
                            <select
                                id="heating-method"
                                wire:model.live="heatingMethod"
                                class="w-full px-4 py-3 border border-slate-300 rounded-lg focus:ring-2 focus:ring-coral-500 focus:border-coral-500 bg-white"
                            >
                                @foreach ($heatingMethodLabels as $method => $label)
                                    <option value="{{ $method }}">{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label for="building-region" class="block text-sm font-medium text-slate-700 mb-2">
                                Sijainti
                            </label>
                            <select
                                id="building-region"
                                wire:model.live="buildingRegion"
                                class="w-full px-4 py-3 border border-slate-300 rounded-lg focus:ring-2 focus:ring-coral-500 focus:border-coral-500 bg-white"
                            >
                                @foreach ($buildingRegionLabels as $region => $label)
                                    <option value="{{ $region }}">{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label for="energy-efficiency" class="block text-sm font-medium text-slate-700 mb-2">
                                Rakennusvuosi / energiatehokkuus
                            </label>
                            <select
                                id="energy-efficiency"
                                wire:model.live="buildingEnergyEfficiency"
                                class="w-full px-4 py-3 border border-slate-300 rounded-lg focus:ring-2 focus:ring-coral-500 focus:border-coral-500 bg-white"
                            >
                                @foreach ($buildingEnergyEfficiencyLabels as $rating => $label)
                                    <option value="{{ $rating }}">{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label for="supplementary-heating" class="block text-sm font-medium text-slate-700 mb-2">
                                Lisälämmitys (valinnainen)
                            </label>
                            <select
                                id="supplementary-heating"
                                wire:model.live="supplementaryHeating"
                                class="w-full px-4 py-3 border border-slate-300 rounded-lg focus:ring-2 focus:ring-coral-500 focus:border-coral-500 bg-white"
                            >
                                <option value="">Ei lisälämmitystä</option>
                                @foreach ($supplementaryHeatingLabels as $method => $label)
                                    <option value="{{ $method }}">{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                </div>
            @endif

            <!-- Extras Section -->
            <div class="mb-8">
                <h4 class="font-semibold text-slate-900 mb-4">Lisävarusteet</h4>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <!-- Bathroom Floor Heating -->
                    <div class="p-4 border rounded-xl {{ $bathroomHeatingArea > 0 ? 'border-coral-300 bg-coral-50' : 'border-slate-100' }}">
                        <div class="flex items-center mb-3">
                            <svg class="w-5 h-5 mr-2 text-slate-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 10h16M4 14h16M4 18h16"></path>
                            </svg>
                            <span class="font-medium text-slate-900">Lattialämmitys</span>
                        </div>
                        <div class="flex items-center">
                            <input
                                type="number"
                                wire:model.live.debounce.300ms="bathroomHeatingArea"
                                min="0"
                                max="50"
                                placeholder="0"
                                class="w-20 px-3 py-2 text-sm border border-slate-300 rounded-lg focus:ring-2 focus:ring-coral-500"
                            >
                            <span class="ml-2 text-sm text-slate-500">m²</span>
                        </div>
                    </div>

                    <!-- Sauna -->
                    <div class="p-4 border rounded-xl {{ $saunaUsagePerWeek > 0 ? 'border-coral-300 bg-coral-50' : 'border-slate-100' }}">
                        <div class="flex items-center mb-3">
                            <svg class="w-5 h-5 mr-2 text-slate-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 18.657A8 8 0 016.343 7.343S7 9 9 10c0-2 .5-5 2.986-7C14 5 16.09 5.777 17.656 7.343A7.975 7.975 0 0120 13a7.975 7.975 0 01-2.343 5.657z"></path>
                            </svg>
                            <span class="font-medium text-slate-900">Sauna</span>
                        </div>
                        <div class="flex items-center">
                            <input
                                type="number"
                                wire:model.live.debounce.300ms="saunaUsagePerWeek"
                                min="0"
                                max="14"
                                placeholder="0"
                                class="w-20 px-3 py-2 text-sm border border-slate-300 rounded-lg focus:ring-2 focus:ring-coral-500"
                            >
                            <span class="ml-2 text-sm text-slate-500">krt/viikko</span>
                        </div>
                    </div>

                    <!-- Electric Vehicle -->
                    <div class="p-4 border rounded-xl {{ $electricVehicleKmsPerMonth > 0 ? 'border-coral-300 bg-coral-50' : 'border-slate-100' }}">
                        <div class="flex items-center mb-3">
                            <svg class="w-5 h-5 mr-2 text-slate-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"></path>
                            </svg>
                            <span class="font-medium text-slate-900">Sähköauto</span>
                        </div>
                        <div class="flex items-center">
                            <input
                                type="number"
                                wire:model.live.debounce.300ms="electricVehicleKmsPerMonth"
                                min="0"
                                max="5000"
                                placeholder="0"
                                class="w-24 px-3 py-2 text-sm border border-slate-300 rounded-lg focus:ring-2 focus:ring-coral-500"
                            >
                            <span class="ml-2 text-sm text-slate-500">km/kk</span>
                        </div>
                    </div>

                    <!-- Cooling -->
                    <div class="p-4 border rounded-xl {{ $cooling ? 'border-coral-300 bg-coral-50' : 'border-slate-100' }}">
                        <div class="flex items-center justify-between">
                            <div class="flex items-center">
                                <svg class="w-5 h-5 mr-2 text-slate-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"></path>
                                </svg>
                                <span class="font-medium text-slate-900">Ilmastointi</span>
                            </div>
                            <button
                                wire:click="toggleCooling"
                                class="relative inline-flex h-6 w-11 flex-shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors duration-200 ease-in-out focus:outline-none focus:ring-2 focus:ring-coral-500 focus:ring-offset-2 {{ $cooling ? 'bg-coral-500' : 'bg-slate-200' }}"
                            >
                                <span class="sr-only">Ilmastointi</span>
                                <span
                                    class="pointer-events-none inline-block h-5 w-5 transform rounded-full bg-white shadow ring-0 transition duration-200 ease-in-out {{ $cooling ? 'translate-x-5' : 'translate-x-0' }}"
                                ></span>
                            </button>
                        </div>
                        <p class="mt-2 text-xs text-slate-500">+240 kWh/vuosi</p>
                    </div>
                </div>
            </div>
        </section>

    <!-- Results Section -->
    <section class="bg-gradient-to-br from-coral-500 to-coral-600 rounded-2xl shadow-lg p-6 text-white mb-8">
        <div class="text-center mb-6">
            <p class="text-coral-100 text-sm mb-1">Arvioitu vuosikulutus</p>
            <p class="text-5xl font-bold">
                {{ number_format($this->totalConsumption, 0, ',', ' ') }}
                <span class="text-2xl font-normal">kWh</span>
            </p>
        </div>

        @if (!empty($calculationResult))
            <div class="grid grid-cols-2 sm:grid-cols-3 gap-4 mb-6">
                <div class="bg-white/10 rounded-lg p-3">
                    <p class="text-coral-100 text-xs">Perussähkö</p>
                    <p class="text-lg font-semibold">{{ number_format($calculationResult['basic_living'] ?? 0, 0, ',', ' ') }} kWh</p>
                </div>

                @if (!empty($calculationResult['heating_total']))
                    <div class="bg-white/10 rounded-lg p-3">
                        <p class="text-coral-100 text-xs">Lämmitys</p>
                        <p class="text-lg font-semibold">{{ number_format($calculationResult['heating_total'], 0, ',', ' ') }} kWh</p>
                    </div>
                @endif

                @if (!empty($calculationResult['sauna']))
                    <div class="bg-white/10 rounded-lg p-3">
                        <p class="text-coral-100 text-xs">Sauna</p>
                        <p class="text-lg font-semibold">{{ number_format($calculationResult['sauna'], 0, ',', ' ') }} kWh</p>
                    </div>
                @endif

                @if (!empty($calculationResult['electricity_vehicle']))
                    <div class="bg-white/10 rounded-lg p-3">
                        <p class="text-coral-100 text-xs">Sähköauto</p>
                        <p class="text-lg font-semibold">{{ number_format($calculationResult['electricity_vehicle'], 0, ',', ' ') }} kWh</p>
                    </div>
                @endif

                @if (!empty($calculationResult['bathroom_underfloor_heating']))
                    <div class="bg-white/10 rounded-lg p-3">
                        <p class="text-coral-100 text-xs">Lattialämmitys</p>
                        <p class="text-lg font-semibold">{{ number_format($calculationResult['bathroom_underfloor_heating'], 0, ',', ' ') }} kWh</p>
                    </div>
                @endif

                @if (!empty($calculationResult['cooling']))
                    <div class="bg-white/10 rounded-lg p-3">
                        <p class="text-coral-100 text-xs">Ilmastointi</p>
                        <p class="text-lg font-semibold">{{ number_format($calculationResult['cooling'], 0, ',', ' ') }} kWh</p>
                    </div>
                @endif
            </div>
        @endif

        <button
            wire:click="compareContracts"
            class="w-full flex items-center justify-center bg-white hover:bg-slate-50 text-coral-600 font-semibold py-4 px-6 rounded-xl transition-colors shadow-sm"
        >
            Vertaile sähkösopimuksia
            <svg class="w-5 h-5 ml-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 8l4 4m0 0l-4 4m4-4H3"></path>
            </svg>
        </button>
    </section>

    <!-- Info Section -->
    <section class="bg-slate-50 rounded-xl p-6 text-sm text-slate-600">
        <h4 class="font-semibold text-slate-900 mb-2">Tietoa laskurista</h4>
        <ul class="list-disc list-inside space-y-1">
            <li>Perussähkönkulutus: 400 kWh/hlö + 30 kWh/m² vuodessa</li>
            <li>Lämmityksen tarve vaihtelee sijainnin ja rakennuksen iän mukaan</li>
            <li>Lämpöpumppu vähentää sähkönkulutusta: ilma-vesi 2.2x, maalämpö 2.9x</li>
            <li>Sauna: ~7.5 kWh/lämmityskerta, jatkuvalämmitteinen ~2750 kWh/v</li>
            <li>Sähköauto: ~0.2 kWh/km</li>
        </ul>
    </section>
    </div>
</div>
