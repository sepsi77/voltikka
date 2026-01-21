<div>
    {{-- JSON-LD Structured Data --}}
    <script type="application/ld+json">
        {!! json_encode($jsonLd, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) !!}
    </script>

    {{-- Breadcrumb JSON-LD --}}
    <script type="application/ld+json">
        {!! json_encode([
            '@context' => 'https://schema.org',
            '@type' => 'BreadcrumbList',
            'itemListElement' => [
                [
                    '@type' => 'ListItem',
                    'position' => 1,
                    'name' => 'Etusivu',
                    'item' => config('app.url'),
                ],
                [
                    '@type' => 'ListItem',
                    'position' => 2,
                    'name' => 'Sahkoyhtiot',
                    'item' => config('app.url') . '/sahkosopimus/sahkoyhtiot',
                ],
                [
                    '@type' => 'ListItem',
                    'position' => 3,
                    'name' => $company->name,
                    'item' => $this->canonicalUrl,
                ],
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) !!}
    </script>

    <!-- Hero Section - Dark slate background -->
    <section class="bg-slate-950 -mx-4 sm:-mx-6 lg:-mx-8 mb-8">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="py-12 lg:py-16">
                {{-- Breadcrumb Navigation --}}
                <nav class="flex items-center text-sm text-slate-400 mb-6" aria-label="Breadcrumb">
                    <a href="/" class="hover:text-white transition-colors">Etusivu</a>
                    <svg class="w-4 h-4 mx-2 text-slate-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                    </svg>
                    <a href="/sahkosopimus/sahkoyhtiot" class="hover:text-white transition-colors">Sahkoyhtiot</a>
                    <svg class="w-4 h-4 mx-2 text-slate-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                    </svg>
                    <span class="text-slate-300">{{ $company->name }}</span>
                </nav>

                <div class="flex flex-col lg:flex-row items-center lg:items-start gap-6">
                    @if ($company->getLogoUrl())
                        <div class="bg-white p-4 rounded-xl">
                            <img
                                src="{{ $company->getLogoUrl() }}"
                                alt="{{ $company->name }}"
                                class="w-32 h-auto object-contain"
                                onerror="this.onerror=null; this.src='https://placehold.co/128x48?text=logo'"
                            >
                        </div>
                    @else
                        <div class="w-32 h-16 bg-slate-700 rounded-xl flex items-center justify-center">
                            <span class="text-slate-300 text-lg font-bold">{{ substr($company->name, 0, 3) }}</span>
                        </div>
                    @endif

                    <div class="flex-1 text-center lg:text-left">
                        <h1 class="text-3xl md:text-4xl font-bold text-white mb-2">
                            {{ $h1 }}
                        </h1>

                        {{-- Hero description with company-specific SEO content --}}
                        <p class="text-lg text-slate-300 mb-4">
                            {{ $heroDescription }}
                        </p>

                        @if ($company->street_address || $company->postal_code || $company->postal_name)
                            <p class="text-slate-300 mb-2">
                                <svg class="w-5 h-5 inline-block mr-1 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"></path>
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"></path>
                                </svg>
                                {{ $company->street_address }}@if($company->postal_code || $company->postal_name), {{ $company->postal_code }} {{ $company->postal_name }}@endif
                            </p>
                        @endif

                        @if ($company->company_url)
                            <a
                                href="{{ $company->company_url }}"
                                target="_blank"
                                rel="noopener noreferrer"
                                class="inline-flex items-center text-coral-400 hover:text-coral-300"
                            >
                                <svg class="w-5 h-5 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"></path>
                                </svg>
                                {{ $company->company_url }}
                            </a>
                        @endif
                    </div>

                    {{-- Company Stats Cards (Desktop) --}}
                    @if ($companyStats['contract_count'] > 0)
                        <div class="hidden lg:flex gap-3">
                            <div class="bg-white/5 backdrop-blur-sm rounded-2xl px-6 py-4 text-center border border-white/10">
                                <div class="text-3xl font-extrabold text-white">{{ $companyStats['contract_count'] }}</div>
                                <div class="text-sm text-slate-400">sopimusta</div>
                            </div>
                            @if ($companyStats['avg_renewable_percent'] !== null)
                                <div class="bg-green-500/20 backdrop-blur-sm rounded-2xl px-6 py-4 text-center border border-green-500/30">
                                    <div class="text-3xl font-extrabold text-green-400">{{ number_format($companyStats['avg_renewable_percent'], 0) }}%</div>
                                    <div class="text-sm text-green-300">uusiutuvaa</div>
                                </div>
                            @endif
                            @if ($companyStats['min_price'] !== null)
                                <div class="bg-coral-500/20 backdrop-blur-sm rounded-2xl px-6 py-4 text-center border border-coral-500/30">
                                    <div class="text-3xl font-extrabold text-coral-400">{{ number_format($companyStats['min_price'], 0, ',', ' ') }}</div>
                                    <div class="text-sm text-coral-300">{{ "\u{20AC}" }}/v alkaen</div>
                                </div>
                            @endif
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </section>

    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">

    {{-- Company Statistics Section (Mobile) --}}
    @if ($companyStats['contract_count'] > 0)
        <section class="lg:hidden mb-8">
            <div class="grid grid-cols-3 gap-3">
                <div class="bg-white rounded-xl p-4 text-center border border-slate-200 shadow-sm">
                    <div class="text-2xl font-extrabold text-slate-900">{{ $companyStats['contract_count'] }}</div>
                    <div class="text-xs text-slate-500">sopimusta</div>
                </div>
                @if ($companyStats['avg_renewable_percent'] !== null)
                    <div class="bg-green-50 rounded-xl p-4 text-center border border-green-200">
                        <div class="text-2xl font-extrabold text-green-600">{{ number_format($companyStats['avg_renewable_percent'], 0) }}%</div>
                        <div class="text-xs text-green-600">uusiutuvaa</div>
                    </div>
                @endif
                @if ($companyStats['min_price'] !== null)
                    <div class="bg-coral-50 rounded-xl p-4 text-center border border-coral-200">
                        <div class="text-2xl font-extrabold text-coral-600">{{ number_format($companyStats['min_price'], 0, ',', ' ') }}</div>
                        <div class="text-xs text-coral-600">{{ "\u{20AC}" }}/v alkaen</div>
                    </div>
                @endif
            </div>
        </section>
    @endif

    {{-- Consumption Selection Section --}}
    <section class="bg-transparent text-center mb-8">
        <h3 class="max-w-2xl mb-4 mx-auto text-2xl font-extrabold tracking-tight leading-none text-slate-900">
            Valitse kulutustaso
        </h3>

        {{-- Consumption Presets Grid --}}
        <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-3 max-w-5xl mx-auto">
            @foreach ($presets as $key => $preset)
                <button
                    wire:click="selectPreset('{{ $key }}')"
                    class="p-4 border-2 rounded-xl transition-all text-left {{ $selectedPreset === $key ? 'bg-gradient-to-r from-coral-500 to-coral-600 border-coral-500 shadow-coral' : 'bg-white border-slate-200 hover:border-coral-400' }}"
                >
                    <div class="flex items-start">
                        <span class="{{ $selectedPreset === $key ? 'bg-white/20' : 'bg-slate-100' }} p-1.5 rounded-lg mr-2 flex-shrink-0">
                            @if ($preset['icon'] === 'apartment')
                                <svg class="w-5 h-5 {{ $selectedPreset === $key ? 'text-white' : 'text-slate-600' }}" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"></path>
                                </svg>
                            @else
                                <svg class="w-5 h-5 {{ $selectedPreset === $key ? 'text-white' : 'text-slate-600' }}" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"></path>
                                </svg>
                            @endif
                        </span>
                        <div class="flex-1 min-w-0">
                            <h5 class="font-semibold text-sm {{ $selectedPreset === $key ? 'text-white' : 'text-slate-900' }} truncate">{{ $preset['label'] }}</h5>
                            <p class="text-xs {{ $selectedPreset === $key ? 'text-white/80' : 'text-slate-500' }}">{{ $preset['description'] }}</p>
                        </div>
                    </div>
                    <div class="mt-2 text-right">
                        <span class="text-lg font-bold {{ $selectedPreset === $key ? 'text-white' : 'text-slate-900' }}">{{ number_format($preset['consumption'], 0, ',', ' ') }}</span>
                        <span class="{{ $selectedPreset === $key ? 'text-white/80' : 'text-slate-500' }} text-xs ml-1">kWh/v</span>
                    </div>
                </button>
            @endforeach
        </div>

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

    {{-- Company Statistics Detail Section --}}
    @if ($companyStats['contract_count'] > 0)
        <section class="mb-8">
            <div class="bg-white rounded-2xl border border-slate-200 p-6">
                <h3 class="text-lg font-bold text-slate-900 mb-4">{{ $company->name }} - yhteenveto</h3>
                <div class="grid grid-cols-2 md:grid-cols-4 gap-6">
                    {{-- Average Price --}}
                    @if ($companyStats['avg_price'] !== null)
                        <div>
                            <p class="text-sm text-slate-500 mb-1">Keskihinta</p>
                            <p class="text-2xl font-bold text-slate-900">{{ number_format($companyStats['avg_price'], 0, ',', ' ') }} <span class="text-base font-normal text-slate-500">{{ "\u{20AC}" }}/v</span></p>
                            <p class="text-xs text-slate-400">{{ number_format($consumption, 0, ',', ' ') }} kWh kulutuksella</p>
                        </div>
                    @endif

                    {{-- Price Range --}}
                    @if ($companyStats['min_price'] !== null && $companyStats['max_price'] !== null)
                        <div>
                            <p class="text-sm text-slate-500 mb-1">Hintahaarukka</p>
                            <p class="text-2xl font-bold text-slate-900">
                                {{ number_format($companyStats['min_price'], 0, ',', ' ') }} - {{ number_format($companyStats['max_price'], 0, ',', ' ') }}
                                <span class="text-base font-normal text-slate-500">{{ "\u{20AC}" }}/v</span>
                            </p>
                            <p class="text-xs text-slate-400">halvin - kallein</p>
                        </div>
                    @endif

                    {{-- Average Emission Factor --}}
                    @if ($companyStats['avg_emission_factor'] !== null)
                        <div>
                            <p class="text-sm text-slate-500 mb-1">Paastokerroin</p>
                            <p class="text-2xl font-bold {{ $companyStats['avg_emission_factor'] == 0 ? 'text-green-600' : ($companyStats['avg_emission_factor'] < 100 ? 'text-green-500' : ($companyStats['avg_emission_factor'] < 300 ? 'text-amber-600' : 'text-red-600')) }}">
                                {{ number_format($companyStats['avg_emission_factor'], 0) }} <span class="text-base font-normal text-slate-500">gCO2/kWh</span>
                            </p>
                            <p class="text-xs text-slate-400">keskiarvo</p>
                        </div>
                    @endif

                    {{-- Contract Types --}}
                    <div>
                        <p class="text-sm text-slate-500 mb-1">Sopimustyypit</p>
                        <div class="flex flex-wrap gap-2">
                            @if ($companyStats['spot_contract_count'] > 0)
                                <span class="inline-flex items-center px-2 py-1 bg-coral-50 text-coral-700 text-xs font-medium rounded-lg">
                                    {{ $companyStats['spot_contract_count'] }} porssisopimus{{ $companyStats['spot_contract_count'] > 1 ? 'ta' : '' }}
                                </span>
                            @endif
                            @if ($companyStats['fixed_price_contract_count'] > 0)
                                <span class="inline-flex items-center px-2 py-1 bg-slate-100 text-slate-700 text-xs font-medium rounded-lg">
                                    {{ $companyStats['fixed_price_contract_count'] }} kiinteahintainen{{ $companyStats['fixed_price_contract_count'] > 1 ? 'ta' : '' }}
                                </span>
                            @endif
                        </div>
                    </div>
                </div>
            </div>
        </section>
    @endif

    <!-- Contracts Section -->
    <h2 class="text-2xl font-bold text-slate-900 mb-4">
        Sahkosopimukset
    </h2>

    <p class="text-slate-600 mb-6">
        <span class="font-semibold">{{ $contracts->count() }}</span> sopimusta saatavilla
    </p>

    <div class="space-y-4">
        @forelse ($contracts as $index => $contract)
            <x-contract-card
                :contract="$contract"
                :rank="$index + 1"
                :featured="$index === 0"
                :consumption="$consumption"
                :showRank="true"
                :showEmissions="true"
                :showEnergyBadges="true"
                :showSpotBadge="true"
            />
        @empty
            <div class="bg-white rounded-2xl shadow-sm border border-slate-100 p-12 text-center">
                <p class="text-slate-500">Ei sahkosopimuksia saatavilla.</p>
            </div>
        @endforelse
    </div>

    {{-- Back to Companies Link --}}
    <div class="mt-8 text-center">
        <a
            href="/sahkosopimus/sahkoyhtiot"
            class="inline-flex items-center text-coral-600 hover:text-coral-700 font-medium"
        >
            <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"></path>
            </svg>
            Takaisin sahkoyhtioihin
        </a>
    </div>
    </div>
</div>
