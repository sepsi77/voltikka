<div>
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
                            @if ($selectedLabel)
                                <button
                                    wire:click="clearAddress"
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
                                    class="w-full px-4 py-3 text-left hover:bg-slate-50 border-b border-slate-100 last:border-0 transition-colors"
                                >
                                    <div class="flex items-center">
                                        <svg class="w-5 h-5 mr-3 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"></path>
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"></path>
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

            <!-- System Size Slider -->
            <div class="mb-8">
                <div class="flex items-center justify-between mb-4">
                    <h4 class="font-semibold text-slate-900">Järjestelmän koko</h4>
                    <span class="text-coral-600 font-bold text-lg">{{ number_format($systemKwp, 1, ',', ' ') }} kWp</span>
                </div>
                <input
                    type="range"
                    wire:model.live.debounce.200ms="systemKwp"
                    min="1"
                    max="20"
                    step="0.5"
                    class="w-full h-2 bg-slate-200 rounded-lg appearance-none cursor-pointer accent-coral-500"
                >
                <div class="flex justify-between text-xs text-slate-500 mt-1">
                    <span>1 kWp</span>
                    <span>20 kWp</span>
                </div>
                <p class="text-sm text-slate-500 mt-2">
                    Tyypillinen kotitalousjärjestelmä on 3-10 kWp. 1 kWp vaatii noin 5 m² kattopinta-alaa.
                </p>
            </div>

            <!-- Shading Selection -->
            <div class="mb-8">
                <h4 class="font-semibold text-slate-900 mb-4">Varjostus</h4>
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

                <!-- Price Mode Toggle -->
                <div class="mb-6">
                    <div class="flex gap-4">
                        <button
                            wire:click="$set('priceMode', 'contract')"
                            class="flex-1 p-3 border rounded-lg text-center transition-all {{ $priceMode === 'contract' ? 'border-coral-500 bg-coral-50' : 'border-slate-200 hover:border-slate-300' }}"
                        >
                            <span class="text-sm font-medium {{ $priceMode === 'contract' ? 'text-coral-700' : 'text-slate-700' }}">Valitse sopimus</span>
                        </button>
                        <button
                            wire:click="$set('priceMode', 'manual')"
                            class="flex-1 p-3 border rounded-lg text-center transition-all {{ $priceMode === 'manual' ? 'border-coral-500 bg-coral-50' : 'border-slate-200 hover:border-slate-300' }}"
                        >
                            <span class="text-sm font-medium {{ $priceMode === 'manual' ? 'text-coral-700' : 'text-slate-700' }}">Syötä hinta</span>
                        </button>
                    </div>
                </div>

                <!-- Contract Selection -->
                @if ($priceMode === 'contract')
                    <div class="mb-6">
                        <label class="block font-semibold text-slate-900 mb-2">Valitse sähkösopimus</label>
                        <select
                            wire:model.live="selectedContractId"
                            class="w-full px-4 py-3 border border-slate-300 rounded-lg focus:ring-2 focus:ring-coral-500 focus:border-coral-500"
                        >
                            <option value="">-- Valitse sopimus --</option>
                            @foreach ($this->availableContracts as $contract)
                                <option value="{{ $contract['id'] }}">
                                    {{ $contract['name'] }} - {{ $contract['company_name'] }} ({{ number_format($contract['price_cents'], 2, ',', ' ') }} c/kWh)
                                </option>
                            @endforeach
                        </select>
                        @if ($this->selectedContract)
                            <div class="mt-2 text-sm text-slate-600">
                                <span class="font-medium">{{ $this->selectedContract['name'] }}</span>
                                <span class="text-slate-400">•</span>
                                <span>{{ $this->selectedContract['company_name'] }}</span>
                            </div>
                        @endif
                    </div>
                @else
                    <!-- Manual Price Input -->
                    <div class="mb-6">
                        <label class="block font-semibold text-slate-900 mb-2">Sähkön hinta (c/kWh)</label>
                        <input
                            type="number"
                            wire:model.live.debounce.300ms="manualPrice"
                            min="0"
                            max="100"
                            step="0.1"
                            placeholder="esim. 10,5"
                            class="w-full px-4 py-3 border border-slate-300 rounded-lg focus:ring-2 focus:ring-coral-500 focus:border-coral-500"
                        >
                        <p class="mt-1 text-sm text-slate-500">Syötä sähkösopimuksesi energiahinta senttiä per kilowattitunti</p>
                    </div>
                @endif

                <!-- Self-Consumption Slider -->
                <div class="mb-6">
                    <div class="flex items-center justify-between mb-2">
                        <h4 class="font-semibold text-slate-900">Oman käytön osuus</h4>
                        <span class="text-coral-600 font-bold text-lg">{{ $selfConsumptionPercent }}%</span>
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
            <section class="bg-gradient-to-br from-coral-500 to-coral-600 rounded-2xl shadow-lg p-6 text-white mb-8">
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
                        <div class="flex items-end justify-between gap-1 h-32 px-2">
                            @foreach ($this->monthlyKwh as $index => $kwh)
                                @php
                                    $height = $this->maxMonthlyKwh > 0 ? ($kwh / $this->maxMonthlyKwh) * 100 : 0;
                                @endphp
                                <div class="flex-1 flex flex-col items-center">
                                    <div
                                        class="w-full bg-white/30 rounded-t transition-all duration-300"
                                        style="height: {{ $height }}%"
                                        title="{{ $monthNames[$index] }}: {{ number_format($kwh, 0, ',', ' ') }} kWh"
                                    ></div>
                                    <span class="text-xs text-coral-100 mt-1">{{ substr($monthNames[$index], 0, 1) }}</span>
                                </div>
                            @endforeach
                        </div>
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
                <section class="bg-gradient-to-br from-emerald-500 to-emerald-600 rounded-2xl shadow-lg p-6 text-white mb-8">
                    <div class="text-center mb-4">
                        <p class="text-emerald-100 text-sm mb-1">Arvioitu säästö</p>
                        <p class="text-4xl font-bold">
                            {{ number_format($this->annualSavings, 0, ',', ' ') }}
                            <span class="text-xl font-normal">€/vuosi</span>
                        </p>
                    </div>

                    <div class="grid grid-cols-2 gap-4 mb-4">
                        <div class="bg-white/10 rounded-lg p-3">
                            <p class="text-emerald-100 text-xs">Oma käyttö</p>
                            <p class="text-lg font-semibold">{{ $selfConsumptionPercent }}%</p>
                        </div>
                        <div class="bg-white/10 rounded-lg p-3">
                            <p class="text-emerald-100 text-xs">Sähkön hinta</p>
                            <p class="text-lg font-semibold">{{ number_format($this->effectivePrice, 2, ',', ' ') }} c/kWh</p>
                        </div>
                    </div>

                    @if ($this->selectedContract)
                        <div class="bg-white/10 rounded-lg p-3 text-sm">
                            <p class="text-emerald-100 text-xs mb-1">Valittu sopimus</p>
                            <p class="font-medium">{{ $this->selectedContract['name'] }}</p>
                            <p class="text-emerald-200">{{ $this->selectedContract['company_name'] }}</p>
                        </div>
                    @endif

                    <p class="text-emerald-100 text-xs mt-4 text-center">
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
        <section class="bg-slate-50 rounded-xl p-6 text-sm text-slate-600">
            <h4 class="font-semibold text-slate-900 mb-2">Tietoa laskurista</h4>
            <ul class="list-disc list-inside space-y-1">
                <li>Tuottoarvio perustuu PVGIS-tietokantaan (EU Joint Research Centre)</li>
                <li>Tyypillinen suomalainen aurinkopaneelijärjestelmä tuottaa 800-1000 kWh/kWp vuodessa</li>
                <li>Tuotanto vaihtelee huomattavasti vuodenajan mukaan: kesällä jopa 10x enemmän kuin talvella</li>
                <li>Optimi kaltevuus Suomessa on noin 40-45° ja suuntaus etelään</li>
                <li>Varjostus, lumi ja pöly vähentävät todellista tuottoa arviosta</li>
            </ul>
        </section>
    </div>
</div>
