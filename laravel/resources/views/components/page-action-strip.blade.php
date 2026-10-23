@props([
    'title' => 'Kiinnitä Voltikka Google-hakuusi',
    'description' => 'Valitse Voltikka suosikkilähteeksi, niin näet sähkön hinnat ja vertailumme Google-haussa useammin.',
])

@php
    use App\Support\Season;
@endphp

<section
    data-page-action-strip
    {{ $attributes }}
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
    {{--
        Paper "R4 · Strip options", option A (page "Round 4 · Listing, calm top"; user decision
        2026-10-23). The strip stays directly after the hero. It has some brand impact again, but
        stays low (about 84px): a small notice-board picture (desktop only), a display title with
        the reason under it, and an inked fox-deep text action. No boxed button. The action keeps
        one box in the loading, ready and fallback states, so nothing jumps when Google is ready.
    --}}
    <div class="vk-wrap">
        <div class="vk-strip-row">
            {{-- Desktop only. Below 1024 the media source does not match, so the img keeps its
                 1×1 data URI and the art never downloads; CSS hides the picture there. --}}
            <picture class="vk-strip-art">
                <source
                    media="(min-width: 1024px)"
                    srcset="{{ Season::art('noticeboard', 560) }} 560w, {{ Season::art('noticeboard') }} 1120w"
                    sizes="190px"
                >
                <img
                    src="data:image/gif;base64,R0lGODlhAQABAAAAACH5BAEKAAEALAAAAAABAAEAAAICTAEAOw=="
                    width="190"
                    height="74"
                    alt=""
                    decoding="async"
                >
            </picture>

            <div class="vk-strip-body">
                <p class="vk-strip-title">{{ $title }}</p>
                <p class="vk-strip-text">{{ $description }}</p>
            </div>

            <div class="vk-strip-actions">
                <button
                    data-google-preferred-source-action
                    type="button"
                    disabled
                    x-show="!preferredSourceLoadFailed"
                    x-on:click="addPreferredSource()"
                    x-bind:disabled="!preferredSourceReady"
                    x-bind:aria-busy="preferredSourceReady ? 'false' : 'true'"
                    class="vk-strip-action vk-focus"
                >
                    <span x-show="!preferredSourceReady" class="vk-strip-action-label vk-strip-action-loading">
                        <x-spinner size="h-4 w-4" color="text-ink-muted" />
                        Ladataan Google-toimintoa
                    </span>
                    <span x-show="preferredSourceReady" x-cloak class="vk-strip-action-label">+ Valitse suosikkilähteeksi</span>
                    <x-ink.wave width="full" stroke="2.6" class="vk-strip-wave" />
                </button>
                <a
                    data-google-preferred-source-fallback
                    href="https://www.google.com/preferences/source?q=voltikka.fi"
                    target="_blank"
                    rel="noopener noreferrer"
                    x-show="preferredSourceLoadFailed"
                    x-cloak
                    x-on:click="trackPreferredSourceClick()"
                    class="vk-strip-action vk-focus"
                >
                    <span class="vk-strip-action-label">
                        Valitse suosikkilähteeksi
                        <svg width="14" height="14" viewBox="0 0 14 14" fill="none" aria-hidden="true" focusable="false" class="shrink-0"><path d="M4 10 10 4M5 4h5v5" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" /></svg>
                    </span>
                    <x-ink.wave width="full" stroke="2.6" class="vk-strip-wave" />
                </a>
                <noscript>
                    <a
                        href="https://www.google.com/preferences/source?q=voltikka.fi"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="vk-strip-action vk-focus"
                    >
                        <span class="vk-strip-action-label">
                            Valitse suosikkilähteeksi
                            <svg width="14" height="14" viewBox="0 0 14 14" fill="none" aria-hidden="true" focusable="false" class="shrink-0"><path d="M4 10 10 4M5 4h5v5" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" /></svg>
                        </span>
                    </a>
                </noscript>
                {{ $slot }}
            </div>
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
