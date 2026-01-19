<!DOCTYPE html>
<html lang="fi">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title>{{ $title ?? 'Voltikka - Sähkösopimusten vertailu' }}</title>

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600,700&display=swap" rel="stylesheet" />

        <!-- Styles / Scripts -->
        @if (file_exists(public_path('build/manifest.json')))
            @vite(['resources/css/app.css', 'resources/js/app.js'])
        @else
            <script src="https://cdn.tailwindcss.com"></script>
        @endif
        @livewireStyles
    </head>
    <body class="font-sans antialiased bg-gray-50 min-h-screen">
        <header class="bg-white shadow-sm border-b border-gray-200">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                <div class="flex justify-between items-center h-16">
                    <div class="flex items-center">
                        <a href="/" class="text-2xl font-bold text-blue-600">Voltikka</a>
                    </div>
                    <nav class="flex space-x-4">
                        <a href="/" class="text-gray-700 hover:text-blue-600 transition">Sopimukset</a>
                    </nav>
                </div>
            </div>
        </header>

        <main class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
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
