@props([
    'tone' => 'dark',
])
{{--
    Homepage hero (Paper board 6HY-0 "Hero · village hilltop", seasons FHW-0): a tall (760px)
    painted village seen from a hill, the fox on the hilltop, and the text column on calm sky
    at the left. It is not <x-site.hero>: the geometry differs (760px, the plate covers the
    whole hero, a horizontal shade behind the text plus a top shade behind the top bar, and a
    270px fox).

    `tone` must be App\Support\Season::heroTone('home-hero') and must equal the layout's
    `heroTone`, so the overlay top bar and the hero text use the same colours. The layout
    renders the top bar over the top 84px; the text column starts at 150px (board).
    The art is decorative (alt="").
--}}
@php
    use App\Support\Season;
@endphp
<section data-site-hero {{ $attributes->class(['vk-tone-'.$tone, 'home-hero relative isolate overflow-hidden bg-night']) }}>
    <img
        class="home-hero-plate"
        src="{{ Season::art('home-hero') }}"
        srcset="{{ Season::art('home-hero', 960) }} 960w, {{ Season::art('home-hero') }} 1672w"
        sizes="100vw"
        alt=""
        fetchpriority="high"
        decoding="async"
    >
    <div class="home-hero-shade" aria-hidden="true"></div>
    <div class="home-hero-top-shade" aria-hidden="true"></div>

    <div class="vk-wrap relative">
        <div class="home-hero-text">
            {{ $slot }}
        </div>
    </div>

    <img
        src="{{ Season::art('home-fox-hilltop') }}"
        alt=""
        width="270"
        height="270"
        decoding="async"
        class="home-hero-fox"
    >

    {{-- Snowdrift edge (board path): the hero melts into the page ground. --}}
    <svg class="pointer-events-none absolute -bottom-[10px] left-0 h-[70px] w-full" viewBox="0 0 1440 70" preserveAspectRatio="none" aria-hidden="true" focusable="false">
        <path d="M0,38 C 180,22 330,34 500,42 C 680,50 820,24 1000,26 C 1170,28 1290,44 1440,30 L1440,70 L0,70 Z" style="fill: var(--color-ground)" />
    </svg>
</section>
