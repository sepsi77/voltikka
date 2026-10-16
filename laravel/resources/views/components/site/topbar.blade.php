@props([
    'tone' => null,
])
{{--
    Site top bar (Paper: "/sahkosopimus v4" board, "Top bar"; light variant in "Village hero · Kesä").

    `tone` is the page's `heroTone` ('dark' | 'light'). With a tone, the bar is transparent and
    sits absolutely over the top of the painted hero. Without a tone (pages that are not yet
    redesigned), the same bar sits in the page flow on a night band with the dark tone.

    Keep: the ids site-desktop-nav / site-mobile-nav (the nav-pending script in the layout reads
    them), both header spot price shells (the loader fills every [data-header-spot-price]), and
    the dropdown panels that physically touch their trigger (laravel/AGENTS.md).
--}}
@php
    $overlay = in_array($tone, ['dark', 'light'], true);
    $toneClass = 'vk-tone-'.($overlay ? $tone : 'dark');

    $isDataNavActive = request()->is('sahkosopimus/tilastot')
        || request()->is('sahkosopimus/sahkon-hintaennuste')
        || request()->is('sahkosopimus/kannattaako-porssisahko')
        || request()->is('sahkosopimus/kannattaako-maaraaikainen')
        || request()->is('maksatko-liikaa')
        || request()->is('spot-price');
    // The consumption calculator lives under /sahkosopimus but has its own top-level item.
    $isContractsNavActive = request()->is('sahkosopimus*') && ! $isDataNavActive && ! request()->is('sahkosopimus/laskuri');

    $contractLinks = [
        ['label' => 'Vertaa sopimuksia', 'href' => '/sahkosopimus', 'active' => request()->is('sahkosopimus') && ! request()->is('sahkosopimus/*')],
        ['label' => 'Halvimmat sopimukset', 'href' => '/sahkosopimus/halvin-sahkosopimus', 'active' => request()->is('sahkosopimus/halvin-sahkosopimus')],
        ['label' => 'Tarjoukset', 'href' => '/sahkosopimus/sahkotarjous', 'active' => request()->is('sahkosopimus/sahkotarjous')],
        ['label' => 'Pörssisähkö', 'href' => '/sahkosopimus/porssisahko', 'active' => request()->is('sahkosopimus/porssisahko')],
        ['label' => 'Määräaikainen', 'href' => '/sahkosopimus/maaraaikainen', 'active' => request()->is('sahkosopimus/maaraaikainen')],
        ['label' => 'Toistaiseksi voimassa', 'href' => '/sahkosopimus/toistaiseksi', 'active' => request()->is('sahkosopimus/toistaiseksi')],
        ['label' => 'Kvartaalisähkö', 'href' => '/sahkosopimus/kvartaalisahko', 'active' => request()->is('sahkosopimus/kvartaalisahko')],
        ['label' => 'Joustosähkö', 'href' => '/sahkosopimus/joustosahko', 'active' => request()->is('sahkosopimus/joustosahko')],
        ['label' => 'Yleissähkö', 'href' => '/sahkosopimus/yleissahko', 'active' => request()->is('sahkosopimus/yleissahko')],
        ['label' => 'Yrityksille', 'href' => '/sahkosopimus/yritykselle', 'active' => request()->is('sahkosopimus/yritykselle')],
        ['label' => 'Sähköyhtiöt', 'href' => '/sahkosopimus/sahkoyhtiot', 'active' => request()->is('sahkosopimus/sahkoyhtiot*')],
    ];

    $dataLinks = [
        ['label' => 'Maksatko liikaa?', 'href' => '/maksatko-liikaa', 'active' => request()->is('maksatko-liikaa')],
        ['label' => 'Sähkösopimusten hintakehitys', 'href' => '/sahkosopimus/tilastot', 'active' => request()->is('sahkosopimus/tilastot')],
        ['label' => 'Sähkön hintaennuste', 'href' => '/sahkosopimus/sahkon-hintaennuste', 'active' => request()->is('sahkosopimus/sahkon-hintaennuste')],
        ['label' => 'Kannattaako pörssisähkö?', 'href' => '/sahkosopimus/kannattaako-porssisahko', 'active' => request()->is('sahkosopimus/kannattaako-porssisahko')],
        ['label' => 'Kannattaako määräaikainen?', 'href' => '/sahkosopimus/kannattaako-maaraaikainen', 'active' => request()->is('sahkosopimus/kannattaako-maaraaikainen')],
        ['label' => 'Pörssisähkön hinta', 'href' => '/spot-price', 'active' => request()->is('spot-price')],
    ];

    $menus = [
        ['label' => 'Sähkösopimukset', 'href' => '/sahkosopimus', 'active' => $isContractsNavActive, 'links' => $contractLinks],
        ['label' => 'Sähködata', 'href' => '/sahkosopimus/tilastot', 'active' => $isDataNavActive, 'links' => $dataLinks],
    ];

    $plainLinks = [
        ['label' => 'Arvioi sähkönkulutus', 'href' => route('calculator'), 'active' => request()->is('sahkosopimus/laskuri')],
        ['label' => 'Aurinkopaneelit', 'href' => '/aurinkopaneelit', 'active' => request()->is('aurinkopaneelit*')],
        ['label' => 'Lämpöpumput', 'href' => '/lampopumput', 'active' => request()->is('lampopumput*')],
    ];
