@php
    use App\Support\Season;

    $season = Season::current();
    $tone = Season::heroTone('map-finland');
    // Live town pins on the painted map (Paper 887-0 "Town · …"), positioned in % of the map
    // box so they scale with it. Each pin is a real link to the town's listing.
    $towns = [
        ['Oulu', 'oulu', 45.6, 45.2],
        ['Tampere', 'tampere', 40.0, 73.0],
        ['Turku', 'turku', 28.8, 84.7],
        ['Helsinki', 'helsinki', 50.0, 89.2],
    ];
@endphp
<div>
    {{-- Picture-book map hero (Paper boards 887-0 and F8J-0): a plain sky whose colour follows
         the season (night blue in autumn and winter, pale blue in spring, peach in summer), the
         painted map of Finland with live town pins, and the fox with a compass. The layout
         renders the top bar over it (heroTone). Art is decorative. --}}
    <section data-site-hero class="vk-tone-{{ $tone }} vk-seo-map-hero vk-seo-map-hero--{{ $season }} relative isolate overflow-hidden">
        @if ($tone === 'dark')
            <svg class="vk-seo-map-sparkles" viewBox="0 0 1440 780" preserveAspectRatio="xMidYMin slice" aria-hidden="true" focusable="false">
                <g style="fill: var(--tone-lead)">
                    <circle cx="120" cy="560" r="1.6" opacity=".7" /><circle cx="260" cy="640" r="1.2" opacity=".6" />
                    <circle cx="430" cy="600" r="1.8" opacity=".7" /><circle cx="610" cy="680" r="1.3" opacity=".5" />
                    <circle cx="700" cy="140" r="1.5" opacity=".6" /><circle cx="1380" cy="210" r="1.6" opacity=".7" />
                    <circle cx="1330" cy="520" r="1.2" opacity=".6" /><circle cx="760" cy="420" r="1.4" opacity=".5" />
                </g>
                <path d="M1290 120 l2 5 5 2 -5 2 -2 5 -2 -5 -5 -2 5 -2 z" style="fill: var(--mark-aurora-light)" />
                <path d="M560 520 l1.6 4 4 1.6 -4 1.6 -1.6 4 -1.6 -4 -4 -1.6 4 -1.6 z" style="fill: var(--mark-violet-light)" />
                <path d="M80 300 l1.6 4 4 1.6 -4 1.6 -1.6 4 -1.6 -4 -4 -1.6 4 -1.6 z" style="fill: var(--mark-aurora-light)" />
            </svg>
        @endif

        <div class="vk-wrap vk-seo-map-inner">
            <div class="vk-seo-map-text">
                <h1 class="vk-seo-map-h1">
                    Sähkösopimukset
                    <span class="vk-seo-map-accent">paikkakunnittain<x-ink.wave width="full" stroke="4" color="var(--tone-underline)" class="vk-seo-map-wave" /></span>
                </h1>
                <p class="vk-hero-lead vk-seo-map-lead">
                    Löydä sähkösopimukset, jotka ovat saatavilla omalla paikkakunnallasi. Valitse kunta listalta tai hae paikkakuntaa nimellä.
                </p>
            </div>

            <div class="vk-seo-map">
                <img
                    src="{{ Season::art('map-finland') }}"
                    srcset="{{ Season::art('map-finland', 600) }} 600w, {{ Season::art('map-finland') }} 1000w"
                    sizes="(min-width: 1024px) 500px, 300px"
                    alt=""
                    width="500"
                    height="666"
                    fetchpriority="high"
                    decoding="async"
                    class="vk-seo-map-img"
                >
                @foreach ($towns as [$name, $slug, $x, $y])
                    <a href="/sahkosopimus/paikkakunnat/{{ $slug }}" class="vk-seo-map-pin vk-focus" style="left: {{ $x }}%; top: {{ $y }}%">
                        <svg width="16" height="16" viewBox="0 0 16 16" aria-hidden="true" focusable="false" class="shrink-0"><circle cx="8" cy="8" r="5.2" style="fill: var(--color-fox); stroke: var(--color-ink)" stroke-width="1.6" /></svg>
                        <span>{{ $name }}</span>
                    </a>
                @endforeach
            </div>

            <img
                src="{{ Season::art('fox-compass') }}"
                alt=""
                width="220"
                height="220"
                decoding="async"
                class="vk-seo-map-fox"
            >
        </div>

        {{-- Snowdrift edge: the hero melts into the page ground. --}}
        <svg class="pointer-events-none absolute -bottom-[10px] left-0 h-[60px] w-full" viewBox="0 0 1440 60" preserveAspectRatio="none" aria-hidden="true" focusable="false">
            <path d="M0,34 C 160,18 300,30 460,36 C 640,42 760,16 940,20 C 1110,24 1240,40 1440,26 L1440,60 L0,60 Z" style="fill: var(--color-ground)" />
        </svg>
    </section>

    <x-page-action-strip />

    <div class="vk-wrap vk-seo-places">
        {{-- Search (underlined field, no box) and the count as a big number (Paper 8TV-0). --}}
        <div class="vk-seo-places-bar">
            <label class="vk-seo-search">
                <span class="sr-only">Hae paikkakuntaa</span>
                <span class="vk-seo-search-row">
                    <svg width="24" height="24" viewBox="0 0 24 24" aria-hidden="true" focusable="false" class="shrink-0">
                        <circle cx="10.4" cy="10.2" r="6.6" fill="none" style="stroke: var(--color-ink)" stroke-width="2" />
                        <path d="M15.4 15.4 L20.6 20.4" fill="none" style="stroke: var(--color-ink)" stroke-width="2.4" stroke-linecap="round" />
                    </svg>
                    <input
                        type="text"
                        wire:model.live.debounce.300ms="search"
                        data-search-input
                        placeholder="Hae paikkakuntaa..."
                        autocomplete="off"
                        class="vk-seo-search-input"
                    >
                </span>
                <svg class="vk-seo-search-line" width="100%" height="10" viewBox="0 0 460 10" preserveAspectRatio="none" aria-hidden="true" focusable="false">
                    <path d="M2 6 C 80 3, 160 8, 240 5 S 390 3, 458 6" fill="none" stroke-width="2.2" stroke-linecap="round" vector-effect="non-scaling-stroke" />
                </svg>
            </label>

            <div class="vk-seo-places-count">
                <p><span class="vk-seo-places-number">{{ $municipalities->count() }}</span> <span class="vk-seo-places-unit">paikkakuntaa</span></p>
                <p class="vk-seo-places-note">Luku kunnan nimen vieressä on sen postinumeroiden määrä.</p>
            </div>
        </div>

        <x-ink.hairline />

        @if ($municipalities->isNotEmpty())
            {{-- A–Ö index: letter groups flow down the columns (6 on a desktop). --}}
            <div class="vk-seo-towns">
                @foreach ($letterGroups as $letter => $group)
                    <section class="vk-seo-letter" aria-labelledby="kirjain-{{ $letter }}">
                        <h2 id="kirjain-{{ $letter }}" class="vk-seo-letter-head">
                            {{ $letter }}
                            <svg width="34" height="8" viewBox="0 0 34 8" aria-hidden="true" focusable="false" class="block"><path d="M2 5 C 10 2, 20 7, 32 4" fill="none" style="stroke: var(--color-fox)" stroke-width="2.4" stroke-linecap="round" /></svg>
                        </h2>
                        <ul class="vk-seo-letter-list">
                            @foreach ($group as $municipality)
                                <li>
                                    <a href="/sahkosopimus/paikkakunnat/{{ $municipality->municipal_name_fi_slug }}" class="vk-seo-town vk-focus">
                                        <span class="vk-seo-town-name">{{ $municipality->municipal_name_fi }}</span>
                                        <span class="vk-seo-town-count">{{ $municipality->postcode_count }}<span class="sr-only"> postinumeroa</span></span>
                                    </a>
                                </li>
                            @endforeach
                        </ul>
                    </section>
                @endforeach
            </div>
        @else
            <p class="vk-seo-places-empty">Ei paikkakuntia löytynyt hakusanalla "{{ $search }}"</p>
        @endif

        {{-- Villager interlude (ski trip) between the index and the footer. --}}
        <x-site.interlude art="interlude-skitrip" class="pt-5" />
    </div>
</div>
