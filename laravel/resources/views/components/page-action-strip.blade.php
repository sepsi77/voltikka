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
        Paper "R3 · Strip" boards (page "Round 3 · revisions"): the Preferred Sources strip.
        Desktop: one centred row of villagers at the notice board (season art), title + text, and
        a pinned paper note. Below 1024: no art, only the text and the note. The note is the real
        button; its torn bottom edge (CSS ::after, redesign-shell.css) matches the paper bill form.
        The strip is not contract or price data, so the villagers may stand here. Not lazy: the
        art is near the fold.
    --}}
    <div class="vk-wrap vk-strip-wrap">
        <div class="vk-strip-row">
            {{-- Desktop only. Below 1024 the media source does not match, so the img keeps its
                 1×1 data URI and the art never downloads; CSS hides the picture there. --}}
            <picture class="vk-strip-art">
                <source
                    media="(min-width: 1024px)"
                    srcset="{{ Season::art('noticeboard', 560) }} 560w, {{ Season::art('noticeboard') }} 1120w"
                    sizes="384px"
                >
                <img
                    src="data:image/gif;base64,R0lGODlhAQABAAAAACH5BAEKAAEALAAAAAABAAEAAAICTAEAOw=="
                    width="384"
                    height="150"
                    alt=""
                    decoding="async"
                >
            </picture>

            <div class="vk-strip-body">
                <h2 class="vk-strip-title">{{ $title }}</h2>
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
                    class="vk-strip-note vk-focus"
                >
                    <span class="vk-strip-note-paper">
                        <span class="vk-strip-note-pin" aria-hidden="true"></span>
                        <span x-show="!preferredSourceReady" class="vk-strip-note-label vk-strip-action-loading">
                            <x-spinner size="h-[18px] w-[18px]" color="text-ink-muted" />
                            Ladataan Google-toimintoa
                        </span>
                        <span x-show="preferredSourceReady" x-cloak class="vk-strip-note-label">
                            <svg width="18" height="18" viewBox="0 0 18 18" fill="none" aria-hidden="true" focusable="false" class="shrink-0"><path d="M9 3v12M3 9h12" stroke="var(--color-fox)" stroke-width="2.6" stroke-linecap="round" /></svg>
                            Valitse suosikkilähteeksi
                        </span>
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
                    class="vk-strip-note vk-focus"
                >
                    <span class="vk-strip-note-paper">
                        <span class="vk-strip-note-pin" aria-hidden="true"></span>
                        <span class="vk-strip-note-label">
                            Valitse suosikkilähteeksi
                            <svg width="14" height="14" viewBox="0 0 14 14" fill="none" aria-hidden="true" focusable="false" class="shrink-0"><path d="M4 10 10 4M5 4h5v5" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" /></svg>
                        </span>
                    </span>
                </a>
                <noscript>
                    <a
                        href="https://www.google.com/preferences/source?q=voltikka.fi"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="vk-strip-note vk-focus"
                    >
                        <span class="vk-strip-note-paper">
                            <span class="vk-strip-note-pin" aria-hidden="true"></span>
                            <span class="vk-strip-note-label">
                                Valitse suosikkilähteeksi
                                <svg width="14" height="14" viewBox="0 0 14 14" fill="none" aria-hidden="true" focusable="false" class="shrink-0"><path d="M4 10 10 4M5 4h5v5" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" /></svg>
                            </span>
                        </span>
                    </a>
                </noscript>
                <p class="vk-strip-small">Google avaa valinnan. Valinta on maksuton.</p>
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
