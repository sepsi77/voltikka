<div class="bg-white">
    {{-- Schema.org structured data --}}
    <x-schema-markup :schemas="$schemas" />

    {{--
        PHASE 4 COMPOSITION. The page is one editorial column on a white surface:
        every section is an h2 + a hairline rule + whitespace, and the only card
        chrome on the page is on the three alternative-contract tiles. There used to
        be a two-column grid of white rounded panels, which forced a nested-card look
        on every module and pushed the alternatives above the content that justifies
        them. Do not wrap a section in a bordered/rounded/shadowed panel again.

        Generated Finnish sentences live in ContractDetail / ContractCard, never here.
    --}}
    @php
        $companyName = $contract->company?->name ?? '';
        // The seller CTA comes from ContractCardPresenter, which guarantees a destination:
        // one live contract carried neither an order link nor a product link and its page
        // rendered no action at all.
        $sellerCta = $card->sellerCta;
        $secondaryCtaUrl = ($contract->order_link && $contract->product_link) ? $contract->product_link : null;

        $isExcludedPricing = ($isPricingExcluded ?? false) === true;
        $isEstimatePricing = ($calculatedCost['is_estimate'] ?? false) === true;

        // The listing keeps the visitor's consumption across the back navigation, the same
        // rule the listing cards use when they deep-link here.
        $listingUrl = $consumption === 5000 ? '/sahkosopimus' : '/sahkosopimus?kulutus=' . $consumption;

        // Alternatives: the two cheapest contracts plus one of the SAME pricing type.
        // Ranking puts pörssisähkö on top almost everywhere, so the two cheapest tiles
        // are usually spot; a visitor who came for price certainty was offered nothing
        // they would buy. The same-type tile is skipped when it is already shown.
        $alternativeTiles = [];
        foreach ($cheaperContracts->take(2) as $alt) {
            $alternativeTiles[] = [
                'contract' => $alt['contract'],
                'total_cost' => $alt['total_cost'],
                'savings' => $alt['savings'],
                'rank' => $alt['rank'],
                'tag' => null,
            ];
        }
        $shownIds = collect($alternativeTiles)->pluck('contract.id')->all();
        if ($sameTypeAlternative && ! in_array($sameTypeAlternative['contract']->id, $shownIds, true)) {
            $alternativeTiles[] = [
                'contract' => $sameTypeAlternative['contract'],
                'total_cost' => $sameTypeAlternative['total_cost'],
                'savings' => $sameTypeAlternative['savings'],
                'rank' => null,
                'tag' => 'Samantyyppinen · ' . $sameTypeAlternative['label'],
            ];
        }
    @endphp

    {{-- ============================ HERO ============================
         Dark slate-950, single column at content width. Quiet metadata, then two
         beats: price fused with the verdict, then consumption + action. The old
         hero carried a separate verdict card, a CO2 aside and a boxed market-reset
         notice; all three competed with the price and all three are gone.

         SPACING LADDER. The hero is one column of eleven stacked blocks, so the
         only thing that can group them is the interval between them. It ran on
         eleven ad-hoc values between 6 and 28 px, which made a beat boundary
         indistinguishable from a line gap and turned the whole hero into one
         undifferentiated stack. Three steps only, and keep them:

           beat boundary   mt-8 sm:mt-10   (32 / 40)  identity | price | verdict | act
           group boundary  mt-5            (20)       a new thought inside one beat
           inside a group  mt-1.5 .. mt-3  (6 .. 12)  label to value, value to caption

         The beat gap is deliberately ~3x the in-group gap. Anything that narrows
         that ratio brings the wall back. --}}
    <section class="bg-slate-950 text-white">
        <div class="mx-auto max-w-3xl px-5 pt-4 pb-8 sm:pt-7 sm:pb-11">
            <nav aria-label="Murupolku" class="text-sm text-slate-300">
                <ol class="flex flex-wrap items-center gap-x-1.5 gap-y-1">
                    <li>
                        <a href="/" class="rounded-sm py-1 hover:text-white hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-white/60">Etusivu</a>
                    </li>
                    <li aria-hidden="true" class="text-slate-500">/</li>
                    <li>
                        {{-- Back to the listing, carrying the consumption the visitor chose. --}}
                        <a href="{{ $listingUrl }}" class="rounded-sm py-1 hover:text-white hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-white/60">Sähkösopimukset</a>
                    </li>
                    @if ($companyName !== '')
                        <li aria-hidden="true" class="hidden text-slate-500 sm:list-item">/</li>
                        <li class="hidden min-w-0 sm:list-item">
                            @if ($companyInternalUrl)
                                <a href="{{ $companyInternalUrl }}" class="rounded-sm py-1 hover:text-white hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-white/60">{{ $companyName }}</a>
                            @else
                                <span>{{ $companyName }}</span>
                            @endif
                        </li>
                    @endif
                    <li aria-hidden="true" class="text-slate-500">/</li>
                    <li aria-current="page" class="text-slate-400">{{ $displayName }}</li>
                </ol>
            </nav>

            {{-- Quiet metadata: seller, then the pricing category. The category label is
                 a real link that opens the FAQ item explaining the mechanism, because a
                 user simulation found the most important concept on the page rendered as
                 a dead label. --}}
            <p class="mt-5 flex flex-wrap items-center gap-x-2.5 gap-y-2 text-sm font-semibold text-slate-300">
                <x-company-logo
                    :company="$contract->company"
                    :name="$companyName"
                    class="h-7 w-7 shrink-0 rounded-lg bg-slate-800 text-[11px] font-bold text-slate-200"
                    img-class="rounded-lg bg-white p-1"
                />
                <span>{{ $companyName }}</span>
                <span aria-hidden="true" class="text-slate-500">·</span>
                @if ($hasPricingMechanismFaq)
                    <a
                        href="#faq-miten"
                        x-data
                        @click.prevent="
                            const item = document.getElementById('faq-miten');
                            if (! item) return;
                            item.open = true;
                            history.replaceState(null, '', '#faq-miten');
                            item.scrollIntoView({
                                behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth',
                                block: 'center',
                            });
                        "
                        class="rounded-sm border-b border-dotted border-sky-200 py-1 text-sky-200 hover:border-solid hover:text-white focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-white/60"
                    >{{ $card->category->label() }}</a>
                @else
                    <span class="text-slate-200">{{ $card->category->label() }}</span>
                @endif
            </p>

            <h1 class="mt-1.5 text-[28px] font-extrabold leading-[1.1] tracking-tight text-white sm:text-4xl">{{ $displayName }}</h1>

            {{-- ---------- Beat 1: price + verdict, the one dominant statement ---------- --}}
            <div
                {{-- The title and its price are strongly bound, so this beat gap is the
                     softest of the three; the load-bearing boundaries are price|verdict
                     and verdict|act. --}}
                class="mt-7 transition-opacity duration-150 sm:mt-8"
                wire:loading.class.delay="opacity-40"
                wire:target="setConsumption, directConsumption"
            >
                @if ($isExcludedPricing)
                    <p class="text-2xl font-extrabold leading-tight text-white sm:text-3xl">Vuosihintaa ei voi laskea luotettavasti</p>
                    <p class="mt-3 max-w-[60ch] text-[15px] leading-relaxed text-slate-200">
                        Sopimuksen hinnoittelusta puuttuu tietoja, joten emme näytä sille vuosiarviota emmekä sisällytä sitä vertailuun.
                    </p>
                @else
                    @php
                        $heroMonthly = number_format(($calculatedCost['total_cost'] ?? 0) / 12, 2, ',', ' ');
                        [$heroInt, $heroDec] = explode(',', $heroMonthly, 2);
                    @endphp
                    {{-- Not "Hinta-arvio ...": the `Arvio` popover sits six pixels below
                         this label and is the page's single estimate marker. The eyebrow,
                         the pill, the verdict small print and the qualifier between them
                         used to say "arvio" four times inside one screen. --}}
                    <p class="text-sm font-semibold text-slate-300">Hinta seuraavalle 12 kuukaudelle</p>
                    <div class="mt-1.5 flex flex-wrap items-baseline gap-x-4 gap-y-3">
                        <span class="font-extrabold leading-none tracking-tight text-white tabular-nums">
                            <span class="text-[44px] sm:text-[56px]">{{ $heroInt }}</span><span class="text-2xl text-slate-400 sm:text-3xl">,{{ $heroDec }}</span><span class="ml-1 text-lg font-bold text-slate-300 sm:text-[22px]">€/kk</span>
                        </span>
                        {{-- The page's one Arvio popover. The card band deliberately does not
                             carry a second one: two teleported panels share a wire:key. --}}
                        @if ($card->estimate)
                            <span class="self-center">
                                <x-info-popover
                                    label="Arvio"
                                    {{-- The 44px touch target is a transparent `::before` box, not
                                         padding. Padding made the pill 44px TALL beside a 56px
                                         number, so a footnote marker read as a second button
                                         competing with the price. The pill now keeps the
                                         component's own slim card size and the hit area extends
                                         invisibly past it. --}}
                                    trigger-class="relative before:absolute before:inset-x-0 before:top-1/2 before:h-11 before:-translate-y-1/2 before:content-['']"
                                    :heading="$card->estimate->heading"
                                    :body="$card->estimate->body"
                                    :link-url="$card->estimate->linkUrl"
                                    :link-text="$card->estimate->linkText"
                                />
                            </span>
                        @endif
                    </div>
                    <p class="mt-2 text-[15px] text-slate-300 tabular-nums">
                        {{ number_format($calculatedCost['total_cost'] ?? 0, 0, ',', ' ') }} € vuodessa ·
                        {{ number_format($consumption, 0, ',', ' ') }} kWh vuosikulutuksella ·
                        sisältää alv 25,5 %
                    </p>

                    {{-- ---------- The verdict, fused with the price rather than boxed
                         beside it. Its four parts read top to bottom as statement, then
                         the same statement drawn, then the way out, then the small print.
                         `katso halvemmat` used to be a third `·` clause inside the rank
                         sentence, where at 390 px it landed mid-wrap and the one action in
                         the beat was buried in running text. It is its own line now. --}}
                    @if ($heroVerdict)
                        <div class="mt-8 sm:mt-10">
                            <p class="text-[17px] leading-relaxed text-slate-200 tabular-nums">
                                <strong class="text-xl font-extrabold text-white">Sija {{ number_format($heroVerdict['rank'], 0, ',', ' ') }}</strong>
                                / {{ number_format($heroVerdict['total'], 0, ',', ' ') }} sopimuksesta
                                @if ($heroVerdict['comparison'])
                                    <span aria-hidden="true" class="text-slate-500">·</span> {{ $heroVerdict['comparison'] }}
                                @endif
                            </p>

                            {{-- Halvin–kallein rail with the contract's own marker, and the
                                 way out beside it. The rail is wider than the old 340 px so
                                 the marker resolves a position on it rather than reading as
                                 a dot beside a label; the link takes the column width the
                                 rail leaves over, so pulling it out of the rank sentence
                                 costs no height above `sm`. Below `sm` it wraps under. --}}
                            <div class="mt-3 flex flex-wrap items-end gap-x-6">
                                {{-- The rail carries the whole market, and the lit part of it
                                     is the share that is cheaper than this contract, so rank 1
                                     leaves it dark and rank 253/291 leaves it almost fully lit.
                                     It was a dot on an even bar, which said only "somewhere".

                                     `aria-hidden` on the group: "halvin / kallein" read aloud
                                     after "Sija 253 / 291 sopimuksesta · 259 €/v kalliimpi kuin
                                     halvin" is noise, and the sentence already carries every
                                     fact the rail draws.

                                     Motion: transforms only, 300 ms, exponential ease-out, and
                                     off under `prefers-reduced-motion`. `left`/`width` are
                                     layout properties and DESIGN.md does not animate those, so
                                     the fill scales and the marker layer translates by a
                                     percentage of its own full-track width. --}}
                                <div aria-hidden="true" class="w-[420px] max-w-full">
                                    <div class="relative h-1.5">
                                        <div class="absolute inset-0 overflow-hidden rounded-full bg-white/10">
                                            <div
                                                class="h-full w-full origin-left rounded-full bg-white/40 transition-transform duration-300 ease-[cubic-bezier(0.16,1,0.3,1)] motion-reduce:transition-none"
                                                style="transform: scaleX({{ $heroVerdict['marker_percent'] / 100 }});"
                                            ></div>
                                        </div>
                                        <span
                                            class="absolute left-0 top-0 block w-full transition-transform duration-300 ease-[cubic-bezier(0.16,1,0.3,1)] motion-reduce:transition-none"
                                            style="transform: translateX({{ $heroVerdict['marker_percent'] }}%);"
                                        >
                                            <span class="block h-4 w-4 -translate-x-1/2 -translate-y-[5px] rounded-full border-[3px] border-slate-950 bg-coral-500"></span>
                                        </span>
                                    </div>
                                    <div class="mt-1.5 flex justify-between text-sm text-slate-300">
                                        <span>halvin</span>
                                        <span>kallein</span>
                                    </div>
                                </div>

                                @if ($heroVerdict['show_cheaper_link'])
                                    <a
                                        href="#halvemmat"
                                        x-data
                                        @click.prevent="
                                            const target = document.getElementById('halvemmat');
                                            if (! target) return;
                                            target.scrollIntoView({
                                                behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth',
                                                block: 'start',
                                            });
                                        "
                                        {{-- `gap-1.5`, not a literal space: an inline-flex box
                                             discards the whitespace between its items and the
                                             arrow rendered flush against the word. --}}
                                        class="-mb-1.5 inline-flex min-h-[44px] items-center gap-1.5 rounded-sm text-[15px] font-semibold text-coral-400 hover:text-coral-300 hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-coral-400"
                                        {{-- Deliberately shorter than the "Katso halvemmat vaihtoehdot"
                                             link that closes "Kannattaako X?" just below: two
                                             identical coral links a screen apart read as a repeat. --}}
                                    >Katso halvemmat <span aria-hidden="true">↓</span></a>
                                @endif
                            </div>

                            <p class="mt-3 max-w-[60ch] text-sm leading-relaxed text-slate-300">{{ $heroVerdict['note'] }}</p>
                        </div>
                    @endif

                    {{-- Category-specific price qualifier: what the figure above actually is.
                         This is the page's only "arvio, ei hintalupaus" statement. It is a
                         new thought inside the verdict beat, so it takes the group interval
                         rather than a beat gap. --}}
                    @if ($priceQualifier)
                        <p class="mt-5 max-w-[60ch] text-[15px] leading-relaxed text-slate-200">{{ $priceQualifier }}</p>
                    @endif
                @endif
            </div>

            {{-- ---------- Beat 2: consumption + action ----------
                 The picker sits ABOVE the seller CTA on purpose: the visitor must be able
                 to put their own consumption in before the page asks them to act on the
                 price. The active chip is a white-on-dark inversion, never white on coral
                 (2,8:1). Every control is at least 44px high. --}}
            <div id="consumption-picker" class="mt-8 scroll-mt-20 sm:mt-10">
                <p class="text-sm font-semibold text-slate-300">Laske omalla kulutuksellasi</p>
                <div
                    class="mt-3 flex flex-wrap items-stretch gap-2"
                    wire:loading.class.delay="opacity-60"
                    wire:target="setConsumption, directConsumption"
                >
                    @foreach ($presets as $label => $value)
                        <button
                            type="button"
                            data-consumption-preset="{{ $value }}"
                            wire:click="setConsumption({{ $value }})"
                            wire:loading.attr="disabled"
                            wire:target="setConsumption"
                            aria-pressed="{{ $consumption === $value ? 'true' : 'false' }}"
                            class="min-h-[44px] rounded-xl border px-4 py-2 text-left text-sm font-semibold transition disabled:cursor-wait focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-white/60 {{ $consumption === $value ? 'border-white bg-white text-slate-900' : 'border-white/20 bg-white/[0.06] text-slate-200 hover:bg-white/[0.12]' }}"
                        >
                            {{ $label }}
                            <span class="block text-xs font-normal tabular-nums {{ $consumption === $value ? 'text-slate-500' : 'text-slate-300' }}">
                                {{ number_format($value, 0, ',', ' ') }} kWh
                            </span>
                        </button>
                    @endforeach

                    {{-- Sized to its content, not stretched to the column edge: a 4-digit
                         field widened to fill the row's slack reads as the primary input
                         and dwarfs the chips it sits beside. The row's ragged right edge
                         is correct for a control cluster. --}}
                    <label class="flex min-h-[44px] items-center gap-2 rounded-xl border border-white/20 bg-white/[0.06] px-4 py-2 focus-within:border-white/60">
                        <span class="sr-only">Oma vuosikulutus kilowattitunteina</span>
                        <input
                            type="number"
                            min="{{ \App\Livewire\ContractDetail::MIN_FREE_CONSUMPTION }}"
                            max="{{ \App\Livewire\ContractDetail::MAX_FREE_CONSUMPTION }}"
                            step="100"
                            inputmode="numeric"
                            wire:model.blur="directConsumption"
                            @keydown.enter.prevent="$event.target.blur()"
                            placeholder="Oma"
                            class="w-20 bg-transparent text-sm font-semibold text-white tabular-nums placeholder:font-normal placeholder:text-slate-400 focus:outline-none"
                        >
                        <span class="shrink-0 text-xs text-slate-300">kWh/v</span>
                    </label>
                </div>
                @if ($presetNotice)
                    <p class="mt-2 max-w-[60ch] text-sm text-slate-300">{{ $presetNotice }}</p>
                @endif
                @if ($rankBasisNotice)
                    <p class="mt-2 max-w-[60ch] text-sm text-slate-300">{{ $rankBasisNotice }}</p>
                @endif

                {{-- The "Tiedätkö tarkan laskusi? Vertaa sähkölaskuusi ↓" link used to close
                     this block. It existed to stop a visitor landing on a collapsed heading,
                     and the bill module is now the first section under the hero and open by
                     default, so the link advertised something already visible. Do not add it
                     back without a reason that survives that change; the section still listens
                     for `open-bill-comparison` so any future opener works. --}}
            </div>

            @if ($sellerCta)
                <div id="hero-cta" class="mt-5 flex flex-wrap items-center gap-x-7 gap-y-4">
                    {{-- Flat coral-600 at 19px/700: large-text 3:1 against white. The old
                         gradient + coral glow put white on coral-500 (2,8:1). --}}
                    <a
                        href="{{ $sellerCta->url }}"
                        @if ($sellerCta->external) target="_blank" rel="noopener noreferrer" @endif
                        data-first-party-analytics="contract_order_click"
                        data-analytics-placement="hero"
                        @auxclick="
                            if ($event.button === 1 && window.voltikkaAnalytics) {
                                window.voltikkaAnalytics.trackContractOrderClick({
                                    context: @js($contractOrderClickContext),
                                    placement: 'hero'
                                });
                            }
                        "
                        @click="
                            window.voltikkaAnalytics && window.voltikkaAnalytics.trackContractOrderClick({
                                context: @js($contractOrderClickContext),
                                placement: 'hero'
                            });
                            $track('Contract Order Clicked', {
                                props: {
                                    contract_id: @js($contract->id),
                                    company: @js($companyName),
                                    pricing_model: @js($contract->pricing_model)
                                }
                            });
                        "
                        class="inline-flex min-h-[52px] items-center justify-center gap-2.5 rounded-xl bg-coral-600 px-7 py-3.5 text-[19px] font-bold text-white transition-colors hover:bg-coral-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white"
                    >
                        {{ $sellerCta->label }}
                        <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"></path>
                        </svg>
                    </a>
                    <p class="max-w-[34ch] text-sm leading-relaxed text-slate-300">
                        <strong class="font-semibold text-slate-200">Tilaus tehdään suoraan sähköyhtiön sivuilla.</strong>
                        Voltikka ei saa provisiota eikä näytä mainoksia.
                    </p>
                </div>
                @if ($secondaryCtaUrl)
                    <p class="mt-3">
                        <a
                            href="{{ $secondaryCtaUrl }}"
                            target="_blank"
                            rel="noopener noreferrer"
                            @click="$track('Contract Info Clicked', {
                                contract_id: {{ $contract->id }},
                                company: '{{ addslashes($companyName) }}'
                            })"
                            class="inline-flex min-h-[44px] items-center rounded-sm text-sm font-medium text-slate-200 underline underline-offset-2 hover:text-white focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-white/60"
                        >
                            Lisätietoja sopimuksesta myyjän sivuilla
                        </a>
                    </p>
                @endif
            @endif
        </div>
    </section>

    <main class="mx-auto max-w-3xl px-5 pb-20">
        {{-- Inactive contract notice. Slate, not amber: amber is an emissions tier. --}}
        @if (! $this->isActive)
            <p class="mt-8 rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-[15px] font-medium text-slate-800">
                Tämä sopimus ei ole enää tarjolla. Sivu on tallessa hintahistorian vuoksi.
            </p>
        @endif

        {{-- ============================ Vertaa nykyiseen sähkölaskuusi ============================
             One bill, this contract, the same billing period. Period basis only, exactly
             like the in-listing mode: the bill total is the anchor and no annual figure is
             derived from it. Per-user compute that never enters the page's prepared payload
             cache.

             It is the FIRST section under the hero, and it is OPEN by default. It used to sit
             below Hintatiedot and later below "Kannattaako X?", collapsed, which put the
             page's strongest personalisation surface roughly 3.4 phone screens down behind
             the largest section on the page. It is the second rung of the ladder the hero's
             consumption picker starts, and the hero links down to it by name.

             Being first under the dark hero, it must not draw a rule against it, so this
             section carries no top border. Hintatiedot below it owns that rule instead. --}}
        @if ($showBillComparison)
            <section
                id="vertaa-laskuun"
                class="scroll-mt-20 py-10 sm:py-11"
                x-data="{ billOpen: true }"
                @open-bill-comparison="billOpen = true"
            >
                {{-- The disclosure trigger stays inside an h2 so the restructured page keeps
                     one flat h1 → h2 outline; a bare button would drop this module out of
                     the heading list entirely. --}}
                <h2>
                    <button
                        type="button"
                        @click="billOpen = !billOpen"
                        :aria-expanded="billOpen ? 'true' : 'false'"
                        aria-controls="vertaa-laskuun-paneeli"
                        class="flex w-full min-h-[44px] items-start justify-between gap-4 rounded-sm text-left focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-coral-500"
                    >
                        <span>
                            <span class="block text-[22px] font-bold text-slate-900">Vertaa nykyiseen sähkölaskuusi</span>
                            <span class="mt-1 block max-w-[65ch] text-[15px] font-normal text-slate-500">
                                Syötä yhden laskun tiedot, niin näytämme mitä tämä sopimus olisi maksanut samalta jaksolta.
                            </span>
                        </span>
                        {{-- Object syntax, and `rotate-180` also in the static class list: the
                             panel is open in the server HTML, so the chevron must already be
                             flipped there or it visibly spins once on Alpine init. Object
                             syntax removes the class again when the visitor collapses it. --}}
                        <svg class="mt-1 h-5 w-5 shrink-0 rotate-180 text-slate-400 transition-transform" :class="{ 'rotate-180': billOpen }" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"></path>
                        </svg>
                    </button>
                </h2>

                {{-- No `x-cloak`: it would hide a panel that is open by default until Alpine
                     boots, which is the flash it exists to prevent, inverted. --}}
                <div id="vertaa-laskuun-paneeli" x-show="billOpen" x-collapse class="pt-6">
                    @include('partials.bill-comparison-form', [
                        'idPrefix' => 'detail-bill',
                        'totalLabel' => 'Sähköenergian osuus (€)',
                    ])

                    @if ($billComparison)
                        <div class="mt-6 border-t border-slate-200 pt-5" wire:loading.class.delay="opacity-40" wire:target="billKwh, billTotalEur, billStartDate, billEndDate, billIncludesVat, setBillPeriodPreset">
                            <p class="text-sm font-semibold text-slate-500">
                                Sama jakso {{ $billComparison['period_label'] }} · {{ number_format($billComparison['kwh'], 0, ',', ' ') }} kWh
                            </p>

                            @if ($billComparison['available'])
                                <div class="mt-4 grid grid-cols-1 gap-5 sm:grid-cols-[1fr_1fr_auto] sm:items-center">
                                    <div>
                                        <p class="text-sm font-semibold text-slate-500">Maksoit nykyisellä sopimuksellasi</p>
                                        <p class="mt-1 text-2xl font-extrabold text-slate-900 tabular-nums">
                                            {{ number_format($billComparison['user_total'], 2, ',', ' ') }}<span class="ml-1 text-sm font-semibold text-slate-500">€</span>
                                        </p>
                                    </div>
                                    <div>
                                        <p class="text-sm font-semibold text-slate-500">{{ $billComparison['contract_name'] }} olisi maksanut</p>
                                        <p class="mt-1 text-2xl font-extrabold text-slate-900 tabular-nums">
                                            {{ number_format($billComparison['contract_cost'], 2, ',', ' ') }}<span class="ml-1 text-sm font-semibold text-slate-500">€</span>
                                        </p>
                                    </div>
                                    {{-- Savings are neutral slate, never green: green and red are the
                                         CO2 delta's on this site (DESIGN.md). Paying more is a coral
                                         warning, the same language as the card warning pills. --}}
                                    <p class="inline-flex items-center self-start rounded-full px-4 py-2 text-[15px] font-bold sm:self-center
                                        {{ $billComparison['verdict'] === 'costs_more'
                                            ? 'bg-coral-50 border border-coral-200 text-coral-700'
                                            : ($billComparison['verdict'] === 'saves'
                                                ? 'bg-slate-900 border border-slate-900 text-white'
                                                : 'bg-slate-100 border border-slate-200 text-slate-700') }}">
                                        {{ $billComparison['delta_label'] }}
                                    </p>
                                </div>

                                <p class="mt-4 max-w-[70ch] text-sm text-slate-600">
                                    {{ $billComparison['basis'] }}
                                    Jakson hinnaksi tulisi {{ number_format($billComparison['implied_cents'], 2, ',', ' ') }} c/kWh perusmaksu mukaan lukien.
                                </p>

                                @if ($billComparison['verdict'] === 'costs_more' && ! empty($alternativeTiles))
                                    <p class="mt-3">
                                        <a
                                            href="#halvemmat"
                                            class="inline-flex min-h-[44px] items-center gap-1 rounded-sm text-[15px] font-semibold text-coral-600 hover:text-coral-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-coral-500"
                                            x-data
                                            @click.prevent="
                                                const target = document.getElementById('halvemmat');
                                                if (! target) return;
                                                target.scrollIntoView({
                                                    behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth',
                                                    block: 'start',
                                                });
                                            "
                                        >
                                            Katso halvemmat vaihtoehdot
                                            <span aria-hidden="true">↓</span>
                                        </a>
                                    </p>
                                @endif
                            @else
                                {{-- Honest unavailability: the module says why instead of rendering
                                     an empty or zero result. --}}
                                <p class="mt-3 max-w-[70ch] text-[15px] leading-relaxed text-slate-700">
                                    {{ $billComparison['message'] }}
                                </p>
                            @endif

                            <p class="mt-4">
                                <button type="button" wire:click="clearBill" class="min-h-[44px] rounded-sm text-sm font-medium text-slate-500 underline underline-offset-2 hover:text-slate-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-coral-500">
                                    Tyhjennä laskun tiedot
                                </button>
                            </p>
                        </div>
                    @endif
                </div>
            </section>
        @endif

        {{-- ============================ Hintatiedot ============================
             The bill module above it is optional, so this section draws the rule only when
             something is actually above it to rule against. --}}
        <section id="hintatiedot" class="scroll-mt-20 py-10 sm:py-11 {{ $showBillComparison ? 'border-t border-slate-200' : '' }}">
            <h2 class="text-[22px] font-bold text-slate-900">Hintatiedot</h2>

            {{-- The pricing category, from the same presenter and with the same tint as
                 the listing card that linked here. Single purpose: it states the category
                 and never a warning. The Arvio marker lives in the hero, on the number it
                 qualifies, so this band deliberately passes no estimate. --}}
            <div class="mt-4 overflow-hidden rounded-lg">
                <x-card.band :band="$card->band" :estimate="null" />
            </div>

            {{-- Pricing-integrity notice: shown only for validated deceptive/conflicting
                 pricing. Coral, not amber: warnings are coral on this site and amber is an
                 emissions tier. --}}
            @if (($pricingIntegrity['detected'] ?? false) && ! empty($pricingIntegrity['detail_facts']))
                <div class="mt-5 rounded-xl border border-coral-200 bg-coral-50 px-5 py-4">
                    <p class="text-sm font-bold text-coral-800">{{ $pricingIntegrity['detail_heading'] ?? 'Huomio hinnoittelusta' }}</p>
                    <ul class="mt-1.5 list-inside list-disc space-y-1 text-sm text-coral-800">
                        @foreach ($pricingIntegrity['detail_facts'] as $fact)
                            <li>{{ $fact }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            {{-- Itemised price rows, from ContractCard\CardReceiptLines. This block used to
                 be hand-rolled here and it drifted below the listing card's honesty; the
                 presenter states the mechanism instead of guessing it from one relational
                 component. --}}
            <div class="mt-5">
                <x-card.receipt :lines="$card->receiptLines" />
            </div>

            {{-- Quiet notes: what the reset estimate reads, and what a promotion is worth.
                 Both replace a block that duplicated the hero (a boxed reset notice and a
                 TARJOUS mini-hero with its own price). --}}
            @foreach ($receiptNotes as $note)
                <p class="mt-3 max-w-[65ch] text-sm leading-relaxed text-slate-500">{{ $note }}</p>
            @endforeach

            {{-- Coral warning pills, priority ordered and capped at two by
                 ContractCard\CardFooterItems. --}}
            @if (count($card->warnings) > 0)
                <div class="mt-4">
                    <x-card.footer :warnings="$card->warnings" />
                </div>
            @endif

            {{-- Static per-consumption cost table. Server-rendered for every visitor
                 regardless of the interactive selection, because "paljonko tämä sopimus
                 maksaa 18 000 kWh kulutuksella" is a search query and the answer has to be
                 in the initial HTML. Costs come from the same calculation path as the hero
                 price, so the two cannot disagree. --}}
            @if (! empty($consumptionCostTable))
                <div class="mt-8 overflow-x-auto">
                    <table class="w-full border-collapse text-[15px]">
                        <caption class="pb-2.5 text-left text-[15px] font-bold text-slate-900">
                            Arvioitu kustannus eri vuosikulutuksilla
                        </caption>
                        <thead>
                            <tr class="border-b border-slate-200 text-sm font-semibold text-slate-500">
                                <th scope="col" class="py-2 pr-3 text-left font-semibold">Vuosikulutus</th>
                                <th scope="col" class="py-2 px-3 text-right font-semibold">€/kk</th>
                                <th scope="col" class="py-2 pl-3 text-right font-semibold">€/vuosi</th>
                            </tr>
                        </thead>
                        <tbody class="tabular-nums">
                            @foreach ($consumptionCostTable as $row)
                                <tr class="border-b border-slate-100 {{ $row['consumption'] === $consumption ? 'bg-slate-50 text-slate-900' : 'text-slate-600' }}">
                                    <th scope="row" class="py-2.5 pr-3 text-left {{ $row['consumption'] === $consumption ? 'font-semibold text-slate-900' : 'font-normal' }}">
                                        {{ number_format($row['consumption'], 0, ',', ' ') }} kWh
                                        <span class="font-normal text-slate-500">· {{ $row['hint'] }}</span>
                                    </th>
                                    @if ($row['total_cost'] !== null)
                                        <td class="py-2.5 px-3 text-right font-bold text-slate-900">{{ number_format($row['monthly_cost'], 2, ',', ' ') }}</td>
                                        <td class="py-2.5 pl-3 text-right">{{ number_format($row['total_cost'], 0, ',', ' ') }}</td>
                                    @else
                                        <td class="py-2.5 px-3 text-right text-slate-500" colspan="2">Ei saatavilla tällä kulutuksella</td>
                                    @endif
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                    <p class="mt-2 text-sm text-slate-500">
                        12 kuukauden arvio ilman siirtomaksuja, hinnat sisältävät alv 25,5 %. Valittu kulutus on korostettu.
                    </p>
                </div>
            @endif

            {{-- The counterfactual: the alternative the visitor is really deciding against.
                 Sentence generated in ContractDetail from typed fields. --}}
            @if ($spotCounterfactual)
                <p class="mt-6 max-w-[65ch] text-[15px] leading-relaxed text-slate-600">
                    {{ $spotCounterfactual['text'] }}
                    <a href="{{ $spotCounterfactual['url'] }}" class="whitespace-nowrap rounded-sm font-semibold text-coral-600 hover:text-coral-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-coral-500">{{ $spotCounterfactual['label'] }} →</a>
                </p>
            @endif
        </section>

        {{-- ============================ Näin hinta on kehittynyt ============================
             One module, one chart. The payload is
             ContractDetail::getPriceDevelopmentProperty(); do not compute chart geometry
             in this template again. --}}
        @if (count($priceHistory) > 0 || count($contractHistory) > 1 || ! $isActive)
            @php
                // Which component the version-to-version delta chips describe.
                // $priceTypeOrder covers unrecognized upstream types too.
                $dedupedHistory = [];
                foreach ($priceHistory as $type => $history) {
                    $sorted = collect($history)->sortBy('date')->values();
                    $previous = null;
                    $rows = [];
                    foreach ($sorted as $record) {
                        if ($previous === null || (float) $record['price'] !== (float) $previous['price']) {
                            $rows[] = $record;
                        }
                        $previous = $record;
                    }
                    if (count($rows) >= 1) {
                        $dedupedHistory[$type] = $rows; // oldest → newest
                    }
                }

                $primaryType = null;
                foreach ($priceTypeOrder as $candidate) {
                    if (! empty($dedupedHistory[$candidate]) && count($dedupedHistory[$candidate]) >= 2) {
                        $primaryType = $candidate;
                        break;
                    }
                }

                $deltaUnit = $primaryType === 'Monthly' ? '€/kk' : 'c/kWh';
                $deltaSubject = $primaryType ? ($priceTypeLabels[$primaryType] ?? $primaryType) : 'Hinta';

                // Match on the component type, not the label: two types can share a label
                // (both winter spellings are "Talvihinta") and a label match would then
                // read the wrong row's price into the delta chip.
                $lookupPrice = function (array $entry) use ($primaryType): ?float {
                    if (! $primaryType) return null;
                    foreach ($entry['prices'] as $p) {
                        if ($p['type'] === $primaryType) return (float) $p['price'];
                    }
                    return null;
                };

                $timeline = [];
                foreach ($contractHistory as $i => $entry) {
                    $current = $lookupPrice($entry);
                    $next = $contractHistory[$i + 1] ?? null; // older
                    $previousPrice = $next ? $lookupPrice($next) : null;
                    $delta = null;
                    if ($current !== null && $previousPrice !== null && abs($current - $previousPrice) > 0.0001) {
                        $delta = $current - $previousPrice;
                    }
                    $timeline[] = array_merge($entry, ['delta_to_previous' => $delta]);
                }

                $currentHistoryEntry = collect($contractHistory)->firstWhere('is_current', true);
                $lastSeenOnSaleDate = $currentHistoryEntry['last_seen_on_sale_date'] ?? null;

                // Past three versions the raw list stops being reading matter.
                $visibleTimeline = array_slice($timeline, 0, 3);
                $hiddenTimeline = array_slice($timeline, 3);

                $chart = $priceDevelopment['chart'] ?? null;
            @endphp

            <section id="hintakehitys" class="scroll-mt-20 border-t border-slate-200 py-10 sm:py-11">
                <div class="flex flex-wrap items-baseline justify-between gap-x-6 gap-y-2">
                    <div>
                        <h2 class="text-[22px] font-bold text-slate-900">Näin hinta on kehittynyt</h2>
                        @if (! empty($priceDevelopment['subtitle']))
                            <p class="mt-1 text-[15px] text-slate-500">{{ $priceDevelopment['subtitle'] }}</p>
                        @endif
                    </div>

                    @if ($chart)
                        {{-- Legend, so series identity is never colour-alone. --}}
                        <ul class="flex flex-wrap gap-x-5 gap-y-2 text-sm text-slate-600">
                            <li class="inline-flex items-center gap-2">
                                <svg width="22" height="10" viewBox="0 0 22 10" aria-hidden="true">
                                    <line x1="0" y1="5" x2="22" y2="5" stroke="{{ $chart['ink'] }}" stroke-width="2.5" stroke-linecap="round"/>
                                </svg>
                                <span class="font-semibold text-slate-900">{{ $chart['series_label'] }}</span>
                            </li>
                            @if ($chart['reference_path'] !== '')
                                <li class="inline-flex items-center gap-2">
                                    <svg width="22" height="10" viewBox="0 0 22 10" aria-hidden="true">
                                        <line x1="0" y1="5" x2="22" y2="5" stroke="{{ $chart['reference_colour'] }}" stroke-width="2" stroke-dasharray="6 5" stroke-linecap="round"/>
                                    </svg>
                                    <span>{{ $chart['reference_label'] }}</span>
                                </li>
                            @endif
                        </ul>
                    @endif
                </div>

                @if ($chart)
                    <div class="relative mt-4" x-data="{ open: false, title: '', series: '', reference: '', tipX: 0, tipY: 0 }" x-ref="wrap">
                        <div class="overflow-x-auto">
                            <div class="min-w-[560px]">
                                <svg viewBox="{{ $chart['view_box'] }}" width="100%" class="block h-auto tabular-nums"
                                     role="img" aria-label="{{ $chart['aria_label'] }}">
                                    {{-- Gridlines --}}
                                    <g stroke="#f1f5f9" stroke-width="1">
                                        @foreach ($chart['y_ticks'] as $tick)
                                            <line x1="{{ $chart['plot']['left'] }}" y1="{{ $tick['y'] }}" x2="{{ $chart['plot']['right'] + 60 }}" y2="{{ $tick['y'] }}"/>
                                        @endforeach
                                    </g>

                                    {{-- Signed zero baseline. Only drawn when the data actually
                                         crosses zero, so a positive-only chart shows no false rule. --}}
                                    @if ($chart['zero_y'] !== null)
                                        <line x1="{{ $chart['plot']['left'] }}" y1="{{ $chart['zero_y'] }}" x2="{{ $chart['plot']['right'] + 60 }}" y2="{{ $chart['zero_y'] }}"
                                              stroke="#94a3b8" stroke-width="1.5"/>
                                        <text x="{{ $chart['plot']['left'] - 8 }}" y="{{ $chart['zero_y'] + 4 }}" font-size="12" fill="#64748b" text-anchor="end">0</text>
                                    @endif

                                    {{-- Y axis --}}
                                    <g font-size="12" fill="#64748b" text-anchor="end">
                                        @foreach ($chart['y_ticks'] as $tick)
                                            <text x="{{ $chart['plot']['left'] - 8 }}" y="{{ $tick['y'] + 4 }}">{{ $tick['label'] }}</text>
                                        @endforeach
                                    </g>
                                    <text x="16" y="18" font-size="12" fill="#64748b">{{ $chart['unit'] }}</text>

                                    {{-- X axis --}}
                                    <g font-size="12" fill="#64748b" text-anchor="middle">
                                        @foreach ($chart['rows'] as $row)
                                            <text x="{{ $row['label_x'] }}" y="{{ $chart['plot']['bottom'] + 28 }}">{{ $row['label'] }}</text>
                                        @endforeach
                                    </g>

                                    {{-- Market reference: dashed, with a direct label --}}
                                    @if ($chart['reference_path'] !== '')
                                        <path d="{{ $chart['reference_path'] }}" fill="none" stroke="{{ $chart['reference_colour'] }}"
                                              stroke-width="2" stroke-dasharray="6 5" stroke-linecap="round" stroke-linejoin="round"/>
                                        @if ($chart['reference_end_label'])
                                            <text x="{{ $chart['reference_end_label']['x'] }}" y="{{ $chart['reference_end_label']['y'] }}"
                                                  font-size="12" fill="{{ $chart['reference_colour'] }}">{{ $chart['reference_end_label']['text'] }}</text>
                                        @endif
                                    @endif

                                    {{-- The contract itself: slate-900 ink --}}
                                    <path d="{{ $chart['series_path'] }}" fill="none" stroke="{{ $chart['ink'] }}"
                                          stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/>
                                    @if ($chart['show_points'])
                                        <g fill="{{ $chart['ink'] }}">
                                            @foreach ($chart['series_points'] as $point)
                                                <circle cx="{{ $point['x'] }}" cy="{{ $point['y'] }}" r="4"/>
                                            @endforeach
                                        </g>
                                    @endif
                                    @if ($chart['series_end_label'])
                                        <text x="{{ $chart['series_end_label']['x'] }}" y="{{ $chart['series_end_label']['y'] }}"
                                              font-size="12" font-weight="700" fill="{{ $chart['ink'] }}">{{ $chart['series_end_label']['text'] }}</text>
                                    @endif

                                    {{-- Hover bands. The sr-only table below carries the same
                                         numbers for anyone not using a pointer. --}}
                                    <g aria-hidden="true">
                                        @foreach ($chart['rows'] as $row)
                                            <rect x="{{ $row['x'] }}" y="{{ $chart['plot']['top'] - 10 }}"
                                                  width="{{ $row['width'] }}" height="{{ $chart['plot']['height'] + 20 }}"
                                                  fill="transparent"
                                                  data-title="{{ $row['title'] }}"
                                                  data-series="{{ $row['series'] ?? '' }}"
                                                  data-reference="{{ $row['reference'] ?? '' }}"
                                                  @mousemove="
                                                      open = true;
                                                      title = $el.dataset.title;
                                                      series = $el.dataset.series;
                                                      reference = $el.dataset.reference;
                                                      const box = $refs.wrap.getBoundingClientRect();
                                                      tipX = Math.min($event.clientX - box.left + 14, box.width - 210);
                                                      tipY = $event.clientY - box.top - 12;
                                                  "
                                                  @mouseleave="open = false"/>
                                        @endforeach
                                    </g>
                                </svg>
                            </div>
                        </div>

                        <div x-show="open" x-cloak :style="'left: ' + tipX + 'px; top: ' + tipY + 'px'"
                             class="pointer-events-none absolute z-10 rounded-lg border border-slate-200 bg-white px-3 py-2 text-[13px] leading-relaxed text-slate-600 shadow-md">
                            <strong class="block text-slate-900" x-text="title"></strong>
                            <span x-show="series !== ''" class="block">
                                {{ $chart['tooltip_series_label'] }}:
                                <strong class="text-slate-900 tabular-nums" x-text="series + ' {{ $chart['unit'] }}'"></strong>
                            </span>
                            <span x-show="reference !== ''" class="block">
                                {{ $chart['tooltip_reference_label'] }}:
                                <span class="tabular-nums" x-text="reference + ' {{ $chart['unit'] }}'"></span>
                            </span>
                        </div>
                    </div>

                    {{-- Accessible mirror of the chart. The wrapper carries `sr-only`, not the
                         table: `display: table` ignores the 1px width. --}}
                    <div class="sr-only">
                        <table>
                            <caption>{{ $chart['aria_label'] }}</caption>
                            <thead>
                                <tr>
                                    <th>Jakso</th>
                                    <th>{{ $chart['tooltip_series_label'] }} ({{ $chart['unit'] }})</th>
                                    @if ($chart['reference_path'] !== '')
                                        <th>{{ $chart['tooltip_reference_label'] }} ({{ $chart['unit'] }})</th>
                                    @endif
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($chart['rows'] as $row)
                                    <tr>
                                        <td>{{ $row['title'] }}</td>
                                        <td>{{ $row['series'] ?? 'ei tietoa' }}</td>
                                        @if ($chart['reference_path'] !== '')
                                            <td>{{ $row['reference'] ?? 'ei tietoa' }}</td>
                                        @endif
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @elseif (! empty($priceDevelopment['message']))
                    {{-- Honest empty state: say the window is too short, never draw a flat line
                         through one observation. --}}
                    <p class="mt-4 max-w-[70ch] text-[15px] leading-relaxed text-slate-700">
                        {{ $priceDevelopment['message'] }}
                    </p>
                @endif

                @if (! empty($priceDevelopment['note']))
                    <p class="mt-4 max-w-[70ch] text-sm leading-relaxed text-slate-500">{{ $priceDevelopment['note'] }}</p>
                @endif

                {{-- Seller behaviour record. Only tags whose data exists are built, and every
                     figure in them is c/kWh or €/kk, never a percentage. --}}
                @if (! empty($priceDevelopment['facts']))
                    <ul class="mt-5 flex flex-wrap gap-2">
                        @foreach ($priceDevelopment['facts'] as $fact)
                            <li class="inline-flex items-center rounded-lg bg-slate-100 px-3 py-1.5 text-sm font-semibold text-slate-600 tabular-nums">
                                {{ $fact }}
                            </li>
                        @endforeach
                    </ul>
                @endif

                {{-- Version timeline --}}
                <ol class="relative mt-7">
                    @if (! $isActive)
                        <li class="relative pb-6 pl-7 sm:pl-8">
                            <span aria-hidden="true" class="absolute left-[7px] top-5 bottom-0 w-[2px] rounded-full bg-slate-200"></span>
                            <span aria-hidden="true" class="absolute left-0 top-1.5 flex h-4 w-4 items-center justify-center">
                                <span class="block h-3 w-3 rounded-full bg-slate-600 ring-4 ring-slate-100"></span>
                            </span>

                            <div class="space-y-1.5">
                                <div class="text-sm font-semibold text-slate-900">Nyt</div>
                                <div class="text-sm font-medium text-slate-800">Sopimus ei ole enää myynnissä</div>
                                <p class="text-sm text-slate-500">
                                    @if ($lastSeenOnSaleDate)
                                        Viimeksi havaittu myynnissä <time datetime="{{ $lastSeenOnSaleDate->format('Y-m-d') }}">{{ $lastSeenOnSaleDate->translatedFormat('j.n.Y') }}</time>.
                                    @else
                                        Viimeinen havainto myynnissä ei ole tiedossa.
                                    @endif
                                </p>
                            </div>
                        </li>
                    @endif

                    @foreach ($visibleTimeline as $i => $entry)
                        @include('partials.contract-version-timeline-item', [
                            'entry' => $entry,
                            'showConnector' => $i < count($visibleTimeline) - 1 || count($hiddenTimeline) > 0,
                            'deltaUnit' => $deltaUnit,
                            'deltaSubject' => $deltaSubject,
                        ])
                    @endforeach
                </ol>

                @if (count($hiddenTimeline) > 0)
                    <details class="group mt-2">
                        <summary class="flex min-h-[44px] cursor-pointer list-none items-center text-sm font-semibold text-slate-600 hover:text-slate-900">
                            Näytä {{ count($hiddenTimeline) }} vanhempaa versiota
                            <span aria-hidden="true" class="ml-1 inline-block transition-transform group-open:rotate-180">▾</span>
                        </summary>
                        <ol class="relative mt-4">
                            @foreach ($hiddenTimeline as $i => $entry)
                                @include('partials.contract-version-timeline-item', [
                                    'entry' => $entry,
                                    'showConnector' => $i < count($hiddenTimeline) - 1,
                                    'deltaUnit' => $deltaUnit,
                                    'deltaSubject' => $deltaSubject,
                                ])
                            @endforeach
                        </ol>
                    </details>
                @endif
            </section>
        @endif

        {{-- ============================ Kannattaako X? ============================
             Generated in PHP from typed fields only. Every figure is priced at the
             comparison consumption, so it moves with the picker.

             It reads as a verdict, so it sits AFTER the evidence it is a verdict on: the
             bill comparison, the itemised price, and the price history. It used to open the
             body, where it asserted a conclusion before the reader had seen a single
             figure. Hintatiedot always renders above it, so the rule is unconditional. --}}
        @if ($verdict)
            <section id="kannattaako" class="scroll-mt-20 border-t border-slate-200 py-10 sm:py-11">
                <h2 class="text-[22px] font-bold text-slate-900">{{ $verdict['heading'] }}</h2>
                <div
                    class="mt-4 space-y-2.5 transition-opacity duration-150"
                    wire:loading.class.delay="opacity-40"
                    wire:target="setConsumption, directConsumption"
                >
                    @foreach ($verdict['paragraphs'] as $paragraph)
                        <p class="max-w-[65ch] text-[17px] leading-relaxed text-slate-700">{{ $paragraph }}</p>
                    @endforeach

                    @if ($verdict['show_cheaper_link'])
                        <p>
                            <a
                                href="#halvemmat"
                                class="inline-flex min-h-[44px] items-center gap-1 rounded-sm text-[15px] font-semibold text-coral-600 hover:text-coral-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-coral-500"
                                x-data
                                @click.prevent="
                                    const target = document.getElementById('halvemmat');
                                    if (! target) return;
                                    target.scrollIntoView({
                                        behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth',
                                        block: 'start',
                                    });
                                "
                            >
                                Katso halvemmat vaihtoehdot
                                <span aria-hidden="true">↓</span>
                            </a>
                        </p>
                    @endif

                    <p class="text-sm text-slate-500">{{ $verdict['basis'] }}</p>
                </div>
            </section>
        @endif

        {{-- ============================ Sähkön alkuperä ja päästöt ============================
             ONE environment module. The hero used to carry a second CO2 stat block with its
             own severity taxonomy (4 tiers) while this section used a fifth-tier variant,
             and the origin breakdown lived in a third panel. They are one section now with
             one taxonomy: DESIGN.md's three emissions tiers. The figures stay smaller than
             the price, because the residual mix must not rival the money on this page. --}}
        @if (! empty($co2Emissions))
            @php
                $sourceLabels = [
                    'coal' => 'Kivihiili',
                    'natural_gas' => 'Maakaasu',
                    'oil' => 'Öljy',
                    'peat' => 'Turve',
                    'fossil_generic' => 'Fossiiliset (erittelemätön)',
                    'nuclear' => 'Ydinvoima',
                    'wind' => 'Tuulivoima',
                    'solar' => 'Aurinkovoima',
                    'hydro' => 'Vesivoima',
                    'biomass' => 'Biomassa',
                    'renewable_general' => 'Uusiutuva (erittelemätön)',
                    'renewable_unspecified' => 'Uusiutuva (erittelemätön)',
                    'residual_mix' => 'Jäännösjakauma',
                ];
                $emissionFactor = (float) ($co2Emissions['emission_factor_g_per_kwh'] ?? 0);
                $annualEmissionsKg = (float) ($co2Emissions['total_emissions_kg'] ?? 0);
                // Average Finnish car fleet: ~140 gCO2/km (Traficom/Sitra), i.e. the cars
                // actually on the road, not new-car type approval.
                $drivingKm = $annualEmissionsKg > 0 ? round($annualEmissionsKg * 1000 / 140) : 0;
                $physicalAverage = \App\Services\CO2EmissionsCalculator::FINLAND_BENCHMARKS['physical_grid_average'];

                // ONE taxonomy, the three DESIGN.md emissions tiers. Static class strings so
                // Tailwind can scan them.
                if ($emissionFactor < 50) {
                    $severityLabel = $emissionFactor == 0 ? 'Päästötön' : 'Matalat päästöt';
                    $severityClass = 'bg-emerald-50 text-emerald-700 ring-emerald-200';
                    $severityDot = 'bg-emerald-500';
                } elseif ($emissionFactor < 200) {
                    $severityLabel = 'Keskitason päästöt';
                    $severityClass = 'bg-amber-50 text-amber-700 ring-amber-200';
                    $severityDot = 'bg-amber-500';
                } else {
                    $severityLabel = 'Korkeat päästöt';
                    $severityClass = 'bg-red-50 text-red-700 ring-red-200';
                    $severityDot = 'bg-red-500';
                }

                $source = $contract->electricitySource;
                $hasSourceData = $source && (
                    ($source->renewable_total ?? 0) > 0 || ($source->nuclear_total ?? 0) > 0 || ($source->fossil_total ?? 0) > 0
                );
            @endphp
            <section id="ymparisto" class="scroll-mt-20 border-t border-slate-200 py-10 sm:py-11">
                <h2 class="text-[22px] font-bold text-slate-900">Sähkön alkuperä ja päästöt</h2>

                <div
                    class="mt-5 flex flex-wrap gap-x-10 gap-y-6 transition-opacity duration-150"
                    wire:loading.class.delay="opacity-40"
                    wire:target="setConsumption, directConsumption"
                >
                    <div class="tabular-nums">
                        @if ($emissionFactor == 0)
                            <p class="text-3xl font-extrabold text-slate-900">0 <span class="text-sm font-semibold text-slate-500">kg CO₂e vuodessa</span></p>
                            <p class="mt-1 max-w-[24ch] text-sm text-slate-600">Tämän sopimuksen sähköntuotannolla ei ole suoria CO₂-päästöjä.</p>
                        @else
                            {{-- The driving equivalent leads, not the kilograms. Nobody holds a
                                 sense of scale for 3 909 kg of CO₂e, and the figure is a yearly
                                 total for an invisible product, so the number that was set in
                                 32px carried the least meaning on the block. Kilometres are a
                                 quantity a reader already has intuition for. The kilograms stay
                                 directly under it as the measured metric the equivalence is
                                 derived from; they are not dropped, only demoted. --}}
                            <p class="text-3xl font-extrabold text-slate-900">
                                {{ number_format($drivingKm, 0, ',', ' ') }} <span class="text-sm font-semibold text-slate-500">km ajoa bensiiniautolla</span>
                            </p>
                            {{-- The figure and its unit are one token: "3 909" and "kg CO₂e"
                                 landed on separate lines at this column width. --}}
                            <p class="mt-1 max-w-[30ch] text-sm text-slate-600">
                                vastaa sopimuksen vuosipäästöjä
                                <strong class="whitespace-nowrap font-semibold text-slate-900">{{ number_format($annualEmissionsKg, 0, ',', ' ') }} kg CO₂e</strong>
                            </p>
                        @endif
                        <span class="mt-2.5 inline-flex items-center gap-1.5 rounded-lg px-2.5 py-1 text-sm font-semibold ring-1 ring-inset {{ $severityClass }}">
                            <span aria-hidden="true" class="h-1.5 w-1.5 rounded-full {{ $severityDot }}"></span>
                            {{ $severityLabel }} · {{ number_format($emissionFactor, 0, ',', '') }} g/kWh
                        </span>
                    </div>

                    <div class="min-w-[260px] max-w-[58ch] flex-1 text-[15px] leading-relaxed text-slate-600">
                        <p>
                            Luku on laskettu valitsemallasi {{ number_format($consumption, 0, ',', ' ') }} kWh vuosikulutuksella.
                            @if (($co2Emissions['residual_mix_percent'] ?? 0) > 0)
                                Myyjä ei ole eritellyt
                                <span class="font-semibold text-slate-900 tabular-nums">{{ number_format($co2Emissions['residual_mix_percent'], 0, ',', '') }} %</span>
                                tämän sopimuksen sähkön alkuperästä, joten se osuus lasketaan jäännösjakaumalla: sillä sähköllä, joka jää jäljelle kun alkuperätakuilla myyty tuotanto on poistettu. Se ei kerro, millaista sähköä juuri sinulle toimitetaan.
                            @elseif ($emissionFactor > $physicalAverage)
                                Päästökerroin perustuu myyjän ilmoittamiin energialähteisiin. Suomen sähköverkon fyysinen keskipäästö on noin {{ number_format($physicalAverage, 0) }} g/kWh.
                            @endif
                        </p>
                        @if ($drivingKm > 0)
                            <p class="mt-3">
                                <a href="/sahkosopimus/fossiiliton" class="inline-flex min-h-[44px] items-center rounded-sm font-semibold text-coral-600 hover:text-coral-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-coral-500">Katso vähäpäästöiset sopimukset →</a>
                            </p>
                        @endif
                    </div>
                </div>

                {{-- Origin breakdown --}}
                @if ($hasSourceData)
                    <dl class="mt-7 max-w-[420px] space-y-3">
                        @foreach ([
                            ['Uusiutuva', $source->renewable_total, 'bg-emerald-500'],
                            ['Ydinvoima', $source->nuclear_total, 'bg-slate-500'],
                            ['Fossiilinen', $source->fossil_total, 'bg-red-500'],
                        ] as [$label, $share, $barClass])
                            @if ($share && $share > 0)
                                <div>
                                    <div class="flex items-baseline justify-between text-sm">
                                        <dt class="text-slate-600">{{ $label }}</dt>
                                        <dd class="font-semibold text-slate-900 tabular-nums">{{ number_format($share, 0, ',', ' ') }} %</dd>
                                    </div>
                                    <div class="mt-1 h-2 w-full overflow-hidden rounded-full bg-slate-200">
                                        <div class="h-2 rounded-full {{ $barClass }}" style="width: {{ min($share, 100) }}%"></div>
                                    </div>
                                </div>
                            @endif
                        @endforeach
                    </dl>

                    @if ($source->renewable_total && $source->renewable_total > 0)
                        <dl class="mt-5 flex flex-wrap gap-x-8 gap-y-2 text-sm">
                            @foreach ([
                                ['Tuulivoima', $source->renewable_wind],
                                ['Vesivoima', $source->renewable_hydro],
                                ['Aurinkovoima', $source->renewable_solar],
                                ['Biomassa', $source->renewable_biomass],
                            ] as [$label, $share])
                                @if ($share && $share > 0)
                                    <div class="flex items-baseline gap-2">
                                        <dt class="text-slate-500">{{ $label }}</dt>
                                        <dd class="font-semibold text-slate-900 tabular-nums">{{ number_format($share, 0, ',', ' ') }} %</dd>
                                    </div>
                                @endif
                            @endforeach
                        </dl>
                    @endif
                @else
                    <p class="mt-6 max-w-[65ch] text-[15px] text-slate-600">
                        Sähkön alkuperätietoja ei ole saatavilla tälle sopimukselle, joten päästölaskennassa käytetään Suomen jäännösjakaumaa.
                    </p>
                @endif

                <details class="group mt-6 border-t border-slate-100">
                    <summary class="flex min-h-[44px] cursor-pointer list-none items-center text-sm font-semibold text-slate-600 hover:text-slate-900">
                        Näytä laskennan yksityiskohdat
                        <span aria-hidden="true" class="ml-1 inline-block transition-transform group-open:rotate-180">▾</span>
                    </summary>

                    <div class="mt-3 space-y-5">
                        <div>
                            <h3 class="text-sm font-semibold text-slate-700">Päästöt energialähteittäin</h3>
                            <dl class="mt-2 divide-y divide-slate-100 text-sm">
                                @foreach ($co2Emissions['emissions_by_source'] as $sourceKey => $emissionsKg)
                                    <div class="flex items-baseline justify-between gap-4 py-2">
                                        <dt class="text-slate-600">{{ $sourceLabels[$sourceKey] ?? $sourceKey }}</dt>
                                        <dd class="font-medium text-slate-900 tabular-nums">{{ number_format($emissionsKg, 1, ',', ' ') }} kg CO₂e</dd>
                                    </div>
                                @endforeach
                            </dl>
                        </div>

                        <div>
                            <h3 class="text-sm font-semibold text-slate-700">Käytetyt päästökertoimet</h3>
                            <dl class="mt-2 divide-y divide-slate-100 text-sm">
                                @foreach ($co2Emissions['emissions_by_source'] as $sourceKey => $emissionsKg)
                                    @if (isset($emissionFactorSources[$sourceKey]))
                                        <div class="flex items-baseline justify-between gap-4 py-2">
                                            <dt class="text-slate-600">
                                                {{ $sourceLabels[$sourceKey] ?? $sourceKey }}
                                                <span class="text-slate-400">({{ $emissionFactorSources[$sourceKey]['source'] }})</span>
                                            </dt>
                                            <dd class="font-medium text-slate-700 tabular-nums">{{ number_format($emissionFactorSources[$sourceKey]['value'], 0, ',', ' ') }} g/kWh</dd>
                                        </div>
                                    @endif
                                @endforeach
                            </dl>
                        </div>

                        <ul class="space-y-1 text-sm text-slate-500">
                            <li>Fossiilisten polttoaineiden päästökertoimet: Tilastokeskus ja IPCC Guidelines for National GHG Inventories.</li>
                            <li>Suomen tuotannon keskiarvo (noin {{ number_format($physicalAverage, 0) }} g/kWh): Fingrid ja Tilastokeskus 2024.</li>
                            <li>Jäännösjakauman päästökerroin: kansallinen jäännösjakauma 2024.</li>
                            <li>Uusiutuvat ja ydinvoima: EU:n alkuperätakuujärjestelmän mukainen 0 g/kWh.</li>
                        </ul>
                    </div>
                </details>
            </section>
        @endif

        {{-- ============================ Sopimusehdot lyhyesti ============================
             One flat grid of the terms Voltikka actually holds, then the seller's own
             description COLLAPSED under it. Rows come from
             ContractDetail::getContractTermsProperty(), which returns only rows whose data
             exists. Do not add a second terms list anywhere on this page. --}}
        <section id="sopimusehdot" class="scroll-mt-20 border-t border-slate-200 py-10 sm:py-11">
            <h2 class="text-[22px] font-bold text-slate-900">Sopimusehdot lyhyesti</h2>
            <p class="mt-1 text-[15px] text-slate-500">Poimittu myyjän ilmoittamista sopimustiedoista.</p>

            @if (! empty($contractTerms))
                <dl class="mt-6 grid grid-cols-1 gap-x-8 gap-y-5 sm:grid-cols-2">
                    @foreach ($contractTerms as $term)
                        <div>
                            <dt class="text-sm font-semibold text-slate-500">{{ $term['label'] }}</dt>
                            <dd class="mt-1 text-base font-bold text-slate-900">{{ $term['value'] }}</dd>
                        </div>
                    @endforeach
                </dl>
            @endif

            @if ($contract->microproduction_buys && $contract->microproduction_default)
                <div class="mt-6">
                    <p class="text-sm font-semibold text-slate-500">Pientuotanto</p>
                    <p class="mt-1 max-w-[65ch] text-[15px] text-slate-700">{{ $contract->microproduction_default }}</p>
                </div>
            @endif

            <p class="mt-6 text-sm text-slate-500">Tarkista ajantasaiset ehdot myyjän sivuilta ennen tilausta.</p>

            {{-- Myyjän tiedot. It used to be a separate "Yhtiön tiedot" panel in the removed
                 right column; the terms section is where a reader looks for who they would
                 be buying from. --}}
            @if ($contract->company)
                <div class="mt-6 border-t border-slate-100 pt-5">
                    <p class="text-sm font-semibold text-slate-500">Myyjä</p>
                    <p class="mt-1 text-[15px] text-slate-700">
                        @if ($companyInternalUrl)
                            <a href="{{ $companyInternalUrl }}" class="rounded-sm font-semibold text-slate-900 underline underline-offset-2 hover:text-coral-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-coral-500">{{ $contract->company->name }}</a>
                        @else
                            <span class="font-semibold text-slate-900">{{ $contract->company->name }}</span>
                        @endif
                        @if ($contract->company->street_address)
                            <span class="text-slate-500">· {{ $contract->company->street_address }}, {{ $contract->company->postal_code }} {{ $contract->company->postal_name }}</span>
                        @endif
                    </p>
                    @if ($contract->company->company_url)
                        <p class="mt-1">
                            <a href="{{ $contract->company->company_url }}" target="_blank" rel="noopener noreferrer" class="inline-flex min-h-[44px] items-center rounded-sm text-[15px] text-coral-600 hover:text-coral-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-coral-500">
                                {{ $contract->company->company_url }}
                            </a>
                        </p>
                    @endif
                </div>
            @endif

            {{-- Internal comparison links for the contract's duration, metering and pricing
                 model. They used to be badge pills in the hero; the editorial hero keeps
                 only the pricing category, and these read better as a "see also" line where
                 the terms they describe are stated. Mapping: Support\ContractInternalLinks. --}}
            @if (! empty($heroBadgeLinks))
                <p class="mt-6 flex flex-wrap items-center gap-x-2 gap-y-1 text-[15px] text-slate-500">
                    <span class="font-semibold text-slate-600">Vertaa samankaltaisia:</span>
                    @foreach ($heroBadgeLinks as $badge)
                        @if ($badge['url'])
                            <a href="{{ $badge['url'] }}" class="inline-flex min-h-[44px] items-center rounded-sm font-semibold text-coral-600 hover:text-coral-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-coral-500">{{ $badge['label'] }}</a>
                        @else
                            <span>{{ $badge['label'] }}</span>
                        @endif
                        @if (! $loop->last)<span aria-hidden="true" class="text-slate-300">·</span>@endif
                    @endforeach
                </p>
            @endif

            {{-- The seller's own description, collapsed. Both bodies come from
                 App\Support\ContractContentSanitizer, never from the raw column: the payloads
                 carry wrapping quotes and shouted "TÄÄLTÄ" callouts that lead nowhere. --}}
            @if ($descriptionHtml || $descriptionText)
                <details class="group mt-6 border-t border-slate-100">
                    <summary class="flex min-h-[44px] cursor-pointer list-none items-center text-sm font-semibold text-slate-600 hover:text-slate-900">
                        Myyjän oma kuvaus sopimuksesta
                        <span aria-hidden="true" class="ml-1 inline-block transition-transform group-open:rotate-180">▾</span>
                    </summary>
                    @if ($descriptionHtml)
                        <div class="prose prose-slate mt-2 max-w-none text-[15px] prose-a:text-coral-600 hover:prose-a:text-coral-700">
                            {!! $descriptionHtml !!}
                        </div>
                    @endif
                    @if ($descriptionText)
                        <p class="mt-2 max-w-[68ch] whitespace-pre-line text-[15px] text-slate-600">{{ $descriptionText }}</p>
                    @endif
                </details>
            @endif
        </section>

        {{-- ============================ Usein kysyttyä ============================
             The list is ContractDetail::getFaqItemsProperty(), which also builds the
             FAQPage JSON-LD, so the visible answers and the schema cannot drift. The
             pricing-mechanism item carries #faq-miten because the hero's category label
             links to it. --}}
        @if (! empty($faqItems))
            <section
                id="usein-kysyttya"
                class="scroll-mt-20 border-t border-slate-200 py-10 sm:py-11"
                x-data
                x-init="
                    const openTargetedItem = () => {
                        const hash = window.location.hash;
                        if (! /^#[A-Za-z][\w-]*$/.test(hash)) return;
                        const target = document.querySelector(hash);
                        if (target && target.tagName === 'DETAILS') target.open = true;
                    };
                    openTargetedItem();
                    window.addEventListener('hashchange', openTargetedItem);
                "
            >
                <h2 class="text-[22px] font-bold text-slate-900">Usein kysyttyä</h2>

                <div class="mt-4 divide-y divide-slate-100 border-t border-slate-100">
                    @foreach ($faqItems as $faq)
                        <details id="{{ $faq['id'] }}" class="group scroll-mt-24">
                            <summary class="flex min-h-[44px] cursor-pointer list-none items-center justify-between gap-4 py-4 text-base font-semibold text-slate-900 hover:text-coral-700">
                                <span>{{ $faq['question'] }}</span>
                                <svg class="h-4 w-4 shrink-0 text-slate-400 transition-transform group-open:rotate-180" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"></path>
                                </svg>
                            </summary>
                            <p class="max-w-[68ch] pb-4 text-[15px] leading-relaxed text-slate-600">{{ $faq['answer'] }}</p>
                        </details>
                    @endforeach
                </div>
            </section>
        @endif

        {{-- ============================ Halvemmat vaihtoehdot ============================
             The only cards on the page. --}}
        @if (! empty($alternativeTiles))
            <section id="halvemmat" class="scroll-mt-20 border-t border-slate-200 py-10 sm:py-11">
                <h2 class="text-[22px] font-bold text-slate-900">
                    @if ($heroVerdict && $heroVerdict['rank'] > 1)
                        Halvemmat vaihtoehdot
                    @else
                        Vaihtoehdot vertailusta
                    @endif
                </h2>
                <p class="mt-1 text-[15px] text-slate-500">
                    @if ($heroVerdict && $heroVerdict['rank'] > 1)
                        {{ number_format($heroVerdict['rank'] - 1, 0, ',', ' ') }} sopimusta on halvempia {{ number_format($comparisonConsumption, 0, ',', ' ') }} kWh vuosikulutuksella. Tässä halvimmat, sekä halvin samantyyppinen.
                    @else
                        Hinnat {{ number_format($comparisonConsumption, 0, ',', ' ') }} kWh vuosikulutuksella.
                    @endif
                </p>

                <div
                    class="mt-5 grid grid-cols-1 gap-3.5 sm:grid-cols-3 transition-opacity duration-150"
                    wire:loading.class.delay="opacity-40"
                    wire:target="setConsumption, directConsumption"
                >
                    @foreach ($alternativeTiles as $alt)
                        @php
                            $altContract = $alt['contract'];
                            $altCompany = $altContract->company;
                            $altMonthly = number_format($alt['total_cost'] / 12, 2, ',', ' ');
                            [$altInt, $altDec] = explode(',', $altMonthly, 2);
                        @endphp
                        <a
                            href="{{ route('contract.detail', ['contractId' => $altContract->id]) }}?kulutus={{ $comparisonConsumption }}"
                            class="flex min-w-0 flex-col gap-2 rounded-2xl border border-slate-200 bg-white p-[18px] transition hover:-translate-y-0.5 hover:shadow-[0_12px_40px_-12px_rgba(0,0,0,0.15)] focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-coral-500"
                        >
                            <span class="flex min-w-0 items-center gap-2">
                                <x-company-logo
                                    :company="$altCompany"
                                    :name="$altCompany?->name ?: $altContract->name"
                                    class="h-8 w-8 rounded bg-slate-100 text-xs font-bold text-slate-600"
                                    img-class="rounded bg-white"
                                />
                                <span class="flex-1 truncate text-sm text-slate-500">{{ $altCompany?->name }}</span>
                            </span>
                            @if ($alt['tag'])
                                <span class="self-start rounded-lg bg-slate-100 px-2.5 py-1 text-[13px] font-bold text-slate-600">{{ $alt['tag'] }}</span>
                            @endif
                            {{-- Same name normalization as the H1 and both card templates, so a
                                 shouted name is calm wherever Voltikka prints it. --}}
                            <span class="text-[15px] font-bold leading-snug text-slate-900">{{ $this->displayNameFor($altContract->name) }}</span>
                            <span class="mt-auto pt-2 text-[22px] font-extrabold text-slate-900 tabular-nums">
                                {{ $altInt }}<span class="text-base text-slate-400">,{{ $altDec }}</span>
                                <span class="text-[13px] font-semibold text-slate-500">€/kk</span>
                            </span>
                            @if (abs($alt['savings']) >= 1)
                                <span class="self-start rounded-lg bg-slate-100 px-2.5 py-1 text-[13px] font-bold text-slate-900 tabular-nums">
                                    @if ($alt['savings'] >= 1)
                                        Säästä n. {{ number_format($alt['savings'], 0, ',', ' ') }} €/v
                                    @else
                                        {{ number_format(abs($alt['savings']), 0, ',', ' ') }} €/v kalliimpi
                                    @endif
                                </span>
                            @endif
                        </a>
                    @endforeach
                </div>

                <p class="mt-4 text-[15px]">
                    <a href="{{ $listingUrl }}" class="inline-flex min-h-[44px] items-center rounded-sm font-bold text-coral-600 hover:text-coral-700 hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-coral-500">
                        @if ($heroVerdict)
                            Vertaa kaikkia {{ number_format($heroVerdict['total'], 0, ',', ' ') }} sopimusta →
                        @else
                            Vertaa kaikkia sopimuksia →
                        @endif
                    </a>
                </p>
            </section>
        @endif

        {{-- Closing method statement. It deliberately does NOT repeat the no-commission
             line: that is stated once beside the CTA and once in the site footer, and
             nowhere else. --}}
        <p class="border-t border-slate-200 pt-8 max-w-[70ch] text-sm leading-relaxed text-slate-500">
            Hintatiedot päivittyvät päivittäin ja 12 kuukauden arviot lasketaan samalla menetelmällä kaikille sopimuksille.
            <a href="/tietoa#menetelma" class="rounded-sm font-semibold text-coral-600 hover:text-coral-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-coral-500">Näin laskemme</a>
        </p>
    </main>

    {{-- Sticky mobile CTA bar. It appears only once the hero CTA has actually scrolled
         PAST the top of the viewport (`bottom < 0`), never merely because the CTA is
         below the fold, and it hides again over the alternatives and the footer so it
         cannot cover the cheaper options it would be competing with. A scroll listener
         is used rather than IntersectionObserver because the rule is a position test,
         not a visibility test, and the same rect check answers both hide conditions. --}}
    @if ($sellerCta && $this->isActive && ! $isExcludedPricing)
        <div
            x-data="{
                show: false,
                update() {
                    const cta = document.getElementById('hero-cta');
                    if (! cta) { this.show = false; return; }
                    const scrolledPast = cta.getBoundingClientRect().bottom < 0;
                    const alts = document.getElementById('halvemmat');
                    const footer = document.querySelector('body > footer, footer');
                    const altsVisible = alts
                        ? alts.getBoundingClientRect().top < window.innerHeight && alts.getBoundingClientRect().bottom > 0
                        : false;
                    const footerVisible = footer ? footer.getBoundingClientRect().top < window.innerHeight : false;
                    this.show = scrolledPast && ! altsVisible && ! footerVisible;
                },
            }"
            x-init="update(); $nextTick(() => update())"
            @scroll.window.passive="update()"
            @resize.window.passive="update()"
            x-show="show"
            x-cloak
            class="fixed inset-x-0 bottom-0 z-40 flex items-center gap-3 border-t border-slate-200 bg-white px-4 py-2.5 sm:hidden"
        >
            <span class="min-w-0 tabular-nums">
                <span class="block text-lg font-extrabold text-slate-900">
                    {{ number_format(($calculatedCost['total_cost'] ?? 0) / 12, 2, ',', ' ') }} €/kk
                </span>
                <span class="block text-[13px] font-semibold text-slate-500">
                    12 kk {{ $isEstimatePricing ? 'arvio' : 'hinta' }} · {{ number_format($consumption, 0, ',', ' ') }} kWh
                </span>
            </span>
            <a
                href="{{ $sellerCta->url }}"
                @if ($sellerCta->external) target="_blank" rel="noopener noreferrer" @endif
                data-first-party-analytics="contract_order_click"
                data-analytics-placement="sticky"
                @auxclick="
                    if ($event.button === 1 && window.voltikkaAnalytics) {
                        window.voltikkaAnalytics.trackContractOrderClick({
                            context: @js($contractOrderClickContext),
                            placement: 'sticky'
                        });
                    }
                "
                @click="
                    window.voltikkaAnalytics && window.voltikkaAnalytics.trackContractOrderClick({
                        context: @js($contractOrderClickContext),
                        placement: 'sticky'
                    });
                    $track('Contract Order Clicked', {
                        props: {
                            contract_id: @js($contract->id),
                            company: @js($companyName),
                            pricing_model: @js($contract->pricing_model)
                        }
                    });
                "
                class="ml-auto inline-flex min-h-[48px] shrink-0 items-center rounded-xl bg-coral-600 px-5 py-3 text-[17px] font-bold text-white hover:bg-coral-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-coral-600"
            >
                Myyjän sivuille
            </a>
        </div>
    @endif
</div>
