@php
    $selectedPostcode = $this->selectedPostcode;
    $postcodeInputId = 'contract-postcode-'.str_replace('.', '-', $this->getId());
    $postcodeErrorId = $postcodeInputId.'-error';
@endphp

{{-- Collapsed availability disclosure. Most visitors never enter a postcode, so
     the form is behind a trigger that itself states the current availability:
     the status stays glanceable ("Saatavuus: koko Suomi" or the selected
     postcode) while the input draws no attention until asked for. The trigger
     anatomy (slate icon, 14px bold title, sub-line, chevron) matches the bill
     and filter disclosures so the three read as one tools cluster. --}}
<section
    class="mb-2"
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
    <div class="overflow-hidden rounded-xl border transition-colors" :class="availabilityOpen ? 'border-slate-300' : 'border-slate-200'">
        <button
            type="button"
            @click="availabilityOpen = !availabilityOpen"
            :aria-expanded="availabilityOpen ? 'true' : 'false'"
            class="flex w-full items-center gap-2.5 px-4 py-2.5 text-left transition-colors"
            :class="availabilityOpen ? '' : 'hover:bg-slate-50'"
        >
            <svg class="h-5 w-5 shrink-0 text-slate-500" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a2 2 0 01-2.828 0l-4.243-4.243a8 8 0 1111.314 0z"></path>
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"></path>
            </svg>
            <span id="{{ $postcodeInputId }}-heading" role="status" class="min-w-0 flex-1 sm:flex sm:items-baseline sm:gap-2">
                <span class="block text-sm font-bold text-slate-900">
                    @if ($selectedPostcode)
                        Saatavuus: {{ $selectedPostcode->postcode }} {{ $selectedPostcode->postcode_fi_name }}@if ($selectedPostcode->municipal_name_fi && $selectedPostcode->municipal_name_fi !== $selectedPostcode->postcode_fi_name), {{ $selectedPostcode->municipal_name_fi }}@endif
                    @else
                        Saatavuus: koko Suomi
                    @endif
                </span>
                <span class="mt-0.5 block text-sm text-slate-600 sm:mt-0">
                    @if ($selectedPostcode)
                        Mukana ovat myös tähän postinumeroon saatavat alueelliset sopimukset.
                    @else
                        Lisää postinumero, niin saat mukaan alueelliset sopimukset.
                    @endif
                </span>
            </span>
            <svg class="h-5 w-5 shrink-0 text-slate-500 transition-transform" :class="availabilityOpen && 'rotate-180'" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
            </svg>
        </button>

        <div x-show="availabilityOpen" x-collapse x-cloak class="border-t border-slate-100 p-4 sm:p-6">
            <div class="max-w-md">
                <label for="{{ $postcodeInputId }}" class="sr-only">Postinumero (5 numeroa)</label>
                <div class="flex min-w-0 gap-2">
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
                        class="min-w-0 flex-1 rounded-xl border bg-white px-3.5 py-2 text-base text-slate-900 placeholder:text-slate-500 focus:outline-none disabled:bg-slate-50 disabled:text-slate-500 {{ $postcodeError ? 'border-red-500 focus:border-red-500' : 'border-slate-200 focus:border-coral-500' }}"
                    >
                    <button
                        type="button"
                        wire:click="applyPostcodeSearch"
                        wire:loading.attr="disabled"
                        wire:target="postcodeSearch,restorePostcode,selectPostcode,applyPostcodeSearch,clearPostcodeFilter"
                        class="shrink-0 rounded-xl bg-slate-950 px-3.5 py-2 text-sm font-bold text-white hover:bg-slate-800 disabled:cursor-wait disabled:bg-slate-300"
                    >
                        <span wire:loading.remove wire:target="applyPostcodeSearch">Käytä numeroa</span>
                        <span wire:loading wire:target="applyPostcodeSearch">Tarkistetaan</span>
                    </button>
                </div>

                @if ($postcodeError)
                    <p id="{{ $postcodeErrorId }}" class="mt-1.5 text-sm font-medium text-red-600" role="alert">{{ $postcodeError }}</p>
                @endif

                <div wire:loading wire:target="postcodeSearch" class="mt-1.5 text-sm text-slate-600" role="status">
                    Haetaan postinumeroita...
                </div>

                @if ($postcodeSearch !== '' && $postcodeSuggestions->isNotEmpty())
                    <ul class="mt-2 divide-y divide-slate-100 border-y border-slate-200" aria-label="Postinumeroehdotukset">
                        @foreach ($postcodeSuggestions as $suggestion)
                            <li>
                                <button
                                    type="button"
                                    wire:click="selectPostcode('{{ $suggestion->postcode }}')"
                                    wire:loading.attr="disabled"
                                    wire:target="selectPostcode,applyPostcodeSearch,clearPostcodeFilter,restorePostcode"
                                    class="flex w-full items-start justify-between gap-4 px-2 py-2.5 text-left hover:bg-slate-50 focus:bg-slate-50 focus:outline-none disabled:cursor-wait disabled:text-slate-500"
                                >
                                    <span class="font-bold tabular-nums text-slate-900">{{ $suggestion->postcode }}</span>
                                    <span class="min-w-0 text-right text-sm text-slate-600">
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
                        class="mt-3 text-sm font-semibold text-coral-600 underline underline-offset-2 hover:text-coral-700 disabled:cursor-wait disabled:text-slate-500"
                    >
                        Poista postinumero
                    </button>
                @endif
            </div>
        </div>
    </div>
</section>
