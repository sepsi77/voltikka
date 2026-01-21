<div>
    <!-- Hero Section - Dark slate with coral gradient accents -->
    <section class="bg-gradient-to-br from-slate-900 via-slate-900 to-slate-950 -mx-4 sm:-mx-6 lg:-mx-8 mb-8 relative overflow-hidden">
        <!-- Decorative gradient blobs -->
        <div class="absolute inset-0 pointer-events-none">
            <div class="absolute top-0 right-1/4 w-96 h-96 bg-coral-500 rounded-full blur-3xl opacity-20"></div>
            <div class="absolute bottom-0 left-0 w-72 h-72 bg-coral-400 rounded-full blur-3xl opacity-10 -translate-x-1/2"></div>
        </div>

        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 relative">
            <div class="grid max-w-screen-xl py-12 mx-auto lg:gap-8 xl:gap-0 lg:py-20 lg:grid-cols-12">
                <div class="mx-auto place-self-center col-12 lg:col-span-7">
                    <div class="inline-flex items-center gap-2 bg-coral-500/20 backdrop-blur-sm px-4 py-2 rounded-full text-sm font-medium text-coral-300 mb-6 border border-coral-500/20">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/>
                        </svg>
                        Yrityksille
                    </div>
                    <h1 class="max-w-2xl mb-4 text-4xl font-extrabold text-white tracking-tight leading-tight md:text-5xl xl:text-6xl">
                        @if ($this->hasActiveFilters())
                            {{ $pageTitle }}
                        @else
                            Sähkösopimus<br>
                            <span class="text-coral-400">yritykselle</span>
                        @endif
                    </h1>
                    <p class="max-w-2xl mb-6 text-slate-300 lg:mb-8 md:text-lg lg:text-xl">
                        Vertaile yrityksille suunnattuja sähkösopimuksia. Löydä edullisin vaihtoehto yrityksen kulutukseen ja vastuullisuustavoitteisiin.
                    </p>
                </div>
                <div class="hidden lg:flex lg:col-span-5 items-center justify-end">
                    <!-- Stats cards -->
                    <div class="flex gap-3">
                        <div class="bg-white/5 backdrop-blur-sm rounded-2xl px-6 py-4 text-center border border-white/10">
                            <div class="text-3xl font-extrabold text-white">{{ $contracts->count() }}</div>
                            <div class="text-sm text-slate-400">sopimusta</div>
                        </div>
                        <div class="bg-white/5 backdrop-blur-sm rounded-2xl px-6 py-4 text-center border border-white/10">
                            <div class="text-3xl font-extrabold text-white">{{ $this->getUniqueCompanyCount() }}</div>
                            <div class="text-sm text-slate-400">yhtiota</div>
                        </div>
                        <div class="bg-coral-500/20 backdrop-blur-sm rounded-2xl px-6 py-4 text-center border border-coral-500/30">
                            <div class="text-3xl font-extrabold text-coral-400">{{ $this->getZeroEmissionCount() }}</div>
                            <div class="text-sm text-coral-300">paastotonta</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">

    <!-- Consumption Selection Section -->
    <section class="bg-transparent text-center mb-8">
        <h3 class="max-w-2xl mb-4 mx-auto text-3xl font-extrabold tracking-tight leading-none text-slate-900">
            Valitse yrityksen kulutustaso
        </h3>

        <!-- Presets Grid -->
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-4 max-w-6xl mx-auto">
            @foreach ($presets as $key => $preset)
                <button
                    wire:click="selectPreset('{{ $key }}')"
                    class="p-5 border-2 rounded-2xl transition-all text-left {{ $selectedPreset === $key ? 'bg-gradient-to-r from-coral-500 to-coral-600 border-coral-500 shadow-coral' : 'bg-white border-slate-200 hover:border-coral-400' }}"
                >
                    <div class="flex items-start">
                        <span class="{{ $selectedPreset === $key ? 'bg-white/20' : 'bg-slate-100' }} p-2 rounded-xl mr-3 flex-shrink-0">
                            @if ($preset['icon'] === 'office')
                                <svg class="w-6 h-6 {{ $selectedPreset === $key ? 'text-white' : 'text-slate-600' }}" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"></path>
                                </svg>
                            @elseif ($preset['icon'] === 'retail')
                                <svg class="w-6 h-6 {{ $selectedPreset === $key ? 'text-white' : 'text-slate-600' }}" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z"></path>
                                </svg>
                            @elseif ($preset['icon'] === 'restaurant')
                                <svg class="w-6 h-6 {{ $selectedPreset === $key ? 'text-white' : 'text-slate-600' }}" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"></path>
                                </svg>
                            @elseif ($preset['icon'] === 'warehouse')
                                <svg class="w-6 h-6 {{ $selectedPreset === $key ? 'text-white' : 'text-slate-600' }}" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 14v3m4-3v3m4-3v3M3 21h18M3 10h18M3 7l9-4 9 4M4 10h16v11H4V10z"></path>
                                </svg>
                            @elseif ($preset['icon'] === 'factory')
                                <svg class="w-6 h-6 {{ $selectedPreset === $key ? 'text-white' : 'text-slate-600' }}" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19.428 15.428a2 2 0 00-1.022-.547l-2.387-.477a6 6 0 00-3.86.517l-.318.158a6 6 0 01-3.86.517L6.05 15.21a2 2 0 00-1.806.547M8 4h8l-1 1v5.172a2 2 0 00.586 1.414l5 5c1.26 1.26.367 3.414-1.415 3.414H4.828c-1.782 0-2.674-2.154-1.414-3.414l5-5A2 2 0 009 10.172V5L8 4z"></path>
                                </svg>
                            @else
                                <svg class="w-6 h-6 {{ $selectedPreset === $key ? 'text-white' : 'text-slate-600' }}" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"></path>
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
        @php
            $useLinks = !$this->hasActiveFilters();
        @endphp
        <div class="lg:flex flex-wrap" :class="{ 'hidden': !filtersOpen }" x-bind:class="{ 'hidden lg:flex': !filtersOpen }">
            <!-- Pricing Model Filters -->
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
                        <button
                            wire:click="setPricingModelFilter('{{ $model }}')"
                            class="flex items-center border focus:outline-none font-medium rounded-lg text-sm px-4 py-2 transition-all {{ $isActive ? 'bg-slate-950 border-slate-950 text-white' : 'bg-slate-50 border-slate-200 text-slate-600 hover:border-slate-300' }}"
                        >
                            <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="{{ $icon }}"></path>
                            </svg>
                            {{ $label }}
                        </button>
                    @endforeach
                </div>
            </div>

            <!-- Contract Duration Filters -->
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
                        <button
                            wire:click="setContractTypeFilter('{{ $type }}')"
                            class="flex items-center border focus:outline-none font-medium rounded-lg text-sm px-4 py-2 transition-all {{ $isActive ? 'bg-slate-950 border-slate-950 text-white' : 'bg-slate-50 border-slate-200 text-slate-600 hover:border-slate-300' }}"
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
                <h4 class="font-semibold text-slate-900 mb-2">Energialahde</h4>
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
                    Tyhjenna suodattimet
                </button>
            </div>
        @endif
    </div>

    <!-- Results Count -->
    <div class="mb-4 text-sm text-slate-600">
        <span class="font-semibold text-slate-900">{{ $contracts->count() }}</span> yrityksille suunnattua sopimusta loytyi
        @if ($this->hasActiveFilters())
            suodattimilla
        @endif
    </div>

    <!-- Contracts List -->
    <div class="space-y-4">
        @forelse ($contracts as $index => $contract)
            <x-contract-card
                :contract="$contract"
                :rank="$index + 1"
                :featured="$index === 0"
                :consumption="$consumption"
                :prices="$this->getLatestPrices($contract)"
                :showRank="true"
                :showEmissions="true"
                :showEnergyBadges="true"
                :showSpotBadge="true"
            />
        @empty
            <div class="bg-white rounded-2xl border border-slate-200 p-12 text-center">
                <svg class="w-16 h-16 mx-auto text-slate-300 mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/>
                </svg>
                <p class="text-slate-500 text-lg">Ei yrityksille suunnattuja sopimuksia saatavilla valituilla suodattimilla.</p>
                <p class="text-slate-400 mt-2">Kokeile muuttaa suodattimia tai kulutustasoa.</p>
            </div>
        @endforelse
    </div>
    </div>
</div>
