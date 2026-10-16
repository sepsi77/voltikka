@props([
    'art',
    'fox' => null,
    'tone' => 'dark',
    'foxClass' => null,
])
{{--
    Painted page hero (design spec §5 "Hero", §6, §7 "Seasons").

    `art` and `fox` are art names from scripts/build-design-art.sh; App\Support\Season picks the
    current Europe/Helsinki season file. `tone` must be App\Support\Season::heroTone(<art>) and
    must equal the `heroTone` the page passes to the layout, so the overlay top bar and the hero
    text use the same colours.

    The top bar is not part of this component: the layout renders it absolutely over the top
    84px of the page when the page passes `heroTone`. The content column therefore starts
    below it (84 + 20px, Paper "Hero text" frame).

    `foxClass` (optional) replaces the classes of the fox image, for a page whose fox has
    another size or place (the cheapest page's 236x177 sled fox). Default: the listing fox.

    Slots: default = the text column (H1, lead), `aside` = the optional right column (the
    page's key figure on the sky). The art is decorative (alt="").
--}}
@php
    use App\Support\Season;
@endphp
<section data-site-hero {{ $attributes->class(['vk-tone-'.$tone, 'relative isolate overflow-hidden bg-night']) }}>
    <img
        class="vk-hero-plate"
        src="{{ Season::art($art) }}"
        srcset="{{ Season::art($art, 960) }} 960w, {{ Season::art($art) }} 1672w"
        sizes="(min-width: 1024px) max(111vw, 1600px), 100vw"
        alt=""
        fetchpriority="high"
        decoding="async"
    >
    <div class="vk-hero-shade" aria-hidden="true"></div>

    <div class="vk-wrap relative flex flex-col gap-8 pb-[150px] pt-[96px] lg:min-h-[460px] lg:flex-row lg:items-start lg:justify-between lg:gap-10 lg:pb-16 lg:pt-[104px]">
        <div class="min-w-0 lg:max-w-[700px] lg:flex-1">
            {{ $slot }}
        </div>
        @isset($aside)
            <div class="min-w-0 lg:w-[330px] lg:shrink-0 lg:pt-1.5">
                {{ $aside }}
            </div>
        @endisset
    </div>

    @if ($fox)
        <img
            src="{{ Season::art($fox) }}"
            alt=""
            width="160"
            height="160"
            decoding="async"
            class="{{ $foxClass ?? 'absolute bottom-1.5 right-4 h-[112px] w-[112px] lg:bottom-[10px] lg:left-1/2 lg:right-auto lg:h-[160px] lg:w-[160px]' }}"
        >
    @endif

    {{-- Snowdrift edge: the hero melts into the page ground. --}}
    <svg class="pointer-events-none absolute -bottom-[10px] left-0 h-[60px] w-full" viewBox="0 0 1440 60" preserveAspectRatio="none" aria-hidden="true" focusable="false">
        <path d="M0,34 C 160,18 300,30 460,36 C 640,42 760,16 940,20 C 1110,24 1240,40 1440,26 L1440,60 L0,60 Z" style="fill: var(--color-ground)" />
    </svg>
</section>
