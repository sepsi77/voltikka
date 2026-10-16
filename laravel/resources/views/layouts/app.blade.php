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

        <!-- Fonts: Bricolage Grotesque (display) + Figtree (text), design/voltikka-design-system.md §3.
             Loaded asynchronously to avoid render-blocking. -->
        <link rel="preload" href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:opsz,wght@12..96,700;12..96,800&family=Figtree:wght@400;500;600;700;800&display=swap" as="style" onload="this.onload=null;this.rel='stylesheet'">
        <noscript><link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:opsz,wght@12..96,700;12..96,800&family=Figtree:wght@400;500;600;700;800&display=swap"></noscript>

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
                                sans: ['Figtree', 'ui-sans-serif', 'system-ui', 'sans-serif'],
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
        class="font-sans antialiased bg-ground text-ink-body min-h-screen flex flex-col"
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
            <div class="h-1 bg-fox animate-pulse"></div>
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
            <div class="h-1 bg-fox animate-pulse"></div>
            <span style="position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0;">Ladataan sivua…</span>
        </div>

        {{-- Top bar: over the painted hero when the page passes heroTone, else on a night band. --}}
        <x-site.topbar :tone="$heroTone ?? null" />

        {{-- overflow-x: clip stops one wide page block (for example an old -mx hero) from scrolling the whole page sideways; it keeps position: sticky working. --}}
        <main class="flex-1 overflow-x-clip">
            {{ $slot }}
        </main>

        <x-site.footer />

        @livewireScripts
        <script>
            document.addEventListener('DOMContentLoaded', () => {
                const navRoots = ['site-desktop-nav', 'site-mobile-nav'];

                const headerSpotPriceLoader = (() => {
                    const refreshDelay = 60_000;
                    const retryDelays = [5_000, 15_000, 30_000, 60_000];
                    let timer = null;
                    let inFlight = null;
                    let failures = 0;
                    let lastMarkup = null;
                    let lastState = null;

                    const containers = () => Array.from(document.querySelectorAll('[data-header-spot-price]'));

                    const render = (markup, state) => {
                        containers().forEach((container) => {
                            container.innerHTML = markup;
                            container.dataset.state = state;
                        });
                    };

                    const schedule = (delay) => {
                        if (timer !== null) {
                            window.clearTimeout(timer);
                        }

                        timer = window.setTimeout(() => {
                            timer = null;
                            refresh();
                        }, delay);
                    };

                    const unavailableMarkup = () => {
                        const template = containers()
                            .map((container) => container.querySelector('[data-header-spot-price-unavailable]'))
                            .find(Boolean);

                        return template?.innerHTML.trim() || lastMarkup;
                    };

                    const responseState = (html) => {
                        const template = document.createElement('template');
                        template.innerHTML = html.trim();

                        return template.content.firstElementChild?.dataset.headerSpotPriceState === 'available'
                            ? 'available'
                            : 'unavailable';
                    };

                    const refresh = () => {
                        if (inFlight) {
                            return inFlight;
                        }

                        const container = containers()[0];
                        if (!container) {
                            return Promise.resolve();
                        }

                        inFlight = fetch(container.dataset.url, {
                            headers: {
                                'X-Requested-With': 'XMLHttpRequest',
                            },
                            credentials: 'same-origin',
                        })
                            .then((response) => {
                                if (!response.ok) {
                                    throw new Error(`Header spot price request failed: ${response.status}`);
                                }

                                return response.text();
                            })
                            .then((html) => {
                                if (!html.trim()) {
                                    throw new Error('Header spot price response was empty');
                                }

                                lastMarkup = html;
                                lastState = responseState(html);
                                failures = 0;
                                render(lastMarkup, lastState);
                                schedule(refreshDelay);
                            })
                            .catch(() => {
                                failures += 1;

                                if (!lastMarkup) {
                                    lastMarkup = unavailableMarkup();
                                    lastState = 'unavailable';
                                }

                                if (lastMarkup) {
                                    render(lastMarkup, lastState);
                                }

                                schedule(retryDelays[Math.min(failures - 1, retryDelays.length - 1)]);
                            })
                            .finally(() => {
                                inFlight = null;
                            });

                        return inFlight;
                    };

                    const start = () => {
                        if (lastMarkup) {
                            render(lastMarkup, lastState);
                        }

                        if (timer === null && !inFlight) {
                            refresh();
                        }
                    };

                    return { start };
                })();

                const clearPendingNavState = () => {
                    document.querySelectorAll('[data-nav-pending="true"]').forEach((link) => {
                        link.dataset.navPending = 'false';
                        link.classList.remove('vk-nav-pending');
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
                    link.classList.add('vk-nav-pending');
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

                document.addEventListener('livewire:navigated', headerSpotPriceLoader.start);

                if ('requestIdleCallback' in window) {
                    window.requestIdleCallback(headerSpotPriceLoader.start, { timeout: 1500 });
                } else {
                    window.addEventListener('load', () => setTimeout(headerSpotPriceLoader.start, 150), { once: true });
                }
            });
        </script>
        @stack('scripts')
    </body>
</html>
