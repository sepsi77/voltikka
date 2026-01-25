<div>
    @if($hasContent)
    <section class="mb-12">
        {{-- Local Companies Section (Tier 1) --}}
        @if($localCompanyContracts->isNotEmpty())
        <div class="mb-8" x-data="{ expanded: false }">
            <div class="flex items-center gap-3 mb-4">
                <div class="p-2 bg-coral-100 rounded-lg">
                    <svg class="w-5 h-5 text-coral-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/>
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/>
                    </svg>
                </div>
                <div>
                    <h2 class="text-xl font-bold text-slate-900">
                        Lähialueen sähköyhtiöt
                    </h2>
                    <p class="text-sm text-slate-500">
                        Sähköyhtiöt, joiden kotipaikka on lähellä {{ $cityName }}a
                    </p>
                </div>
            </div>

            <div class="space-y-4">
                @foreach($localCompanyContracts as $index => $contract)
                    <div class="relative" x-show="expanded || {{ $index }} < 10" x-collapse.duration.300ms>
                        {{-- Distance badge --}}
                        @if(isset($contract->company_distance_km) && $contract->company_distance_km > 0)
                        <div class="absolute -top-2 right-4 z-10">
                            <span class="inline-flex items-center gap-1 px-2.5 py-1 bg-coral-500 text-white text-xs font-bold rounded-full shadow-sm">
                                <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/>
                                </svg>
                                {{ number_format($contract->company_distance_km, 0) }} km
                            </span>
                        </div>
                        @elseif(isset($contract->company_distance_km) && $contract->company_distance_km == 0)
                        <div class="absolute -top-2 right-4 z-10">
                            <span class="inline-flex items-center gap-1 px-2.5 py-1 bg-green-500 text-white text-xs font-bold rounded-full shadow-sm">
                                <svg class="w-3 h-3" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M5.05 4.05a7 7 0 119.9 9.9L10 18.9l-4.95-4.95a7 7 0 010-9.9zM10 11a2 2 0 100-4 2 2 0 000 4z" clip-rule="evenodd"/>
                                </svg>
                                {{ $cityName }}
                            </span>
                        </div>
                        @endif
                        <x-contract-card
                            :contract="$contract"
                            :rank="$index + 1"
                            :consumption="$consumption"
                            :showRank="false"
                        />
                    </div>
                @endforeach
            </div>

            {{-- Expand/Collapse button --}}
            @if($localCompanyContracts->count() > 10)
            <div class="mt-4 text-center">
                <button
                    @click="expanded = !expanded"
                    class="inline-flex items-center gap-2 px-4 py-2 text-sm font-medium text-coral-600 hover:text-coral-700 hover:bg-coral-50 rounded-lg transition-colors"
                >
                    <span x-text="expanded ? 'Näytä vähemmän' : 'Näytä kaikki {{ $localCompanyContracts->count() }} sopimusta'"></span>
                    <svg class="w-4 h-4 transition-transform" :class="expanded ? 'rotate-180' : ''" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                    </svg>
                </button>
            </div>
            @endif
        </div>
        @endif

        {{-- Regional Contracts Section (Tier 2) --}}
        @if($regionalContracts->isNotEmpty())
        <div class="mb-8" x-data="{ expanded: false }">
            <div class="flex items-center gap-3 mb-4">
                <div class="p-2 bg-blue-100 rounded-lg">
                    <svg class="w-5 h-5 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 20l-5.447-2.724A1 1 0 013 16.382V5.618a1 1 0 011.447-.894L9 7m0 13l6-3m-6 3V7m6 10l4.553 2.276A1 1 0 0021 18.382V7.618a1 1 0 00-.553-.894L15 4m0 13V4m0 0L9 7"/>
                    </svg>
                </div>
                <div>
                    <h2 class="text-xl font-bold text-slate-900">
                        Alueelliset sopimukset {{ $cityLocative }}
                    </h2>
                    <p class="text-sm text-slate-500">
                        Sopimukset, jotka ovat saatavilla vain tietyillä alueilla
                    </p>
                </div>
            </div>

            <div class="space-y-4">
                @foreach($regionalContracts as $index => $contract)
                    <div class="relative" x-show="expanded || {{ $index }} < 10" x-collapse.duration.300ms>
                        {{-- Regional badge --}}
                        <div class="absolute -top-2 right-4 z-10">
                            <span class="inline-flex items-center gap-1 px-2.5 py-1 bg-blue-500 text-white text-xs font-bold rounded-full shadow-sm">
                                <svg class="w-3 h-3" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M5.05 4.05a7 7 0 119.9 9.9L10 18.9l-4.95-4.95a7 7 0 010-9.9zM10 11a2 2 0 100-4 2 2 0 000 4z" clip-rule="evenodd"/>
                                </svg>
                                Alueellinen
                            </span>
                        </div>
                        <x-contract-card
                            :contract="$contract"
                            :rank="$index + 1"
                            :consumption="$consumption"
                            :showRank="false"
                        />
                    </div>
                @endforeach
            </div>

            {{-- Expand/Collapse button --}}
            @if($regionalContracts->count() > 10)
            <div class="mt-4 text-center">
                <button
                    @click="expanded = !expanded"
                    class="inline-flex items-center gap-2 px-4 py-2 text-sm font-medium text-blue-600 hover:text-blue-700 hover:bg-blue-50 rounded-lg transition-colors"
                >
                    <span x-text="expanded ? 'Näytä vähemmän' : 'Näytä kaikki {{ $regionalContracts->count() }} sopimusta'"></span>
                    <svg class="w-4 h-4 transition-transform" :class="expanded ? 'rotate-180' : ''" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                    </svg>
                </button>
            </div>
            @endif
        </div>
        @endif

        {{-- Divider before main list --}}
        <div class="border-t border-slate-200 pt-8 mt-8">
            <div class="flex items-center gap-3 mb-4">
                <div class="p-2 bg-slate-100 rounded-lg">
                    <svg class="w-5 h-5 text-slate-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3.055 11H5a2 2 0 012 2v1a2 2 0 002 2 2 2 0 012 2v2.945M8 3.935V5.5A2.5 2.5 0 0010.5 8h.5a2 2 0 012 2 2 2 0 104 0 2 2 0 012-2h1.064M15 20.488V18a2 2 0 012-2h3.064M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                </div>
                <div>
                    <h2 class="text-xl font-bold text-slate-900">
                        Kaikki sopimukset {{ $cityLocative }}
                    </h2>
                    <p class="text-sm text-slate-500">
                        Vertaa kaikkia saatavilla olevia sähkösopimuksia
                    </p>
                </div>
            </div>
        </div>
    </section>
    @endif
</div>
