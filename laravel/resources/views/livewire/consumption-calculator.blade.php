<div>
    <script type="application/ld+json">{!! json_encode($this->generateJsonLd(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!}</script>

    <!-- Hero Section - Dark slate background -->
    <section class="bg-slate-950 -mx-4 sm:-mx-6 lg:-mx-8 mb-8">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <nav class="pt-6 text-sm text-slate-400" aria-label="Murupolku">
                <ol class="flex flex-wrap items-center gap-2">
                    <li><a href="/" class="hover:text-coral-400">Etusivu</a></li>
                    <li aria-hidden="true">›</li>
                    <li><a href="/sahkosopimus" class="hover:text-coral-400">Sähkösopimus</a></li>
                    <li aria-hidden="true">›</li>
                    <li class="text-slate-200">{{ $this->pageHeading }}</li>
                </ol>
            </nav>
            <div class="py-12 lg:py-16 text-center">
                <h1 class="text-3xl md:text-4xl xl:text-5xl font-extrabold text-white tracking-tight leading-none mb-4">
                    {{ $this->pageHeading }}
                </h1>
                <p class="max-w-2xl mx-auto text-slate-300 md:text-lg">
                    {{ $this->pageTagline }}
                </p>
            </div>
        </div>
    </section>

    <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 py-8">

    <!-- Introduction -->
    <div class="mb-8 max-w-3xl mx-auto">
        <p class="text-slate-600 leading-relaxed">
            {{ $this->seoIntroText }}
        </p>
    </div>

    <!-- Calculator Section -->
    <section class="bg-white rounded-2xl shadow-sm border border-slate-100 p-6 mb-8">
            <!-- Preset Quick Select -->
            <div class="mb-8">
                <h4 class="font-semibold text-slate-900 mb-3">Valitse valmis profiili</h4>
                <div class="flex flex-wrap gap-2">
                    <button
                        wire:click="selectPreset('small_apartment')"
                        class="px-4 py-2 text-sm font-medium rounded-lg border transition-colors {{ $selectedPreset === 'small_apartment' ? 'bg-coral-500 text-white border-coral-500' : 'bg-white text-slate-700 border-slate-200 hover:border-coral-400' }}"
                    >
                        Pieni yksiö
                    </button>
                    <button
                        wire:click="selectPreset('medium_apartment')"
                        class="px-4 py-2 text-sm font-medium rounded-lg border transition-colors {{ $selectedPreset === 'medium_apartment' ? 'bg-coral-500 text-white border-coral-500' : 'bg-white text-slate-700 border-slate-200 hover:border-coral-400' }}"
                    >
                        Kerrostalo 2 hlö
                    </button>
                    <button
                        wire:click="selectPreset('medium_house_heat_pump')"
                        class="px-4 py-2 text-sm font-medium rounded-lg border transition-colors {{ $selectedPreset === 'medium_house_heat_pump' ? 'bg-coral-500 text-white border-coral-500' : 'bg-white text-slate-700 border-slate-200 hover:border-coral-400' }}"
                    >
                        Omakotitalo + ILP
                    </button>
                    <button
                        wire:click="selectPreset('large_house_electric')"
                        class="px-4 py-2 text-sm font-medium rounded-lg border transition-colors {{ $selectedPreset === 'large_house_electric' ? 'bg-coral-500 text-white border-coral-500' : 'bg-white text-slate-700 border-slate-200 hover:border-coral-400' }}"
                    >
                        Suuri talo + sähkö
                    </button>
                </div>
            </div>

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
                        wire:model.blur="livingArea"
                        min="20"
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
                        wire:model.blur="numPeople"
                        min="1"
                        max="10"
                        class="w-full px-4 py-3 border border-slate-300 rounded-lg focus:ring-2 focus:ring-coral-500 focus:border-coral-500"
                    >
                </div>
            </div>

            <!-- Heating Toggle -->
            <div class="mb-6 pb-6 border-b border-slate-100">
                <div class="flex items-center justify-between">
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
                <div class="mb-8">
                    <h4 class="font-semibold text-slate-900 mb-4">Lämmitysasetukset</h4>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                        <div>
                            <label for="heating-method" class="block text-sm font-medium text-slate-700 mb-2">
                                Päälämmitysmuoto
                            </label>
                            <select
                                id="heating-method"
                                wire:model.blur="heatingMethod"
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
                                wire:model.blur="buildingRegion"
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
                                wire:model.blur="buildingEnergyEfficiency"
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
                                wire:model.blur="supplementaryHeating"
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

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-x-8 gap-y-5">
                    <!-- Bathroom Floor Heating -->
                    <div class="flex items-start gap-3">
                        <svg class="w-5 h-5 mt-0.5 text-slate-400 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 10h16M4 14h16M4 18h16"></path>
                        </svg>
                        <div class="flex-1 min-w-0">
                            <label for="bathroom-area" class="block text-sm font-medium text-slate-700 mb-1">Lattialämmitys</label>
                            <div class="flex items-center">
                                <input
                                    type="number"
                                    id="bathroom-area"
                                    wire:model.blur="bathroomHeatingArea"
                                    min="0"
                                    max="50"
                                    placeholder="0"
                                    class="w-20 px-3 py-2 text-sm border border-slate-300 rounded-lg focus:ring-2 focus:ring-coral-500"
                                >
                                <span class="ml-2 text-sm text-slate-500">m²</span>
                            </div>
                        </div>
                    </div>

                    <!-- Sauna -->
                    <div class="flex items-start gap-3">
                        <svg class="w-5 h-5 mt-0.5 text-slate-400 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 18.657A8 8 0 016.343 7.343S7 9 9 10c0-2 .5-5 2.986-7C14 5 16.09 5.777 17.656 7.343A7.975 7.975 0 0120 13a7.975 7.975 0 01-2.343 5.657z"></path>
                        </svg>
                        <div class="flex-1 min-w-0">
                            <label for="sauna-usage" class="block text-sm font-medium text-slate-700 mb-1">Sauna</label>
                            <div class="flex items-center">
                                <input
                                    type="number"
                                    id="sauna-usage"
                                    wire:model.blur="saunaUsagePerWeek"
                                    min="0"
                                    max="14"
                                    placeholder="0"
                                    class="w-20 px-3 py-2 text-sm border border-slate-300 rounded-lg focus:ring-2 focus:ring-coral-500"
                                >
                                <span class="ml-2 text-sm text-slate-500">krt/viikko</span>
                            </div>
                        </div>
                    </div>

                    <!-- Electric Vehicle -->
                    <div class="flex items-start gap-3">
                        <svg class="w-5 h-5 mt-0.5 text-slate-400 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"></path>
                        </svg>
                        <div class="flex-1 min-w-0">
                            <label for="ev-kms" class="block text-sm font-medium text-slate-700 mb-1">Sähköauto</label>
                            <div class="flex items-center">
                                <input
                                    type="number"
                                    id="ev-kms"
                                    wire:model.blur="electricVehicleKmsPerMonth"
                                    min="0"
                                    max="5000"
                                    placeholder="0"
                                    class="w-24 px-3 py-2 text-sm border border-slate-300 rounded-lg focus:ring-2 focus:ring-coral-500"
                                >
                                <span class="ml-2 text-sm text-slate-500">km/kk</span>
                            </div>
                        </div>
                    </div>

                    <!-- Cooling -->
                    <div class="flex items-start gap-3">
                        <svg class="w-5 h-5 mt-0.5 text-slate-400 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"></path>
                        </svg>
                        <div class="flex-1 min-w-0">
                            <div class="flex items-center justify-between">
                                <label for="cooling-toggle" class="block text-sm font-medium text-slate-700">Ilmastointi</label>
                                <button
                                    id="cooling-toggle"
                                    wire:click="toggleCooling"
                                    class="relative inline-flex h-6 w-11 flex-shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors duration-200 ease-in-out focus:outline-none focus:ring-2 focus:ring-coral-500 focus:ring-offset-2 {{ $cooling ? 'bg-coral-500' : 'bg-slate-200' }}"
                                >
                                    <span class="sr-only">Ilmastointi</span>
                                    <span
                                        class="pointer-events-none inline-block h-5 w-5 transform rounded-full bg-white shadow ring-0 transition duration-200 ease-in-out {{ $cooling ? 'translate-x-5' : 'translate-x-0' }}"
                                    ></span>
                                </button>
                            </div>
                            <p class="text-xs text-slate-500 mt-1">+240 kWh/vuosi</p>
                        </div>
                    </div>
                </div>
            </div>
        </section>

    <!-- Results Section -->
    <section class="bg-slate-950 rounded-2xl p-6 sm:p-8 text-white mb-8">
        <div class="text-center mb-6">
            <p class="text-slate-300 text-sm mb-1">Arvioitu vuosikulutus</p>
            <p class="text-5xl font-bold">
                {{ number_format($this->totalConsumption, 0, ',', ' ') }}
                <span class="text-2xl font-normal">kWh</span>
            </p>
        </div>

        @if (!empty($calculationResult))
            <div class="grid grid-cols-2 sm:grid-cols-3 gap-4 mb-6">
                <div class="bg-white/5 border border-white/10 rounded-lg p-3">
                    <p class="text-slate-300 text-xs">Perussähkö</p>
                    <p class="text-lg font-semibold">{{ number_format($calculationResult['basic_living'] ?? 0, 0, ',', ' ') }} kWh</p>
                </div>

                @if (!empty($calculationResult['heating_total']))
                    <div class="bg-white/5 border border-white/10 rounded-lg p-3">
                        <p class="text-slate-300 text-xs">Lämmitys</p>
                        <p class="text-lg font-semibold">{{ number_format($calculationResult['heating_total'], 0, ',', ' ') }} kWh</p>
                    </div>
                @endif

                @if (!empty($calculationResult['sauna']))
                    <div class="bg-white/5 border border-white/10 rounded-lg p-3">
                        <p class="text-slate-300 text-xs">Sauna</p>
                        <p class="text-lg font-semibold">{{ number_format($calculationResult['sauna'], 0, ',', ' ') }} kWh</p>
                    </div>
                @endif

                @if (!empty($calculationResult['electricity_vehicle']))
                    <div class="bg-white/5 border border-white/10 rounded-lg p-3">
                        <p class="text-slate-300 text-xs">Sähköauto</p>
                        <p class="text-lg font-semibold">{{ number_format($calculationResult['electricity_vehicle'], 0, ',', ' ') }} kWh</p>
                    </div>
                @endif

                @if (!empty($calculationResult['bathroom_underfloor_heating']))
                    <div class="bg-white/5 border border-white/10 rounded-lg p-3">
                        <p class="text-slate-300 text-xs">Lattialämmitys</p>
                        <p class="text-lg font-semibold">{{ number_format($calculationResult['bathroom_underfloor_heating'], 0, ',', ' ') }} kWh</p>
                    </div>
                @endif

                @if (!empty($calculationResult['cooling']))
                    <div class="bg-white/5 border border-white/10 rounded-lg p-3">
                        <p class="text-slate-300 text-xs">Ilmastointi</p>
                        <p class="text-lg font-semibold">{{ number_format($calculationResult['cooling'], 0, ',', ' ') }} kWh</p>
                    </div>
                @endif
            </div>
        @endif

        <p class="text-slate-400 text-xs leading-relaxed mb-5">
            Arvio perustuu tilastollisiin keskiarvoihin (mm. 400 kWh/asukas + 30 kWh/m² vuodessa sekä lämmitystavan ja rakennusvuoden mukainen kerroin), eikä se ole toteutunut sähkölasku. Todellinen kulutus vaihtelee asumistottumusten, kodinkoneiden ja sään mukaan.
        </p>

        <button
            wire:click="compareContracts"
            class="w-full flex items-center justify-center bg-coral-500 hover:bg-coral-400 text-white font-semibold py-4 px-6 rounded-xl transition-colors"
        >
            Vertaile sähkösopimuksia
            <svg class="w-5 h-5 ml-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 8l4 4m0 0l-4 4m4-4H3"></path>
            </svg>
        </button>
    </section>

    @php($priceEstimates = $this->contractTypePriceEstimates)
    @if (!empty($priceEstimates['rows']))
        <!-- Contract type price estimate section -->
        <section class="bg-white rounded-2xl shadow-sm border border-slate-100 p-6 sm:p-8 mb-8">
            <div class="mb-6">
                <p class="text-sm font-semibold text-coral-600 mb-2">Sähkön hinta laskuri</p>
                <h2 class="text-2xl font-bold text-slate-900 mb-3">Sähkön hinta eri sopimustyypeillä</h2>
                <p class="text-slate-600 leading-relaxed">
                    Kun tiedät vuosikulutuksesi, voit arvioida sähkön hinnan eri sopimustyypeillä.
                    Alla oleva laskuri käyttää Voltikan hintatilastoja ja näyttää, mitä
                    {{ number_format($this->totalConsumption, 0, ',', ' ') }} kWh vuosikulutus maksaisi
                    tyypillisellä edullisella, mediaanihintaisella ja kalliimmalla sopimuksella.
                </p>
            </div>

            <div class="overflow-x-auto -mx-2 sm:mx-0">
                <table class="min-w-full text-sm border border-slate-200 rounded-xl overflow-hidden">
                    <thead class="bg-slate-50 text-slate-700">
                        <tr>
                            <th class="text-left px-4 py-3 font-semibold">Sopimustyyppi</th>
                            <th class="text-right px-4 py-3 font-semibold">Edullinen p20</th>
                            <th class="text-right px-4 py-3 font-semibold">Mediaani</th>
                            <th class="text-right px-4 py-3 font-semibold">Kalliimpi p80</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-200">
                        @foreach ($priceEstimates['rows'] as $row)
                            <tr class="hover:bg-slate-50/70">
                                <td class="px-4 py-4 align-top">
                                    <div class="font-semibold text-slate-900">{{ $row['label'] }}</div>
                                    <div class="text-xs text-slate-500 mt-1 max-w-xs">{{ $row['description'] }}</div>
                                </td>
                                @foreach (['p20', 'median', 'p80'] as $quantile)
                                    <td class="px-4 py-4 text-right align-top whitespace-nowrap">
                                        @if (!empty($row['costs'][$quantile]))
                                            <div class="font-semibold text-slate-900">
                                                {{ number_format($row['costs'][$quantile]['annual'], 0, ',', ' ') }} €/v
                                            </div>
                                            <div class="text-xs text-slate-500">
                                                {{ number_format($row['costs'][$quantile]['monthly'], 0, ',', ' ') }} €/kk
                                            </div>
                                        @else
                                            <span class="text-slate-400">–</span>
                                        @endif
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="mt-6 grid gap-5 sm:grid-cols-2 text-sm text-slate-600 leading-relaxed">
                <div>
                    <p class="font-semibold text-slate-900 mb-1">Miten hinta lasketaan?</p>
                    <p>
                        Perusarvio on kulutus kWh × energian hinta snt/kWh / 100 + perusmaksu × 12.
                        Pörssisähkön vertailussa käytetään tilastojen vuosikustannusarviota, jotta toteutunut
                        12 kuukauden pörssihinta on vertailukelpoinen kiinteiden sopimusten kanssa.
                    </p>
                </div>
                <div>
                    <p class="font-semibold text-slate-900 mb-1">Tiedä sähkön hinta omalla kulutuksella</p>
                    <p>
                        Arviot perustuvat Voltikan keräämiin sähkösopimusten hintatilastoihin
                        @if (!empty($priceEstimates['date']))
                            (päivitetty {{ \Carbon\Carbon::parse($priceEstimates['date'])->format('d.m.Y') }})
                        @endif
                        . Todellinen hinta riippuu valitusta sopimuksesta, kampanjoista ja pörssisähkön toteutuneesta hinnasta.
                    </p>
                </div>
            </div>

            <div class="mt-6 flex flex-col sm:flex-row gap-3">
                <button
                    wire:click="compareContracts"
                    class="inline-flex items-center justify-center bg-coral-500 hover:bg-coral-600 text-white font-semibold py-3 px-5 rounded-xl transition-colors"
                >
                    Vertaa sopimukset omalla kulutuksella
                </button>
                <a href="/sahkosopimus/tilastot" class="inline-flex items-center justify-center bg-slate-100 hover:bg-slate-200 text-slate-700 font-semibold py-3 px-5 rounded-xl transition-colors">
                    Katso sähkön hintatilastot
                </a>
            </div>
        </section>
    @endif

    <!-- SEO Content Section -->
    <section class="bg-white rounded-2xl shadow-sm border border-slate-100 p-6 sm:p-8 mb-8 text-slate-700 leading-relaxed">
        <h2 class="text-2xl font-bold text-slate-900 mb-3">Miten sähkönkulutuslaskuri toimii?</h2>
        <p class="mb-4">
            Voltikan sähkönkulutuslaskuri laskee arvion vuosikulutuksestasi neljästä osasta: perussähköstä,
            mahdollisesta sähkölämmityksestä, käyttöveden lämmityksestä sekä erillisistä lisäkuluttajista.
            Pohjana on tilastollinen kaava, jota tarkennetaan syöttämiesi tietojen mukaan.
        </p>
        <ul class="list-disc pl-5 space-y-2 mb-6">
            <li><strong>Perussähkö:</strong> 400 kWh asukasta kohti vuodessa + 30 kWh asuinneliötä kohti.
                Tämä kattaa valaistuksen, kodinkoneet, viihde-elektroniikan ja muun jokapäiväisen kulutuksen.</li>
            <li><strong>Lämmityksen sähkönkulutus:</strong> riippuu asunnon koosta, sijainnista
                (etelä, keski- vai pohjois-Suomi), rakennusvuodesta ja lämmitystavasta. Suora sähkölämmitys
                käyttää sähköä 1:1, ilma-vesilämpöpumppu noin 2,2× tehokkaammin ja maalämpö noin 2,9× tehokkaammin.</li>
            <li><strong>Käyttöveden lämmitys:</strong> noin 120 litraa vettä asukasta kohden päivässä, josta
                40 % on lämmintä. Sähkölämmitteisessä talossa tämä on osa kokonaiskulutusta.</li>
            <li><strong>Lisäkuluttajat:</strong> sauna noin 7,5 kWh/lämmityskerta, sähköauto noin 0,2 kWh/km,
                kylpyhuoneen lattialämmitys noin 200 kWh/m²/vuosi ja ilmastointi noin 240 kWh/vuosi.</li>
        </ul>

        <h2 class="text-2xl font-bold text-slate-900 mb-3">Sähkön kulutuksen laskeminen itse</h2>
        <p class="mb-4">
            Voit toistaa saman laskelman myös käsin – sähkönkulutuslaskuri käyttää tilastollista kaavaa,
            joka jakautuu neljään osaan: <strong>perussähkö</strong>, <strong>lämmitys</strong>,
            <strong>käyttöveden lämmitys</strong> ja <strong>lisäkuluttajat</strong>. Käytämme
            esimerkkinä 120 m² omakotitaloa Keski-Suomessa: 4 asukasta, rakennettu 2000-luvulla,
            suora sähkölämmitys.
        </p>

        <h3 class="text-lg font-semibold text-slate-900 mb-2">1. Perussähkö (valaistus, kodinkoneet, elektroniikka)</h3>
        <div class="bg-slate-50 border border-slate-200 rounded-lg p-4 mb-3 font-mono text-sm text-slate-800">
            Perussähkö = asukkaat × 400 kWh + asuinpinta-ala × 30 kWh
        </div>
        <p class="mb-6">
            Esimerkkitaloutemme: 4 × 400 + 120 × 30 = 1 600 + 3 600 = <strong>5 200 kWh/v</strong>.
        </p>

        <h3 class="text-lg font-semibold text-slate-900 mb-2">2. Lämmityksen sähkönkulutus</h3>
        <p class="mb-3">
            Lämmityksen energiantarve riippuu lämmitettävästä tilavuudesta sekä sijainnin ja
            rakennusvuoden mukaan määräytyvästä lämmityskertoimesta. Kaava:
        </p>
        <div class="bg-slate-50 border border-slate-200 rounded-lg p-4 mb-3 font-mono text-sm text-slate-800">
            Lämmitysenergia = asuinpinta-ala × 2,6 m × lämmityskerroin (kWh/m³)
        </div>
        <p class="mb-3">Käytä alla olevaa taulukkoa lämmityskertoimen valintaan (kWh/m³/vuosi):</p>
        <div class="mb-4 overflow-x-auto">
            <table class="w-full text-sm border border-slate-200 rounded-lg overflow-hidden">
                <thead class="bg-slate-50 text-slate-700">
                    <tr>
                        <th class="text-left px-3 py-2 font-semibold">Rakennuskausi</th>
                        <th class="text-right px-3 py-2 font-semibold">Etelä-Suomi</th>
                        <th class="text-right px-3 py-2 font-semibold">Keski-Suomi</th>
                        <th class="text-right px-3 py-2 font-semibold">Pohjois-Suomi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-200">
                    <tr><td class="px-3 py-2">Passiivitalo</td><td class="px-3 py-2 text-right">8</td><td class="px-3 py-2 text-right">10</td><td class="px-3 py-2 text-right">12</td></tr>
                    <tr><td class="px-3 py-2">Matalaenergiatalo</td><td class="px-3 py-2 text-right">19</td><td class="px-3 py-2 text-right">24</td><td class="px-3 py-2 text-right">31</td></tr>
                    <tr><td class="px-3 py-2">2010-luku</td><td class="px-3 py-2 text-right">32</td><td class="px-3 py-2 text-right">39</td><td class="px-3 py-2 text-right">49</td></tr>
                    <tr><td class="px-3 py-2">2000-luku</td><td class="px-3 py-2 text-right">42</td><td class="px-3 py-2 text-right">51</td><td class="px-3 py-2 text-right">65</td></tr>
                    <tr><td class="px-3 py-2">1990-luku</td><td class="px-3 py-2 text-right">47</td><td class="px-3 py-2 text-right">57</td><td class="px-3 py-2 text-right">73</td></tr>
                    <tr><td class="px-3 py-2">1980-luku</td><td class="px-3 py-2 text-right">55</td><td class="px-3 py-2 text-right">66</td><td class="px-3 py-2 text-right">84</td></tr>
                    <tr><td class="px-3 py-2">1970-luku</td><td class="px-3 py-2 text-right">56</td><td class="px-3 py-2 text-right">68</td><td class="px-3 py-2 text-right">86</td></tr>
                    <tr><td class="px-3 py-2">1960-luku</td><td class="px-3 py-2 text-right">61</td><td class="px-3 py-2 text-right">74</td><td class="px-3 py-2 text-right">93</td></tr>
                    <tr><td class="px-3 py-2">Vanhempi</td><td class="px-3 py-2 text-right">65</td><td class="px-3 py-2 text-right">78</td><td class="px-3 py-2 text-right">99</td></tr>
                </tbody>
            </table>
            <p class="text-xs text-slate-500 mt-2">Lähde: lammitysvertailu.eneuvonta.fi</p>
        </div>
        <p class="mb-3">
            Esimerkki: lämmitystilavuus 120 × 2,6 = 312 m³. Keski-Suomi + 2000-luku → kerroin
            51 kWh/m³. Lämmitysenergian tarve = 312 × 51 ≈ <strong>15 900 kWh</strong>.
        </p>
        <p class="mb-3">
            Lopullinen sähkönkulutus riippuu lämmitystavan hyötysuhteesta: jaa lämmitysenergia
            hyötysuhteella.
        </p>
        <ul class="list-disc pl-5 space-y-1 mb-6">
            <li>Suora sähkölämmitys (η = 1,0): 15 900 / 1,0 = <strong>15 900 kWh</strong></li>
            <li>Ilma-vesilämpöpumppu (η = 2,2): 15 900 / 2,2 ≈ <strong>7 230 kWh</strong></li>
            <li>Maalämpö (η = 2,9): 15 900 / 2,9 ≈ <strong>5 480 kWh</strong></li>
        </ul>

        <h3 class="text-lg font-semibold text-slate-900 mb-2">3. Käyttöveden lämmitys</h3>
        <p class="mb-3">
            Sähkölämmitteisessä talossa myös käyttöveden lämmitys kuluttaa sähköä. Oletuksena
            henkilö käyttää 120 litraa vettä päivässä, josta 40 % on lämmintä, ja 1 °C → 58 °C
            lämmitykseen tarvitaan 58 °C × 1 kWh/(°C·m³).
        </p>
        <div class="bg-slate-50 border border-slate-200 rounded-lg p-4 mb-3 font-mono text-sm text-slate-800">
            Käyttövesi = asukkaat × 120 l × 0,4 × 365 × 58 / 1000
        </div>
        <p class="mb-6">
            Esimerkki: 4 × 120 × 0,4 × 365 × 58 / 1000 ≈ <strong>4 070 kWh/v</strong>
            (lisäksi varaajan energiahäviö noin 600–1 000 kWh vuodessa).
        </p>

        <h3 class="text-lg font-semibold text-slate-900 mb-2">4. Lisäkuluttajat</h3>
        <p class="mb-3">Lisää summa erikseen kullekin lisäkuluttajalle:</p>
        <ul class="list-disc pl-5 space-y-2 mb-6">
            <li><strong>Sauna:</strong> käyttökerrat/viikko × 7,5 kWh × 52 viikkoa.
                Esim. 2 kertaa viikossa: 2 × 7,5 × 52 = <strong>780 kWh/v</strong>.
                Jatkuvalämmitteinen kiuas n. 2 750 kWh/v.</li>
            <li><strong>Sähköauto:</strong> km/kuukausi × 0,199 kWh × 12 kuukautta.
                Esim. 1 500 km/kk: 1 500 × 0,199 × 12 ≈ <strong>3 580 kWh/v</strong>.</li>
            <li><strong>Kylpyhuoneen lattialämmitys:</strong> lämmitetyt neliöt × 200 kWh.
                Esim. 5 m²: 5 × 200 = <strong>1 000 kWh/v</strong>.</li>
            <li><strong>Ilmastointi:</strong> kiinteä noin <strong>240 kWh/v</strong>.</li>
        </ul>

        <h3 class="text-lg font-semibold text-slate-900 mb-2">Esimerkin yhteenlasku</h3>
        <div class="mb-3 overflow-x-auto">
            <table class="w-full text-sm border border-slate-200 rounded-lg overflow-hidden">
                <thead class="bg-slate-50 text-slate-700">
                    <tr>
                        <th class="text-left px-3 py-2 font-semibold">Erä</th>
                        <th class="text-right px-3 py-2 font-semibold">Sähkönkulutus</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-200">
                    <tr><td class="px-3 py-2">Perussähkö (4 hlö, 120 m²)</td><td class="px-3 py-2 text-right">5 200 kWh</td></tr>
                    <tr><td class="px-3 py-2">Lämmitys (suora sähkö, 2000-luku, Keski-Suomi)</td><td class="px-3 py-2 text-right">15 900 kWh</td></tr>
                    <tr><td class="px-3 py-2">Käyttövesi (4 hlö)</td><td class="px-3 py-2 text-right">4 700 kWh</td></tr>
                    <tr><td class="px-3 py-2">Sauna (2 × viikossa)</td><td class="px-3 py-2 text-right">780 kWh</td></tr>
                    <tr class="bg-slate-50 font-semibold text-slate-900"><td class="px-3 py-2">Yhteensä</td><td class="px-3 py-2 text-right">≈ 26 600 kWh/v</td></tr>
                </tbody>
            </table>
        </div>
        <p class="mb-6">
            Jos sama talo lämmitettäisiin ilma-vesilämpöpumpulla, lämmityserä putoaisi noin
            7 230 kWh:iin ja kokonaiskulutus noin 18 000 kWh:iin vuodessa. Maalämmöllä
            kokonaiskulutus jäisi noin 16 200 kWh:iin.
        </p>

        <h3 class="text-lg font-semibold text-slate-900 mb-2">Vertaa tulosta tilastollisiin keskiarvoihin</h3>
        <p class="mb-3">
            Kun olet saanut oman lukusi, vertaa sitä saman kokoluokan kotitalouksien tyypilliseen
            kulutukseen. Jos arviosi poikkeaa selvästi alla olevasta haarukasta, suurin selittäjä
            on yleensä lämmitystapa tai rakennuksen eristystaso – tarkista ne ensimmäisenä.
        </p>
        <div class="mb-6 overflow-x-auto">
            <table class="w-full text-sm border border-slate-200 rounded-lg overflow-hidden">
                <thead class="bg-slate-50 text-slate-700">
                    <tr>
                        <th class="text-left px-3 py-2 font-semibold">Asuntotyyppi</th>
                        <th class="text-left px-3 py-2 font-semibold">Tyypillinen vuosikulutus</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-200">
                    <tr><td class="px-3 py-2">Kerrostaloasunto</td><td class="px-3 py-2">2 000–5 000 kWh</td></tr>
                    <tr><td class="px-3 py-2">Rivitaloasunto</td><td class="px-3 py-2">5 000–9 000 kWh</td></tr>
                    <tr><td class="px-3 py-2">Omakotitalo ilman sähkölämmitystä</td><td class="px-3 py-2">5 000–10 000 kWh</td></tr>
                    <tr><td class="px-3 py-2">Sähkölämmitteinen omakotitalo</td><td class="px-3 py-2">15 000–25 000 kWh</td></tr>
                </tbody>
            </table>
        </div>

        <h2 class="text-2xl font-bold text-slate-900 mb-3">Mikä vaikuttaa sähkönkulutukseen?</h2>
        <p class="mb-3">
            Sähkönkulutus vaihtelee kotitalouksien välillä merkittävästi. Suurimmat tekijät ovat:
        </p>
        <ul class="list-disc pl-5 space-y-1 mb-6">
            <li>asunnon koko ja huoneiden määrä</li>
            <li>asukkaiden lukumäärä</li>
            <li>lämmitysmuoto (suora sähkö, lämpöpumppu, kaukolämpö, öljy, puu)</li>
            <li>rakennusvuosi ja eristyksen taso</li>
            <li>maantieteellinen sijainti (etelä-, keski- vai pohjois-Suomi)</li>
            <li>elämäntavat: kotona vietetty aika, saunominen, sähköauton lataus</li>
            <li>kodinkoneiden energiatehokkuus ja käyttöaste</li>
        </ul>

        <h2 class="text-2xl font-bold text-slate-900 mb-3">Sähkön hinta ja vuosikustannus</h2>
        <p class="mb-3">
            Kun tiedät vuotuisen kulutuksesi, voit laskea sähkölaskun suuruuden yksinkertaisella kaavalla:
            <strong>kulutus (kWh) × sähkön hinta (snt/kWh) + perusmaksu (€/kk) × 12</strong>.
            Sähkön hinta vaihtelee sopimustyypin mukaan – pörssisähkössä hinta seuraa tuntikohtaista markkinahintaa,
            kun taas kiinteähintaisessa sopimuksessa hinta pysyy samana koko sopimuskauden.
        </p>
        <p class="mb-6">
            Voltikan
            <a href="/sahkosopimus" class="text-coral-600 hover:underline">sähkösopimusten vertailussa</a>
            näet eri sopimusten arvioidut vuosikustannukset suoraan sinun kulutuksellesi laskettuna.
            Voit myös katsoa erikseen
            <a href="/sahkosopimus/halvin-sahkosopimus" class="text-coral-600 hover:underline">halvimmat sähkösopimukset</a>
            tai vertailla
            <a href="/sahkosopimus/porssisahko" class="text-coral-600 hover:underline">pörssisähkösopimuksia</a>.
        </p>

        <h2 class="text-2xl font-bold text-slate-900 mb-3">Usein kysyttyä sähkönkulutuksesta</h2>
        <div class="space-y-4">
            @foreach ($this->faqItems as $faq)
                <div class="border border-slate-200 rounded-lg p-4">
                    <h3 class="font-semibold text-slate-900 mb-2">{{ $faq['question'] }}</h3>
                    <p class="text-slate-700">{{ $faq['answer'] }}</p>
                </div>
            @endforeach
        </div>
    </section>
    </div>
</div>
