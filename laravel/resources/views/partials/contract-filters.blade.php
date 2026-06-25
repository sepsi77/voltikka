{{-- Shared Contract Filters Partial --}}
{{-- Used by both contracts-list.blade.php and seo-contracts-list.blade.php --}}

@php
    // Count user-applied filters so the collapsed trigger can show a badge.
    $activeFilterCount = collect([
        ! empty($pricingModelFilter),
        ! empty($contractTypeFilter),
        $fossilFreeFilter ?? false,
        $renewableFilter ?? false,
        $nuclearFilter ?? false,
    ])->filter()->count();
@endphp
<div class="bg-white rounded-2xl py-2 border border-slate-200 mb-6" x-data="{ filtersOpen: @js($this->hasActiveFilters()) }">
    {{-- Accordion trigger (all sizes): filters are collapsed by default so the
         contract list stays high on the page. --}}
    <button
        type="button"
        @click="filtersOpen = !filtersOpen"
        :aria-expanded="filtersOpen ? 'true' : 'false'"
        class="w-full px-4 py-2.5 flex items-center justify-between text-left font-semibold text-slate-900"
    >
        <span class="flex items-center gap-2">
            <svg class="w-5 h-5 text-slate-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2a1 1 0 01-.293.707L14 13.414V19a1 1 0 01-.553.894l-4 2A1 1 0 018 21v-7.586L3.293 6.707A1 1 0 013 6V4z"></path>
            </svg>
            Rajaa hakua
            @if ($activeFilterCount > 0)
                <span class="inline-flex items-center justify-center min-w-[1.25rem] h-5 px-1.5 rounded-full bg-coral-600 text-white text-xs font-bold tabular-nums">{{ $activeFilterCount }}</span>
            @endif
        </span>
        <svg class="w-5 h-5 transform transition-transform" :class="{ 'rotate-180': filtersOpen }" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
        </svg>
    </button>

    {{-- Filter Content (collapses on all sizes) --}}
    <div x-show="filtersOpen" x-collapse x-cloak class="lg:flex flex-wrap gap-y-4 pt-3 border-t border-slate-100">
        {{-- Pricing Model Filters --}}
        <div class="flex flex-col px-4 lg:w-full lg:shrink-0 lg:mb-4">
            <h4 class="font-semibold text-slate-900 mb-2">Hinnoittelumalli</h4>
            <div class="flex flex-col lg:flex-row flex-wrap gap-2">
                @foreach ($pricingModels as $model => $label)
                    @php
                        $icons = [
                            'FixedPrice' => 'M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z',
                            'Spot' => 'M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z',
                            'Hybrid' => 'M8 9l3 3-3 3m5 0h3M5 20h14a2 2 0 002-2V6a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z',
                            'Quarterly' => 'M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z',
                            'TimeOfUse' => 'M12 3v1m0 16v1m9-9h-1M4 12H3m15.364 6.364l-.707-.707M6.343 6.343l-.707-.707m12.728 0l-.707.707M6.343 17.657l-.707.707M16 12a4 4 0 11-8 0 4 4 0 018 0z',
                            'Seasonal' => 'M3 15a4 4 0 004 4h9a5 5 0 10-.1-9.999 5.002 5.002 0 10-9.78 2.096A4.001 4.001 0 003 15z',
                        ];
                        $icon = $icons[$model] ?? $icons['FixedPrice'];
                        $isActive = $pricingModelFilter === $model;
                    @endphp
                    <button
                        wire:click="setPricingModelFilter('{{ $model }}')"
                        class="flex items-center border focus:outline-none font-medium rounded-lg text-sm px-4 py-2 transition-all {{ $isActive ? 'bg-slate-950 border-slate-950 text-white' : 'bg-slate-50 border-slate-200 text-slate-600 hover:border-slate-300' }}"
                    >
                        <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="{{ $icon }}"></path>
                        </svg>
                        {{ $label }}
                    </button>
                @endforeach
            </div>
        </div>

        {{-- Contract Duration Filters --}}
        <div class="flex flex-col border-t lg:border-t-0 border-slate-200 px-4 mt-4 pt-4 lg:pt-4">
            <h4 class="font-semibold text-slate-900 mb-2">Sopimuksen kesto</h4>
            <div class="flex flex-col lg:flex-row gap-2">
                @foreach ($contractTypes as $type => $label)
                    @php
                        $icons = [
                            'OpenEnded' => 'M13 5l7 7-7 7M5 5l7 7-7 7',
                            'FixedTerm' => 'M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z',
                        ];
                        $icon = $icons[$type] ?? $icons['OpenEnded'];
                        $isActive = $contractTypeFilter === $type;
                    @endphp
                    <button
                        wire:click="setContractTypeFilter('{{ $type }}')"
                        class="flex items-center border focus:outline-none font-medium rounded-lg text-sm px-4 py-2 transition-all {{ $isActive ? 'bg-slate-950 border-slate-950 text-white' : 'bg-slate-50 border-slate-200 text-slate-600 hover:border-slate-300' }}"
                    >
                        <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="{{ $icon }}"></path>
                        </svg>
                        {{ $label }}
                    </button>
                @endforeach
            </div>
        </div>

        {{-- Energy Source Filters --}}
        <div class="flex flex-col border-t lg:border-t-0 lg:border-l border-slate-200 px-4 mt-4 pt-4 lg:pt-4">
            <h4 class="font-semibold text-slate-900 mb-2">Energialähde</h4>
            <div class="flex flex-col lg:flex-row gap-2">
                <button
                    wire:click="$toggle('fossilFreeFilter')"
                    class="flex items-center border focus:outline-none font-medium rounded-lg text-sm px-4 py-2 transition-all {{ $fossilFreeFilter ? 'bg-slate-950 border-slate-950 text-white' : 'bg-slate-50 border-slate-200 text-slate-600 hover:border-slate-300' }}"
                >
                    <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 3v4M3 5h4M6 17v4m-2-2h4m5-16l2.286 6.857L21 12l-5.714 2.143L13 21l-2.286-6.857L5 12l5.714-2.143L13 3z"></path>
                    </svg>
                    Fossiiliton
                </button>
                <button
                    wire:click="$toggle('renewableFilter')"
                    class="flex items-center border focus:outline-none font-medium rounded-lg text-sm px-4 py-2 transition-all {{ $renewableFilter ? 'bg-slate-950 border-slate-950 text-white' : 'bg-slate-50 border-slate-200 text-slate-600 hover:border-slate-300' }}"
                >
                    <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364 6.364l-.707-.707M6.343 6.343l-.707-.707m12.728 0l-.707.707M6.343 17.657l-.707.707M16 12a4 4 0 11-8 0 4 4 0 018 0z"></path>
                    </svg>
                    Uusiutuva
                </button>
                <button
                    wire:click="$toggle('nuclearFilter')"
                    class="flex items-center border focus:outline-none font-medium rounded-lg text-sm px-4 py-2 transition-all {{ $nuclearFilter ? 'bg-slate-950 border-slate-950 text-white' : 'bg-slate-50 border-slate-200 text-slate-600 hover:border-slate-300' }}"
                >
                    <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"></path>
                    </svg>
                    Ydinvoima
                </button>
            </div>
        </div>
    </div>

    {{-- Clear Filters --}}
    @if ($this->hasActiveFilters())
        <div class="px-4 mt-4">
            <button
                wire:click="resetFilters"
                class="text-sm text-coral-600 hover:text-coral-700 font-medium"
            >
                Tyhjennä suodattimet
            </button>
        </div>
    @endif
</div>
