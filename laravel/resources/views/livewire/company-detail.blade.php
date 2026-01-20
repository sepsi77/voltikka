<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
    <!-- Back Link -->
    <a href="/" class="inline-flex items-center text-coral-600 hover:text-coral-700 font-medium mb-6">
        <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"></path>
        </svg>
        Takaisin sopimuksiin
    </a>

    <!-- Company Header -->
    <div class="bg-white rounded-2xl shadow-sm border border-slate-100 p-6 mb-8">
        <div class="flex flex-col lg:flex-row items-center lg:items-start gap-6">
            @if ($company->getLogoUrl())
                <img
                    src="{{ $company->getLogoUrl() }}"
                    alt="{{ $company->name }}"
                    class="w-32 h-auto object-contain"
                    onerror="this.onerror=null; this.src='https://placehold.co/128x48?text=logo'"
                >
            @else
                <div class="w-32 h-16 bg-slate-200 rounded flex items-center justify-center">
                    <span class="text-slate-500 text-lg font-bold">{{ substr($company->name, 0, 3) }}</span>
                </div>
            @endif

            <div class="flex-1 text-center lg:text-left">
                <h1 class="text-3xl font-bold text-slate-900 mb-2">
                    {{ $company->name }}
                </h1>

                @if ($company->street_address || $company->postal_code || $company->postal_name)
                    <p class="text-slate-600 mb-2">
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
                        class="inline-flex items-center text-coral-600 hover:text-coral-700"
                    >
                        <svg class="w-5 h-5 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"></path>
                        </svg>
                        {{ $company->company_url }}
                    </a>
                @endif
            </div>
        </div>
    </div>

    <!-- Contracts Section -->
    <h2 class="text-2xl font-bold text-slate-900 mb-4">
        Sähkösopimukset
    </h2>

    <p class="text-slate-600 mb-6">
        <span class="font-semibold">{{ $contracts->count() }}</span> sopimusta saatavilla
    </p>

    <div class="space-y-4">
        @forelse ($contracts as $contract)
            @php
                $source = $contract->electricitySource;
                $generalPrice = $contract->priceComponents->where('price_component_type', 'General')->sortByDesc('price_date')->first()?->price;
                $monthlyFee = $contract->priceComponents->where('price_component_type', 'Monthly')->sortByDesc('price_date')->first()?->price ?? 0;
            @endphp
            <div class="w-full p-4 bg-white border border-slate-100 rounded-2xl shadow-sm sm:p-6">
                <div class="flex flex-col lg:flex-row items-center justify-between">
                    <div class="text-center lg:text-left mb-4 lg:mb-0">
                        <h3 class="text-xl font-bold text-slate-900 mb-1">
                            {{ $contract->name }}
                        </h3>
                        <p class="text-sm text-slate-500">
                            @php
                                $typeLabels = [
                                    'FixedTerm' => 'Määräaikainen',
                                    'OpenEnded' => 'Toistaiseksi voimassa',
                                    'Spot' => 'Pörssisähkö',
                                ];
                            @endphp
                            {{ $typeLabels[$contract->contract_type] ?? $contract->contract_type }}
                        </p>
                    </div>

                    <div class="flex flex-col lg:flex-row items-center gap-4">
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

                <!-- Energy Source Badges -->
                @if ($source)
                    <div class="flex flex-wrap gap-2 mt-4">
                        @if ($source->renewable_total && $source->renewable_total > 0)
                            <span class="text-slate-700 border border-green-700 bg-[#E4FFC9] font-medium rounded-lg text-sm px-3 py-1">
                                Uusiutuva {{ number_format($source->renewable_total, 0) }}%
                            </span>
                        @endif
                        @if ($source->nuclear_total && $source->nuclear_total > 0)
                            <span class="text-slate-700 border border-green-700 bg-[#E4FFC9] font-medium rounded-lg text-sm px-3 py-1">
                                Ydinvoima {{ number_format($source->nuclear_total, 0) }}%
                            </span>
                        @endif
                        @if ($source->fossil_total && $source->fossil_total > 0)
                            <span class="text-slate-700 border border-slate-700 bg-slate-100 font-medium rounded-lg text-sm px-3 py-1">
                                Fossiilinen {{ number_format($source->fossil_total, 0) }}%
                            </span>
                        @endif
                    </div>
                @endif
            </div>
        @empty
            <div class="bg-white rounded-2xl shadow-sm border border-slate-100 p-12 text-center">
                <p class="text-slate-500">Ei sähkösopimuksia saatavilla.</p>
            </div>
        @endforelse
    </div>
</div>
