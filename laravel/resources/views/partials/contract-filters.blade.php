{{-- Shared Contract Filters Partial --}}
{{-- Used by both contracts-list.blade.php and seo-contracts-list.blade.php --}}

@php
    // Count only the filters this accordion actually hosts. The pricing-type pills live
    // above the list (partials/pricing-bucket-pills.blade.php), so a pill selection must
    // neither open this panel nor inflate its badge, even though `hasActiveFilters()`
    // counts it for "Tyhjennä suodattimet" and the default-listing cache guard.
    $activeFilterCount = $this->activeAccordionFilterCount();
@endphp
<div class="rounded-xl border border-slate-200 mb-6 overflow-hidden transition-colors" :class="filtersOpen ? 'border-slate-300' : ''" x-data="{ filtersOpen: @js($this->hasActiveAccordionFilters()) }">
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

    {{-- Filter Content (collapses on all sizes).

         The "Hinnoittelumalli" section was removed from here: pricing type is the filter
         visitors actually need on a spot-heavy list, so it is now the always-visible pill
         row above the list (partials/pricing-bucket-pills.blade.php). The old section also
         mixed real pricing mechanisms with metering pseudo-types (Quarterly / TimeOfUse /
         Seasonal name-matching), which are reachable through their own SEO pages. The
         `pricingModelFilter` property and its query logic are intentionally kept so legacy
         `?pricingModelFilter=` links keep working. --}}
    <div x-show="filtersOpen" x-collapse x-cloak class="lg:flex flex-wrap gap-y-4 pt-3 pb-5 border-t border-slate-100">
        {{-- Contract Duration Filters --}}
        <div class="flex flex-col px-4">
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
        <div class="px-4 mt-4 pb-5">
            <button
                wire:click="resetFilters"
                class="text-sm text-coral-600 hover:text-coral-700 font-medium"
            >
                Tyhjennä suodattimet
            </button>
        </div>
    @endif
</div>
