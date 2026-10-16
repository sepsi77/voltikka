@props([
    'tone' => 'dark',
])
{{--
    Painted hero of /spot-price (Paper boards 11H-0 "Scene hero" and EBO-0 "Seasons · /spot-price").

    Why not <x-site.hero>: this hero is 720px high, its plate is the full picture-book village
    (top-anchored, 90px of it below the hero), the fox (250px) sits on the snow at the centre and
    looks up at the sky, and the text column carries the current price as a poster number. It
    reuses the shared tone variables (.vk-tone-*), .vk-hero-h1/.vk-hero-lead and the snowdrift edge.

    `tone` must be App\Support\Season::heroTone('spot-hero') and equal the layout's `heroTone`.
    The shade follows the season of the art (none in talvi, light in kevät/kesä, night in syksy).
    Laptop and up: the art fills the hero behind the text. Phone and tablet: the text sits on the
    tone ground and the village is a band below it, so text never sits on busy art.
--}}
@php
    use App\Support\Season;
@endphp
<section data-site-hero {{ $attributes->class(['vk-tone-'.$tone, 'spot-hero', 'spot-hero--'.Season::current()]) }}>
    <div class="spot-hero-art" aria-hidden="true">
        <img
            class="spot-hero-plate"
            src="{{ Season::art('spot-hero') }}"
            srcset="{{ Season::art('spot-hero', 960) }} 960w, {{ Season::art('spot-hero') }} 1672w"
            sizes="(min-width: 1024px) max(100vw, 1440px), 640px"
            alt=""
            fetchpriority="high"
            decoding="async"
        >
        <div class="spot-hero-shade"></div>
        <img
            class="spot-hero-fox"
            src="{{ Season::art('fox-sit-skyward') }}"
            alt=""
            width="250"
            height="250"
            decoding="async"
        >
    </div>

    <div class="vk-wrap spot-hero-text">
        {{ $slot }}
    </div>

    {{-- Snowdrift edge: the hero melts into the page ground (same edge as <x-site.hero>). --}}
    <svg class="pointer-events-none absolute -bottom-[10px] left-0 z-[2] h-[60px] w-full" viewBox="0 0 1440 60" preserveAspectRatio="none" aria-hidden="true" focusable="false">
        <path d="M0,34 C 160,18 300,30 460,36 C 640,42 760,16 940,20 C 1110,24 1240,40 1440,26 L1440,60 L0,60 Z" style="fill: var(--color-ground)" />
    </svg>
</section>