@endphp
<header
    data-site-topbar
    data-topbar-mode="{{ $overlay ? 'overlay' : 'band' }}"
    x-data="{ mobileMenuOpen: false }"
    x-on:keydown.escape.window="mobileMenuOpen = false"
    @class([
        'vk-topbar',
        $toneClass,
        'absolute inset-x-0 top-0 z-40' => $overlay,
        'relative z-40 bg-night' => ! $overlay,
    ])
>
    <div class="vk-wrap flex h-16 items-center justify-between gap-4 lg:h-[84px] xl:gap-6">
        {{-- Logo: fox mark + wordmark (the mark is a placeholder, spec §9 item 4). --}}
        <a href="/" class="vk-topbar-logo vk-focus flex shrink-0 items-center gap-2 lg:gap-2.5">
            <svg class="h-7 w-7 lg:h-[34px] lg:w-[34px]" viewBox="0 0 34 34" aria-hidden="true" focusable="false">
                <path d="M5 29 C 6 16, 14 7, 27 5 C 24 12, 22 20, 13 26 C 10 28, 7 29, 5 29 Z" style="fill: var(--color-fox)" />
                <path d="M27 5 C 25.5 9, 24.5 12, 22.5 15 C 21 12.5, 22.5 8, 27 5 Z" style="fill: var(--color-snow-white)" />
                <path d="M29.5 11.5 l1 2.2 2.2 1 -2.2 1 -1 2.2 -1 -2.2 -2.2 -1 2.2 -1 z" style="fill: var(--mark-aurora-light)" />
                <circle cx="31" cy="4" r="1.6" style="fill: var(--mark-violet-light)" />
            </svg>
            <span class="vk-topbar-wordmark">Voltikka</span>
        </a>

        {{-- Desktop navigation --}}
        <nav class="hidden items-center gap-4 lg:flex xl:gap-7" id="site-desktop-nav" aria-label="Päävalikko">
            @foreach ($menus as $menu)
                <div
                    class="relative"
                    x-data="{ open: false }"
                    @mouseenter="open = true"
                    @mouseleave="open = false"
                    @focusin="open = true"
                    @focusout="$el.contains($event.relatedTarget) || (open = false)"
                    @keydown.escape="open = false"
                >
                    <a href="{{ $menu['href'] }}" @class(['vk-nav-item vk-focus inline-flex items-center gap-1.5', 'is-active' => $menu['active']])>
                        {{ $menu['label'] }}
                        <svg class="vk-nav-chevron h-2.5 w-2.5 transition-transform" :class="{ 'rotate-180': open }" viewBox="0 0 10 10" aria-hidden="true" focusable="false">
                            <path d="M1.5 3.5 C 3 5, 4 6, 5 7 C 6 6, 7 5, 8.5 3.5" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" />
                        </svg>
                    </a>
                    {{-- The transparent top padding keeps the panel touching the trigger. --}}
                    <div x-show="open" x-transition.opacity.duration.100ms class="vk-nav-panel absolute left-0 top-full z-50" style="display: none;">
                        <div class="vk-nav-panel-body">
                            @foreach ($menu['links'] as $link)
                                <a href="{{ $link['href'] }}" @class(['vk-nav-panel-link vk-focus', 'is-active' => $link['active']]) @if ($link['active']) aria-current="page" @endif>
                                    {{ $link['label'] }}
                                </a>
                            @endforeach
                        </div>
                    </div>
                </div>
            @endforeach
            @foreach ($plainLinks as $link)
                <a href="{{ $link['href'] }}" @class(['vk-nav-item vk-focus', 'is-active' => $link['active']]) @if ($link['active']) aria-current="page" @endif>
                    {{ $link['label'] }}
                </a>
            @endforeach
        </nav>

        {{-- Spot price (desktop) --}}
        <div class="hidden shrink-0 lg:block">
            <x-header-spot-price-shell />
        </div>

        {{-- Spot price + menu button (mobile) --}}
        <div class="flex min-w-0 items-center gap-1 lg:hidden">
            <x-header-spot-price-shell />
            <button
                @click="mobileMenuOpen = !mobileMenuOpen"
                type="button"
                class="vk-topbar-menu-button vk-focus"
                aria-controls="mobile-menu"
                :aria-expanded="mobileMenuOpen"
            >
                <span class="sr-only">Avaa valikko</span>
                <svg x-show="!mobileMenuOpen" class="h-6 w-6" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                    <path d="M3.5 6.5 C 9 5.8, 15 7, 20.5 6.2 M3.5 12.2 C 9 11.6, 15 12.8, 20.5 12 M3.5 17.8 C 9 17.2, 15 18.3, 20.5 17.6" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" />
                </svg>
                <svg x-show="mobileMenuOpen" class="h-6 w-6" viewBox="0 0 24 24" aria-hidden="true" focusable="false" style="display: none;">
                    <path d="M5.5 5.5 C 10 10.2, 14 13.8, 18.5 18.5 M18.5 5.5 C 14 10, 10 14, 5.5 18.5" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" />
                </svg>
            </button>
        </div>
    </div>

    {{-- Mobile menu: a night panel that opens over the hero or the page. --}}
    <div
        x-show="mobileMenuOpen"
        x-transition.opacity.duration.100ms
        class="vk-mobile-menu vk-tone-dark absolute inset-x-0 top-full z-50 lg:hidden"
        id="mobile-menu"
        style="display: none;"
    >
        <div class="vk-wrap py-3" id="site-mobile-nav">
            @foreach ($menus as $menu)
                <div class="vk-mobile-menu-row" x-data="{ expanded: {{ $menu['active'] ? 'true' : 'false' }} }">
                    <button @click="expanded = !expanded" type="button" :aria-expanded="expanded" @class(['vk-mobile-menu-item vk-focus flex w-full items-center justify-between', 'is-active' => $menu['active']])>
                        <span>{{ $menu['label'] }}</span>
                        <svg class="h-3 w-3 transition-transform" :class="{ 'rotate-180': expanded }" viewBox="0 0 10 10" aria-hidden="true" focusable="false">
                            <path d="M1.5 3.5 C 3 5, 4 6, 5 7 C 6 6, 7 5, 8.5 3.5" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" />
                        </svg>
                    </button>
                    <div x-show="expanded" class="flex flex-col pb-2 pl-4">
                        @foreach ($menu['links'] as $link)
                            <a href="{{ $link['href'] }}" @class(['vk-mobile-menu-link vk-focus', 'is-active' => $link['active']]) @if ($link['active']) aria-current="page" @endif>
                                {{ $link['label'] }}
                            </a>
                        @endforeach
                    </div>
                </div>
            @endforeach
            @foreach ($plainLinks as $link)
                <div class="vk-mobile-menu-row">
                    <a href="{{ $link['href'] }}" @class(['vk-mobile-menu-item vk-focus block', 'is-active' => $link['active']]) @if ($link['active']) aria-current="page" @endif>
                        {{ $link['label'] }}
                    </a>
                </div>
            @endforeach
        </div>
    </div>
</header>
