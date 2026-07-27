<div>
    {{-- Schema.org structured data --}}
    <x-schema-markup :schemas="$schemas" />

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
                    <a href="/sahkosopimus/sahkoyhtiot" class="hover:text-white transition-colors">Sähköyhtiöt</a>
                    <svg class="w-4 h-4 mx-2 text-slate-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                    </svg>
                    <span class="text-slate-300">{{ $company->name }}</span>
                </nav>

                <div class="flex flex-col lg:flex-row items-center lg:items-start gap-6">
                    <x-company-logo
                        :company="$company"
                        class="h-16 w-32 rounded-xl bg-slate-700 text-lg font-bold text-slate-300"
                        img-class="bg-white rounded-xl p-3"
                    />

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
    <section x-data="{ panelOpen: false }" class="bg-transparent text-center mb-8">
        <h3 class="max-w-2xl mb-4 mx-auto text-2xl font-extrabold tracking-tight leading-none text-slate-900">
            Valitse kulutustaso
        </h3>

        {{-- Mobile-only toggle --}}
        <button
            type="button"
            @click="panelOpen = !panelOpen"
            class="lg:hidden inline-flex items-center gap-2 mx-auto mb-5 bg-white border border-slate-200 rounded-full px-5 py-2.5 text-sm font-semibold text-slate-700 hover:border-coral-400 transition-colors"
            :aria-expanded="panelOpen ? 'true' : 'false'"
        >
            <span x-text="panelOpen ? 'Piilota vaihtoehdot' : 'Vaihda kulutusta'"></span>
            <svg class="w-4 h-4 transition-transform" :class="panelOpen && 'rotate-180'" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
            </svg>
        </button>

        {{-- Consumption Presets Grid --}}
        <div :class="panelOpen ? 'grid' : 'hidden lg:grid'" class="grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-3 max-w-5xl mx-auto">
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
                            <p class="text-sm text-slate-500 mb-1">Päästökerroin</p>
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
                                    {{ $companyStats['spot_contract_count'] }} pörssisopimus{{ $companyStats['spot_contract_count'] > 1 ? 'ta' : '' }}
                                </span>
                            @endif
                            @if ($companyStats['fixed_price_contract_count'] > 0)
                                <span class="inline-flex items-center px-2 py-1 bg-slate-100 text-slate-700 text-xs font-medium rounded-lg">
                                    {{ $companyStats['fixed_price_contract_count'] }} {{ $companyStats['fixed_price_contract_count'] > 1 ? 'kiinteähintaista' : 'kiinteähintainen' }}
                                </span>
                            @endif
                        </div>
                    </div>
                </div>
                <p class="mt-5 pt-4 border-t border-slate-100 text-xs text-slate-500 leading-relaxed">
                    Luvut perustuvat Voltikan riippumattomaan vertailuun: arvioidut 12 kk kulut {{ number_format($consumption, 0, ',', ' ') }} kWh vuosikulutuksella, hinnat sis. alv 25,5 % (siirtomaksu ei sisälly).
                    <a href="/tietoa#menetelma" class="text-coral-600 hover:text-coral-700 underline underline-offset-2 font-medium whitespace-nowrap">Näin laskemme &rarr;</a>
                </p>
            </div>
        </section>
    @endif

    @php
        $detailUrl = function ($contract) use ($consumption) {
            $url = route('contract.detail', ['contractId' => $contract->id]);

            return $consumption === 5000 ? $url : $url . '?kulutus=' . $consumption;
        };
    @endphp

    {{-- Promotions --}}
    <section id="tarjoukset" class="mb-10">
        <h2 class="text-2xl font-bold text-slate-900 mb-2">{{ $company->name }} tarjoukset</h2>

        @if ($promotionContracts->isEmpty())
            <p class="text-slate-600 mb-4 max-w-prose">
                {{ $company->name }} ei tarjoa juuri nyt kampanjahintaisia sopimuksia.
                Voltikka tarkistaa sopimukset päivittäin. Kun yhtiö julkaisee tarjouksen, se ilmestyy tähän automaattisesti.
                Käy siis katsomassa myöhemmin uudelleen.
            </p>
            <a href="/sahkosopimus/sahkotarjous" class="inline-flex items-center font-medium text-coral-600 hover:text-coral-700">
                Katso kaikki voimassa olevat sähkötarjoukset &rarr;
            </a>
        @else
            <p class="text-slate-600 mb-4 max-w-prose">
                <span class="font-semibold">{{ $promotionContracts->count() }}</span> sopimusta kampanjahinnalla.
                Vertailuhinta on laskettu {{ number_format($consumption, 0, ',', ' ') }} kWh kulutuksella ja se sisältää tarjouksen.
                Mitattu etu vertaa laskettua hintaa saman sopimuksen normaalihintaan.
                Tavallisen sopimuksen etu koskee 12 kuukauden vertailujaksoa. Lyhyen määräaikaisen sopimuksen rivillä näkyy todellinen etu ja sopimuskauden kesto.
            </p>

            <div class="overflow-x-auto rounded-2xl border border-slate-200 bg-white">
                <table class="w-full text-left text-sm">
                    <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                        <tr>
                            <th scope="col" class="px-4 py-3 font-semibold">Sopimus</th>
                            <th scope="col" class="px-4 py-3 font-semibold">Tarjous</th>
                            <th scope="col" class="px-4 py-3 font-semibold text-right">Mitattu etu</th>
                            <th scope="col" class="px-4 py-3 font-semibold text-right">Vertailuhinta (12 kk)</th>
                            <th scope="col" class="px-4 py-3"><span class="sr-only">Tiedot</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($promotionContracts as $contract)
                            @php
                                $offerFact = $contract->offer_fact;
                            @endphp
                            <tr>
                                <td class="px-4 py-3 font-medium text-slate-900">{{ $contract->name }}</td>
                                <td class="px-4 py-3">
                                    <span class="inline-flex items-center rounded-lg bg-coral-50 px-2 py-1 text-xs font-semibold text-coral-700">
                                        {{ $offerFact['label'] }}
                                    </span>
                                </td>
                                <td class="px-4 py-3 text-right tabular-nums whitespace-nowrap">
                                    @if (($offerFact['benefit_text'] ?? null) !== null)
                                        <span class="font-semibold text-slate-900">{{ $offerFact['benefit_text'] }}</span>
                                        <span class="block text-xs font-normal text-slate-500">{{ $offerFact['basis_label'] }}</span>
                                    @else
                                        <span class="text-slate-400" title="Kampanjan euromääräistä vaikutusta ei voi laskea luotettavasti tästä sopimuksesta.">&ndash;</span>
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-right font-semibold text-slate-900 whitespace-nowrap">
                                    @if (($contract->calculated_cost['total_cost'] ?? null) !== null)
                                        {{ number_format($contract->calculated_cost['total_cost'], 0, ',', ' ') }} {{ "\u{20AC}" }}/v
                                    @else
                                        <span class="font-normal text-slate-400">Ei laskettavissa</span>
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-right whitespace-nowrap">
                                    <a href="{{ $detailUrl($contract) }}" class="font-medium text-coral-600 hover:text-coral-700">Tiedot &rarr;</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <p class="mt-3 text-xs text-slate-500">
                Viiva etusarakkeessa tarkoittaa, ettei kampanjan euromääräistä vaikutusta voi laskea luotettavasti tämän sopimuksen tiedoista.
                Hinnat sis. alv 25,5 %, siirtomaksu ei sisälly.
                <a href="/sahkosopimus/sahkotarjous" class="font-medium text-coral-600 hover:text-coral-700">Vertaa kaikkia sähkötarjouksia &rarr;</a>
            </p>
        @endif
    </section>

    {{-- Market comparison --}}
    @if ($marketComparison)
        @include('partials.company-market-comparison', [
            'company' => $company,
            'marketComparison' => $marketComparison,
        ])
    @endif

    {{-- Spot contracts --}}
    @if ($spotContracts->isNotEmpty())
        <section id="porssisahko" class="mb-10">
            <h2 class="text-2xl font-bold text-slate-900 mb-2">{{ $company->name }} pörssisähkö</h2>
            <p class="text-slate-600 mb-4 max-w-prose">
                Pörssisähkössä maksat sähkön tuntikohtaisen markkinahinnan, yhtiön marginaalin ja kuukausittaisen perusmaksun.
                Marginaali on se osa hinnasta, jonka yhtiö itse päättää, joten vertaa sitä muihin myyjiin.
                Vuosihinta on arvio ja perustuu edeltävän 12 kuukauden toteutuneeseen pörssihintaan.
            </p>

            <div class="overflow-x-auto rounded-2xl border border-slate-200 bg-white">
                <table class="w-full text-left text-sm">
                    <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                        <tr>
                            <th scope="col" class="px-4 py-3 font-semibold">Sopimus</th>
                            <th scope="col" class="px-4 py-3 font-semibold text-right">Marginaali</th>
                            <th scope="col" class="px-4 py-3 font-semibold text-right">Perusmaksu</th>
                            <th scope="col" class="px-4 py-3 font-semibold text-right">Vuosihinta (arvio)</th>
                            <th scope="col" class="px-4 py-3"><span class="sr-only">Tiedot</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($spotContracts as $contract)
                            @php
                                $margin = $contract->calculated_cost['spot_price_margin'] ?? null;
                                $fee = $contract->calculated_cost['monthly_fixed_fee'] ?? null;
                                $total = $contract->calculated_cost['total_cost'] ?? null;
                            @endphp
                            <tr>
                                <td class="px-4 py-3 font-medium text-slate-900">{{ $contract->name }}</td>
                                <td class="px-4 py-3 text-right tabular-nums whitespace-nowrap">
                                    {{ $margin !== null ? number_format($margin, 2, ',', ' ') . ' c/kWh' : '–' }}
                                </td>
                                <td class="px-4 py-3 text-right tabular-nums whitespace-nowrap">
                                    {{ $fee !== null ? number_format($fee, 2, ',', ' ') . ' ' . "\u{20AC}" . '/kk' : '–' }}
                                </td>
                                <td class="px-4 py-3 text-right font-semibold tabular-nums text-slate-900 whitespace-nowrap">
                                    {{ $total !== null ? number_format($total, 0, ',', ' ') . ' ' . "\u{20AC}" . '/v' : '–' }}
                                </td>
                                <td class="px-4 py-3 text-right whitespace-nowrap">
                                    <a href="{{ $detailUrl($contract) }}" class="font-medium text-coral-600 hover:text-coral-700">Tiedot &rarr;</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <p class="mt-3 text-xs text-slate-500">
                Hinnat sis. alv 25,5 %, siirtomaksu ei sisälly. Vertailu {{ number_format($consumption, 0, ',', ' ') }} kWh vuosikulutuksella.
                <a href="/sahkosopimus/porssisahko" class="font-medium text-coral-600 hover:text-coral-700">Vertaa kaikkia pörssisähkösopimuksia &rarr;</a>
            </p>
        </section>
    @endif

    <!-- Contracts Section -->
    <h2 class="text-2xl font-bold text-slate-900 mb-4">
        Sähkösopimukset
    </h2>

    <p class="text-slate-600 mb-6">
        <span class="font-semibold">{{ $contracts->count() }}</span> sopimusta saatavilla
    </p>

    <div class="space-y-6">
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
                <p class="text-slate-500">Ei sähkösopimuksia saatavilla.</p>
            </div>
        @endforelse
    </div>

    {{-- FAQ. Items come from CompanyDetail::getFaqItemsProperty(), which also feeds the FAQPage schema. --}}
    @if (! empty($faqItems))
        <section id="usein-kysyttya" class="mt-12">
            <h2 class="text-2xl font-bold text-slate-900 mb-4">Usein kysyttyä</h2>

            <div class="divide-y divide-slate-200 rounded-2xl border border-slate-200 bg-white">
                @foreach ($faqItems as $item)
                    <details class="group px-5 py-4">
                        <summary class="flex cursor-pointer items-center justify-between gap-4 text-base font-semibold text-slate-900 marker:content-none">
                            {{ $item['question'] }}
                            <svg class="h-5 w-5 shrink-0 text-slate-400 transition-transform group-open:rotate-180" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                            </svg>
                        </summary>
                        <p class="mt-3 text-slate-600 leading-relaxed">{{ $item['answer'] }}</p>
                    </details>
                @endforeach
            </div>
        </section>
    @endif

    {{-- Back to Companies Link --}}
    <div class="mt-8 text-center">
        <a
            href="/sahkosopimus/sahkoyhtiot"
            class="inline-flex items-center text-coral-600 hover:text-coral-700 font-medium"
        >
            <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"></path>
            </svg>
            Takaisin sähköyhtiöihin
        </a>
    </div>
    </div>
</div>
