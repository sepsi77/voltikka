<!DOCTYPE html>
<html lang="fi">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title>{{ $title ?? 'Voltikka - Sähkösopimusten vertailu' }}</title>
        @if (isset($metaDescription))
        <meta name="description" content="{{ $metaDescription }}">
        @endif
        @if (!empty($robots))
        <meta name="robots" content="{{ $robots }}">
        @endif

        <!-- Favicon -->
        <link rel="icon" type="image/svg+xml" href="/favicon.svg">
        <link rel="icon" type="image/x-icon" href="/favicon.ico">

        {{-- Canonical URL --}}
        @if (isset($canonical))
        <link rel="canonical" href="{{ $canonical }}">
        @else
        <link rel="canonical" href="{{ url()->current() }}">
        @endif

        {{-- Pagination SEO links (rel="prev" and rel="next") --}}
        @if (isset($prevUrl))
        <link rel="prev" href="{{ $prevUrl }}">
        @endif
        @if (isset($nextUrl))
        <link rel="next" href="{{ $nextUrl }}">
        @endif

        {{-- Open Graph Tags --}}
        <meta property="og:type" content="website">
        <meta property="og:locale" content="fi_FI">
        <meta property="og:site_name" content="Voltikka">
        <meta property="og:title" content="{{ $ogTitle ?? $title ?? 'Voltikka - Sähkösopimusten vertailu' }}">
        @if (isset($ogDescription) || isset($metaDescription))
        <meta property="og:description" content="{{ $ogDescription ?? $metaDescription }}">
        @endif
        <meta property="og:url" content="{{ url()->current() }}">

        <!-- Preconnect to required origins for performance -->
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>

        <!-- Preload critical assets -->
        @if (file_exists(public_path('build/manifest.json')))
            @php
                $manifest = json_decode(file_get_contents(public_path('build/manifest.json')), true);
                $cssFile = $manifest['resources/css/app.css']['file'] ?? null;
                $jsFile = $manifest['resources/js/app.js']['file'] ?? null;
            @endphp
            @if ($cssFile)
                <link rel="preload" href="{{ asset('build/' . $cssFile) }}" as="style">
            @endif
            @if ($jsFile)
                <link rel="preload" href="{{ asset('build/' . $jsFile) }}" as="script">
            @endif
        @endif
        <link rel="preload" href="/vendor/livewire/livewire.min.js" as="script">

        <!-- Fonts - Plus Jakarta Sans (Fresh Coral design system) -->
        <!-- Load fonts asynchronously to avoid render-blocking -->
        <link rel="preload" href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" as="style" onload="this.onload=null;this.rel='stylesheet'">
        <noscript><link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap"></noscript>

        <!-- Styles / Scripts -->
        @if (file_exists(public_path('build/manifest.json')))
            @vite(['resources/css/app.css', 'resources/js/app.js'])
        @else
            <script src="https://cdn.tailwindcss.com"></script>
            <script>
                tailwind.config = {
                    theme: {
                        extend: {
                            fontFamily: {
                                sans: ['Plus Jakarta Sans', 'ui-sans-serif', 'system-ui', 'sans-serif'],
                            },
                            colors: {
                                // Fresh Coral design system colors
                                coral: {
                                    50: '#fff7ed', 100: '#ffedd5', 200: '#fed7aa', 300: '#fdba74',
                                    400: '#fb923c', 500: '#f97316', 600: '#ea580c', 700: '#c2410c',
                                    800: '#9a3412', 900: '#7c2d12', 950: '#431407', DEFAULT: '#f97316',
                                },
                                slate: {
                                    50: '#f8fafc', 100: '#f1f5f9', 200: '#e2e8f0', 300: '#cbd5e1',
                                    400: '#94a3b8', 500: '#64748b', 600: '#475569', 700: '#334155',
                                    800: '#1e293b', 900: '#0f172a', 950: '#0f1419', DEFAULT: '#64748b',
                                },
                                emissions: {
                                    low: '#22c55e', medium: '#f59e0b', high: '#ef4444', zero: '#22c55e',
                                },
                                // Legacy colors (kept for backwards compatibility)
                                primary: {
                                    50: '#e4feff', 100: '#dbfdff', 200: '#d2fdff', 300: '#b7fbff',
                                    400: '#80f8ff', 500: '#4AF5FF', 600: '#43dde6', 700: '#38b8bf',
                                    800: '#2c9399', 900: '#24787d', DEFAULT: '#4AF5FF',
                                },
                                secondary: {
                                    50: '#ecffd9', 100: '#e6ffcc', 200: '#dfffbf', 300: '#ccff99',
                                    400: '#a6ff4d', 500: '#80FF00', 600: '#73e600', 700: '#60bf00',
                                    800: '#4d9900', 900: '#3f7d00', DEFAULT: '#80FF00',
                                },
                                tertiary: {
                                    50: '#dce1e4', 100: '#d0d7db', 200: '#c5cdd3', 300: '#a1aeb8',
                                    400: '#5b7282', 500: '#15354D', 600: '#133045', 700: '#10283a',
                                    800: '#0d202e', 900: '#0a1a26', DEFAULT: '#15354D',
                                },
                                success: {
                                    50: '#edf7dc', 100: '#e6f5d0', 200: '#e0f2c5', 300: '#ceeba2',
                                    400: '#a9db5c', 500: '#84cc16', 600: '#77b814', 700: '#639911',
                                    800: '#4f7a0d', 900: '#41640b', DEFAULT: '#84cc16',
                                },
                                warning: {
                                    50: '#fcf4da', 100: '#fbf0ce', 200: '#faecc1', 300: '#f7e19c',
                                    400: '#f0ca52', 500: '#EAB308', 600: '#d3a107', 700: '#b08606',
                                    800: '#8c6b05', 900: '#735804', DEFAULT: '#EAB308',
                                },
                                error: {
                                    50: '#ffe4e4', 100: '#ffdbdb', 200: '#ffd2d2', 300: '#ffb7b7',
                                    400: '#ff8080', 500: '#FF4A4A', 600: '#e64343', 700: '#bf3838',
                                    800: '#992c2c', 900: '#7d2424', DEFAULT: '#FF4A4A',
                                },
                                surface: {
                                    50: '#fefefe', 100: '#fdfefe', 200: '#fdfdfd', 300: '#fbfcfc',
                                    400: '#f9fafa', 500: '#F6F8F8', 600: '#dddfdf', 700: '#b9baba',
                                    800: '#949595', 900: '#797a7a', DEFAULT: '#F6F8F8',
                                },
                            },
                            boxShadow: {
                                'coral': '0 10px 30px -10px rgb(249 115 22 / 0.3)',
                                'coral-lg': '0 20px 40px -15px rgb(249 115 22 / 0.4)',
                                'card-hover': '0 12px 40px -12px rgb(0 0 0 / 0.15)',
                            },
                        },
                    },
                }
            </script>
        @endif

        <!-- Privacy-friendly analytics by Plausible -->
        <script async src="https://plausible.io/js/pa-pDXhgmi5eZqoIa4scLqJt.js"></script>
        <script>
          window.plausible=window.plausible||function(){(plausible.q=plausible.q||[]).push(arguments)},plausible.init=plausible.init||function(i){plausible.o=i||{}};
          plausible.init()
        </script>


        @livewireStyles
    </head>
    <body
        x-data="{ pageNavigating: false }"
        x-on:page-navigation-start.window="pageNavigating = true"
        x-on:page-navigation-end.window="pageNavigating = false"
        x-on:pageshow.window="pageNavigating = false"
        :class="pageNavigating ? 'cursor-progress' : ''"
        class="font-sans antialiased bg-slate-50 min-h-screen flex flex-col"
    >
        {{-- Global Livewire Loading Indicator --}}
        <div
            x-data="{ show: false, timeout: null }"
            x-on:livewire:request-started.window="clearTimeout(timeout); timeout = setTimeout(() => show = true, 150)"
            x-on:livewire:request-finished.window="clearTimeout(timeout); show = false"
            x-on:page-navigation-end.window="clearTimeout(timeout); show = false"
            x-on:hashchange.window="clearTimeout(timeout); show = false"
            x-on:pageshow.window="clearTimeout(timeout); show = false"
            x-show="show"
            x-transition:enter="transition ease-out duration-150"
            x-transition:enter-start="opacity-0"
            x-transition:enter-end="opacity-100"
            x-transition:leave="transition ease-in duration-100"
            x-transition:leave-start="opacity-100"
            x-transition:leave-end="opacity-0"
            class="fixed top-0 left-0 right-0 z-50"
            style="display: none;"
        >
            <div class="h-1 bg-coral-500 animate-pulse"></div>
        </div>

        {{-- Immediate page navigation feedback for normal link clicks --}}
        <div
            x-show="pageNavigating"
            x-transition:enter="transition ease-out duration-150"
            x-transition:enter-start="opacity-0"
            x-transition:enter-end="opacity-100"
            x-transition:leave="transition ease-in duration-100"
            x-transition:leave-start="opacity-100"
            x-transition:leave-end="opacity-0"
            class="fixed top-0 left-0 right-0 z-[60] pointer-events-none"
            style="display: none;"
            aria-live="polite"
            aria-label="Ladataan sivua"
        >
            <div class="h-1 bg-gradient-to-r from-coral-500 via-coral-400 to-coral-500 animate-pulse"></div>
            <span style="position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0;">Ladataan sivua…</span>
        </div>

        <header class="bg-white border-b border-slate-200" x-data="{ mobileMenuOpen: false }">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                <div class="flex justify-between items-center h-16">
                    <!-- Logo -->
                    <div class="flex items-center">
                        <a href="/" class="flex items-center gap-2.5">
                            <div class="w-9 h-9 bg-gradient-to-br from-coral-500 to-coral-600 rounded-xl flex items-center justify-center shadow-lg shadow-coral-500/20">
                                <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z"/>
                                </svg>
                            </div>
                            <span class="text-xl font-extrabold text-slate-900">Voltikka</span>
                        </a>
                    </div>

                    <!-- Desktop Navigation -->
                    <nav class="hidden lg:flex items-center space-x-1" id="site-desktop-nav">
                        @php
                            $isDataNavActive = request()->is('sahkosopimus/tilastot')
                                || request()->is('sahkosopimus/sahkon-hintaennuste')
                                || request()->is('sahkosopimus/kannattaako-porssisahko')
                                || request()->is('sahkosopimus/kannattaako-maaraaikainen')
                                || request()->is('spot-price');
                            $isContractsNavActive = request()->is('sahkosopimus*') && ! $isDataNavActive;
                        @endphp
                        {{-- Sähkösopimukset dropdown --}}
                        <div class="relative" x-data="{ open: false }" @mouseenter="open = true" @mouseleave="open = false">
                            <a href="/sahkosopimus" class="px-4 py-2 rounded-lg text-slate-500 hover:text-slate-900 font-medium transition-colors inline-flex items-center gap-1 {{ $isContractsNavActive ? 'bg-slate-100 text-slate-900 font-semibold' : '' }}">
                                Sähkösopimukset
                                <svg class="w-4 h-4 transition-transform" :class="{ 'rotate-180': open }" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                            </a>
                            <div x-show="open" x-transition:enter="transition ease-out duration-100" x-transition:enter-start="transform opacity-0 scale-95" x-transition:enter-end="transform opacity-100 scale-100" x-transition:leave="transition ease-in duration-75" x-transition:leave-start="transform opacity-100 scale-100" x-transition:leave-end="transform opacity-0 scale-95" class="absolute left-0 mt-1 w-48 bg-white rounded-lg shadow-lg border border-slate-200 py-1 z-50" style="display: none;">
                                <a href="/sahkosopimus" class="block px-4 py-2 text-sm text-slate-700 hover:bg-slate-50 {{ request()->is('sahkosopimus') && !request()->is('sahkosopimus/*') ? 'bg-slate-100 font-semibold' : '' }}">Vertaa sopimuksia</a>
                                <a href="/sahkosopimus/halvin-sahkosopimus" class="block px-4 py-2 text-sm text-slate-700 hover:bg-slate-50 {{ request()->is('sahkosopimus/halvin-sahkosopimus') ? 'bg-slate-100 font-semibold' : '' }}">Halvimmat sopimukset</a>
                                <a href="/sahkosopimus/sahkotarjous" class="block px-4 py-2 text-sm text-slate-700 hover:bg-slate-50 {{ request()->is('sahkosopimus/sahkotarjous') ? 'bg-slate-100 font-semibold' : '' }}">Tarjoukset</a>
                                <a href="/sahkosopimus/porssisahko" class="block px-4 py-2 text-sm text-slate-700 hover:bg-slate-50 {{ request()->is('sahkosopimus/porssisahko') ? 'bg-slate-100 font-semibold' : '' }}">Pörssisähkö</a>
                                <a href="/sahkosopimus/kvartaalisahko" class="block px-4 py-2 text-sm text-slate-700 hover:bg-slate-50 {{ request()->is('sahkosopimus/kvartaalisahko') ? 'bg-slate-100 font-semibold' : '' }}">Kvartaalisähkö</a>
                                <a href="/sahkosopimus/joustosahko" class="block px-4 py-2 text-sm text-slate-700 hover:bg-slate-50 {{ request()->is('sahkosopimus/joustosahko') ? 'bg-slate-100 font-semibold' : '' }}">Joustosähkö</a>
                                <a href="/sahkosopimus/yleissahko" class="block px-4 py-2 text-sm text-slate-700 hover:bg-slate-50 {{ request()->is('sahkosopimus/yleissahko') ? 'bg-slate-100 font-semibold' : '' }}">Yleissähkö</a>
                                <a href="/sahkosopimus/yritykselle" class="block px-4 py-2 text-sm text-slate-700 hover:bg-slate-50 {{ request()->is('sahkosopimus/yritykselle') ? 'bg-slate-100 font-semibold' : '' }}">Yrityksille</a>
                                <a href="/sahkosopimus/sahkoyhtiot" class="block px-4 py-2 text-sm text-slate-700 hover:bg-slate-50 {{ request()->is('sahkosopimus/sahkoyhtiot*') ? 'bg-slate-100 font-semibold' : '' }}">Sähköyhtiöt</a>
                            </div>
                        </div>
                        {{-- Data ja selvitykset dropdown --}}
                        <div class="relative" x-data="{ open: false }" @mouseenter="open = true" @mouseleave="open = false">
                            <a href="/sahkosopimus/tilastot" class="px-4 py-2 rounded-lg text-slate-500 hover:text-slate-900 font-medium transition-colors inline-flex items-center gap-1 {{ $isDataNavActive ? 'bg-slate-100 text-slate-900 font-semibold' : '' }}">
                                Sähködata
                                <svg class="w-4 h-4 transition-transform" :class="{ 'rotate-180': open }" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                            </a>
                            <div x-show="open" x-transition:enter="transition ease-out duration-100" x-transition:enter-start="transform opacity-0 scale-95" x-transition:enter-end="transform opacity-100 scale-100" x-transition:leave="transition ease-in duration-75" x-transition:leave-start="transform opacity-100 scale-100" x-transition:leave-end="transform opacity-0 scale-95" class="absolute left-0 mt-1 w-64 bg-white rounded-lg shadow-lg border border-slate-200 py-1 z-50" style="display: none;">
                                <a href="/sahkosopimus/tilastot" class="block px-4 py-2 text-sm text-slate-700 hover:bg-slate-50 {{ request()->is('sahkosopimus/tilastot') ? 'bg-slate-100 font-semibold' : '' }}">Sähkösopimusten hintakehitys</a>
                                <a href="/sahkosopimus/sahkon-hintaennuste" class="block px-4 py-2 text-sm text-slate-700 hover:bg-slate-50 {{ request()->is('sahkosopimus/sahkon-hintaennuste') ? 'bg-slate-100 font-semibold' : '' }}">Sähkön hintaennuste</a>
                                <a href="/sahkosopimus/kannattaako-porssisahko" class="block px-4 py-2 text-sm text-slate-700 hover:bg-slate-50 {{ request()->is('sahkosopimus/kannattaako-porssisahko') ? 'bg-slate-100 font-semibold' : '' }}">Kannattaako pörssisähkö?</a>
                                <a href="/sahkosopimus/kannattaako-maaraaikainen" class="block px-4 py-2 text-sm text-slate-700 hover:bg-slate-50 {{ request()->is('sahkosopimus/kannattaako-maaraaikainen') ? 'bg-slate-100 font-semibold' : '' }}">Kannattaako määräaikainen?</a>
                                <a href="/spot-price" class="block px-4 py-2 text-sm text-slate-700 hover:bg-slate-50 {{ request()->is('spot-price') ? 'bg-slate-100 font-semibold' : '' }}">Pörssisähkön hinta</a>
                            </div>
                        </div>
                        <a href="{{ route('calculator') }}" class="px-4 py-2 rounded-lg text-slate-500 hover:text-slate-900 font-medium transition-colors {{ request()->is('sahkosopimus/laskuri') ? 'bg-slate-100 text-slate-900 font-semibold' : '' }}">
                            Arvioi sähkönkulutus
                        </a>
                        <a href="/aurinkopaneelit" class="px-4 py-2 rounded-lg text-slate-500 hover:text-slate-900 font-medium transition-colors {{ request()->is('aurinkopaneelit*') ? 'bg-slate-100 text-slate-900 font-semibold' : '' }}">
                            Aurinkopaneelit
                        </a>
                        <a href="/lampopumput" class="px-4 py-2 rounded-lg text-slate-500 hover:text-slate-900 font-medium transition-colors {{ request()->is('lampopumput*') ? 'bg-slate-100 text-slate-900 font-semibold' : '' }}">
                            Lämpöpumput
                        </a>
                    </nav>

                    <!-- Spot Price Badge (Desktop) -->
                    <div class="hidden lg:block">
                        <x-header-spot-price-shell />
                    </div>

                    <!-- Spot Price Badge + Mobile menu button -->
                    <div class="lg:hidden flex items-center gap-2">
                        <x-header-spot-price-shell />
                        <button
                            @click="mobileMenuOpen = !mobileMenuOpen"
                            type="button"
                            class="inline-flex items-center justify-center p-2 rounded-lg text-slate-500 hover:text-slate-900 hover:bg-slate-100 focus:outline-none focus:ring-2 focus:ring-inset focus:ring-coral-500 transition-colors"
                            aria-controls="mobile-menu"
                            :aria-expanded="mobileMenuOpen"
                        >
                            <span class="sr-only">Avaa valikko</span>
                            <!-- Hamburger icon -->
                            <svg x-show="!mobileMenuOpen" class="h-6 w-6" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
                            </svg>
                            <!-- Close icon -->
                            <svg x-show="mobileMenuOpen" class="h-6 w-6" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true" style="display: none;">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                            </svg>
                        </button>
                    </div>
                </div>
            </div>

            <!-- Mobile menu -->
            <div x-show="mobileMenuOpen" x-transition:enter="transition ease-out duration-100" x-transition:enter-start="transform opacity-0 scale-95" x-transition:enter-end="transform opacity-100 scale-100" x-transition:leave="transition ease-in duration-75" x-transition:leave-start="transform opacity-100 scale-100" x-transition:leave-end="transform opacity-0 scale-95" class="lg:hidden" id="mobile-menu" style="display: none;">
                <div class="px-2 pt-2 pb-3 space-y-1 bg-white border-t border-slate-200" id="site-mobile-nav">
                    {{-- Sähkösopimukset collapsible section --}}
                    <div x-data="{ expanded: {{ $isContractsNavActive ? 'true' : 'false' }} }">
                        <button @click="expanded = !expanded" class="w-full flex items-center justify-between px-3 py-2 rounded-lg text-base font-medium text-slate-500 hover:text-slate-900 hover:bg-slate-50 {{ $isContractsNavActive ? 'bg-slate-100 text-slate-900 font-semibold' : '' }}">
                            <span>Sähkösopimukset</span>
                            <svg class="w-4 h-4 transition-transform" :class="{ 'rotate-180': expanded }" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                        </button>
                        <div x-show="expanded" x-transition class="pl-4 space-y-1 mt-1">
                            <a href="/sahkosopimus" class="block px-3 py-2 rounded-lg text-sm text-slate-500 hover:text-slate-900 hover:bg-slate-50 {{ request()->is('sahkosopimus') && !request()->is('sahkosopimus/*') ? 'bg-slate-100 text-slate-900 font-semibold' : '' }}">
                                Vertaa sopimuksia
                            </a>
                            <a href="/sahkosopimus/halvin-sahkosopimus" class="block px-3 py-2 rounded-lg text-sm text-slate-500 hover:text-slate-900 hover:bg-slate-50 {{ request()->is('sahkosopimus/halvin-sahkosopimus') ? 'bg-slate-100 text-slate-900 font-semibold' : '' }}">
                                Halvimmat sopimukset
                            </a>
                            <a href="/sahkosopimus/sahkotarjous" class="block px-3 py-2 rounded-lg text-sm text-slate-500 hover:text-slate-900 hover:bg-slate-50 {{ request()->is('sahkosopimus/sahkotarjous') ? 'bg-slate-100 text-slate-900 font-semibold' : '' }}">
                                Tarjoukset
                            </a>
                            <a href="/sahkosopimus/porssisahko" class="block px-3 py-2 rounded-lg text-sm text-slate-500 hover:text-slate-900 hover:bg-slate-50 {{ request()->is('sahkosopimus/porssisahko') ? 'bg-slate-100 text-slate-900 font-semibold' : '' }}">
                                Pörssisähkö
                            </a>
                            <a href="/sahkosopimus/kvartaalisahko" class="block px-3 py-2 rounded-lg text-sm text-slate-500 hover:text-slate-900 hover:bg-slate-50 {{ request()->is('sahkosopimus/kvartaalisahko') ? 'bg-slate-100 text-slate-900 font-semibold' : '' }}">
                                Kvartaalisähkö
                            </a>
                            <a href="/sahkosopimus/joustosahko" class="block px-3 py-2 rounded-lg text-sm text-slate-500 hover:text-slate-900 hover:bg-slate-50 {{ request()->is('sahkosopimus/joustosahko') ? 'bg-slate-100 text-slate-900 font-semibold' : '' }}">
                                Joustosähkö
                            </a>
                            <a href="/sahkosopimus/yleissahko" class="block px-3 py-2 rounded-lg text-sm text-slate-500 hover:text-slate-900 hover:bg-slate-50 {{ request()->is('sahkosopimus/yleissahko') ? 'bg-slate-100 text-slate-900 font-semibold' : '' }}">
                                Yleissähkö
                            </a>
                            <a href="/sahkosopimus/yritykselle" class="block px-3 py-2 rounded-lg text-sm text-slate-500 hover:text-slate-900 hover:bg-slate-50 {{ request()->is('sahkosopimus/yritykselle') ? 'bg-slate-100 text-slate-900 font-semibold' : '' }}">
                                Yrityksille
                            </a>
                            <a href="/sahkosopimus/sahkoyhtiot" class="block px-3 py-2 rounded-lg text-sm text-slate-500 hover:text-slate-900 hover:bg-slate-50 {{ request()->is('sahkosopimus/sahkoyhtiot*') ? 'bg-slate-100 text-slate-900 font-semibold' : '' }}">
                                Sähköyhtiöt
                            </a>
                        </div>
                    </div>
                    <div x-data="{ expanded: {{ $isDataNavActive ? 'true' : 'false' }} }">
                        <button @click="expanded = !expanded" class="w-full flex items-center justify-between px-3 py-2 rounded-lg text-base font-medium text-slate-500 hover:text-slate-900 hover:bg-slate-50 {{ $isDataNavActive ? 'bg-slate-100 text-slate-900 font-semibold' : '' }}">
                            <span>Sähködata</span>
                            <svg class="w-4 h-4 transition-transform" :class="{ 'rotate-180': expanded }" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                        </button>
                        <div x-show="expanded" x-transition class="pl-4 space-y-1 mt-1">
                            <a href="/sahkosopimus/tilastot" class="block px-3 py-2 rounded-lg text-sm text-slate-500 hover:text-slate-900 hover:bg-slate-50 {{ request()->is('sahkosopimus/tilastot') ? 'bg-slate-100 text-slate-900 font-semibold' : '' }}">
                                Sähkösopimusten hintakehitys
                            </a>
                            <a href="/sahkosopimus/sahkon-hintaennuste" class="block px-3 py-2 rounded-lg text-sm text-slate-500 hover:text-slate-900 hover:bg-slate-50 {{ request()->is('sahkosopimus/sahkon-hintaennuste') ? 'bg-slate-100 text-slate-900 font-semibold' : '' }}">
                                Sähkön hintaennuste
                            </a>
                            <a href="/sahkosopimus/kannattaako-porssisahko" class="block px-3 py-2 rounded-lg text-sm text-slate-500 hover:text-slate-900 hover:bg-slate-50 {{ request()->is('sahkosopimus/kannattaako-porssisahko') ? 'bg-slate-100 text-slate-900 font-semibold' : '' }}">
                                Kannattaako pörssisähkö?
                            </a>
                            <a href="/sahkosopimus/kannattaako-maaraaikainen" class="block px-3 py-2 rounded-lg text-sm text-slate-500 hover:text-slate-900 hover:bg-slate-50 {{ request()->is('sahkosopimus/kannattaako-maaraaikainen') ? 'bg-slate-100 text-slate-900 font-semibold' : '' }}">
                                Kannattaako määräaikainen?
                            </a>
                            <a href="/spot-price" class="block px-3 py-2 rounded-lg text-sm text-slate-500 hover:text-slate-900 hover:bg-slate-50 {{ request()->is('spot-price') ? 'bg-slate-100 text-slate-900 font-semibold' : '' }}">
                                Pörssisähkön hinta
                            </a>
                        </div>
                    </div>
                    <a href="{{ route('calculator') }}" class="block px-3 py-2 rounded-lg text-base font-medium text-slate-500 hover:text-slate-900 hover:bg-slate-50 {{ request()->is('sahkosopimus/laskuri') ? 'bg-slate-100 text-slate-900 font-semibold' : '' }}">
                        Arvioi sähkönkulutus
                    </a>
                    <a href="/aurinkopaneelit" class="block px-3 py-2 rounded-lg text-base font-medium text-slate-500 hover:text-slate-900 hover:bg-slate-50 {{ request()->is('aurinkopaneelit*') ? 'bg-slate-100 text-slate-900 font-semibold' : '' }}">
                        Aurinkopaneelit
                    </a>
                    <a href="/lampopumput" class="block px-3 py-2 rounded-lg text-base font-medium text-slate-500 hover:text-slate-900 hover:bg-slate-50 {{ request()->is('lampopumput*') ? 'bg-slate-100 text-slate-900 font-semibold' : '' }}">
                        Lämpöpumput
                    </a>
                </div>
            </div>
        </header>

        <main class="flex-1">
            {{ $slot }}
        </main>

        <footer class="bg-slate-950 mt-auto">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-16">
                <!-- Footer Links Grid -->
                <div class="grid grid-cols-2 md:grid-cols-4 gap-8 mb-12">
                    <!-- Sähkösopimukset -->
                    <div>
                        <h3 class="text-white font-semibold mb-4">Sähkösopimukset</h3>
                        <ul class="space-y-2">
                            <li><a href="/" class="text-slate-400 hover:text-white text-sm transition-colors">Kaikki sopimukset</a></li>
                            <li><a href="/sahkosopimus" class="text-slate-400 hover:text-white text-sm transition-colors">Vertaa sopimuksia</a></li>
                            <li><a href="/sahkosopimus/halvin-sahkosopimus" class="text-slate-400 hover:text-white text-sm transition-colors">Halvimmat sopimukset</a></li>
                            <li><a href="/sahkosopimus/sahkotarjous" class="text-slate-400 hover:text-white text-sm transition-colors">Tarjoukset</a></li>
                            <li><a href="/sahkosopimus/yritykselle" class="text-slate-400 hover:text-white text-sm transition-colors">Yrityksille</a></li>
                            <li><a href="/sahkosopimus/sahkoyhtiot" class="text-slate-400 hover:text-white text-sm transition-colors">Sähköyhtiöt</a></li>
                        </ul>
                    </div>

                    <!-- Laskurit -->
                    <div>
                        <h3 class="text-white font-semibold mb-4">Laskurit</h3>
                        <ul class="space-y-2">
                            <li><a href="{{ route('calculator') }}" class="text-slate-400 hover:text-white text-sm transition-colors">Arvioi sähkönkulutus</a></li>
                            <li><a href="/aurinkopaneelit/laskuri" class="text-slate-400 hover:text-white text-sm transition-colors">Aurinkopaneelilaskuri</a></li>
                            <li><a href="/lampopumput/laskuri" class="text-slate-400 hover:text-white text-sm transition-colors">Lämpöpumppulaskuri</a></li>
                        </ul>

                    </div>

                    <!-- Data ja selvitykset -->
                    <div>
                        <h3 class="text-white font-semibold mb-4">Data ja selvitykset</h3>
                        <ul class="space-y-2">
                            <li><a href="/sahkosopimus/tilastot" class="text-slate-400 hover:text-white text-sm transition-colors">Sähkösopimusten hintakehitys</a></li>
                            <li><a href="/sahkosopimus/sahkon-hintaennuste" class="text-slate-400 hover:text-white text-sm transition-colors">Sähkön hintaennuste</a></li>
                            <li><a href="/sahkosopimus/kannattaako-porssisahko" class="text-slate-400 hover:text-white text-sm transition-colors">Kannattaako pörssisähkö?</a></li>
                            <li><a href="/sahkosopimus/kannattaako-maaraaikainen" class="text-slate-400 hover:text-white text-sm transition-colors">Kannattaako määräaikainen?</a></li>
                            <li><a href="/spot-price" class="text-slate-400 hover:text-white text-sm transition-colors">Pörssisähkön hinta</a></li>
                        </ul>
                    </div>

                    <!-- Paikkakunnat -->
                    <div>
                        <h3 class="text-white font-semibold mb-4">Paikkakunnat</h3>
                        <ul class="space-y-2">
                            <li><a href="/sahkosopimus/paikkakunnat/helsinki" class="text-slate-400 hover:text-white text-sm transition-colors">Helsinki</a></li>
                            <li><a href="/sahkosopimus/paikkakunnat/tampere" class="text-slate-400 hover:text-white text-sm transition-colors">Tampere</a></li>
                            <li><a href="/sahkosopimus/paikkakunnat/oulu" class="text-slate-400 hover:text-white text-sm transition-colors">Oulu</a></li>
                            <li><a href="/sahkosopimus/paikkakunnat/turku" class="text-slate-400 hover:text-white text-sm transition-colors">Turku</a></li>
                            <li><a href="{{ route('locations') }}" class="text-coral-400 hover:text-coral-300 text-sm font-medium transition-colors">Kaikki paikkakunnat →</a></li>
                        </ul>
                    </div>

                    <!-- Voltikka -->
                    <div>
                        <a href="/" class="flex items-center gap-2.5 mb-4">
                            <div class="w-9 h-9 bg-gradient-to-br from-coral-500 to-coral-600 rounded-xl flex items-center justify-center">
                                <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z"/>
                                </svg>
                            </div>
                            <span class="text-xl font-extrabold text-white">Voltikka</span>
                        </a>
                        <p class="text-slate-400 text-sm">Suomen kattavin sähkösopimusten vertailupalvelu.</p>
                    </div>
                </div>

                <!-- Copyright -->
                <div class="border-t border-slate-800 pt-8 text-center text-sm text-slate-500">
                    &copy; {{ date('Y') }} Voltikka
                </div>
            </div>
        </footer>

        @livewireScripts
        <script>
            document.addEventListener('DOMContentLoaded', () => {
                const navRoots = ['site-desktop-nav', 'site-mobile-nav'];

                const loadHeaderSpotPrice = () => {
                    document.querySelectorAll('[data-header-spot-price]').forEach((container) => {
                        if (container.dataset.loaded === 'true' || container.dataset.loading === 'true') {
                            return;
                        }

                        container.dataset.loading = 'true';

                        fetch(container.dataset.url, {
                            headers: {
                                'X-Requested-With': 'XMLHttpRequest',
                            },
                            credentials: 'same-origin',
                        })
                            .then((response) => response.ok ? response.text() : null)
                            .then((html) => {
                                if (!html) {
                                    return;
                                }

                                container.innerHTML = html;
                                container.dataset.loaded = 'true';
                            })
                            .catch(() => {
                                // Keep the lightweight placeholder on failure.
                            })
                            .finally(() => {
                                container.dataset.loading = 'false';
                            });
                    });
                };

                const clearPendingNavState = () => {
                    document.querySelectorAll('[data-nav-pending="true"]').forEach((link) => {
                        link.dataset.navPending = 'false';
                        link.classList.remove('opacity-70', 'scale-[0.98]', 'bg-coral-50', 'text-coral-700', 'shadow-sm');
                        link.removeAttribute('aria-busy');
                    });
                };

                const markPendingNavLink = (link) => {
                    clearPendingNavState();

                    const insidePrimaryNav = navRoots.some((id) => link.closest(`#${id}`));
                    if (!insidePrimaryNav) {
                        return;
                    }

                    link.dataset.navPending = 'true';
                    link.setAttribute('aria-busy', 'true');
                    link.classList.add('opacity-70', 'scale-[0.98]', 'bg-coral-50', 'text-coral-700', 'shadow-sm');
                };

                const startNavigationFeedback = () => {
                    window.dispatchEvent(new CustomEvent('page-navigation-start'));
                };

                const stopNavigationFeedback = () => {
                    clearPendingNavState();
                    window.dispatchEvent(new CustomEvent('page-navigation-end'));
                };

                document.addEventListener('click', (event) => {
                    if (event.defaultPrevented || event.button !== 0) {
                        return;
                    }

                    if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
                        return;
                    }

                    const link = event.target.closest('a[href]');
                    if (!link) {
                        return;
                    }

                    if (link.hasAttribute('download') || link.dataset.noNavLoading !== undefined) {
                        return;
                    }

                    const target = link.getAttribute('target');
                    if (target && target !== '_self') {
                        return;
                    }

                    const href = link.getAttribute('href');
                    if (!href || href.startsWith('#') || href.startsWith('mailto:') || href.startsWith('tel:') || href.startsWith('javascript:')) {
                        return;
                    }

                    let url;
                    try {
                        url = new URL(link.href, window.location.href);
                    } catch {
                        return;
                    }

                    if (url.origin !== window.location.origin) {
                        return;
                    }

                    const currentUrl = new URL(window.location.href);
                    const onlyHashChanged = url.pathname === currentUrl.pathname
                        && url.search === currentUrl.search
                        && url.hash
                        && url.hash !== currentUrl.hash;

                    if (onlyHashChanged) {
                        return;
                    }

                    if (url.href === currentUrl.href) {
                        return;
                    }

                    markPendingNavLink(link);
                    startNavigationFeedback();
                }, { passive: true });

                let lastKnownNavigationUrl = new URL(window.location.href);

                window.addEventListener('pageshow', () => {
                    lastKnownNavigationUrl = new URL(window.location.href);
                    stopNavigationFeedback();
                });
                window.addEventListener('pagehide', stopNavigationFeedback);
                window.addEventListener('hashchange', () => {
                    lastKnownNavigationUrl = new URL(window.location.href);
                    stopNavigationFeedback();
                });
                window.addEventListener('popstate', () => {
                    const nextUrl = new URL(window.location.href);
                    const onlyHashChanged = nextUrl.pathname === lastKnownNavigationUrl.pathname
                        && nextUrl.search === lastKnownNavigationUrl.search
                        && nextUrl.hash !== lastKnownNavigationUrl.hash;

                    lastKnownNavigationUrl = nextUrl;

                    if (onlyHashChanged) {
                        stopNavigationFeedback();
                        return;
                    }

                    startNavigationFeedback();
                });

                if ('requestIdleCallback' in window) {
                    window.requestIdleCallback(loadHeaderSpotPrice, { timeout: 1500 });
                } else {
                    window.addEventListener('load', () => setTimeout(loadHeaderSpotPrice, 150), { once: true });
                }
            });
        </script>
        @stack('scripts')
    </body>
</html>
