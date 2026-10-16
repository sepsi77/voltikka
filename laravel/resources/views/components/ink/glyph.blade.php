@props(['bucket', 'size' => 44])
{{--
    Inked price-shape glyph of a pricing category (design spec §5 "Category glyphs").
    The shape, not the colour, names the category, so it works without colour vision.
    `bucket` is a PricingBucket value: porssisahko | paivittyva | kulutusvaikutus | kiintea.
    Exact paths from the Paper listing board (controls block, "Miten hinta käyttäytyy").
--}}
@php
    $bucket = $bucket instanceof \App\Services\ContractCard\Enums\PricingBucket ? $bucket->value : $bucket;
@endphp
<svg width="{{ $size }}" height="{{ $size }}" viewBox="0 0 44 44" aria-hidden="true" focusable="false" {{ $attributes->merge(['class' => 'inline-block shrink-0']) }}>
    @switch($bucket)
        @case('porssisahko')
            <g style="fill: var(--color-cat-spot); opacity: .6">
                <rect x="6.2" y="27" width="5" height="10" /><rect x="12.2" y="19" width="5" height="18" /><rect x="18.2" y="31" width="5" height="6" />
                <rect x="24.2" y="11" width="5" height="26" /><rect x="30.2" y="22" width="5" height="15" /><rect x="36.2" y="26" width="4.6" height="11" />
            </g>
            <path d="M5 37.5 L5.2 25.8 L10.3 26.1 L10.1 37.5 M11.1 37.5 L11.2 17.9 L16.2 18.1 L16.1 37.5 M17.2 37.5 L17.1 30.2 L22.1 29.9 L22.3 37.5 M23.1 37.5 L23.3 9.8 L28.2 10.1 L28.1 37.5 M29.2 37.5 L29.1 21 L34.2 21.2 L34.1 37.5 M35.2 37.5 L35.1 25.1 L39.9 24.9 L40 37.5" fill="none" style="stroke: var(--color-ink)" stroke-width="1.25" stroke-linecap="round" stroke-linejoin="round" />
            <path d="M24.5 15 L27.5 13 M24.5 20 L27.5 18 M24.5 25 L27.5 23 M12.5 23 L15.5 21 M12.5 28 L15.5 26 M30.5 26 L33.5 24 M30.5 31 L33.5 29" fill="none" style="stroke: var(--color-ink); opacity: var(--opacity-hatching)" stroke-width="0.8" stroke-linecap="round" />
            @break
        @case('paivittyva')
            <path d="M4.5 35 L10.5 29 M8 37 L14.5 30.5 M12.5 37 L15 34.5 M15.5 22 L20 17.5 M15.5 28 L26 17.5 M15.5 34 L27 22.5 M18 37 L27 28 M23 37 L27 33 M27.5 30 L33 24.5 M27.5 36 L39 24.5 M33 37 L40 30" fill="none" style="stroke: var(--color-cat-periodic); opacity: .5" stroke-linecap="round" />
            <path d="M4 28.5 C 8 28.2, 12 28.8, 15 28.5 V17 C 19 16.7, 23 17.3, 27 17 V24.5 C 31 24.2, 36 24.8, 40 24.5" fill="none" style="stroke: var(--color-cat-periodic)" stroke-width="3.4" stroke-linecap="round" stroke-linejoin="round" />
            <path d="M5 27.6 L15.8 27.5 L15.6 16.2 L27.8 16.3 L27.7 23.7 L40.4 23.6" fill="none" style="stroke: var(--color-ink)" stroke-width="1.1" stroke-linecap="round" stroke-linejoin="round" />
            <path d="M15 37 V41.5 M27 37 V41.5" fill="none" style="stroke: var(--color-ink)" stroke-width="1.6" stroke-linecap="round" />
            @break
        @case('kulutusvaikutus')
            <path d="M5 15 C 17 14.6, 29 15.4, 41 15 V31 C 29 31.4, 17 30.6, 5 31 Z" style="fill: var(--color-cat-usage); opacity: .22" />
            <path d="M7 29 L11 16 M13 29 L17 16 M19 29 L23 16 M25 29 L29 16 M31 29 L35 16 M37 29 L39.5 21" fill="none" style="stroke: var(--color-cat-usage); opacity: .4" stroke-width="0.9" stroke-linecap="round" />
            <path d="M4 14 C 16 13.6, 28 14.4, 40 14 M4 30 C 16 29.6, 28 30.4, 40 30" fill="none" style="stroke: var(--color-ink)" stroke-width="1.1" stroke-linecap="round" stroke-dasharray="2.5 2.5" />
            <path d="M4 22.2 C 15 21.6, 28 22.6, 40 22" fill="none" style="stroke: var(--color-cat-usage)" stroke-width="3.4" stroke-linecap="round" />
            <path d="M4.8 21.4 C 15.5 20.9, 28.5 21.8, 40.6 21.2" fill="none" style="stroke: var(--color-ink)" stroke-width="1.1" stroke-linecap="round" />
            @break
        @default
            <path d="M4 26.2 C 11 25.8, 19 26.5, 27 26" fill="none" style="stroke: var(--color-cat-fixed)" stroke-width="3.4" stroke-linecap="round" />
            <path d="M4.8 25.4 C 11.5 25, 19.5 25.7, 27.6 25.2" fill="none" style="stroke: var(--color-ink)" stroke-width="1.1" stroke-linecap="round" />
            <rect x="31.5" y="22" width="11" height="9.5" rx="1.5" style="fill: var(--color-cat-fixed); opacity: .55" />
            <path d="M32.5 21 V17.5 C 32.5 13.2, 39.5 13.2, 39.5 17.5 V21" fill="none" style="stroke: var(--color-ink)" stroke-width="1.6" stroke-linecap="round" />
            <path d="M30.6 21.2 L41.3 20.9 L41.5 30.4 L30.4 30.6 Z" fill="none" style="stroke: var(--color-ink)" stroke-width="1.3" stroke-linejoin="round" />
            <path d="M32.5 29 L35 23 M35.5 29.5 L38 23.5 M38.5 29.5 L40.5 25" fill="none" style="stroke: var(--color-ink); opacity: .4" stroke-width="0.8" stroke-linecap="round" />
            <circle cx="36" cy="25.2" r="1.3" style="fill: var(--color-ink)" />
    @endswitch
    <path d="M3 38.6 C 14 37.6, 28 39.3, 41.5 38.2" fill="none" style="stroke: var(--color-ink)" stroke-width="1.6" stroke-linecap="round" />
</svg>
