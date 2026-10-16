@php
    $selectedPostcode = $this->selectedPostcode;
    $postcodeInputId = 'contract-postcode-'.str_replace('.', '-', $this->getId());
    $postcodeErrorId = $postcodeInputId.'-error';
@endphp

{{-- Collapsed availability disclosure (Paper board 2J3-0, "Controls · compact", third row).
     Most visitors never enter a postcode, so the form is behind a trigger that itself
     states the current availability ("Saatavuus: koko Suomi" or the selected postcode):
     the status stays glanceable while the input draws no attention until asked for.
     Inside the listing's `.vk-disclosures` row the wrapper is `display: contents`, so the
     trigger sits in the row and the panel opens below the row; used alone (homepage,
     cheapest page) it is a plain block. The helper sentence opens with the panel. --}}
<section
    class="vk-disclosure"
    aria-labelledby="{{ $postcodeInputId }}-heading"
    x-data="{
        availabilityOpen: false,
        initializePostcode() {
            try {
                const current = String(this.$wire.postcodeFilter ?? '').trim();
                if (current !== '') {
                    localStorage.setItem('voltikka_postcode_v1', current);
                    return;
                }

                const saved = localStorage.getItem('voltikka_postcode_v1');
                if (saved !== null) {
                    this.$wire.restorePostcode(saved);
                }
            } catch (error) {
                // Storage can be unavailable in restricted browser modes.
            }
        },
        storePostcode(postcode) {
            try {
                localStorage.setItem('voltikka_postcode_v1', postcode);
            } catch (error) {
                // The active Livewire selection still works for this page.
            }
        },
        removePostcode() {
            try {
                localStorage.removeItem('voltikka_postcode_v1');
            } catch (error) {
                // The active Livewire selection is already safe.
            }
        }
    }"
    x-init="$nextTick(() => initializePostcode())"
    x-on:postcode-preference-stored.window="storePostcode($event.detail.postcode)"
    x-on:postcode-preference-removed.window="removePostcode()"
>
    <button
        type="button"
        @click="availabilityOpen = !availabilityOpen"
        :aria-expanded="availabilityOpen ? 'true' : 'false'"
        aria-expanded="false"
        class="vk-disclosure-trigger"
    >
        <span id="{{ $postcodeInputId }}-heading" role="status" class="vk-disclosure-title">
            @if ($selectedPostcode)
                Saatavuus: {{ $selectedPostcode->postcode }} {{ $selectedPostcode->postcode_fi_name }}@if ($selectedPostcode->municipal_name_fi && $selectedPostcode->municipal_name_fi !== $selectedPostcode->postcode_fi_name), {{ $selectedPostcode->municipal_name_fi }}@endif
            @else
                Saatavuus: koko Suomi
            @endif
        </span>
        <svg class="vk-chevron" viewBox="0 0 18 18" aria-hidden="true" focusable="false"><path d="M4 7l5 5 5-5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" /></svg>
    </button>

    <div x-show="availabilityOpen" x-collapse x-cloak class="vk-disclosure-panel">
        <p class="vk-panel-lead">
            @if ($selectedPostcode)
                Mukana ovat myös tähän postinumeroon saatavat alueelliset sopimukset.
            @else
                Lisää postinumero, niin saat mukaan alueelliset sopimukset.
            @endif
        </p>
        <div class="mt-3 max-w-md">
            <label for="{{ $postcodeInputId }}" class="sr-only">Postinumero (5 numeroa)</label>
            <div class="flex min-w-0 items-end gap-4">
                <input
                    id="{{ $postcodeInputId }}"
                    type="text"
                    inputmode="numeric"
                    autocomplete="postal-code"
                    maxlength="5"
                    data-search-input
                    wire:model.live.debounce.300ms="postcodeSearch"
                    wire:loading.attr="disabled"
                    wire:target="restorePostcode,selectPostcode,applyPostcodeSearch,clearPostcodeFilter"
                    @if ($postcodeError) aria-describedby="{{ $postcodeErrorId }}" @endif
                    aria-invalid="{{ $postcodeError ? 'true' : 'false' }}"
                    placeholder="Postinumero, esim. 00100"
                    class="vk-input flex-1"
                >
                <button
                    type="button"
                    wire:click="applyPostcodeSearch"
                    wire:loading.attr="disabled"
                    wire:target="postcodeSearch,restorePostcode,selectPostcode,applyPostcodeSearch,clearPostcodeFilter"
                    class="vk-inked-link vk-focus shrink-0 pb-2 disabled:cursor-wait disabled:text-ink-muted"
                >
                    <span wire:loading.remove wire:target="applyPostcodeSearch">Käytä numeroa →</span>
                    <span wire:loading wire:target="applyPostcodeSearch">Tarkistetaan</span>
                </button>
            </div>

            @if ($postcodeError)
                <p id="{{ $postcodeErrorId }}" class="vk-notice mt-1.5" role="alert">{{ $postcodeError }}</p>
            @endif

            <div wire:loading wire:target="postcodeSearch" class="mt-1.5 text-sm text-ink-body" role="status">
                Haetaan postinumeroita...
            </div>

            @if ($postcodeSearch !== '' && $postcodeSuggestions->isNotEmpty())
                <ul class="vk-rows mt-2" aria-label="Postinumeroehdotukset">
                    @foreach ($postcodeSuggestions as $suggestion)
                        <li>
                            <button
                                type="button"
                                wire:click="selectPostcode('{{ $suggestion->postcode }}')"
                                wire:loading.attr="disabled"
                                wire:target="selectPostcode,applyPostcodeSearch,clearPostcodeFilter,restorePostcode"
                                class="vk-focus flex w-full items-start justify-between gap-4 px-1 py-2.5 text-left hover:text-fox-deep disabled:cursor-wait disabled:text-ink-muted"
                            >
                                <span class="font-extrabold tabular-nums text-ink">{{ $suggestion->postcode }}</span>
                                <span class="min-w-0 text-right text-sm text-ink-body">
                                    {{ $suggestion->postcode_fi_name }}@if ($suggestion->municipal_name_fi && $suggestion->municipal_name_fi !== $suggestion->postcode_fi_name), {{ $suggestion->municipal_name_fi }}@endif
                                </span>
                            </button>
                        </li>
                    @endforeach
                </ul>
            @endif

            @if ($selectedPostcode)
                <button
                    type="button"
                    wire:click="clearPostcodeFilter"
                    wire:loading.attr="disabled"
                    wire:target="clearPostcodeFilter,restorePostcode,selectPostcode,applyPostcodeSearch"
                    class="vk-inked-link vk-focus mt-3 disabled:cursor-wait disabled:text-ink-muted"
                >
                    Poista postinumero
                </button>
            @endif
        </div>
    </div>
</section>
