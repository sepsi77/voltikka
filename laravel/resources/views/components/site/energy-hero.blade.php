@props([
    'art',
    'tone' => 'dark',
    'variant' => 'solar',
])
{{--
    Painted hero of the two energy calculators (design spec §5 "Hero", §7 "Seasons"):
    /aurinkopaneelit/laskuri (Paper boards 3WH-0 and EKC-0, `variant` solar, 660px) and
    /lampopumput/laskuri (boards C1D-0 and EBU-0, `variant` heatpump, 540px).

    Why not <x-site.hero>: the fox is painted into these plates (on the cottage roof, next to the
    heat pump), the shade falls from the left to calm sky behind a wide text column, and the solar
    hero holds the address field. That field opens a suggestion list, so only the art layer clips;
    the section itself does not.

    `art` is an art name from scripts/design-art/energy.sh. `tone` must be
    App\Support\Season::heroTone(<art>) and equal the layout's `heroTone`.
    Laptop and up: the art fills the hero behind the text. Phone and tablet: the text sits on the
    tone ground and the scene is a band below it, so text never sits on busy art.
--}}
@php
    use App\Support\Season;
@endphp
<section data-site-hero {{ $attributes->class(['vk-tone-'.$tone, 'energy-hero', 'energy-hero--'.$variant]) }}>
    <div class="energy-hero-art" aria-hidden="true">
        <img
            class="energy-hero-plate"
            src="{{ Season::art($art) }}"
            srcset="{{ Season::art($art, 960) }} 960w, {{ Season::art($art) }} 1672w"
            sizes="(min-width: 1024px) max(100vw, 1440px), 760px"
            alt=""
            fetchpriority="high"
            decoding="async"
        >
        <div class="energy-hero-shade"></div>
        <div class="energy-hero-topshade"></div>
    </div>

    <div class="vk-wrap energy-hero-text">
        {{ $slot }}
    </div>

    @if ($variant === 'heatpump')
        {{-- Snowdrift edge: the hero melts into the page ground (same edge as <x-site.hero>). --}}
        <svg class="pointer-events-none absolute -bottom-[10px] left-0 z-[1] h-[60px] w-full" viewBox="0 0 1440 60" preserveAspectRatio="none" aria-hidden="true" focusable="false">
            <path d="M0,34 C 160,18 300,30 460,36 C 640,42 760,16 940,20 C 1110,24 1240,40 1440,26 L1440,60 L0,60 Z" style="fill: var(--color-ground)" />
        </svg>
    @else
        {{-- Ground blend: the meadow fades into the page ground (Paper "Ground blend"). --}}
        <div class="energy-hero-ground" aria-hidden="true"></div>
    @endif
</section>
