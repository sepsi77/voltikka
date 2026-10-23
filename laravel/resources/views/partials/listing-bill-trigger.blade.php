{{-- The bill comparison action beside the results count (Paper "R4 · Bill comparison beside
     results", user decision 2026-10-23). It is not a filter: it reranks the whole list by the
     visitor's own bill, so it sits next to the list, as an inked action with a small torn-bill
     mark, not in the filter row. Needs the parent's Alpine `billOpen`. --}}
<button
    type="button"
    @click="billOpen = !billOpen"
    :aria-expanded="billOpen ? 'true' : 'false'"
    aria-expanded="{{ $this->isBillModeActive() ? 'true' : 'false' }}"
    aria-controls="listing-bill-panel"
    class="vk-bill-trigger vk-focus"
    data-bill-trigger
>
    <svg class="vk-bill-trigger-mark" viewBox="0 0 34 42" aria-hidden="true" focusable="false"><path d="M3 2 H31 V35 L27 38 L23 35 L19 38 L15 35 L11 38 L7 35 L3 38 Z" style="fill: var(--color-snow-white); stroke: var(--color-ink)" stroke-width="2" stroke-linejoin="round" /><path d="M8 10 H26 M8 16 H20 M8 22 H26" style="stroke: var(--color-edge-strong)" stroke-width="2" stroke-linecap="round" /><path d="M8 29 H18" style="stroke: var(--color-fox)" stroke-width="2.4" stroke-linecap="round" /></svg>
    <span class="vk-bill-trigger-text">
        <span class="vk-bill-trigger-title">
            Vertaa nykyiseen sähkölaskuusi
            <svg class="vk-chevron" viewBox="0 0 18 18" aria-hidden="true" focusable="false"><path d="M4 7l5 5 5-5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" /></svg>
        </span>
        <x-ink.wave width="full" stroke="2.6" class="vk-bill-trigger-wave" />
        <span class="vk-bill-trigger-sub">Katso hintaero samalla ajalla ja kulutuksella.</span>
    </span>
</button>
