@props(['icon', 'size' => 38])
{{--
    Inked icons of the company leaderboards (Paper board 6R6-0, "Leaderboards row 1/2"):
    coins (cheapest), leaf (greenest), cloud (cleanest emissions), papers (most contracts),
    spot (best spot margins: the spot category glyph), fee (lowest monthly fee).
    Decorative: the board title next to the icon names it.
--}}
@if ($icon === 'spot')
    <x-ink.glyph bucket="porssisahko" :size="$size" />
@else
<svg width="{{ $size }}" height="{{ $size }}" viewBox="0 0 38 38" aria-hidden="true" focusable="false" {{ $attributes->merge(['class' => 'inline-block shrink-0']) }}>
    @switch($icon)
        @case('coins')
            <ellipse cx="19" cy="29" rx="12" ry="4.5" style="fill: var(--color-star); opacity: .55" />
            <ellipse cx="19" cy="22.5" rx="12" ry="4.5" style="fill: var(--color-star); opacity: .55" />
            <ellipse cx="19" cy="16" rx="12" ry="4.5" style="fill: var(--color-star); opacity: .7" />
            <path d="M7 16 V29 C 7 32, 31 32, 31 29 V16 M7 22.5 C 7 25.5, 31 25.5, 31 22.5" fill="none" style="stroke: var(--color-ink)" stroke-width="1.4" />
            <ellipse cx="19" cy="16" rx="12" ry="4.5" fill="none" style="stroke: var(--color-ink)" stroke-width="1.4" />
            <path d="M15.5 16 H22.5" fill="none" style="stroke: var(--color-ink)" stroke-width="1.2" stroke-linecap="round" />
            @break
        @case('leaf')
            <path d="M8 30 C 8 15, 18 7, 32 7 C 32 21, 24 31, 10 30 Z" style="fill: var(--color-aurora); opacity: .55" />
            <path d="M7 31 C 7.5 15.5, 17.5 6.5, 31 6.5 C 31.5 20.5, 23.5 30.5, 9 30 M7 31 C 13 23, 19 17, 26 12" fill="none" style="stroke: var(--color-ink)" stroke-width="1.4" stroke-linecap="round" />
            @break
        @case('cloud')
            <path d="M10 28 C 4 28, 4 19, 10.5 19 C 11 13, 19 11, 22 16 C 25 12, 33 14, 32 21 C 37 22, 36 28, 31 28 Z" style="fill: var(--color-lake); opacity: .22" />
            <path d="M9.5 28.5 C 3.5 28, 4 18.5, 10.5 18.6 C 11.3 12.5, 19.3 10.8, 22.2 15.8 C 25.3 11.7, 33.3 14, 32.2 20.8 C 37.2 21.8, 36.2 28.6, 30.6 28.3 Z" fill="none" style="stroke: var(--color-ink)" stroke-width="1.4" stroke-linejoin="round" />
            <path d="M14 23 L17.5 26 L24 19.5" fill="none" style="stroke: var(--color-aurora)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
            @break
        @case('papers')
            <rect x="12" y="6" width="19" height="24" rx="1.5" style="fill: var(--color-snow-white); stroke: var(--color-ink)" stroke-width="1.3" />
            <rect x="9" y="9" width="19" height="24" rx="1.5" style="fill: var(--color-snow-white); stroke: var(--color-ink)" stroke-width="1.3" />
            <rect x="6" y="12" width="19" height="24" rx="1.5" style="fill: var(--color-ground); stroke: var(--color-ink)" stroke-width="1.4" />
            <path d="M10 18 H21 M10 22 H19 M10 26 H21" fill="none" style="stroke: var(--color-ink-muted)" stroke-width="1.3" stroke-linecap="round" />
            <path d="M10 31 H16" fill="none" style="stroke: var(--color-fox)" stroke-width="1.8" stroke-linecap="round" />
            @break
        @case('fee')
            <circle cx="19.6" cy="19.6" r="12" style="fill: var(--color-star); opacity: .5" />
            <circle cx="19" cy="19" r="12" fill="none" style="stroke: var(--color-ink)" stroke-width="1.4" />
            <ellipse cx="19" cy="19" rx="4" ry="6" fill="none" style="stroke: var(--color-ink)" stroke-width="1.6" />
            <path d="M9 29 L29 9" fill="none" style="stroke: var(--color-fox)" stroke-width="1.8" stroke-linecap="round" />
            @break
    @endswitch
</svg>
@endif
