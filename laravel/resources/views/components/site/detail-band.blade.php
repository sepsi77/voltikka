@props(['tone' => 'dark'])
{{--
    Short painted hero band of the contract detail page (Paper boards 39Y-0 "Hero band" and
    EBP-0 "Seasons · Sopimus"). 360px on a laptop, a lower band on a phone.

    The band carries no text. The fox is painted far away at the right edge of the frozen lake
    (design spec §5 "Seller pages": the fox stands at the far edge of a landscape band; the
    contract name, price and seller sit below the band, on the ground). Do not put the H1, the
    price or the seller logo on this band.

    `tone` must be App\Support\Season::heroTone('contract-band') and must equal the `heroTone`
    the page passes to the layout, so the overlay top bar uses the matching shade and colours.
    The art is decorative (alt="").
--}}
@php
    use App\Support\Season;
@endphp
<section data-site-hero aria-hidden="true" {{ $attributes->class(['vk-tone-'.$tone, 'vk-detail-band']) }}>
    <img
        class="vk-detail-band-plate"
        src="{{ Season::art('contract-band') }}"
        srcset="{{ Season::art('contract-band', 960) }} 960w, {{ Season::art('contract-band') }} 1672w"
        sizes="(min-width: 1024px) 100vw, 175vw"
        alt=""
        fetchpriority="high"
        decoding="async"
    >
    <div class="vk-detail-band-shade"></div>

    {{-- Snowdrift edge: the band melts into the page ground (same path as x-site.hero). --}}
    <svg class="vk-detail-band-edge" viewBox="0 0 1440 66" preserveAspectRatio="none" focusable="false">
        <path d="M0,34 C 160,18 300,30 460,36 C 640,42 760,16 940,20 C 1110,24 1240,40 1440,26 L1440,66 L0,66 Z" style="fill: var(--color-ground)" />
    </svg>
</section>
