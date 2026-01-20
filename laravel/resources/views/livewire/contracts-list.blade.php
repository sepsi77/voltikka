<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
    <!-- Hero Section -->
    <section class="bg-transparent mb-8">
        <div class="grid max-w-screen-xl px-4 py-8 mx-auto lg:gap-8 xl:gap-0 lg:py-16 lg:grid-cols-12">
            <div class="mx-auto place-self-center col-12 lg:col-span-7">
                <p class="bg-success-100 w-fit text-center mb-4 text-success-800 text-xs font-medium p-2.5 rounded-full border border-success-400">
                    Vertaile ja säästä
                </p>
                <h1 class="max-w-2xl mb-4 text-4xl font-extrabold text-tertiary-500 tracking-tight leading-none md:text-5xl xl:text-6xl">
                    Vertaa sähkösopimuksia
                </h1>
                <p class="max-w-2xl mb-6 font-light text-gray-500 lg:mb-8 md:text-lg lg:text-xl">
                    Löydä edullisin sähkösopimus helposti. Vertaile hintoja, sopimusehtoja ja energialähteitä yhdestä paikasta.
                </p>
            </div>
            <div class="lg:mt-0 col-12 lg:col-span-5 lg:flex mx-auto mt-8 lg:mt-0">
                <!-- Decorative element placeholder -->
            </div>
        </div>
    </section>

    <!-- Consumption Selection Section -->
    <section class="bg-transparent text-center mb-8">
        <h3 class="max-w-2xl mb-4 mx-auto text-3xl font-extrabold tracking-tight leading-none">
            Valitse kulutustaso
        </h3>

        <!-- Tab Toggle -->
        <div class="flex justify-center mb-6">
            <div class="inline-flex rounded-full bg-gray-100 p-1">
                <button
                    wire:click="setActiveTab('presets')"
                    class="px-6 py-2 text-sm font-medium rounded-full transition-colors {{ $activeTab === 'presets' ? 'bg-white text-tertiary-500 shadow' : 'text-gray-500 hover:text-gray-700' }}"
                >
                    Valmiit profiilit
                </button>
                <button
                    wire:click="setActiveTab('calculator')"
                    class="px-6 py-2 text-sm font-medium rounded-full transition-colors {{ $activeTab === 'calculator' ? 'bg-white text-tertiary-500 shadow' : 'text-gray-500 hover:text-gray-700' }}"
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
                        class="p-5 bg-white border rounded-2xl shadow-sm hover:shadow-md hover:border-primary-300 transition-all text-left {{ $selectedPreset === $key ? 'border-primary-500 ring-2 ring-primary-200' : 'border-gray-200' }}"
                    >
                        <div class="flex items-start">
                            <span class="bg-[#E4FFC9] p-2 rounded-lg mr-3 flex-shrink-0">
                                @if ($preset['icon'] === 'apartment')
                                    <svg class="w-6 h-6 text-gray-700" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"></path>
                                    </svg>
                                @else
                                    <svg class="w-6 h-6 text-gray-700" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"></path>
                                    </svg>
                                @endif
                            </span>
                            <div class="flex-1 min-w-0">
                                <h5 class="font-semibold text-gray-900 truncate">{{ $preset['label'] }}</h5>
                                <p class="text-sm text-gray-500">{{ $preset['description'] }}</p>
                            </div>
                            <svg class="w-6 h-6 flex-shrink-0 ml-2 {{ $selectedPreset === $key ? 'text-primary-500' : 'text-gray-300' }}" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"></path>
                            </svg>
                        </div>
                        <div class="mt-3 text-right">
                            <span class="text-xl font-bold text-tertiary-500">{{ number_format($preset['consumption'], 0, ',', ' ') }}</span>
                            <span class="text-gray-500 text-sm ml-1">kWh/v</span>
                        </div>
                    </button>
                @endforeach
            </div>
        @endif

        <!-- Calculator Tab -->
        @if ($activeTab === 'calculator')
            <div class="max-w-2xl mx-auto">
                <div class="bg-white rounded-2xl shadow-sm border border-gray-200 p-6 text-left">
                    <!-- Custom Consumption Input -->
                    <div class="mb-6">
                        <label for="custom-consumption" class="block text-sm font-medium text-gray-700 mb-2">
                            Vuosikulutus (kWh)
                        </label>
                        <input
                            type="number"
                            id="custom-consumption"
                            wire:model.live.debounce.300ms="consumption"
                            min="500"
                            max="50000"
                            step="100"
                            class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500 text-lg"
                        >
                        <p class="mt-2 text-sm text-gray-500">
                            Syötä vuotuinen sähkönkulutuksesi kilowattitunteina.
                        </p>
                    </div>

                    <!-- Quick Reference -->
                    <div class="bg-gray-50 rounded-xl p-4">
                        <h4 class="font-semibold text-gray-900 mb-3 text-sm">Tyypilliset kulutukset</h4>
                        <div class="grid grid-cols-2 gap-2 text-sm">
                            <div class="flex justify-between">
                                <span class="text-gray-600">Yksiö (1 hlö)</span>
                                <span class="font-medium">2 000 kWh</span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-gray-600">Kerrostalo (2-4 hlö)</span>
                                <span class="font-medium">3 500-5 000 kWh</span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-gray-600">Omakotitalo</span>
                                <span class="font-medium">5 000-8 000 kWh</span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-gray-600">Sähkölämmitteinen</span>
                                <span class="font-medium">12 000-18 000 kWh</span>
                            </div>
                        </div>
                    </div>

                    <!-- Link to full calculator -->
                    <div class="mt-4 text-center">
                        <a href="{{ route('calculator') }}" class="text-primary-600 hover:text-primary-800 text-sm font-medium inline-flex items-center">
                            Laske tarkempi kulutusarvio
                            <svg class="w-4 h-4 ml-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 8l4 4m0 0l-4 4m4-4H3"></path>
                            </svg>
                        </a>
                    </div>
                </div>
            </div>
        @endif

        <!-- Current Selection Display -->
        <div class="mt-6">
            <div class="inline-flex items-center bg-tertiary-50 border border-tertiary-200 rounded-full px-6 py-3">
                <svg class="w-5 h-5 text-tertiary-600 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"></path>
                </svg>
                <span class="text-tertiary-700 font-medium">Vertailu kulutuksella:</span>
                <span class="text-tertiary-900 font-bold ml-2">{{ number_format($consumption, 0, ',', ' ') }} kWh/v</span>
            </div>
        </div>
    </section>

    <!-- Filter Section -->
    <div class="bg-white rounded-lg py-5 border-2 border-gray-200 mb-8" x-data="{ filtersOpen: false }">
        <!-- Mobile Accordion Trigger -->
        <button
            @click="filtersOpen = !filtersOpen"
            class="lg:hidden w-full px-4 py-2 flex items-center justify-between text-left font-semibold text-gray-900"
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
                <h4 class="font-semibold text-gray-900 mb-2">Sopimustyyppi</h4>
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
                            class="flex items-center bg-white border focus:outline-none hover:bg-gray-100 focus:ring-4 focus:ring-gray-200 font-medium rounded-full text-sm px-5 py-2.5 transition-colors {{ $contractTypeFilter === $type ? 'text-success-500 border-success-600' : 'text-gray-900 border-gray-300' }}"
                        >
                            <svg class="w-6 h-6 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="{{ $icon }}"></path>
                            </svg>
                            {{ $label }}
                        </button>
                    @endforeach
                </div>
            </div>

            <!-- Energy Source Filters -->
            <div class="flex flex-col border-t lg:border-t-0 lg:border-l-2 border-gray-300 px-4 mt-4 pt-4 lg:mt-0 lg:pt-0">
                <h4 class="font-semibold text-gray-900 mb-2">Energialähde</h4>
                <div class="flex flex-col lg:flex-row gap-2">
                    <button
                        wire:click="$toggle('fossilFreeFilter')"
                        class="flex items-center bg-white border focus:outline-none hover:bg-gray-100 focus:ring-4 focus:ring-gray-200 font-medium rounded-full text-sm px-5 py-2.5 transition-colors {{ $fossilFreeFilter ? 'text-success-500 border-success-600' : 'text-gray-900 border-gray-300' }}"
                    >
                        <svg class="w-6 h-6 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 3v4M3 5h4M6 17v4m-2-2h4m5-16l2.286 6.857L21 12l-5.714 2.143L13 21l-2.286-6.857L5 12l5.714-2.143L13 3z"></path>
                        </svg>
                        Fossiiliton
                    </button>
                    <button
                        wire:click="$toggle('renewableFilter')"
                        class="flex items-center bg-white border focus:outline-none hover:bg-gray-100 focus:ring-4 focus:ring-gray-200 font-medium rounded-full text-sm px-5 py-2.5 transition-colors {{ $renewableFilter ? 'text-success-500 border-success-600' : 'text-gray-900 border-gray-300' }}"
                    >
                        <svg class="w-6 h-6 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364 6.364l-.707-.707M6.343 6.343l-.707-.707m12.728 0l-.707.707M6.343 17.657l-.707.707M16 12a4 4 0 11-8 0 4 4 0 018 0z"></path>
                        </svg>
                        Uusiutuva
                    </button>
                    <button
                        wire:click="$toggle('nuclearFilter')"
                        class="flex items-center bg-white border focus:outline-none hover:bg-gray-100 focus:ring-4 focus:ring-gray-200 font-medium rounded-full text-sm px-5 py-2.5 transition-colors {{ $nuclearFilter ? 'text-success-500 border-success-600' : 'text-gray-900 border-gray-300' }}"
                    >
                        <svg class="w-6 h-6 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
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
                    class="text-sm text-primary-600 hover:text-primary-800 font-medium"
                >
                    Tyhjennä suodattimet
                </button>
            </div>
        @endif
    </div>

    <!-- Results Count -->
    <div class="mb-4 text-sm text-gray-600">
        <span class="font-semibold">{{ $contracts->count() }}</span> sopimusta löytyi
        @if ($this->hasActiveFilters())
            suodattimilla
        @endif
    </div>

    <!-- Contracts List -->
    <div class="space-y-4">
        @forelse ($contracts as $contract)
            @php
                $prices = $this->getLatestPrices($contract);
                $generalPrice = $prices['General']['price'] ?? null;
                $monthlyFee = $prices['Monthly']['price'] ?? 0;
                $totalCost = $contract->calculated_cost['total_cost'] ?? 0;
                $isSpotContract = $contract->calculated_cost['is_spot_contract'] ?? false;
                $spotMargin = $contract->calculated_cost['spot_price_margin'] ?? null;
                $spotDayAvg = $contract->calculated_cost['spot_price_day_avg'] ?? null;
                $source = $contract->electricitySource;
            @endphp
            <div class="w-full p-4 bg-white border border-gray-200 rounded-lg shadow sm:p-8">
                <div class="flex flex-col lg:flex-row items-center">
                    <!-- Company Logo and Contract Name -->
                    <div class="flex flex-col lg:flex-row items-center">
                        @if ($contract->company?->getLogoUrl())
                            <img
                                src="{{ $contract->company->getLogoUrl() }}"
                                alt="{{ $contract->company->name }}"
                                class="w-24 h-auto object-contain"
                                onerror="this.onerror=null; this.src='https://placehold.co/96x32?text=logo'"
                            >
                        @else
                            <div class="w-24 h-12 bg-gray-200 rounded flex items-center justify-center">
                                <span class="text-gray-500 text-sm font-bold">{{ substr($contract->company?->name ?? 'N/A', 0, 3) }}</span>
                            </div>
                        @endif
                        <div class="flex flex-col items-start ml-0 lg:ml-4 mt-4 lg:mt-0 text-center lg:text-left">
                            <h5 class="mb-2 text-2xl font-bold text-gray-900">
                                {{ $contract->name }}
                            </h5>
                            <p class="mb-5 text-base text-gray-500">
                                {{ $contract->company?->name }}
                            </p>
                        </div>
                    </div>

                    <!-- Pricing Grid -->
                    <div class="flex flex-col w-full lg:flex-row items-start lg:items-center lg:ml-auto justify-end gap-4">
                        @if ($isSpotContract && $spotMargin !== null)
                            {{-- Spot contract: show margin and average spot price --}}
                            <div class="text-start px-2">
                                <h5 class="mb-2 text-xl font-bold text-gray-900">
                                    {{ number_format($spotMargin, 2, ',', ' ') }} c/kWh
                                </h5>
                                <p class="text-base text-gray-500">
                                    Marginaali
                                </p>
                            </div>
                            @if ($spotDayAvg !== null)
                                <div class="text-start px-2">
                                    <h5 class="mb-2 text-xl font-bold text-gray-500">
                                        + {{ number_format($spotDayAvg, 2, ',', ' ') }} c/kWh
                                    </h5>
                                    <p class="text-base text-gray-400 text-sm">
                                        Pörssisähkö (ka.)
                                    </p>
                                </div>
                            @endif
                        @elseif ($generalPrice !== null)
                            {{-- Fixed price contract --}}
                            <div class="text-start px-2">
                                <h5 class="mb-2 text-xl font-bold text-gray-900">
                                    {{ number_format($generalPrice, 2, ',', ' ') }} c/kWh
                                </h5>
                                <p class="text-base text-gray-500">
                                    Hinta per kWh
                                </p>
                            </div>
                        @endif

                        <div class="text-start px-2">
                            <h5 class="mb-2 text-xl font-bold text-gray-900">
                                {{ number_format($monthlyFee, 2, ',', ' ') }} EUR/kk
                                <span class="text-sm font-normal text-gray-500">{{ $contract->contract_type }}</span>
                            </h5>
                            <p class="text-base text-gray-500">
                                Perusmaksu
                            </p>
                        </div>

                        <!-- Total Cost with Cyan Border -->
                        <div class="border-solid lg:border-l-2 lg:border-primary lg:pl-4 text-start">
                            <h5 class="mb-2 text-xl font-bold text-gray-900">
                                {{ number_format($totalCost, 2, ',', ' ') }} EUR
                            </h5>
                            <p class="text-base text-gray-500">
                                Vuosikustannus
                                @if ($isSpotContract)
                                    <span class="text-xs text-gray-400">(arvio)</span>
                                @endif
                            </p>
                        </div>
                    </div>
                </div>

                <!-- Energy Source Badges and CTA -->
                <div class="flex flex-wrap lg:flex-nowrap items-center mt-6">
                    @if ($source)
                        @if ($source->renewable_total && $source->renewable_total > 0)
                            <span class="text-gray-700 border border-green-700 bg-[#E4FFC9] font-medium rounded-lg text-sm px-5 py-2.5 text-center mr-2 mb-2 lg:mb-0">
                                Uusiutuva <span class="font-semibold ml-2">{{ number_format($source->renewable_total, 0) }}%</span>
                            </span>
                        @endif
                        @if ($source->nuclear_total && $source->nuclear_total > 0)
                            <span class="text-gray-700 border border-green-700 bg-[#E4FFC9] font-medium rounded-lg text-sm px-5 py-2.5 text-center mr-2 mb-2 lg:mb-0">
                                Ydinvoima <span class="font-semibold ml-2">{{ number_format($source->nuclear_total, 0) }}%</span>
                            </span>
                        @endif
                        @if ($source->fossil_total && $source->fossil_total > 0)
                            <span class="text-gray-700 border border-gray-700 bg-gray-100 font-medium rounded-lg text-sm px-5 py-2.5 text-center mr-2 mb-2 lg:mb-0">
                                Fossiilinen <span class="font-semibold ml-2">{{ number_format($source->fossil_total, 0) }}%</span>
                            </span>
                        @endif
                    @endif

                    <a
                        href="{{ route('contract.detail', $contract->id) }}"
                        class="w-full lg:w-auto flex items-center justify-center text-tertiary-500 bg-primary hover:bg-tertiary-500 hover:text-primary focus:outline-none focus:ring-4 focus:ring-primary-300 font-medium rounded-full text-sm px-5 py-2.5 text-center ml-auto mt-5 lg:mt-0 transition-colors"
                    >
                        Katso lisää
                        <svg class="w-6 h-6 ml-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 8l4 4m0 0l-4 4m4-4H3"></path>
                        </svg>
                    </a>
                </div>
            </div>
        @empty
            <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-12 text-center">
                <p class="text-gray-500">Ei sopimuksia saatavilla.</p>
            </div>
        @endforelse
    </div>
</div>
