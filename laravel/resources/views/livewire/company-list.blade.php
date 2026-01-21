<div>
    <!-- JSON-LD Structured Data -->
    <script type="application/ld+json">
        {!! json_encode($jsonLd, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) !!}
    </script>

    <!-- Hero Section -->
    <section class="bg-slate-950 -mx-4 sm:-mx-6 lg:-mx-8 mb-8">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="py-12 lg:py-16">
                <!-- Back Link -->
                <a href="{{ route('sahkosopimus.index') }}" class="inline-flex items-center text-slate-300 hover:text-white font-medium mb-6">
                    <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"></path>
                    </svg>
                    Takaisin sopimuksiin
                </a>

                <h1 class="text-3xl md:text-4xl lg:text-5xl font-bold text-white mb-4">
                    {{ $pageTitle }}
                </h1>

                <p class="text-lg text-slate-300 max-w-2xl">
                    Vertaile {{ $companyCount }} sähköyhtiön sopimuksia, hintoja ja energialähteitä.
                </p>
            </div>
        </div>
    </section>

    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">

        <!-- Ranking Categories Section -->
        <section class="mb-12">
            <h2 class="text-2xl font-bold text-slate-900 mb-6">Parhaat sähköyhtiöt</h2>

            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                <!-- Cheapest Companies -->
                <div class="bg-white rounded-2xl shadow-sm border border-slate-100 p-6">
                    <div class="flex items-center mb-4">
                        <div class="w-10 h-10 bg-green-100 rounded-lg flex items-center justify-center mr-3">
                            <svg class="w-6 h-6 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                            </svg>
                        </div>
                        <h3 class="text-lg font-bold text-slate-900">Halvimmat</h3>
                    </div>
                    <ul class="space-y-3">
                        @foreach ($cheapestCompanies->take(5) as $index => $data)
                            <li class="flex items-center justify-between">
                                <a href="/sahkosopimus/sahkoyhtiot/{{ $data['company']->name_slug }}" class="text-slate-700 hover:text-coral-500 font-medium">
                                    <span class="text-slate-400 mr-2">{{ $index + 1 }}.</span>
                                    {{ $data['company']->name }}
                                </a>
                                <span class="text-sm text-slate-500">{{ number_format($data['lowestPrice'], 0, ',', ' ') }} EUR/v</span>
                            </li>
                        @endforeach
                    </ul>
                </div>

                <!-- Greenest Companies -->
                <div class="bg-white rounded-2xl shadow-sm border border-slate-100 p-6">
                    <div class="flex items-center mb-4">
                        <div class="w-10 h-10 bg-emerald-100 rounded-lg flex items-center justify-center mr-3">
                            <svg class="w-6 h-6 text-emerald-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 3v4M3 5h4M6 17v4m-2-2h4m5-16l2.286 6.857L21 12l-5.714 2.143L13 21l-2.286-6.857L5 12l5.714-2.143L13 3z"></path>
                            </svg>
                        </div>
                        <h3 class="text-lg font-bold text-slate-900">Vihreimmät</h3>
                    </div>
                    <ul class="space-y-3">
                        @foreach ($greenestCompanies->take(5) as $index => $data)
                            <li class="flex items-center justify-between">
                                <a href="/sahkosopimus/sahkoyhtiot/{{ $data['company']->name_slug }}" class="text-slate-700 hover:text-coral-500 font-medium">
                                    <span class="text-slate-400 mr-2">{{ $index + 1 }}.</span>
                                    {{ $data['company']->name }}
                                </a>
                                <span class="text-sm text-green-600">{{ number_format($data['avgRenewable'], 0) }}% uusiutuva</span>
                            </li>
                        @endforeach
                    </ul>
                </div>

                <!-- Cleanest Emissions -->
                <div class="bg-white rounded-2xl shadow-sm border border-slate-100 p-6">
                    <div class="flex items-center mb-4">
                        <div class="w-10 h-10 bg-sky-100 rounded-lg flex items-center justify-center mr-3">
                            <svg class="w-6 h-6 text-sky-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 15a4 4 0 004 4h9a5 5 0 10-.1-9.999 5.002 5.002 0 10-9.78 2.096A4.001 4.001 0 003 15z"></path>
                            </svg>
                        </div>
                        <h3 class="text-lg font-bold text-slate-900">Puhtaimmat päästöt</h3>
                    </div>
                    <ul class="space-y-3">
                        @foreach ($cleanestEmissionsCompanies->take(5) as $index => $data)
                            <li class="flex items-center justify-between">
                                <a href="/sahkosopimus/sahkoyhtiot/{{ $data['company']->name_slug }}" class="text-slate-700 hover:text-coral-500 font-medium">
                                    <span class="text-slate-400 mr-2">{{ $index + 1 }}.</span>
                                    {{ $data['company']->name }}
                                </a>
                                <span class="text-sm text-slate-500">{{ number_format($data['avgEmissions'], 0) }} gCO2/kWh</span>
                            </li>
                        @endforeach
                    </ul>
                </div>

                <!-- Most Contracts -->
                <div class="bg-white rounded-2xl shadow-sm border border-slate-100 p-6">
                    <div class="flex items-center mb-4">
                        <div class="w-10 h-10 bg-purple-100 rounded-lg flex items-center justify-center mr-3">
                            <svg class="w-6 h-6 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                            </svg>
                        </div>
                        <h3 class="text-lg font-bold text-slate-900">Eniten sopimuksia</h3>
                    </div>
                    <ul class="space-y-3">
                        @foreach ($mostContractsCompanies->take(5) as $index => $data)
                            <li class="flex items-center justify-between">
                                <a href="/sahkosopimus/sahkoyhtiot/{{ $data['company']->name_slug }}" class="text-slate-700 hover:text-coral-500 font-medium">
                                    <span class="text-slate-400 mr-2">{{ $index + 1 }}.</span>
                                    {{ $data['company']->name }}
                                </a>
                                <span class="text-sm text-slate-500">{{ $data['contractCount'] }} sopimusta</span>
                            </li>
                        @endforeach
                    </ul>
                </div>

                <!-- Best Spot Margins -->
                @if ($bestSpotMarginsCompanies->isNotEmpty())
                <div class="bg-white rounded-2xl shadow-sm border border-slate-100 p-6">
                    <div class="flex items-center mb-4">
                        <div class="w-10 h-10 bg-amber-100 rounded-lg flex items-center justify-center mr-3">
                            <svg class="w-6 h-6 text-amber-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"></path>
                            </svg>
                        </div>
                        <h3 class="text-lg font-bold text-slate-900">Parhaat pörssimarginaalit</h3>
                    </div>
                    <ul class="space-y-3">
                        @foreach ($bestSpotMarginsCompanies->take(5) as $index => $data)
                            <li class="flex items-center justify-between">
                                <a href="/sahkosopimus/sahkoyhtiot/{{ $data['company']->name_slug }}" class="text-slate-700 hover:text-coral-500 font-medium">
                                    <span class="text-slate-400 mr-2">{{ $index + 1 }}.</span>
                                    {{ $data['company']->name }}
                                </a>
                                <span class="text-sm text-slate-500">{{ number_format($data['lowestSpotMargin'], 2, ',', ' ') }} c/kWh</span>
                            </li>
                        @endforeach
                    </ul>
                </div>
                @endif

                <!-- Lowest Monthly Fees -->
                @if ($lowestMonthlyFeesCompanies->isNotEmpty())
                <div class="bg-white rounded-2xl shadow-sm border border-slate-100 p-6">
                    <div class="flex items-center mb-4">
                        <div class="w-10 h-10 bg-rose-100 rounded-lg flex items-center justify-center mr-3">
                            <svg class="w-6 h-6 text-rose-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z"></path>
                            </svg>
                        </div>
                        <h3 class="text-lg font-bold text-slate-900">Pienimmät perusmaksut</h3>
                    </div>
                    <ul class="space-y-3">
                        @foreach ($lowestMonthlyFeesCompanies->take(5) as $index => $data)
                            <li class="flex items-center justify-between">
                                <a href="/sahkosopimus/sahkoyhtiot/{{ $data['company']->name_slug }}" class="text-slate-700 hover:text-coral-500 font-medium">
                                    <span class="text-slate-400 mr-2">{{ $index + 1 }}.</span>
                                    {{ $data['company']->name }}
                                </a>
                                <span class="text-sm text-slate-500">{{ number_format($data['lowestMonthlyFee'], 2, ',', ' ') }} EUR/kk</span>
                            </li>
                        @endforeach
                    </ul>
                </div>
                @endif
            </div>
        </section>

        <!-- 100% Renewable Section -->
        @if ($fullyRenewableCompanies->isNotEmpty())
        <section class="mb-12">
            <h2 class="text-2xl font-bold text-slate-900 mb-4">100% uusiutuvaa energiaa tarjoavat yhtiöt</h2>
            <p class="text-slate-600 mb-6">Nämä yhtiöt tarjoavat vähintään yhden täysin uusiutuvalla energialla tuotetun sähkösopimuksen.</p>

            <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5 gap-4">
                @foreach ($fullyRenewableCompanies->take(10) as $data)
                    <a
                        href="/sahkosopimus/sahkoyhtiot/{{ $data['company']->name_slug }}"
                        class="bg-gradient-to-br from-green-50 to-emerald-50 border border-green-200 rounded-xl p-4 text-center hover:shadow-md transition-shadow"
                    >
                        @if ($data['company']->getLogoUrl())
                            <img
                                src="{{ $data['company']->getLogoUrl() }}"
                                alt="{{ $data['company']->name }}"
                                class="w-16 h-12 mx-auto object-contain mb-2"
                                onerror="this.onerror=null; this.src='https://placehold.co/64x48?text=logo'"
                            >
                        @else
                            <div class="w-16 h-12 mx-auto bg-green-100 rounded flex items-center justify-center mb-2">
                                <span class="text-green-600 font-bold text-sm">{{ substr($data['company']->name, 0, 3) }}</span>
                            </div>
                        @endif
                        <p class="text-sm font-medium text-slate-700 truncate">{{ $data['company']->name }}</p>
                        <p class="text-xs text-green-600">100% uusiutuva</p>
                    </a>
                @endforeach
            </div>
        </section>
        @endif

        <!-- All Companies Section -->
        <section>
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between mb-6">
                <h2 class="text-2xl font-bold text-slate-900 mb-4 sm:mb-0">Kaikki sähköyhtiöt</h2>

                <!-- Search -->
                <div class="relative">
                    <input
                        type="text"
                        wire:model.live.debounce.300ms="search"
                        placeholder="Hae yhtiötä..."
                        class="w-full sm:w-64 pl-10 pr-4 py-2 border border-slate-200 rounded-lg focus:ring-2 focus:ring-coral-500 focus:border-coral-500"
                    >
                    <svg class="absolute left-3 top-2.5 w-5 h-5 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                    </svg>
                </div>
            </div>

            <p class="text-slate-600 mb-6">
                @if ($search)
                    Löytyi <span class="font-semibold">{{ $filteredCompanies->count() }}</span> yhtiötä hakusanalla "{{ $search }}"
                @else
                    <span class="font-semibold">{{ $companyCount }}</span> sähköyhtiötä vertailussa
                @endif
            </p>

            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                @forelse ($filteredCompanies as $data)
                    <a
                        href="/sahkosopimus/sahkoyhtiot/{{ $data['company']->name_slug }}"
                        class="bg-white border border-slate-100 rounded-2xl shadow-sm p-4 hover:shadow-md hover:border-coral-200 transition-all"
                    >
                        <div class="flex items-center gap-4">
                            @if ($data['company']->getLogoUrl())
                                <div class="flex-shrink-0 bg-white p-2 rounded-lg border border-slate-100">
                                    <img
                                        src="{{ $data['company']->getLogoUrl() }}"
                                        alt="{{ $data['company']->name }}"
                                        class="w-16 h-12 object-contain"
                                        onerror="this.onerror=null; this.src='https://placehold.co/64x48?text=logo'"
                                    >
                                </div>
                            @else
                                <div class="flex-shrink-0 w-16 h-12 bg-slate-100 rounded-lg flex items-center justify-center">
                                    <span class="text-slate-500 font-bold text-sm">{{ substr($data['company']->name, 0, 3) }}</span>
                                </div>
                            @endif

                            <div class="flex-1 min-w-0">
                                <h3 class="font-bold text-slate-900 truncate">{{ $data['company']->name }}</h3>
                                <p class="text-sm text-slate-500">{{ $data['contractCount'] }} sopimusta</p>

                                <div class="flex flex-wrap gap-1 mt-2">
                                    @if ($data['hasFullyRenewable'])
                                        <span class="text-xs bg-green-100 text-green-700 px-2 py-0.5 rounded-full">100% uusiutuva</span>
                                    @endif
                                    @if ($data['hasSpotContracts'])
                                        <span class="text-xs bg-amber-100 text-amber-700 px-2 py-0.5 rounded-full">Pörssisähkö</span>
                                    @endif
                                </div>
                            </div>

                            <div class="flex-shrink-0 text-right">
                                <p class="text-sm font-semibold text-slate-900">{{ number_format($data['lowestPrice'], 0, ',', ' ') }} EUR</p>
                                <p class="text-xs text-slate-500">alkaen/vuosi</p>
                            </div>
                        </div>
                    </a>
                @empty
                    <div class="col-span-full bg-white rounded-2xl shadow-sm border border-slate-100 p-12 text-center">
                        <svg class="w-12 h-12 mx-auto text-slate-300 mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.172 16.172a4 4 0 015.656 0M9 10h.01M15 10h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                        </svg>
                        <p class="text-slate-500">Ei yhtiöitä hakuehdoilla.</p>
                    </div>
                @endforelse
            </div>
        </section>
    </div>
</div>
