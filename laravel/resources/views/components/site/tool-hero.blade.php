@props([
    'art',
    'tone' => 'dark',
])
{{--
    Painted hero of the tool pages (Paper boards 4UL-0 "Meter hero" and 8X7-0 "Bill hero",
    seasons EBS-0 / EBT-0): /sahkosopimus/laskuri and /maksatko-liikaa.

    Why not <x-site.hero>: these heroes are 540px high, the fox is painted into the plate
    (no cutout), and the shade is horizontal (900px at the text side) plus a 120px band behind
    the top bar. It reuses the tone variables (.vk-tone-*) and the snowdrift edge.

    `art` is laskuri-hero or maksatko-hero (scripts/design-art/tools.sh). `tone` must be
    App\Support\Season::heroTone(<art>) and equal the layout's `heroTone`.
    Laptop and up: the plate fills the hero behind the text. Phone and tablet: the text sits on
    the tone ground and the scene is a band below it, so text never sits on busy art.
--}}
@php
    use App\Support\Season;
@endphp
<section data-site-hero {{ $attributes->class(['vk-tone-'.$tone, 'tool-hero', 'tool-hero--'.$art]) }}>
    <div class="tool-hero-art" aria-hidden="true">
        <img
            class="tool-hero-plate"
            src="{{ Season::art($art) }}"
            srcset="{{ Season::art($art, 960) }} 960w, {{ Season::art($art) }} 1672w"
            sizes="(min-width: 1024px) max(100vw, 1440px), 640px"
            alt=""
            fetchpriority="high"
            decoding="async"
        >
        <div class="tool-hero-shade"></div>
    </div>

    <div class="vk-wrap tool-hero-text">
        {{ $slot }}
    </div>

    {{-- Snowdrift edge: the hero melts into the page ground (same edge as <x-site.hero>). --}}
    <svg class="pointer-events-none absolute -bottom-[10px] left-0 z-[2] h-[60px] w-full" viewBox="0 0 1440 60" preserveAspectRatio="none" aria-hidden="true" focusable="false">
        <path d="M0,34 C 160,18 300,30 460,36 C 640,42 760,16 940,20 C 1110,24 1240,40 1440,26 L1440,60 L0,60 Z" style="fill: var(--color-ground)" />
    </svg>
</section>
