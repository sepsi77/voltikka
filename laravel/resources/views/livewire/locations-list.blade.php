<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
    <!-- Hero Section -->
    <section class="bg-transparent mb-8">
        <div class="max-w-screen-xl px-4 py-8 mx-auto lg:py-16">
            <h1 class="max-w-2xl mb-4 text-4xl font-extrabold text-slate-900 tracking-tight leading-none md:text-5xl xl:text-6xl">
                Sähkösopimukset paikkakunnittain
            </h1>
            <p class="max-w-2xl mb-6 font-light text-slate-500 md:text-lg lg:text-xl">
                Löydä sähkösopimukset, jotka ovat saatavilla omalla paikkakunnallasi. Valitse kunta listalta tai hae paikkakuntaa nimellä.
            </p>
        </div>
    </section>

    @if ($selectedMunicipality)
        <!-- Selected Municipality View -->
        <div class="mb-8">
            <button
                wire:click="clearSelection"
                class="inline-flex items-center text-coral-600 hover:text-coral-700 font-medium mb-4"
            >
                <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"></path>
                </svg>
                Takaisin paikkakuntiin
            </button>

            <h2 class="text-2xl font-bold text-slate-900 mb-4">
                Sähkösopimukset: {{ $selectedMunicipality }}
            </h2>

            <p class="text-slate-600 mb-6">
                <span class="font-semibold">{{ $contracts->count() }}</span> sopimusta saatavilla paikkakunnalla {{ $selectedMunicipality }}
            </p>

            <!-- Contract Cards -->
            <div class="space-y-4">
                @forelse ($contracts as $contract)
                    @php
                        $source = $contract->electricitySource;
                        $generalPrice = $contract->priceComponents->where('price_component_type', 'General')->sortByDesc('price_date')->first()?->price;
                        $monthlyFee = $contract->priceComponents->where('price_component_type', 'Monthly')->sortByDesc('price_date')->first()?->price ?? 0;
                    @endphp
                    <div class="w-full p-4 bg-white border border-slate-100 rounded-2xl shadow-sm sm:p-6">
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
                                    <div class="w-24 h-12 bg-slate-200 rounded flex items-center justify-center">
                                        <span class="text-slate-500 text-sm font-bold">{{ substr($contract->company?->name ?? 'N/A', 0, 3) }}</span>
                                    </div>
                                @endif
                                <div class="flex flex-col items-start ml-0 lg:ml-4 mt-4 lg:mt-0 text-center lg:text-left">
                                    <h5 class="mb-1 text-xl font-bold text-slate-900">
                                        {{ $contract->name }}
                                    </h5>
                                    <p class="text-base text-slate-500">
                                        {{ $contract->company?->name }}
                                    </p>
                                </div>
                            </div>

                            <!-- Pricing -->
                            <div class="flex flex-col lg:flex-row items-center lg:ml-auto mt-4 lg:mt-0 gap-4">
                                @if ($generalPrice !== null)
                                    <div class="text-center lg:text-left px-2">
                                        <h5 class="text-lg font-bold text-slate-900">
                                            {{ number_format($generalPrice, 2, ',', ' ') }} c/kWh
                                        </h5>
                                        <p class="text-sm text-slate-500">Energia</p>
                                    </div>
                                @endif

                                <div class="text-center lg:text-left px-2">
                                    <h5 class="text-lg font-bold text-slate-900">
                                        {{ number_format($monthlyFee, 2, ',', ' ') }} EUR/kk
                                    </h5>
                                    <p class="text-sm text-slate-500">Perusmaksu</p>
                                </div>

                                <a
                                    href="{{ route('contract.detail', $contract->id) }}"
                                    class="flex items-center justify-center text-white bg-gradient-to-r from-coral-500 to-coral-600 hover:from-coral-400 hover:to-coral-500 font-medium rounded-xl text-sm px-5 py-2.5 transition-colors shadow-sm"
                                >
                                    Katso lisää
                                    <svg class="w-5 h-5 ml-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 8l4 4m0 0l-4 4m4-4H3"></path>
                                    </svg>
                                </a>
                            </div>
                        </div>
                    </div>
                @empty
                    <div class="bg-white rounded-2xl shadow-sm-sm border border-slate-100 p-12 text-center">
                        <p class="text-slate-500">Ei sopimuksia saatavilla tällä paikkakunnalla.</p>
                    </div>
                @endforelse
            </div>
        </div>
    @else
        <!-- Municipality Search -->
        <div class="mb-8">
            <div class="relative max-w-md">
                <input
                    type="text"
                    wire:model.live.debounce.300ms="search"
                    placeholder="Hae paikkakuntaa..."
                    class="w-full pl-10 pr-4 py-3 border border-slate-300 rounded-lg focus:ring-2 focus:ring-coral-500 focus:border-coral-500"
                >
                <svg class="absolute left-3 top-1/2 transform -translate-y-1/2 w-5 h-5 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                </svg>
            </div>
        </div>

        <!-- Municipality List -->
        <div class="mb-4 text-sm text-slate-600">
            <span class="font-semibold">{{ $municipalities->count() }}</span> paikkakuntaa
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-4">
            @forelse ($municipalities as $municipality)
                <button
                    wire:click="selectMunicipality('{{ $municipality->municipal_name_fi }}', '{{ $municipality->municipal_name_fi_slug }}')"
                    class="p-4 bg-white border border-slate-100 rounded-2xl shadow-sm hover:border-coral-500 hover:shadow-md transition-all text-left"
                >
                    <h3 class="text-lg font-semibold text-slate-900">
                        {{ $municipality->municipal_name_fi }}
                    </h3>
                    <p class="text-sm text-slate-500">
                        {{ $municipality->postcode_count }} postinumeroa
                    </p>
                </button>
            @empty
                <div class="col-span-full bg-white rounded-2xl shadow-sm-sm border border-slate-100 p-12 text-center">
                    <p class="text-slate-500">Ei paikkakuntia löytynyt hakusanalla "{{ $search }}"</p>
                </div>
            @endforelse
        </div>
    @endif
</div>
