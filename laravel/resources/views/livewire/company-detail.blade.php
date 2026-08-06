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
                        <p class="text-lg text-slate-300 mb-2">
                            {{ $heroDescription }}
                        </p>
                        @if ($companyStats['contract_count'] > 0)
                            <p class="mb-3 text-base text-slate-300">
                                Tällä sivulla näet yhtiön sähkösopimukset, hinnat, tarjoukset ja pörssisähkön myyjäkohtaiset kulut.
                            </p>
                        @endif

                        @if ($updatedAt)
                            <p class="mb-4 text-sm font-medium text-slate-300">
                                Päivitetty {{ $updatedAt->translatedFormat('j.n.Y') }}
                            </p>
                        @endif

                        @if ($company->street_address || $company->postal_code || $company->postal_name)
                            <p class="text-slate-300 mb-2">
                                <span class="block text-sm font-semibold text-slate-300">Yhtiön ilmoittama osoite</span>
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

    {{-- Consumption selector matches the main comparison page. --}}
    <section x-data="{ panelOpen: false }" class="mb-8 bg-transparent">
        <div class="mb-3 flex items-center justify-between gap-3">
            <p class="text-sm font-bold tracking-tight text-slate-700">Vuosikulutus</p>
            <div class="flex items-center gap-3">
                <a
                    href="/sahkosopimus/laskuri"
                    class="hidden items-center gap-1.5 text-sm font-semibold text-slate-500 transition-colors hover:text-slate-700 lg:inline-flex"
                >
                    <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 7h6m-6 4h6m-6 4h4M5 3h14a2 2 0 012 2v14a2 2 0 01-2 2H5a2 2 0 01-2-2V5a2 2 0 012-2z"></path>
                    </svg>
                    En tiedä – arvioi laskurilla
                </a>
                <button
                    type="button"
                    @click="panelOpen = !panelOpen"
                    class="inline-flex items-center gap-1.5 text-sm font-semibold text-slate-600 lg:hidden"
                    :aria-expanded="panelOpen ? 'true' : 'false'"
                >
                    <span x-text="panelOpen ? 'Piilota' : 'Vaihda'"></span>
                    <svg class="h-4 w-4 transition-transform" :class="panelOpen && 'rotate-180'" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                    </svg>
                </button>
            </div>
        </div>

        <div :class="panelOpen ? 'block' : 'hidden lg:block'">
            <a
                href="/sahkosopimus/laskuri"
                class="mb-3 inline-flex w-full items-center justify-center gap-1.5 rounded-xl border border-slate-200 py-2.5 text-sm font-semibold text-slate-600 lg:hidden"
            >
                En tiedä – arvioi laskurilla
            </a>

            <div class="flex flex-col overflow-hidden rounded-xl border border-slate-200 bg-white divide-y divide-slate-200 sm:flex-row sm:divide-x sm:divide-y-0">
                @foreach ($presets as $key => $preset)
                    @php $isSelected = $selectedPreset === $key; @endphp
                    <button
                        type="button"
                        wire:click="selectPreset('{{ $key }}')"
                        aria-pressed="{{ $isSelected ? 'true' : 'false' }}"
                        class="flex flex-1 flex-col items-start px-3 py-2.5 text-left transition-colors {{ $isSelected ? 'bg-gradient-to-br from-coral-500 to-coral-600' : 'hover:bg-coral-50/60' }}"
                    >
                        <span class="text-sm font-semibold leading-tight {{ $isSelected ? 'text-white' : 'text-slate-900' }}">{{ $preset['label'] }}</span>
                        <span class="mt-0.5 text-xs leading-snug {{ $isSelected ? 'text-white/80' : 'text-slate-500' }}">{{ $preset['description'] }}</span>
                        <span class="mt-1 text-sm font-bold tabular-nums {{ $isSelected ? 'text-white' : 'text-slate-900' }}">{{ number_format($preset['consumption'], 0, ',', ' ') }} kWh/v</span>
                    </button>
                @endforeach

                @php $isDirect = $selectedPreset === null; @endphp
                <div class="flex flex-1 flex-col justify-center px-3 py-2.5 transition-colors {{ $isDirect ? 'bg-coral-50' : '' }}">
                    <label for="company-direct-consumption" class="text-xs leading-snug {{ $isDirect ? 'font-semibold text-coral-600' : 'font-medium text-slate-500' }}">Tiedän kulutukseni</label>
                    <div class="mt-1 flex items-baseline gap-1">
                        <input
                            id="company-direct-consumption"
                            type="number"
                            min="0"
                            step="100"
                            inputmode="numeric"
                            wire:model.blur="directConsumption"
                            placeholder="esim. 7000"
                            @if ($directConsumptionNotice) aria-invalid="true" aria-describedby="company-direct-consumption-notice" @endif
                            class="w-full min-w-0 bg-transparent text-sm font-bold tabular-nums text-slate-900 placeholder:font-normal placeholder:text-slate-500 focus:outline-none"
                        >
                        <span class="shrink-0 text-xs text-slate-500">kWh/v</span>
                    </div>
                    @if ($directConsumptionNotice)
                        <p id="company-direct-consumption-notice" role="alert" class="mt-1 text-xs text-red-600">{{ $directConsumptionNotice }}</p>
                    @endif
                </div>
            </div>
        </div>
    </section>

    {{-- Company Statistics Detail Section --}}
    @if ($companyStats['contract_count'] > 0)
        <section class="mb-8">
            <div class="bg-white rounded-2xl border border-slate-200 p-6">
                <h2 class="text-lg font-bold text-slate-900 mb-4">{{ $company->name }}: hinnat lyhyesti</h2>
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
                                    {{ $companyStats['spot_contract_count'] }} pörssisähkösopimus{{ $companyStats['spot_contract_count'] > 1 ? 'ta' : '' }}
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
                Vertailussa ei ole nyt kampanjahintaista sopimusta. Voltikka päivittää sopimustiedot päivittäin.
            </p>
            <a href="/sahkosopimus/sahkotarjous" class="inline-flex items-center font-medium text-coral-600 hover:text-coral-700">
                Katso kaikki voimassa olevat sähkötarjoukset &rarr;
            </a>
        @else
            <p class="text-slate-600 mb-4 max-w-prose">
                <span class="font-semibold">{{ $promotionContracts->count() }}</span> sopimusta kampanjahinnalla.
                Tarjous kertoo kampanjahinnan ja sen keston. Säästö vertaa kampanjahintaa saman sopimuksen normaalihintaan {{ number_format($consumption, 0, ',', ' ') }} kWh kulutuksella.
                Lyhyessä määräaikaisessa sopimuksessa säästö koskee todellista sopimuskautta.
            </p>

            <div class="overflow-x-auto rounded-2xl border border-slate-200 bg-white">
                <table class="w-full text-left text-sm">
                    <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                        <tr>
                            <th scope="col" class="px-4 py-3 font-semibold">Sopimus</th>
                            <th scope="col" class="px-4 py-3 font-semibold">Tarjous</th>
                            <th scope="col" class="px-4 py-3 font-semibold text-right">Säästö</th>
                            <th scope="col" class="px-4 py-3 font-semibold text-right">12 kk:n vertailuhinta</th>
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
                Viiva säästösarakkeessa tarkoittaa, ettei säästöä voi laskea luotettavasti.
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
    @else
        <section id="hintavertailu" class="mb-10">
            <h2 class="text-2xl font-bold text-slate-900 mb-2">{{ $company->name }}: sähkön hinta</h2>
            <p class="text-slate-600 max-w-prose">
                Yhtiön ja markkinan vertailukelpoista hintatietoa ei ole nyt saatavilla. Katso nykyiset sopimushinnat alta.
            </p>
        </section>
    @endif

    {{-- Spot contracts --}}
    <section id="porssisahko" class="mb-10">
        <h2 class="text-2xl font-bold text-slate-900 mb-2">{{ $company->name }}: pörssisähkö, marginaali ja perusmaksu</h2>

        @if ($spotContracts->isNotEmpty())
            @php
                $spotCount = $spotContracts->count();
                $spotCountText = $spotCount === 1
                    ? '1 pörssisähkösopimus'
                    : $spotCount . ' pörssisähkösopimusta';
                $spotMarginMedian = $spotBenchmarks['spot_margin']['median'] ?? null;
                $spotMonthlyFeeMedian = $spotBenchmarks['monthly_fee']['median'] ?? null;
            @endphp
            <p class="text-slate-600 mb-4 max-w-prose">
                {{ $company->name }} myy pörssisähköä. Vertailussa on {{ $spotCountText }}.
                Myyjän itse määrittämät kulut ovat marginaali ja kuukausittainen perusmaksu.
                Nord Poolin markkinahinta on kaikille pörssisähkötuotteille yhteinen, joten se ei kerro myyjän kilpailukyvystä.
                Nykyinen vuosihinta arvioidaan ensisijaisesti seuraavan 12 kuukauden tukkumarkkinan ennakkohinnoista ja historiallisesta päivä–yö-erosta. Jos ennakkohintoja ei voi käyttää, laskelma näyttää erikseen merkityn edeltävän 12 kuukauden toteutuneeseen pörssihintaan perustuvan arvion.
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
                                $marginDelta = $margin !== null && $spotMarginMedian !== null
                                    ? (float) $margin - (float) $spotMarginMedian
                                    : null;
                                $feeDelta = $fee !== null && $spotMonthlyFeeMedian !== null
                                    ? (float) $fee - (float) $spotMonthlyFeeMedian
                                    : null;
                            @endphp
                            <tr>
                                <td class="px-4 py-3 font-medium text-slate-900">{{ $contract->name }}</td>
                                <td class="px-4 py-3 text-right tabular-nums whitespace-nowrap">
                                    {{ $margin !== null ? number_format($margin, 2, ',', ' ') . ' c/kWh' : '–' }}
                                    @if ($marginDelta !== null)
                                        <span class="block text-xs font-normal text-slate-500">
                                            @if (abs($marginDelta) < 0.005)
                                                Sama kuin markkinan mediaani
                                            @else
                                                {{ number_format(abs($marginDelta), 2, ',', ' ') }} c/kWh {{ $marginDelta < 0 ? 'alle' : 'yli' }} markkinan mediaanin
                                            @endif
                                        </span>
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-right tabular-nums whitespace-nowrap">
                                    {{ $fee !== null ? number_format($fee, 2, ',', ' ') . ' ' . "\u{20AC}" . '/kk' : '–' }}
                                    @if ($feeDelta !== null)
                                        <span class="block text-xs font-normal text-slate-500">
                                            @if (abs($feeDelta) < 0.005)
                                                Sama kuin markkinan mediaani
                                            @else
                                                {{ number_format(abs($feeDelta), 2, ',', ' ') }} {{ "\u{20AC}" }}/kk {{ $feeDelta < 0 ? 'alle' : 'yli' }} markkinan mediaanin
                                            @endif
                                        </span>
                                    @endif
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
                Hinnat sis. alv 25,5 %, siirtomaksu ei sisälly. Vuosihinta on laskettu {{ number_format($consumption, 0, ',', ' ') }} kWh vuosikulutuksella.
                Markkinavertailu koskee vain myyjän marginaalia ja perusmaksua; Nord Poolin hinta ei ole myyjäkohtainen.
                @if ($spotBenchmarks !== null)
                    Mediaanit perustuvat {{ \Illuminate\Support\Carbon::parse($spotBenchmarks['stat_date'])->translatedFormat('j.n.Y') }}
                    {{ $spotBenchmarks['pricing_basis'] === 'canonical_calculation' ? 'Voltikan laskemiin nykyhintoihin' : 'myyjiltä havaittuihin hintoihin' }}.
                @endif
                <a href="/sahkosopimus/porssisahko" class="font-medium text-coral-600 hover:text-coral-700">Vertaa kaikkia pörssisähkösopimuksia &rarr;</a>
            </p>
        @else
            <p class="text-slate-600 mb-4 max-w-prose">
                {{ $company->name }} ei tarjoa tällä hetkellä kotitalouksille pörssisähkösopimusta Voltikan vertailussa.
            </p>
            <a href="/sahkosopimus/porssisahko" class="inline-flex items-center font-medium text-coral-600 hover:text-coral-700">
                Vertaa kaikkia pörssisähkösopimuksia &rarr;
            </a>
        @endif
    </section>

    <!-- Contracts Section -->
    <h2 class="text-2xl font-bold text-slate-900 mb-4">
        {{ $company->name }} sähkösopimukset
    </h2>

    <p class="text-slate-600 mb-6">
        @if ($contracts->count() === 1)
            <span class="font-semibold">1</span> kotitalouksille sopiva sopimus saatavilla
        @else
            <span class="font-semibold">{{ $contracts->count() }}</span> kotitalouksille sopivaa sopimusta saatavilla
        @endif
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

    @if ($businessContracts->isNotEmpty())
        <section id="yrityksille" class="mt-12">
            <h2 class="text-2xl font-bold text-slate-900 mb-2">{{ $company->name }} sähkösopimukset yrityksille</h2>
            <p class="text-slate-600 mb-6">
                @if ($businessContracts->count() === 1)
                    <span class="font-semibold">1</span> yrityksille sopiva sopimus saatavilla
                @else
                    <span class="font-semibold">{{ $businessContracts->count() }}</span> yrityksille sopivaa sopimusta saatavilla
                @endif
            </p>

            <div class="space-y-6">
                @foreach ($businessContracts as $index => $contract)
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
