<!DOCTYPE html>
<html lang="fi">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title>{{ $title ?? 'Voltikka - Sähkösopimusten vertailu' }}</title>
        @if (isset($metaDescription))
        <meta name="description" content="{{ $metaDescription }}">
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

        {{-- Open Graph Tags --}}
        <meta property="og:type" content="website">
        <meta property="og:locale" content="fi_FI">
        <meta property="og:site_name" content="Voltikka">
        <meta property="og:title" content="{{ $title ?? 'Voltikka - Sähkösopimusten vertailu' }}">
        @if (isset($metaDescription))
        <meta property="og:description" content="{{ $metaDescription }}">
        @endif
        <meta property="og:url" content="{{ url()->current() }}">

        <!-- Fonts - Plus Jakarta Sans (Fresh Coral design system) -->
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">

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
        @livewireStyles
    </head>
    <body class="font-sans antialiased bg-slate-50 min-h-screen flex flex-col">
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
                    <nav class="hidden lg:flex items-center space-x-1">
                        <a href="/" class="px-4 py-2 rounded-lg text-slate-500 hover:text-slate-900 font-medium transition-colors {{ request()->is('/') ? 'bg-slate-100 text-slate-900 font-semibold' : '' }}">
                            Sähkösopimukset
                        </a>
                        <a href="{{ route('calculator') }}" class="px-4 py-2 rounded-lg text-slate-500 hover:text-slate-900 font-medium transition-colors {{ request()->is('sahkosopimus/laskuri') ? 'bg-slate-100 text-slate-900 font-semibold' : '' }}">
                            Laskuri
                        </a>

                        <!-- Housing Type Dropdown -->
                        <div class="relative" x-data="{ open: false }">
                            <button @click="open = !open" @click.outside="open = false" class="px-4 py-2 rounded-lg text-slate-500 hover:text-slate-900 font-medium transition-colors flex items-center gap-1">
                                Asuntotyyppi
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path></svg>
                            </button>
                            <div x-show="open" x-transition class="absolute left-0 mt-2 w-48 bg-white rounded-xl shadow-lg py-2 z-50 border border-slate-200">
                                <a href="/sahkosopimus/omakotitalo" class="block px-4 py-2 text-sm text-slate-500 hover:text-slate-900 hover:bg-slate-50">Omakotitalo</a>
                                <a href="/sahkosopimus/rivitalo" class="block px-4 py-2 text-sm text-slate-500 hover:text-slate-900 hover:bg-slate-50">Rivitalo</a>
                                <a href="/sahkosopimus/kerrostalo" class="block px-4 py-2 text-sm text-slate-500 hover:text-slate-900 hover:bg-slate-50">Kerrostalo</a>
                            </div>
                        </div>

                        <!-- Energy Source Dropdown -->
                        <div class="relative" x-data="{ open: false }">
                            <button @click="open = !open" @click.outside="open = false" class="px-4 py-2 rounded-lg text-slate-500 hover:text-slate-900 font-medium transition-colors flex items-center gap-1">
                                Energialähde
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path></svg>
                            </button>
                            <div x-show="open" x-transition class="absolute left-0 mt-2 w-48 bg-white rounded-xl shadow-lg py-2 z-50 border border-slate-200">
                                <a href="/sahkosopimus/vihrea-sahko" class="block px-4 py-2 text-sm text-slate-500 hover:text-slate-900 hover:bg-slate-50">Vihreä sähkö</a>
                                <a href="/sahkosopimus/tuulisahko" class="block px-4 py-2 text-sm text-slate-500 hover:text-slate-900 hover:bg-slate-50">Tuulisähkö</a>
                                <a href="/sahkosopimus/aurinkosahko" class="block px-4 py-2 text-sm text-slate-500 hover:text-slate-900 hover:bg-slate-50">Aurinkosähkö</a>
                            </div>
                        </div>

                        <a href="{{ route('locations') }}" class="px-4 py-2 rounded-lg text-slate-500 hover:text-slate-900 font-medium transition-colors {{ request()->is('sahkosopimus/paikkakunnat*') ? 'bg-slate-100 text-slate-900 font-semibold' : '' }}">
                            Paikkakunnat
                        </a>
                        <a href="/spot-price" class="px-4 py-2 rounded-lg text-slate-500 hover:text-slate-900 font-medium transition-colors {{ request()->is('spot-price') ? 'bg-slate-100 text-slate-900 font-semibold' : '' }}">
                            Pörssisähkö
                        </a>
                    </nav>

                    <!-- Spot Price Badge (Desktop) -->
                    <div class="hidden lg:block">
                        @livewire('header-spot-price')
                    </div>

                    <!-- Spot Price Badge + Mobile menu button -->
                    <div class="lg:hidden flex items-center gap-2">
                        @livewire('header-spot-price')
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
                <div class="px-2 pt-2 pb-3 space-y-1 bg-white border-t border-slate-200">
                    <a href="/" class="block px-3 py-2 rounded-lg text-base font-medium text-slate-500 hover:text-slate-900 hover:bg-slate-50 {{ request()->is('/') ? 'bg-slate-100 text-slate-900 font-semibold' : '' }}">
                        Sähkösopimukset
                    </a>
                    <a href="{{ route('calculator') }}" class="block px-3 py-2 rounded-lg text-base font-medium text-slate-500 hover:text-slate-900 hover:bg-slate-50 {{ request()->is('sahkosopimus/laskuri') ? 'bg-slate-100 text-slate-900 font-semibold' : '' }}">
                        Laskuri
                    </a>

                    <!-- Housing Type Section -->
                    <div class="px-3 py-2">
                        <div class="text-xs font-semibold text-slate-400 uppercase tracking-wider mb-2">Asuntotyyppi</div>
                        <a href="/sahkosopimus/omakotitalo" class="block px-3 py-1.5 text-sm text-slate-500 hover:text-slate-900 hover:bg-slate-50 rounded-lg">Omakotitalo</a>
                        <a href="/sahkosopimus/rivitalo" class="block px-3 py-1.5 text-sm text-slate-500 hover:text-slate-900 hover:bg-slate-50 rounded-lg">Rivitalo</a>
                        <a href="/sahkosopimus/kerrostalo" class="block px-3 py-1.5 text-sm text-slate-500 hover:text-slate-900 hover:bg-slate-50 rounded-lg">Kerrostalo</a>
                    </div>

                    <!-- Energy Source Section -->
                    <div class="px-3 py-2">
                        <div class="text-xs font-semibold text-slate-400 uppercase tracking-wider mb-2">Energialähde</div>
                        <a href="/sahkosopimus/vihrea-sahko" class="block px-3 py-1.5 text-sm text-slate-500 hover:text-slate-900 hover:bg-slate-50 rounded-lg">Vihreä sähkö</a>
                        <a href="/sahkosopimus/tuulisahko" class="block px-3 py-1.5 text-sm text-slate-500 hover:text-slate-900 hover:bg-slate-50 rounded-lg">Tuulisähkö</a>
                        <a href="/sahkosopimus/aurinkosahko" class="block px-3 py-1.5 text-sm text-slate-500 hover:text-slate-900 hover:bg-slate-50 rounded-lg">Aurinkosähkö</a>
                    </div>

                    <a href="{{ route('locations') }}" class="block px-3 py-2 rounded-lg text-base font-medium text-slate-500 hover:text-slate-900 hover:bg-slate-50 {{ request()->is('sahkosopimus/paikkakunnat*') ? 'bg-slate-100 text-slate-900 font-semibold' : '' }}">
                        Paikkakunnat
                    </a>
                    <a href="/spot-price" class="block px-3 py-2 rounded-lg text-base font-medium text-slate-500 hover:text-slate-900 hover:bg-slate-50 {{ request()->is('spot-price') ? 'bg-slate-100 text-slate-900 font-semibold' : '' }}">
                        Pörssisähkö
                    </a>
                </div>
            </div>
        </header>

        <main class="flex-1">
            {{ $slot }}
        </main>

        <footer class="bg-slate-950 mt-auto">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-16">
                <div class="flex flex-col items-center">
                    <a href="/" class="flex items-center gap-2.5 mb-4">
                        <div class="w-9 h-9 bg-gradient-to-br from-coral-500 to-coral-600 rounded-xl flex items-center justify-center">
                            <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z"/>
                            </svg>
                        </div>
                        <span class="text-xl font-extrabold text-white">Voltikka</span>
                    </a>
                    <p class="text-slate-400 text-sm mb-8">Suomen kattavin sähkösopimusten vertailupalvelu.</p>
                    <div class="border-t border-slate-800 pt-8 text-center text-sm text-slate-500">
                        &copy; {{ date('Y') }} Voltikka
                    </div>
                </div>
            </div>
        </footer>

        @livewireScripts
        @stack('scripts')
    </body>
</html>
