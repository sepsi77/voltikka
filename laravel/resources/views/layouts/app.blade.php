<!DOCTYPE html>
<html lang="fi">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title>{{ $title ?? 'Voltikka - Sähkösopimusten vertailu' }}</title>

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;500;600;700&display=swap" rel="stylesheet">

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
                                sans: ['Roboto', 'ui-sans-serif', 'system-ui', 'sans-serif'],
                            },
                            colors: {
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
                        },
                    },
                }
            </script>
        @endif
        <!-- Alpine.js for interactive components -->
        <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
        @livewireStyles
    </head>
    <body class="font-sans antialiased bg-surface min-h-screen flex flex-col">
        <header class="bg-white shadow-sm border-b-2 border-surface-200" x-data="{ mobileMenuOpen: false }">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                <div class="flex justify-between items-center h-16">
                    <!-- Logo -->
                    <div class="flex items-center">
                        <a href="/" class="flex items-center">
                            <!-- Desktop logo -->
                            <span class="hidden lg:block text-2xl font-bold text-tertiary">Voltikka</span>
                            <!-- Mobile logo (smaller) -->
                            <span class="lg:hidden text-xl font-bold text-tertiary">Voltikka</span>
                        </a>
                    </div>

                    <!-- Desktop Navigation -->
                    <nav class="hidden lg:flex items-center space-x-6">
                        <a href="/" class="text-tertiary-500 hover:text-tertiary-700 font-medium transition">
                            Sähkösopimukset
                        </a>
                        <a href="/paikkakunnat" class="text-tertiary-500 hover:text-tertiary-700 font-medium transition">
                            Paikkakunnat
                        </a>
                    </nav>

                    <!-- Mobile menu button -->
                    <div class="lg:hidden">
                        <button
                            @click="mobileMenuOpen = !mobileMenuOpen"
                            type="button"
                            class="inline-flex items-center justify-center p-2 rounded-md text-tertiary-500 hover:text-tertiary-700 hover:bg-gray-100 focus:outline-none focus:ring-2 focus:ring-inset focus:ring-primary-500"
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
                <div class="px-2 pt-2 pb-3 space-y-1 bg-white border-t border-gray-200">
                    <a href="/" class="block px-3 py-2 rounded-md text-base font-medium text-tertiary-500 hover:text-tertiary-700 hover:bg-gray-50">
                        Sähkösopimukset
                    </a>
                    <a href="/paikkakunnat" class="block px-3 py-2 rounded-md text-base font-medium text-tertiary-500 hover:text-tertiary-700 hover:bg-gray-50">
                        Paikkakunnat
                    </a>
                </div>
            </div>
        </header>

        <main class="flex-1">
            {{ $slot }}
        </main>

        <footer class="bg-white border-t border-gray-200 mt-auto">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6">
                <p class="text-center text-gray-500 text-sm">
                    &copy; {{ date('Y') }} Voltikka. Kaikki oikeudet pidätetään.
                </p>
            </div>
        </footer>

        @livewireScripts
    </body>
</html>
