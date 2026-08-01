<div>
    {{-- JSON-LD Structured Data --}}
    @if(!empty($seoData['jsonLd']))
    <script type="application/ld+json">
        {!! json_encode($seoData['jsonLd'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) !!}
    </script>
    @endif

    {{-- SEO Hero Section - Dark slate background with gradient --}}
    <section class="bg-gradient-to-br from-slate-900 via-slate-900 to-slate-950 -mx-4 sm:-mx-6 lg:-mx-8 mb-6 relative overflow-hidden">
        {{-- Decorative gradient blobs --}}
        <div class="absolute inset-0 pointer-events-none">
            <div class="absolute top-0 right-1/4 w-96 h-96 bg-coral-500 rounded-full blur-3xl opacity-20"></div>
            <div class="absolute bottom-0 left-0 w-72 h-72 bg-coral-400 rounded-full blur-3xl opacity-10 -translate-x-1/2"></div>
        </div>

        <div class="max-w-7xl mx-auto px-6 sm:px-8 lg:px-10 relative">
            <div class="grid max-w-screen-xl py-8 mx-auto lg:gap-8 xl:gap-0 lg:py-12 lg:grid-cols-12">
                <div class="mx-auto place-self-center col-12 lg:col-span-8">
                    @if ($isBusinessPage)
                        <div class="inline-flex items-center gap-2 bg-coral-500/20 backdrop-blur-sm px-3 py-1.5 rounded-full text-xs font-medium text-coral-300 mb-3 border border-coral-500/20">
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/>
                            </svg>
                            Yrityksille
                        </div>
                    @endif
                    <h1 class="max-w-2xl mb-3 text-3xl font-extrabold text-white tracking-tight leading-tight md:text-4xl xl:text-5xl">
                        {{ $pageHeading }}
                    </h1>
                    <p class="max-w-2xl mb-4 text-slate-300 md:text-base lg:text-lg">
                        {{ $seoIntroText }}
                    </p>
                    <x-contract-market-insight-pills :insight="$marketInsight ?? null" class="mb-1" />
                </div>
                <div class="hidden lg:flex col-12 lg:col-span-4 mx-auto">
                    {{-- Decorative element placeholder --}}
                </div>
            </div>
        </div>
    </section>

    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6">

    {{-- Breadcrumb Navigation --}}
    @if($hasSeoFilter)
    <nav class="mb-6" aria-label="Breadcrumb">
        <ol class="flex flex-wrap items-center gap-x-2 gap-y-1 text-sm text-slate-500 min-w-0">
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
    @endif

    {{-- Solar Potential Snippet (for city pages) --}}
    @if($isCityPage && $municipality)
        <livewire:city-solar-estimate
            :municipality-id="$municipality->id"
            :city-locative="$cityInfo['locative'] ?? $citySlug"
            :city-slug="$citySlug"
            lazy
        />
    @endif

    {{-- Energy Source Statistics Section --}}
    @if($isEnergySourcePage && !empty($energySourceStats))
    <section class="bg-white rounded-2xl shadow-sm border border-slate-100 p-6 mb-8">
        <h2 class="text-xl font-bold text-slate-900 mb-4">Energialähteiden tilastot</h2>
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
            <div class="text-center p-4 bg-green-50 rounded-lg">
                <div class="text-2xl font-bold text-green-600">{{ $energySourceStats['avg_renewable'] ?? 0 }}%</div>
                <div class="text-sm text-slate-600">Uusiutuva keskiarvo</div>
            </div>
            @if(($energySourceStats['avg_wind'] ?? 0) > 0)
            <div class="text-center p-4 bg-blue-50 rounded-lg">
                <div class="text-2xl font-bold text-blue-600">{{ $energySourceStats['avg_wind'] }}%</div>
                <div class="text-sm text-slate-600">Tuulivoima keskiarvo</div>
            </div>
            @endif
            @if(($energySourceStats['avg_solar'] ?? 0) > 0)
            <div class="text-center p-4 bg-yellow-50 rounded-lg">
                <div class="text-2xl font-bold text-yellow-600">{{ $energySourceStats['avg_solar'] }}%</div>
                <div class="text-sm text-slate-600">Aurinkovoima keskiarvo</div>
            </div>
            @endif
            @if(($energySourceStats['avg_hydro'] ?? 0) > 0)
            <div class="text-center p-4 bg-coral-50 rounded-lg">
                <div class="text-2xl font-bold text-coral-600">{{ $energySourceStats['avg_hydro'] }}%</div>
                <div class="text-sm text-slate-600">Vesivoima keskiarvo</div>
            </div>
            @endif
            <div class="text-center p-4 bg-slate-50 rounded-lg">
                <div class="text-2xl font-bold text-slate-700">{{ $energySourceStats['total_contracts'] ?? 0 }}</div>
                <div class="text-sm text-slate-600">Sopimusta yhteensä</div>
            </div>
            <div class="text-center p-4 bg-green-50 rounded-lg">
                <div class="text-2xl font-bold text-green-600">{{ $energySourceStats['fossil_free_count'] ?? 0 }}</div>
                <div class="text-sm text-slate-600">Fossiilivapaa</div>
            </div>
        </div>
    </section>
    @endif

    {{-- Environmental Impact Section --}}
    @if($isEnergySourcePage && $environmentalInfo)
    <section class="bg-gradient-to-r from-green-50 to-emerald-50 rounded-lg border border-green-200 p-6 mb-8">
        <div class="flex items-start">
            <div class="flex-shrink-0">
                <svg class="w-8 h-8 text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3.055 11H5a2 2 0 012 2v1a2 2 0 002 2 2 2 0 012 2v2.945M8 3.935V5.5A2.5 2.5 0 0010.5 8h.5a2 2 0 012 2 2 2 0 104 0 2 2 0 012-2h1.064M15 20.488V18a2 2 0 012-2h3.064M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                </svg>
            </div>
            <div class="ml-4">
                <h3 class="text-lg font-semibold text-slate-900 mb-2">Ympäristövaikutus</h3>
                <p class="text-slate-700">{{ $environmentalInfo }}</p>
            </div>
        </div>
    </section>
    @endif

    @include('partials.contract-consumption-selector', [
        'isBusinessPage' => $isBusinessPage,
        'showCalculatorTab' => $showCalculatorTab ?? true,
    ])

    {{-- Bill comparison ("Maksatko liikaa") entry — proven on /sahkosopimus first. --}}
    @if ($showBillComparison)
        {{-- Bill comparison is a collapsed disclosure so it does not push the
             contract list down for everyone; opens automatically once a bill is
             entered (bill mode active). --}}
        <section class="mb-3" x-data="{ billOpen: @js($this->isBillModeActive()) }">
            <div class="rounded-xl border overflow-hidden transition-colors" :class="billOpen ? 'border-coral-300' : 'border-slate-200'">
                <button
                    type="button"
                    @click="billOpen = !billOpen"
                    :aria-expanded="billOpen ? 'true' : 'false'"
                    class="w-full flex items-center gap-3 px-4 py-3 text-left transition-colors"
                    :class="billOpen ? 'bg-coral-50/60' : 'hover:bg-slate-50'"
                >
                    <span class="flex-shrink-0 flex items-center justify-center w-9 h-9 rounded-lg bg-coral-50">
                        <svg class="w-5 h-5 text-coral-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                        </svg>
                    </span>
                    <span class="min-w-0 flex-1">
                        <span class="block text-base font-bold text-slate-900">Maksatko nykyisestä sopimuksestasi liikaa?</span>
                        <span class="block text-sm text-slate-600 mt-0.5">Syötä yhden laskusi tiedot, niin näet säästösi.</span>
                    </span>
                    <svg class="w-5 h-5 flex-shrink-0 text-slate-400 transition-transform" :class="billOpen && 'rotate-180'" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                    </svg>
                </button>

                <div x-show="billOpen" x-collapse x-cloak class="border-t border-slate-100 p-4 sm:p-6">
                {{-- Shared bill entry fields; the contract detail module includes
                     the same partial so the two surfaces cannot drift. --}}
                @include('partials.bill-comparison-form', ['idPrefix' => 'bill'])

                @if ($this->isBillModeActive())
                    <div class="mt-4">
                        <button type="button" wire:click="clearBill" class="text-sm font-medium text-slate-500 hover:text-slate-700 underline underline-offset-2">
                            Tyhjennä ja palaa normaaliin vertailuun
                        </button>
                    </div>
                @endif
                </div>
            </div>
        </section>
    @endif

    {{-- Pricing-type pills: always visible, above the list and outside the accordion. --}}
    @include('partials.pricing-bucket-pills')

    {{-- Filter Section (shared partial) --}}
    @include('partials.contract-filters')

    {{-- Local Contracts Section (for city pages) --}}
    @if($isCityPage && $citySlug && $localContractsData['has_content'])
        <livewire:local-contracts-section
            :city-name="$cityInfo['name']"
            :city-locative="$cityInfo['locative']"
            :consumption="$consumption"
            :local-company-contracts="$localContractsData['local_companies']"
            :regional-contracts="$localContractsData['regional_contracts']"
            wire:key="local-contracts-{{ $citySlug }}-{{ $consumption }}"
        />
    @endif

    @if ($offerType === 'promotion')
        {{-- Independence note: the offers page is where affiliate suspicion is highest --}}
        <div class="flex items-start gap-2.5 mb-4 text-sm text-slate-600">
            <svg class="w-5 h-5 text-coral-600 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m7.5 2a9.5 9.5 0 11-19 0 9.5 9.5 0 0119 0z"/>
            </svg>
            <p>
                Voltikka ei saa palkkiota sähköyhtiöiltä tarjousten näyttämisestä eikä rahoita toimintaansa mainoksilla. Tarjoukset järjestetään arvioidun 12 kk kokonaiskustannuksen mukaan, kaikille yhtiöille samalla logiikalla.
                <a href="/tietoa" class="text-coral-600 hover:text-coral-700 underline underline-offset-2 font-medium whitespace-nowrap">Tietoa Voltikasta</a>
            </p>
        </div>
    @endif

    @if ($this->isBillModeActive() && $this->billSummary)
        {{-- Current-contract anchor: the dark "focused moment" while the user --}}
        {{-- decides. Coral reserved for the one savings number. --}}
        @php $bs = $this->billSummary; @endphp
        <div class="bg-slate-950 rounded-2xl p-6 mb-6 text-white">
            <p class="text-sm font-semibold uppercase tracking-wide text-coral-400 mb-3">Sinun sopimuksesi</p>
            <div class="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-5">
                <div>
                    <p class="text-slate-300 text-sm mb-1">Maksoit laskutusjaksollasi noin</p>
                    <p class="text-3xl font-extrabold tabular-nums">{{ number_format($bs['user_monthly_cost'], 1, ',', ' ') }}<span class="text-lg font-medium text-slate-300 ml-1">€/kk</span></p>
                    <p class="text-sm text-slate-300 mt-1">Sijalla {{ $bs['user_rank'] }} / {{ $bs['total_ranked'] }} vertailluista sopimuksista</p>
                </div>
                @if ($bs['cheapest_monthly_saving'] > 0.5)
                    <div class="sm:text-right">
                        <p class="text-slate-300 text-sm mb-1">Halvimmalla sopimuksella säästäisit</p>
                        <p class="text-3xl font-extrabold text-coral-400 tabular-nums">{{ number_format($bs['cheapest_monthly_saving'], 0, ',', ' ') }}<span class="text-lg font-medium text-coral-300 ml-1">€/kk</span></p>
                    </div>
                @else
                    <div class="sm:text-right">
                        <p class="text-base font-semibold text-white">Sopimuksesi on jo kilpailukykyinen.</p>
                        <p class="text-sm text-slate-300 mt-1">Halvempaa ei juuri löydy tällä jaksolla.</p>
                    </div>
                @endif
            </div>
            <p class="text-xs text-slate-300 mt-4">
                Vertailu perustuu laskutusjaksosi ({{ $billStartDate }} – {{ $billEndDate }}) toteutuneisiin hintoihin samalla kulutuksella. Pörssisopimuksilla käytetään jakson todellisia tuntihintoja. Hinnat sis. alv 25,5 %, siirtomaksu ei sisälly.
            </p>
        </div>
    @else
        {{-- Results caption: plain text + a hairline divider so it reads as the
             list header, not another stacked card. Lets the contracts lead. --}}
        <div class="mb-5 flex flex-col gap-2 border-b border-slate-200 pb-4 lg:flex-row lg:items-baseline lg:justify-between">
            <p class="text-sm text-slate-600">
                <span class="font-bold text-slate-900">{{ $contracts->total() }} sopimusta</span> vertailussa. Lasketut 12 kk kulut sisältäen tarjoukset, hinnat sis. alv 25,5 % (siirtomaksu ei sisälly).
                <a href="/tietoa#menetelma" class="text-coral-600 hover:text-coral-700 underline underline-offset-2 font-medium whitespace-nowrap">Näin laskemme &rarr;</a>
            </p>
            {{-- Type-band legend; replaced the emissions legend when the card's emissions
                 left stripe was removed. See resources/views/components/card/legend.blade.php. --}}
            <div class="shrink-0">
                <x-card.legend />
            </div>
        </div>
    @endif

    {{-- Non-blocking recompute feedback (filters, consumption, bill entry). --}}
    <div wire:loading.delay class="fixed bottom-4 right-4 z-40 inline-flex items-center gap-2 rounded-full bg-slate-900 text-white text-sm font-medium px-4 py-2 shadow-lg">
        <x-spinner size="h-4 w-4" color="text-coral-400" label="Päivitetään vertailua" />
        <span>Päivitetään…</span>
    </div>

    {{-- Contracts List --}}
    <div class="space-y-6 transition-opacity duration-200 motion-reduce:transition-none" wire:loading.delay.class="opacity-50">
        @forelse ($contracts as $index => $contract)
            @php
                // Calculate the overall rank based on current page
                $overallRank = (($contracts->currentPage() - 1) * $contracts->perPage()) + $index + 1;
            @endphp

            @if ($this->isBillModeActive())
                <x-contract-card
                    :contract="$contract"
                    :rank="$overallRank"
                    :featured="false"
                    :consumption="$consumption"
                    :prices="$this->getLatestPrices($contract)"
                    :percentiles="$this->getPercentiles()"
                    :billMode="true"
                    :periodComparison="$contract->period_comparison ?? null"
                    :detailConsumption="$this->billSummary['annual_kwh'] ?? null"
                    :showRank="true"
                    :showEmissions="true"
                    :showEnergyBadges="true"
                    :showSpotBadge="true"
                />
            @elseif ($overallRank === 1)
                <x-featured-contract-card
                    :contract="$contract"
                    :consumption="$consumption"
                    :prices="$this->getLatestPrices($contract)"
                />
            @else
                <x-contract-card
                    :contract="$contract"
                    :rank="$overallRank"
                    :featured="$overallRank <= 3"
                    :consumption="$consumption"
                    :prices="$this->getLatestPrices($contract)"
                    :percentiles="$this->getPercentiles()"
                    :showRank="true"
                    :showEmissions="true"
                    :showEnergyBadges="true"
                    :showSpotBadge="true"
                />
            @endif
        @empty
            <div class="bg-white rounded-2xl shadow-sm border border-slate-100 p-12 text-center">
                <p class="text-slate-500">Ei sopimuksia saatavilla.</p>
            </div>
        @endforelse
    </div>

    {{-- Pagination Links --}}
    @if ($contracts->lastPage() > 1)
        <div class="mt-8">
            {{ $contracts->links('livewire.partials.pagination') }}
        </div>
    @endif

    {{-- Internal Links Section (for SEO) --}}
    @if($hasSeoFilter)
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
                        <a href="/sahkosopimus/kiintea-hinta" class="hover:text-coral-600">Kiinteähintaiset sähkösopimukset</a>
                    </li>
                    <li>
                        <a href="/sahkosopimus/kvartaalisahko" class="hover:text-coral-600">Kvartaalisähkösopimukset</a>
                    </li>
                    <li>
                        <a href="/sahkosopimus/aikasahko" class="hover:text-coral-600">Aikasähkösopimukset</a>
                    </li>
                    <li>
                        <a href="/sahkosopimus/kausisahko" class="hover:text-coral-600">Kausisähkösopimukset</a>
                    </li>
                    <li>
                        <a href="/sahkosopimus/joustosahko" class="hover:text-coral-600">Joustosähkösopimukset</a>
                    </li>
                    <li>
                        <a href="/sahkosopimus/yleissahko" class="hover:text-coral-600">Yleissähkösopimukset</a>
                    </li>
                    <li>
                        <a href="/sahkosopimus/kulutusvaikutus" class="hover:text-coral-600">Kulutusvaikutukselliset sähkösopimukset</a>
                    </li>
                </ul>
            </div>

            {{-- Housing Types & Contract Duration --}}
            <div>
                <h3 class="font-semibold text-slate-900 mb-3">Asumismuoto & sopimustyyppi</h3>
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
                    <li>
                        <a href="/sahkosopimus/maaraaikainen" class="hover:text-coral-600">Määräaikaiset sopimukset</a>
                    </li>
                    <li>
                        <a href="/sahkosopimus/toistaiseksi" class="hover:text-coral-600">Toistaiseksi voimassa olevat</a>
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
                    <li>
                        <a href="/sahkosopimus/fossiiliton" class="hover:text-coral-600">Fossiiliton sähkö</a>
                    </li>
                    <li>
                        <a href="/sahkosopimus/uusiutuva-sahko" class="hover:text-coral-600">Uusiutuva sähkö</a>
                    </li>
                    <li>
                        <a href="/sahkosopimus/ydinvoima" class="hover:text-coral-600">Ydinvoimasähkö</a>
                    </li>
                </ul>
            </div>

            {{-- Consumption Levels & Related Links --}}
            <div>
                <h3 class="font-semibold text-slate-900 mb-3">Kulutustason mukaan</h3>
                <ul class="space-y-2 text-slate-600">
                    <li>
                        <a href="/sahkosopimus/kulutus/2000-kwh" class="hover:text-coral-600">2 000 kWh sopimukset</a>
                    </li>
                    <li>
                        <a href="/sahkosopimus/kulutus/5000-kwh" class="hover:text-coral-600">5 000 kWh sopimukset</a>
                    </li>
                    <li>
                        <a href="/sahkosopimus/kulutus/10000-kwh" class="hover:text-coral-600">10 000 kWh sopimukset</a>
                    </li>
                    <li>
                        <a href="/sahkosopimus/kulutus/18000-kwh" class="hover:text-coral-600">18 000 kWh sopimukset</a>
                    </li>
                    <li>
                        <a href="/sahkosopimus/kulutus/20000-kwh" class="hover:text-coral-600">20 000 kWh sopimukset</a>
                    </li>
                </ul>
                <h3 class="font-semibold text-slate-900 mb-3 mt-6">Muut palvelut</h3>
                <ul class="space-y-2 text-slate-600">
                    <li>
                        <a href="/sahkosopimus" class="hover:text-coral-600">Vertaile sopimuksia</a>
                    </li>
                    <li>
                        <a href="/sahkosopimus/halvin-sahkosopimus" class="hover:text-coral-600">Halvimmat sopimukset</a>
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
    @endif
    </div>
</div>
