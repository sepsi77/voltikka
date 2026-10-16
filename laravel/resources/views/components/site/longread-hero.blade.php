@props([
    'variant',
    'tone' => 'dark',
    'plate' => null,
    'figure' => null,
    'figureWidth' => null,
    'figureHeight' => null,
    'drift' => true,
])
{{--
    Hero of the long-read pages (Paper page "Pages · group F"). One component, four variants:

    tietoa   night band with a warm lamp glow and the fox-at-desk cutout at the right (49Y-0)
    legal    the night-sky plate, a left-to-right navy shade and the sleeping fox (38P-0)
    article  an editorial header on the page ground with the fireside fox (6HX-0); the page
             passes no heroTone, so the top bar sits on its night band above it
    404      the full lost-in-the-forest plate with a radial shade behind the text (AO6-0)

    `plate` and `figure` are art names (App\Support\Season picks the season file). `tone` must
    equal the heroTone the page passes to the layout. Art is decorative (alt="").
    The default slot is the text column.
--}}
@php
    use App\Support\Season;
@endphp
<section
    @if ($variant !== 'article') data-site-hero @endif
    {{ $attributes->class(['vk-tone-'.$tone, 'vk-lr-hero', 'vk-lr-hero--'.$variant, 'relative isolate overflow-hidden']) }}
>
    @if ($plate)
        <img
            class="vk-lr-hero-plate"
            src="{{ Season::art($plate) }}"
            srcset="{{ Season::art($plate, 960) }} 960w, {{ Season::art($plate) }} 1672w"
            sizes="100vw"
            alt=""
            fetchpriority="high"
            decoding="async"
        >
    @endif
    <div class="vk-lr-hero-shade" aria-hidden="true"></div>

    <div class="vk-wrap vk-lr-hero-inner">
        <div class="vk-lr-hero-text">
            {{ $slot }}
        </div>
        @if ($figure)
            <img
                class="vk-lr-hero-figure"
                src="{{ Season::art($figure) }}"
                alt=""
                @if ($figureWidth) width="{{ $figureWidth }}" @endif
                @if ($figureHeight) height="{{ $figureHeight }}" @endif
                decoding="async"
            >
        @endif
    </div>

    @if ($drift)
        {{-- Snowdrift edge: the hero melts into the page ground. --}}
        <svg class="pointer-events-none absolute -bottom-[10px] left-0 h-[60px] w-full" viewBox="0 0 1440 60" preserveAspectRatio="none" aria-hidden="true" focusable="false">
            <path d="M0,34 C 160,18 300,30 460,36 C 640,42 760,16 940,20 C 1110,24 1240,40 1440,26 L1440,60 L0,60 Z" style="fill: var(--color-ground)" />
        </svg>
    @endif
</section>
