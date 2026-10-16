{{--
    Top-bar spot price before the loader in layouts/app.blade.php fills it (design spec §5 "Top bar":
    plain text with a sparkle mark, no pill). Colours come from the top-bar tone variables.
    Keep data-header-spot-price, data-url, data-state and the unavailable template: the loader uses them.
--}}
<div data-header-spot-price data-url="/api/header-spot-price" data-state="loading" aria-live="polite">
    <a href="/spot-price" class="vk-topbar-spot vk-focus" aria-busy="true">
        <x-ink.sparkle size="12" color="var(--color-edge-strong)" class="vk-topbar-spot-mark" />
        <span class="vk-topbar-spot-label">Spot-hinta</span>
        <span class="vk-topbar-spot-loading">Ladataan…</span>
    </a>
    <template data-header-spot-price-unavailable>
        <a href="/spot-price" class="vk-topbar-spot vk-focus">
            <span class="vk-topbar-spot-unavailable">Spot-hintoja ei ole saatavilla</span>
            <span class="vk-topbar-spot-arrow" aria-hidden="true">→</span>
        </a>
    </template>
</div>
