{{--
    Top-bar spot price, rendered by /api/header-spot-price and placed into every
    [data-header-spot-price] shell. Plain text with an aurora-light sparkle (Paper "Spot price now
    (top bar)"); colours come from the top-bar tone variables. Keep data-header-spot-price-state.
--}}
<div data-header-spot-price-state="{{ $currentPrice !== null ? 'available' : 'unavailable' }}">
    @if ($currentPrice !== null)
        <a href="/spot-price" class="vk-topbar-spot vk-focus">
            <x-ink.sparkle size="12" color="var(--mark-aurora-light)" class="vk-topbar-spot-mark" />
            <span class="vk-topbar-spot-label">Spot-hinta</span>
            <span class="vk-topbar-spot-price">{{ number_format($currentPrice['price_with_tax'], 2, ',', ' ') }} c/kWh</span>
        </a>
    @else
        <a href="/spot-price" class="vk-topbar-spot vk-focus">
            <span class="vk-topbar-spot-unavailable">Spot-hintoja ei ole saatavilla</span>
            <span class="vk-topbar-spot-arrow" aria-hidden="true">→</span>
        </a>
    @endif
</div>
