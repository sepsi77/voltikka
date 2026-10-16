{{-- Shared Contract Filters Partial --}}
{{-- Used by both contracts-list.blade.php and seo-contracts-list.blade.php --}}

@php
    // Count only the filters this accordion hosts. The pricing-type pills and postcode
    // selector live above it, so those selections do not open the panel or inflate its
    // badge. `hasActiveFilters()` still counts both for the clear action and cache guard.
    $activeFilterCount = $this->activeAccordionFilterCount();
@endphp
{{-- "Rajaa hakua" disclosure (Paper board 2J3-0, "Controls · compact", third row). Inside
     the listing's `.vk-disclosures` row the wrapper is `display: contents`: the trigger sits
     in the row and the panel opens below the row, without a frame. Used alone (homepage) it
     is a plain block. The active-filter count is an inked mark, not a badge. --}}
<div class="vk-disclosure" x-data="{ filtersOpen: @js($this->hasActiveAccordionFilters()) }">
    <button
        type="button"
        @click="filtersOpen = !filtersOpen"
        :aria-expanded="filtersOpen ? 'true' : 'false'"
        aria-expanded="{{ $this->hasActiveAccordionFilters() ? 'true' : 'false' }}"
        class="vk-disclosure-trigger"
    >
        <span class="vk-disclosure-title">Rajaa hakua</span>
        @if ($activeFilterCount > 0)
            <span class="vk-count"><x-ink.sparkle size="11" color="var(--color-fox)" />{{ $activeFilterCount }}<span class="sr-only"> valittua rajausta</span></span>
        @endif
        <svg class="vk-chevron" viewBox="0 0 18 18" aria-hidden="true" focusable="false"><path d="M4 7l5 5 5-5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" /></svg>
    </button>

    {{-- Filter Content (collapses on all sizes).

         The "Hinnoittelumalli" section was removed from here: pricing type is the filter
         visitors actually need on a spot-heavy list, so it is now the always-visible row
         above the list (partials/pricing-bucket-pills.blade.php). The old section also
         mixed real pricing mechanisms with metering pseudo-types (Quarterly / TimeOfUse /
         Seasonal name-matching), which are reachable through their own SEO pages. The
         `pricingModelFilter` property and its query logic are intentionally kept so legacy
         `?pricingModelFilter=` links keep working. --}}
    <div x-show="filtersOpen" x-collapse x-cloak class="vk-disclosure-panel">
        <div class="flex flex-col gap-5 sm:flex-row sm:gap-12">
            {{-- Contract duration: one value at a time (radio look). --}}
            <div>
                <p class="vk-form-label" id="filter-duration-label">Sopimuksen kesto</p>
                <div class="vk-choices mt-1" role="group" aria-labelledby="filter-duration-label">
                    @foreach ($contractTypes as $type => $label)
                        @php $isActive = $contractTypeFilter === $type; @endphp
                        <button
                            type="button"
                            wire:click="setContractTypeFilter('{{ $type }}')"
                            aria-pressed="{{ $isActive ? 'true' : 'false' }}"
                            class="vk-choice"
                        >
                            @if ($isActive)
                                <svg class="vk-mark" viewBox="0 0 22 22" aria-hidden="true" focusable="false"><circle cx="11" cy="11" r="9.5" style="fill: var(--color-fox)" /><path d="M6.5 11.5 L9.6 14.3 L15.6 7.8" fill="none" style="stroke: var(--color-snow-white)" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" /></svg>
                            @else
                                <svg class="vk-mark" viewBox="0 0 22 22" aria-hidden="true" focusable="false"><circle cx="11" cy="11" r="9" style="fill: var(--color-snow-white); stroke: var(--color-field-edge)" stroke-width="2" /></svg>
                            @endif
                            {{ $label }}
                        </button>
                    @endforeach
                </div>
            </div>

            {{-- Energy source: independent toggles (checkbox look). --}}
            <div>
                <p class="vk-form-label" id="filter-energy-label">Energialähde</p>
                <div class="vk-choices mt-1" role="group" aria-labelledby="filter-energy-label">
                    @foreach (['fossilFreeFilter' => ['Fossiiliton', $fossilFreeFilter], 'renewableFilter' => ['Uusiutuva', $renewableFilter], 'nuclearFilter' => ['Ydinvoima', $nuclearFilter]] as $filterProperty => [$filterLabel, $filterOn])
                        <button
                            type="button"
                            wire:click="$toggle('{{ $filterProperty }}')"
                            aria-pressed="{{ $filterOn ? 'true' : 'false' }}"
                            class="vk-choice"
                        >
                            @if ($filterOn)
                                <svg class="vk-mark" viewBox="0 0 22 22" aria-hidden="true" focusable="false"><rect x="1" y="1" width="20" height="20" rx="5" style="fill: var(--color-fox)" /><path d="M6.5 11.5 L9.6 14.3 L15.6 7.8" fill="none" style="stroke: var(--color-snow-white)" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" /></svg>
                            @else
                                <svg class="vk-mark" viewBox="0 0 22 22" aria-hidden="true" focusable="false"><rect x="2" y="2" width="18" height="18" rx="5" style="fill: var(--color-snow-white); stroke: var(--color-field-edge)" stroke-width="2" /></svg>
                            @endif
                            {{ $filterLabel }}
                        </button>
                    @endforeach
                </div>
            </div>
        </div>

        {{-- Clear Filters. Inside the collapsible panel on purpose: when the
             accordion holds an active filter, the panel opens by default, and a
             pill or postcode selection already has its own inline clear action.
             Outside the panel this link floated inside the collapsed box. --}}
        @if ($this->hasActiveFilters())
            <button
                type="button"
                wire:click="resetFilters"
                class="vk-inked-link vk-focus mt-4"
            >
                Tyhjennä suodattimet
            </button>
        @endif
    </div>
</div>
