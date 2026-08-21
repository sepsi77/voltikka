@props([
    'title' => 'Löydä Voltikka helpommin',
    'description' => 'Valitse Voltikka suosikkilähteeksi Google-haussa, niin löydät sisältömme helpommin.',
])

<section
    data-page-action-strip
    {{ $attributes->class('border-y border-slate-200 bg-white') }}
    x-data="{
        preferredSourceReady: false,
        preferredSourceLoadFailed: false,
        preferredSourceLoadTimeout: null,
        init() {
            const updateReadyState = () => {
                this.preferredSourceReady = typeof window.voltikkaPreferredSourceClient?.addPreferredSource === 'function';

                if (this.preferredSourceReady) {
                    this.preferredSourceLoadFailed = false;
                    window.clearTimeout(this.preferredSourceLoadTimeout);
                    this.preferredSourceLoadTimeout = null;
                }
            };

            updateReadyState();

            if (!this.preferredSourceReady) {
                window.addEventListener('voltikka:preferred-source-ready', updateReadyState, { once: true });
                this.preferredSourceLoadTimeout = window.setTimeout(() => {
                    updateReadyState();

                    if (!this.preferredSourceReady) {
                        this.preferredSourceLoadFailed = true;
                    }
                }, 8000);
            }
        },
        trackPreferredSourceClick() {
            try {
                this.$track('Google Preferred Source Clicked', {
                    props: { placement: 'post_hero' },
                });
            } catch (error) {
                // Analytics must not block the Google action or fallback navigation.
            }
        },
        addPreferredSource() {
            const client = window.voltikkaPreferredSourceClient;

            if (!this.preferredSourceReady || typeof client?.addPreferredSource !== 'function') {
                return;
            }

            this.trackPreferredSourceClick();
            client.addPreferredSource();
        },
    }"
>
    <div class="mx-auto flex max-w-7xl flex-col gap-4 px-4 py-5 sm:flex-row sm:items-center sm:justify-between sm:px-6 lg:px-8">
        <div class="max-w-2xl">
            <h2 class="text-lg font-bold text-slate-900">{{ $title }}</h2>
            <p class="mt-1 text-sm leading-6 text-slate-600">{{ $description }}</p>
        </div>

        <div class="flex shrink-0 flex-wrap items-center gap-3">
            <button
                data-google-preferred-source-action
                type="button"
                disabled
                x-show="!preferredSourceLoadFailed"
                x-on:click="addPreferredSource()"
                x-bind:disabled="!preferredSourceReady"
                x-bind:aria-busy="preferredSourceReady ? 'false' : 'true'"
                class="inline-flex min-h-11 items-center justify-center gap-2 rounded-xl bg-slate-900 px-5 py-2.5 text-sm font-bold text-white transition-colors hover:bg-slate-800 focus:outline-none focus:ring-2 focus:ring-coral-500 focus:ring-offset-2 disabled:cursor-wait disabled:bg-slate-200 disabled:text-slate-500"
            >
                <span x-show="!preferredSourceReady" class="inline-flex items-center gap-2">
                    <x-spinner size="h-4 w-4" color="text-slate-500" />
                    Ladataan Google-toimintoa
                </span>
                <span x-show="preferredSourceReady" x-cloak class="inline-flex items-center gap-2">
                    <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 5v14m-7-7h14"></path>
                    </svg>
                    Valitse suosikkilähteeksi
                </span>
            </button>
            <a
                data-google-preferred-source-fallback
                href="https://www.google.com/preferences/source?q=voltikka.fi"
                target="_blank"
                rel="noopener noreferrer"
                x-show="preferredSourceLoadFailed"
                x-cloak
                x-on:click="trackPreferredSourceClick()"
                class="inline-flex min-h-11 items-center justify-center gap-2 rounded-xl bg-slate-900 px-5 py-2.5 text-sm font-bold text-white transition-colors hover:bg-slate-800 focus:outline-none focus:ring-2 focus:ring-coral-500 focus:ring-offset-2"
            >
                <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 5v14m-7-7h14"></path>
                </svg>
                Valitse suosikkilähteeksi
            </a>
            <noscript>
                <a
                    href="https://www.google.com/preferences/source?q=voltikka.fi"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="text-sm font-semibold text-slate-700 underline decoration-slate-300 underline-offset-4 hover:text-coral-700"
                >
                    Valitse suosikkilähteeksi
                </a>
            </noscript>
            {{ $slot }}
        </div>
    </div>
</section>

@push('scripts')
    @once
        <script async src="https://news.google.com/swg/js/v1/publisher.js" preferred-sources-control="manual"></script>
        <script>
            (self.PREFERRED_SOURCE = self.PREFERRED_SOURCE || []).push(function (preferredSource) {
                preferredSource.init({ theme: 'light' });
                window.voltikkaPreferredSourceClient = preferredSource;
                window.dispatchEvent(new CustomEvent('voltikka:preferred-source-ready'));
            });
        </script>
    @endonce
@endpush
