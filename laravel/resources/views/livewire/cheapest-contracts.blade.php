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
                        <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20">
                            <path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"/>
                        </svg>
                        Edullisimmat sopimukset
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

        {{-- Calculator Tab (simplified for this page) --}}
        @if ($activeTab === 'calculator')
            <div class="max-w-md mx-auto">
                <div class="bg-white rounded-2xl shadow-sm border border-slate-100 p-6 text-left">
                    <label for="custom-consumption" class="block text-sm font-medium text-slate-700 mb-2">
                        Syötä vuosikulutus (kWh)
                    </label>
                    <input
                        type="number"
                        id="custom-consumption"
                        wire:model.live.debounce.500ms="consumption"
                        min="500"
                        max="50000"
                        step="500"
                        class="w-full px-4 py-3 border border-slate-300 rounded-lg focus:ring-2 focus:ring-coral-500 focus:border-coral-500 text-2xl font-bold text-center"
                    >
                    <p class="text-sm text-slate-500 mt-2 text-center">
                        Löydät kulutustiedon sähkölaskustasi
                    </p>
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

    {{-- Featured Contract (#1 Cheapest) --}}
    @if ($featuredContract)
        <section class="mb-8">
            <x-featured-contract-card
                :contract="$featuredContract"
                :consumption="$consumption"
                :prices="$this->getLatestPrices($featuredContract)"
            />
        </section>
    @endif

    {{-- Remaining Contracts (#2-11) --}}
    <section>
        <h2 class="text-2xl font-bold text-slate-900 mb-4">Seuraavaksi edullisimmat</h2>
        <div class="space-y-4">
            @forelse ($contracts as $index => $contract)
                <x-contract-card
                    :contract="$contract"
                    :rank="$index + 2"
                    :consumption="$consumption"
                    :prices="$this->getLatestPrices($contract)"
                    :showRank="true"
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
    </section>

    {{-- Internal Links Section (for SEO) --}}
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
    </div>
</div>
