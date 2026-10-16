@props([
    'art',
    'tone' => 'dark',
    'variant' => 'stats',
])
{{--
    Painted hero of the data pages (Paper boards 6MP-0 "Observatory hero" and D8Q-0 "Weather
    station hero", season boards FL5-0 and G4L-0): /sahkosopimus/tilastot and
    /sahkosopimus/sahkon-hintaennuste.

    Why not <x-site.hero>: these heroes are 700px high, the plate is the full scene (the fox is
    painted into it, at the right), the text column carries a facts row and inked links, and the
    page's key figure sits on the sky at the right (`aside` slot). The tone variables
    (.vk-tone-*), .vk-hero-h1 and .vk-hero-lead are shared.

    `art` is an art name from scripts/design-art/data.sh. `tone` must be
    App\Support\Season::heroTone(<art>) and equal the layout's `heroTone`. `variant` is
    'stats' or 'forecast' (plate crop and column widths of each board).
    Laptop and up: the art fills the hero behind the text, with a side shade. Phone and tablet:
    the text sits on the tone ground and the scene is a band below it, so text never sits on
    busy art. The art is decorative (alt="").
--}}
@php
    use App\Support\Season;
@endphp
<section data-site-hero {{ $attributes->class(['vk-tone-'.$tone, 'data-hero', 'data-hero--'.$variant]) }}>
    <div class="data-hero-art" aria-hidden="true">
        <img
            class="data-hero-plate"
            src="{{ Season::art($art) }}"
            srcset="{{ Season::art($art, 960) }} 960w, {{ Season::art($art) }} 1672w"
            sizes="(min-width: 1024px) max(100vw, 1440px), 640px"
            alt=""
            fetchpriority="high"
            decoding="async"
        >
        <div class="data-hero-shade"></div>
        <div class="data-hero-blend"></div>
    </div>

    <div class="vk-wrap data-hero-body">
        <div class="data-hero-text">
            {{ $slot }}
        </div>
        @isset($aside)
            <div class="data-hero-aside">
                {{ $aside }}
            </div>
        @endisset
    </div>
</section>
