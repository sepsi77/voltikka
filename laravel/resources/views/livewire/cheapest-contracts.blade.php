<div>
    {{-- JSON-LD Structured Data --}}
    @if(!empty($seoData['jsonLd']))
    <script type="application/ld+json">
        {!! json_encode($seoData['jsonLd'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) !!}
    </script>
    @endif

    {{-- SEO Hero Section - Dark slate background with gradient --}}
    <section class="bg-gradient-to-br from-slate-900 via-slate-900 to-slate-950 -mx-4 sm:-mx-6 lg:-mx-8 relative overflow-hidden">
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
                    <p class="max-w-2xl mb-5 text-slate-300 md:text-lg lg:text-xl">
                        {{ $seoIntroText }}
                    </p>
                    <x-contract-market-insight-pills :insight="$marketInsight ?? null" class="mb-1" />
                </div>
                <div class="lg:mt-0 col-12 lg:col-span-5 lg:flex mx-auto mt-8 lg:mt-0">
                    {{-- Decorative element placeholder --}}
                </div>
            </div>
        </div>
    </section>

    <x-page-action-strip />

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

    @include('partials.contract-consumption-selector', [
        'isBusinessPage' => false,
        'showCalculatorTab' => true,
    ])

    {{-- Pricing-type pills. This page has its own template (it is not the shared
         seo-contracts-list view) and carries no filter accordion, so the pill row is
         included on its own; it narrows the cheapest ranking to one pricing type. --}}
    @include('partials.pricing-bucket-pills')

    @include('partials.contract-postcode-selector')

    {{-- Featured Contract (#1 Cheapest) --}}
    @if ($featuredContract)
        <section class="mt-6 mb-8">
            <x-featured-contract-card
                :contract="$featuredContract"
                :consumption="$consumption"
                :prices="$this->getLatestPrices($featuredContract)"
            />
        </section>
    @endif

    {{-- Remaining Contracts (#2-11) --}}
    <section class="mt-6">
        <h2 class="text-2xl font-bold text-slate-900 mb-4">Seuraavaksi edullisimmat</h2>
        <div class="space-y-6">
            @forelse ($contracts as $index => $contract)
                <x-contract-card
                    :contract="$contract"
                    :rank="$index + 2"
                    :consumption="$consumption"
                    :prices="$this->getLatestPrices($contract)"
                    :percentiles="$this->getPercentiles()"
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
                        <a href="/sahkosopimus/yleissahko" class="hover:text-coral-600">Yleissähkösopimukset</a>
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
