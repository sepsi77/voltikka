@props([
    'art',
    'tone' => 'dark',
    'variant' => 'list',
])
{{--
    Painted hero of the company pages (Paper boards 6R6-0 / EBQ-0 and AX2-0 / EBR-0).

    variant
      'list'  /sahkosopimus/sahkoyhtiot: 600px village street, text column at the left. The fox
              walks in the painting itself (bottom left), below the text, never next to a
              seller.
      'band'  /sahkosopimus/sahkoyhtiot/{slug}: a 460px farmland band. The fox stands at the far
              left edge of the painting, so the text column (company name, H1, lead) sits on the
              opposite, right side (spec §5 "Seller pages"). The company facts go below the band.

    `art` is an art name from scripts/design-art/company.sh; App\Support\Season picks the
    season file. `tone` must be App\Support\Season::heroTone(<art>) and must equal the page's
    layout `heroTone`, so the overlay top bar and the hero text use the same colours.
    The art is decorative (alt="").
--}}
@php
    use App\Support\Season;
@endphp
<section data-site-hero {{ $attributes->class(['vk-tone-'.$tone, 'vk-co-hero', 'vk-co-hero--'.$variant]) }}>
    <img
        class="vk-co-hero-plate"
        src="{{ Season::art($art) }}"
        srcset="{{ Season::art($art, 960) }} 960w, {{ Season::art($art) }} 1672w"
        sizes="(min-width: 1024px) max(100vw, 1440px), 100vw"
        alt=""
        fetchpriority="high"
        decoding="async"
    >
    <div class="vk-co-hero-shade" aria-hidden="true"></div>

    <div class="vk-wrap vk-co-hero-inner">
        <div class="vk-co-hero-text">
            {{ $slot }}
        </div>
    </div>

    {{-- Snowdrift edge (Paper "Ground edge"): the hero melts into the page ground. --}}
    <svg class="vk-co-hero-edge" viewBox="0 0 1440 66" preserveAspectRatio="none" aria-hidden="true" focusable="false">
        @if ($variant === 'list')
            <path d="M0,30 C 180,44 340,22 520,28 C 700,34 820,46 1000,30 C 1160,16 1300,24 1440,34 L1440,66 L0,66 Z" style="fill: var(--color-ground)" />
        @else
            <path d="M0,34 C 160,18 300,30 460,36 C 640,42 760,16 940,20 C 1110,24 1240,40 1440,26 L1440,66 L0,66 Z" style="fill: var(--color-ground)" />
        @endif
    </svg>
</section>
