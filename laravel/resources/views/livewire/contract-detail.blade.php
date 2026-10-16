<div class="bg-ground">
    {{-- Schema.org structured data --}}
    <x-schema-markup :schemas="$schemas" />

    {{--
        REDESIGN (2026-10-16, Paper boards 39Y-0 "Sopimus — /sahkosopimus/sopimus picture book"
        and EBP-0 "Seasons · Sopimus"). A short painted band with the fox far away, then the
        contract on the page ground. No cards, panels, pills or shadows: sections are display
        headings with the wavy fox underline, inked hairlines, dotted leaders and drawn marks.
        CSS: resources/css/redesign-detail.css.

        The fox is never next to the contract, its price or the seller (design spec §5 "Seller
        pages"): the band has no text, and the H1, the price and the logo sit below it. There
        is no villager interlude on this page, because every block is a contract or seller block.

        Section order is the approved editorial order and it is load bearing (see
        app/Livewire/AGENTS.md "ContractDetail"): header, bill module (open), Hintatiedot,
        price history, Kannattaako, emissions, terms, FAQ, alternatives.

        Generated Finnish sentences live in ContractDetail / ContractCard, never here.
    --}}
    @php
        use App\Support\Season;

        $companyName = $contract->company?->name ?? '';
        // The seller CTA comes from ContractCardPresenter, which guarantees a destination:
        // one live contract carried neither an order link nor a product link and its page
        // rendered no action at all.
        $sellerCta = $card->sellerCta;
        $secondaryCtaUrl = ($contract->order_link && $contract->product_link) ? $contract->product_link : null;
        $hasLogo = $contract->company?->getLocalLogoUrl() !== null;

        $isExcludedPricing = ($isPricingExcluded ?? false) === true;
        $isEstimatePricing = ($calculatedCost['is_estimate'] ?? false) === true;

        // The listing keeps the visitor's consumption across the back navigation, the same
        // rule the listing cards use when they deep-link here.
        $listingUrl = $consumption === 5000 ? '/sahkosopimus' : '/sahkosopimus?kulutus=' . $consumption;

        // Category colour and glyph of the viewed contract (glyph shape + name carry the
        // meaning; the colour only repeats it).
        $bucketValue = $card->bucket->value;
        $catClass = match ($bucketValue) {
            'porssisahko' => 'text-cat-spot',
            'paivittyva' => 'text-cat-periodic',
            'kulutusvaikutus' => 'text-cat-usage',
            default => 'text-cat-fixed',
        };

        // Seasonal home art for the four reference consumptions (same art as the listing's
        // consumption row). A capped contract can show fewer presets; the art follows the kWh.
        $homeArt = [
            2000 => 'home-studio',
            5000 => 'home-apartment',
            10000 => 'home-rowhouse',
            18000 => 'home-house',
        ];

        // Alternatives: the two cheapest contracts plus one of the SAME pricing type.
        // Ranking puts pörssisähkö on top almost everywhere, so the two cheapest rows
        // are usually spot; a visitor who came for price certainty was offered nothing
        // they would buy. The same-type row is skipped when it is already shown.
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

        $scrollTo = fn (string $id, string $block = 'start') => "
            const target = document.getElementById('{$id}');
            if (! target) return;
            target.scrollIntoView({
                behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth',
                block: '{$block}',
            });
        ";
    @endphp

    <x-site.detail-band :tone="Season::heroTone('contract-band')" />

    {{-- Preferred Sources strip: directly under the painted band, before the breadcrumb
         (round 3 rule: the same place on every page). --}}
    <x-page-action-strip />

    <div class="vk-wrap vk-detail-body">
        <nav aria-label="Murupolku" class="vk-crumbs vk-detail-crumbs">
            <ol>
                <li>
                    <a href="/" class="vk-focus">Etusivu</a>
                </li>
                <li aria-hidden="true">/</li>
                <li>
                    {{-- Back to the listing, carrying the consumption the visitor chose. --}}
                    <a href="{{ $listingUrl }}" class="vk-focus">Sähkösopimukset</a>
                </li>
                @if ($companyName !== '')
                    <li aria-hidden="true" class="hidden sm:list-item">/</li>
                    <li class="hidden min-w-0 sm:list-item">
                        @if ($companyInternalUrl)
                            <a href="{{ $companyInternalUrl }}" class="vk-focus">{{ $companyName }}</a>
                        @else
                            <span>{{ $companyName }}</span>
                        @endif
                    </li>
                @endif
                <li aria-hidden="true">/</li>
                <li aria-current="page">{{ $displayName }}</li>
            </ol>
        </nav>

        {{-- ============================ Contract header (Paper 3BS-0) ============================
             Left: seller · category, the H1, the price fused with the verdict. Right: own
             consumption, then the seller CTA. The consumption picker sits ABOVE the CTA on
             purpose: the visitor must be able to put their own consumption in before the page
             asks them to act on the price. --}}
        <header class="vk-detail-head">
            <div class="vk-detail-main">
                {{-- Quiet metadata: seller, then the pricing category. The category label is a
                     real link that opens the FAQ item explaining the mechanism, because a user
                     simulation found the most important concept on the page rendered as a dead
                     label. --}}
                <p class="vk-detail-meta">
                    @if ($hasLogo)
                        <x-company-logo variant="ledger" :company="$contract->company" :name="$companyName" />
                    @endif
                    <span>{{ $companyName }}</span>
                    <span aria-hidden="true" class="vk-detail-meta-sep">·</span>
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
                            class="vk-detail-category vk-focus {{ $catClass }}"
                        ><x-ink.glyph :bucket="$bucketValue" size="28" /><span class="vk-detail-category-text">{{ $card->category->label() }}</span></a>
                    @else
                        <span class="vk-detail-category {{ $catClass }}"><x-ink.glyph :bucket="$bucketValue" size="28" /><span>{{ $card->category->label() }}</span></span>
                    @endif
                </p>

                <h1 class="vk-detail-h1">{{ $displayName }}</h1>

                {{-- ---------- Price + verdict, the one dominant statement ---------- --}}
                <div
                    class="vk-detail-price-beat"
                    wire:loading.class.delay="opacity-40"
                    wire:target="setConsumption, directConsumption"
                >
                    @if ($priceAvailabilityNotice)
                        <p class="vk-detail-price-missing">{{ $priceAvailabilityNotice['heading'] }}</p>
                        <p class="vk-detail-price-missing-body">{{ $priceAvailabilityNotice['body'] }}</p>
                    @elseif ($isExcludedPricing)
                        <p class="vk-detail-price-missing">Vuosihintaa ei voi laskea luotettavasti</p>
                        <p class="vk-detail-price-missing-body">
                            Sopimuksen hinnoittelusta puuttuu tietoja, joten emme näytä sille vuosiarviota emmekä sisällytä sitä vertailuun.
                        </p>
                    @else
                        @php
                            $heroMonthly = number_format(($calculatedCost['total_cost'] ?? 0) / 12, 2, ',', ' ');
                            [$heroInt, $heroDec] = explode(',', $heroMonthly, 2);
                        @endphp
                        {{-- Not "Hinta-arvio ...": the `Arvio` trigger beside the number is the
                             page's single estimate marker. --}}
                        <p class="vk-detail-kicker">{{ ($calculatedCost['contract_term'] ?? null) !== null ? 'Vuositasolle laskettu vertailuhinta' : 'Hinta seuraavalle 12 kuukaudelle' }}</p>
                        <div class="vk-detail-price-row">
                            <span class="vk-detail-price">
                                <span class="vk-detail-price-int">{{ $heroInt }}</span><span class="vk-detail-price-dec">,{{ $heroDec }}</span><span class="vk-detail-price-unit">€/kk</span>
                            </span>
                            {{-- The page's one Arvio popover. The category line in Hintatiedot
                                 deliberately does not carry a second one: two teleported panels
                                 share a wire:key. The 44px hit area is a transparent ::before. --}}
                            @if ($card->estimate)
                                <span class="vk-detail-arvio">
                                    <x-info-popover
                                        variant="inked"
                                        label="Arvio"
                                        trigger-class="relative before:absolute before:inset-x-0 before:top-1/2 before:h-11 before:-translate-y-1/2 before:content-['']"
                                        :heading="$card->estimate->heading"
                                        :body="$card->estimate->body"
                                        :link-url="$card->estimate->linkUrl"
                                        :link-text="$card->estimate->linkText"
                                    />
                                </span>
                            @endif
                        </div>
                        <p class="vk-detail-price-line">
                            {{ number_format($calculatedCost['total_cost'] ?? 0, 0, ',', ' ') }} € vuodessa ·
                            {{ number_format($consumption, 0, ',', ' ') }} kWh vuosikulutuksella ·
                            {{ $contract->target_group === 'Company' ? 'ilman ALV:tä' : 'sisältää ALV:n' }}
                        </p>

                        {{-- The verdict, fused with the price: statement, the same statement
                             drawn as a ruler, the way out, the small print. --}}
                        @if ($heroVerdict)
                            <div class="vk-detail-rank">
                                <p class="vk-detail-rank-line">
                                    <strong class="vk-detail-rank-number">Sija {{ number_format($heroVerdict['rank'], 0, ',', ' ') }}</strong>
                                    / {{ number_format($heroVerdict['total'], 0, ',', ' ') }} sopimuksesta
                                    @if ($heroVerdict['comparison'])
                                        <span aria-hidden="true">·</span> {{ $heroVerdict['comparison'] }}
                                    @endif
                                </p>

                                {{-- Halvin–kallein ruler. The aurora share under the line is the
                                     part of the market that is cheaper than this contract, so
                                     rank 1 leaves it empty. `aria-hidden`: the sentence above
                                     carries every fact the ruler draws.

                                     Motion: transforms only, 300 ms ease-out, off under
                                     prefers-reduced-motion. The share scales, and the marker
                                     layer translates by a percentage of its own full width. --}}
                                <div aria-hidden="true" class="vk-detail-ruler">
                                    <div class="vk-detail-ruler-track">
                                        <svg class="vk-detail-ruler-line" viewBox="0 0 720 12" preserveAspectRatio="none" focusable="false">
                                            <path d="M1 6 C 140 4.5, 300 7.5, 460 5.6 S 640 4.8, 719 6" fill="none" style="stroke: var(--color-ink)" stroke-width="2" stroke-linecap="round" vector-effect="non-scaling-stroke" />
                                            <path d="M1 1 V11 M72 3 V9 M144 3 V9 M216 3 V9 M288 3 V9 M360 1 V11 M432 3 V9 M504 3 V9 M576 3 V9 M648 3 V9 M719 1 V11" fill="none" style="stroke: var(--color-ink)" stroke-width="1.4" stroke-linecap="round" vector-effect="non-scaling-stroke" />
                                        </svg>
                                        <div class="vk-detail-ruler-share" style="transform: scaleX({{ $heroVerdict['marker_percent'] / 100 }});"></div>
                                        <span class="vk-detail-ruler-marker" style="transform: translateX({{ $heroVerdict['marker_percent'] }}%);">
                                            <svg class="vk-detail-ruler-node" viewBox="0 0 24 36" focusable="false">
                                                <path d="M12 1 V15" fill="none" style="stroke: var(--color-fox)" stroke-width="2.4" stroke-linecap="round" />
                                                <path d="M7 10 L12 16 L17 10" fill="none" style="stroke: var(--color-fox)" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" />
                                                <circle cx="12" cy="28" r="6" style="fill: var(--color-fox)" />
                                                <circle cx="12" cy="28" r="2.2" style="fill: var(--color-ground)" />
                                            </svg>
                                        </span>
                                    </div>
                                    <div class="vk-detail-ruler-labels">
                                        <span class="vk-detail-ruler-cheap">halvin</span>
                                        <span>kallein</span>
                                    </div>
                                </div>

                                <div class="vk-detail-rank-actions">
                                    @if ($heroVerdict['show_cheaper_link'])
                                        {{-- Deliberately shorter than the "Katso halvemmat
                                             vaihtoehdot" link that closes "Kannattaako X?":
                                             two identical links a screen apart read as a repeat. --}}
                                        <a href="#halvemmat" x-data @click.prevent="{{ $scrollTo('halvemmat') }}" class="vk-action vk-focus min-h-[44px] justify-center">
                                            <span>Katso halvemmat <span aria-hidden="true">↓</span></span>
                                            <x-ink.wave width="150" stroke="2.4" />
                                        </a>
                                    @endif
                                    <p class="vk-detail-note">{{ $heroVerdict['note'] }}</p>
                                </div>
                            </div>
                        @endif

                        {{-- Category-specific price qualifier: what the figure above actually is.
                             This is the page's only "arvio, ei hintalupaus" statement. --}}
                        @if ($priceQualifier)
                            <p class="vk-detail-qualifier">{{ $priceQualifier }}</p>
                        @endif
                    @endif
                </div>
            </div>

            {{-- ---------- Own consumption + action (Paper 3WI-0) ---------- --}}
            <div class="vk-detail-aside">
                <div id="consumption-picker" class="flex scroll-mt-20 flex-col gap-3">
                    <p class="vk-detail-aside-title">Laske omalla kulutuksellasi</p>
                    {{-- Radio rows: drawn radio, seasonal home art, name, kWh. The selected kWh
                         turns fox-deep. They stay <button aria-pressed> (one tap, immediate). --}}
                    <div
                        class="vk-detail-presets"
                        role="group"
                        aria-label="Kulutusprofiilit"
                        wire:loading.class.delay="opacity-60"
                        wire:target="setConsumption, directConsumption"
                    >
                        @foreach ($presets as $label => $value)
                            @php $isSel = $consumption === $value; @endphp
                            <button
                                type="button"
                                data-consumption-preset="{{ $value }}"
                                wire:click="setConsumption({{ $value }})"
                                wire:loading.attr="disabled"
                                wire:target="setConsumption"
                                aria-pressed="{{ $isSel ? 'true' : 'false' }}"
                                class="vk-detail-preset"
                            >
                                @if ($isSel)
                                    <svg class="vk-mark" viewBox="0 0 22 22" aria-hidden="true" focusable="false"><circle cx="11" cy="11" r="9.5" style="fill: var(--color-fox)" /><path d="M6.5 11.5 L9.6 14.3 L15.6 7.8" fill="none" style="stroke: var(--color-snow-white)" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" /></svg>
                                @else
                                    <svg class="vk-mark" viewBox="0 0 22 22" aria-hidden="true" focusable="false"><circle cx="11" cy="11" r="9" style="fill: var(--color-snow-white); stroke: var(--color-field-edge)" stroke-width="2" /></svg>
                                @endif
                                @if (isset($homeArt[$value]))
                                    <img src="{{ Season::art($homeArt[$value]) }}" alt="" width="40" height="40" loading="lazy" decoding="async" class="vk-detail-preset-art">
                                @endif
                                <span class="vk-detail-preset-name">{{ $label }}</span>
                                <span class="vk-detail-preset-kwh">{{ number_format($value, 0, ',', ' ') }} kWh</span>
                            </button>
                        @endforeach
                    </div>

                    <label class="vk-detail-own">
                        <span class="vk-detail-own-label">Oma vuosikulutus kilowattitunteina</span>
                        <span class="vk-detail-own-field">
                            <input
                                type="number"
                                min="{{ \App\Livewire\ContractDetail::MIN_FREE_CONSUMPTION }}"
                                max="{{ \App\Livewire\ContractDetail::MAX_FREE_CONSUMPTION }}"
                                step="100"
                                inputmode="numeric"
                                wire:model.live.blur="directConsumption"
                                @keydown.enter.prevent="$event.target.blur()"
                                placeholder="Oma"
                            >
                            <span class="vk-detail-own-unit">kWh/v</span>
                        </span>
                    </label>
                    @if ($presetNotice)
                        <p class="vk-detail-aside-notice">{{ $presetNotice }}</p>
                    @endif
                    @if ($rankBasisNotice)
                        <p class="vk-detail-aside-notice">{{ $rankBasisNotice }}</p>
                    @endif
                </div>

                @if ($sellerCta)
                    <div id="hero-cta" class="vk-detail-cta-block">
                        {{-- The page's one filled button (spec §4 --radius-button). White on fox
                             at 19px/800 is large text (3:1). --}}
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
                            class="vk-detail-cta"
                        >
                            {{ $sellerCta->label }}
                            <svg width="16" height="16" viewBox="0 0 16 16" aria-hidden="true" focusable="false">
                                <path d="M5 3h8v8M13 3L3.5 12.5" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" />
                            </svg>
                        </a>
                        <p class="vk-detail-cta-note">
                            <strong>Tilaus tehdään suoraan sähköyhtiön sivuilla.</strong>
                            Voltikka ei saa provisiota eikä näytä mainoksia.
                        </p>
                        @if ($secondaryCtaUrl)
                            <p>
                                <a
                                    href="{{ $secondaryCtaUrl }}"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    @click="$track('Contract Info Clicked', {
                                        contract_id: {{ $contract->id }},
                                        company: '{{ addslashes($companyName) }}'
                                    })"
                                    class="vk-inked-link vk-focus inline-flex min-h-[44px] items-center whitespace-normal"
                                >
                                    Lisätietoja sopimuksesta myyjän sivuilla
                                </a>
                            </p>
                        @endif
                    </div>
                @endif
            </div>
        </header>
    </div>

    {{-- Sections (round 3 board HYA-0): every section starts with the same edge rule and air,
         64 px apart on desktop and 48 px on a phone (redesign-detail.css "Sections"). --}}
    <div class="vk-wrap vk-detail-sections">
        {{-- Inactive contract notice: caution ink, not a warning colour, between hairlines. --}}
        @if (! $this->isActive)
            <p class="vk-detail-inactive">
                <span aria-hidden="true">!</span>
                <span>Tämä sopimus ei ole enää tarjolla. Sivu on tallessa hintahistorian vuoksi.</span>
            </p>
        @endif

        {{-- ============================ Vertaa nykyiseen sähkölaskuusi ============================
             One bill, this contract, the same billing period. Period basis only, exactly like
             the in-listing mode: the bill total is the anchor and no annual figure is derived
             from it. Per-user compute that never enters the page's prepared payload cache.

             Round 3 (boards HYA-0, IND-0): the FIRST section under the header, a normal
             always-open section. The form is the torn-paper bill of /maksatko-liikaa (the shared
             partial in its paper layout) and the answer sits at its side. No fox here: the
             module stands next to a contract price. --}}
        @if ($showBillComparison)
            @php
                // The torn edges of the paper bill: the same 10 px zigzag as /maksatko-liikaa.
                $zigTop = 'M0 8';
                $zigBottom = 'M0 6';
                for ($x = 10; $x <= 620; $x += 10) {
                    $zigTop .= ' L'.$x.' '.(($x / 10) % 2 === 1 ? 1 : 8);
                    $zigBottom .= ' L'.$x.' '.(($x / 10) % 2 === 1 ? 13 : 6);
                }
            @endphp
            <section id="vertaa-laskuun" class="vk-detail-section scroll-mt-20" aria-labelledby="vertaa-laskuun-title">
                <div class="vk-detail-billhead">
                    <x-site.section-heading id="vertaa-laskuun-title" wave="320">Vertaa nykyiseen sähkölaskuusi</x-site.section-heading>
                    <p class="vk-detail-billlead">
                        Syötä yhden laskun tiedot, niin näytämme mitä tämä sopimus olisi maksanut samalta jaksolta.
                    </p>
                </div>

                <div class="vk-detail-billrow">
                    <div class="bill-paper vk-detail-paper">
                        <svg class="bill-paper-edge" viewBox="0 0 620 14" preserveAspectRatio="none" aria-hidden="true" focusable="false">
                            <path d="{{ $zigTop }} L620 14 L0 14 Z" style="fill: var(--color-snow-white)" />
                            <path d="{{ $zigTop }}" fill="none" style="stroke: var(--color-ink)" stroke-width="1.4" stroke-linejoin="round" vector-effect="non-scaling-stroke" />
                        </svg>
                        <div class="bill-paper-body">
                            <div class="flex flex-col gap-1">
                                <p class="vk-kicker">Laskun tiedot</p>
                                <h3 class="bill-paper-title vk-detail-paper-title">Nykyinen sähkölaskusi</h3>
                                <p class="text-[15px] leading-[22px] text-ink-body">Kaikki löytyy sähkölaskulta, et tarvitse vuosikulutusta.</p>
                            </div>

                            @include('partials.bill-comparison-form', [
                                'idPrefix' => 'detail-bill',
                                'totalLabel' => 'Sähköenergian osuus (€)',
                                'paper' => true,
                            ])
                        </div>
                        <svg class="bill-paper-edge" viewBox="0 0 620 14" preserveAspectRatio="none" aria-hidden="true" focusable="false">
                            <path d="{{ $zigBottom }} L620 0 L0 0 Z" style="fill: var(--color-snow-white)" />
                            <path d="{{ $zigBottom }}" fill="none" style="stroke: var(--color-ink)" stroke-width="1.4" stroke-linejoin="round" vector-effect="non-scaling-stroke" />
                        </svg>
                    </div>

                    <div class="vk-detail-bill-result" aria-live="polite" wire:loading.class.delay="opacity-40" wire:target="billKwh, billTotalEur, billStartDate, billEndDate, billIncludesVat, setBillPeriodPreset">
                        @if ($billComparison)
                            <p class="vk-detail-bill-kicker">
                                Sama jakso {{ $billComparison['period_label'] }} · {{ number_format($billComparison['kwh'], 0, ',', ' ') }} kWh
                            </p>

                            @if ($billComparison['available'])
                                @php $billVerdict = $billComparison['verdict']; @endphp
                                <dl class="vk-detail-bill-ledger">
                                    <div class="vk-detail-bill-row">
                                        <dt>Maksoit nykyisellä sopimuksellasi</dt>
                                        <span class="vk-leader" aria-hidden="true"></span>
                                        <dd>{{ number_format($billComparison['user_total'], 2, ',', ' ') }}&nbsp;€</dd>
                                    </div>
                                    <div class="vk-detail-bill-row vk-detail-bill-row--contract">
                                        <dt>{{ $billComparison['contract_name'] }} olisi maksanut</dt>
                                        <span class="vk-leader" aria-hidden="true"></span>
                                        <dd>{{ number_format($billComparison['contract_cost'], 2, ',', ' ') }}&nbsp;€</dd>
                                    </div>
                                </dl>

                                {{-- Saving in the saving colour, paying more in price-high, about the
                                     same in ink. delta_label is the accessible sentence; the visual
                                     label + figure split the same value from `delta`. --}}
                                <div class="vk-detail-bill-delta vk-detail-bill-delta--{{ $billVerdict }}">
                                    <p class="sr-only">{{ $billComparison['delta_label'] }}</p>
                                    @if ($billVerdict === 'about_the_same')
                                        <p class="vk-detail-bill-same" aria-hidden="true">{{ $billComparison['delta_label'] }}</p>
                                    @else
                                        <div aria-hidden="true">
                                            <p class="vk-detail-bill-say">{{ $billVerdict === 'saves' ? 'Olisit säästänyt n.' : 'Olisit maksanut n.' }}</p>
                                            <p class="vk-detail-bill-figure">{{ number_format(abs($billComparison['delta']), 2, ',', ' ') }}&nbsp;€</p>
                                            <svg class="vk-detail-bill-loop" viewBox="0 0 330 20" focusable="false">
                                                <path d="M4 12 C 70 4, 130 16, 200 9 S 300 5, 326 11" fill="none" stroke="currentColor" stroke-width="4" stroke-linecap="round" />
                                                <path d="M24 17 C 100 11, 180 18, 262 13" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" opacity=".7" />
                                            </svg>
                                            <p class="vk-detail-bill-per">{{ $billVerdict === 'costs_more' ? 'enemmän tällä yhdellä laskutusjaksolla.' : 'tällä yhdellä laskutusjaksolla.' }}</p>
                                        </div>
                                    @endif
                                </div>

                                <div class="flex flex-col gap-[18px]">
                                    <p class="vk-detail-bill-basis">
                                        {{ $billComparison['basis'] }}
                                        Jakson hinnaksi tulisi {{ number_format($billComparison['implied_cents'], 2, ',', ' ') }} c/kWh perusmaksu mukaan lukien.
                                    </p>
                                    <div class="flex flex-wrap items-center gap-x-7 gap-y-2">
                                        @if ($billVerdict === 'costs_more' && ! empty($alternativeTiles))
                                            <a href="#halvemmat" x-data @click.prevent="{{ $scrollTo('halvemmat') }}" class="vk-action vk-focus min-h-[44px] justify-center">
                                                <span>Katso halvemmat vaihtoehdot <span aria-hidden="true">↓</span></span>
                                                <x-ink.wave width="240" stroke="2.4" />
                                            </a>
                                        @endif
                                        <button type="button" wire:click="clearBill" class="vk-inked-link vk-focus min-h-[44px]">
                                            Tyhjennä laskun tiedot
                                        </button>
                                    </div>
                                </div>
                            @else
                                {{-- Honest unavailability: the module says why instead of rendering
                                     an empty or zero result. --}}
                                <p class="vk-detail-bill-unavailable">{{ $billComparison['message'] }}</p>
                                <p>
                                    <button type="button" wire:click="clearBill" class="vk-inked-link vk-focus min-h-[44px]">
                                        Tyhjennä laskun tiedot
                                    </button>
                                </p>
                            @endif
                        @else
                            {{-- Empty state: what the answer will be, in three steps. --}}
                            <p class="vk-detail-bill-kicker">Tulos näkyy tässä</p>
                            <p class="vk-detail-bill-question">Mitä {{ $displayName }} olisi maksanut samalta jaksolta?</p>
                            <ol class="vk-detail-bill-steps">
                                <li><span aria-hidden="true">1</span>Valitse laskusi laskutusjakso.</li>
                                <li><span aria-hidden="true">2</span>Kirjoita jakson kulutus kilowattitunteina.</li>
                                <li><span aria-hidden="true">3</span>Kirjoita sähköenergian osuus euroina, ilman sähkön siirtoa.</li>
                            </ol>
                            <p class="vk-detail-bill-privacy">Vertailu käyttää vain tämän yhden jakson tietoja. Laskusi tiedot eivät tallennu.</p>
                        @endif
                    </div>
                </div>
            </section>
        @endif

        {{-- ============================ Hintatiedot (Paper 48Z-0) ============================ --}}
        <section id="hintatiedot" class="vk-detail-section">
            <div class="vk-detail-columns">
                <div class="vk-detail-stack">
                    <x-site.section-heading wave="190">Hintatiedot</x-site.section-heading>

                    {{-- The pricing category, from the same presenter as the listing entry that
                         linked here: glyph + name + meaning. Single purpose: never a warning.
                         The Arvio marker lives on the hero price, so this line passes none. --}}
                    <div class="vk-detail-cat">
                        <x-card.band variant="ledger" :band="$card->band" :bucket="$card->bucket" :estimate="null" />
                    </div>

                    @if ($card->offerDescription !== null)
                        <p class="vk-detail-offer">
                            <x-ink.sparkle size="13" color="var(--color-fox)" />
                            <span>{{ $card->offerDescription }}</span>
                        </p>
                    @endif

                    {{-- Pricing-integrity notice: only for validated deceptive/conflicting
                         pricing. A warning mark "!" + text in price-high, no box. --}}
                    @if ($card->promotionEndNotice !== null)
                        <p class="vk-detail-warning">
                            <span class="vk-detail-warning-mark" aria-hidden="true">!</span>
                            <span>{{ $card->promotionEndNotice }}</span>
                        </p>
                    @elseif (($pricingIntegrity['detected'] ?? false) && ! empty($pricingIntegrity['detail_facts']) && ! $card->suppressPriceChangeNotice)
                        <div class="vk-detail-warning">
                            <span class="vk-detail-warning-mark" aria-hidden="true">!</span>
                            <div>
                                <p class="vk-detail-warning-title">{{ $pricingIntegrity['detail_heading'] ?? 'Huomio hinnoittelusta' }}</p>
                                <ul>
                                    @foreach ($pricingIntegrity['detail_facts'] as $fact)
                                        <li>{{ $fact }}</li>
                                    @endforeach
                                </ul>
                            </div>
                        </div>
                    @endif

                    {{-- Itemised price rows, from ContractCard\CardReceiptLines (the presenter
                         states the mechanism instead of guessing it). Label 18, dotted leader,
                         value in Bricolage. A soft (estimated) value is lighter. --}}
                    @if (count($card->receiptLines) > 0)
                        <dl class="vk-detail-parts">
                            @foreach ($card->receiptLines as $line)
                                <div class="vk-detail-part">
                                    <dt class="vk-detail-part-label">{{ $line->label }}</dt>
                                    <span class="vk-leader" aria-hidden="true"></span>
                                    <dd class="vk-detail-part-value {{ $line->soft ? 'vk-detail-part-value--soft' : '' }}">{{ $line->value }}@if ($line->unit)&nbsp;<span class="vk-detail-part-unit">{{ $line->unit }}</span>@endif</dd>
                                </div>
                            @endforeach
                        </dl>
                    @endif

                    {{-- Quiet notes: what the reset estimate reads, and what a promotion is worth. --}}
                    @foreach ($receiptNotes as $note)
                        <p class="vk-detail-small">{{ $note }}</p>
                    @endforeach

                    {{-- Warning marks, priority ordered and capped at two by
                         ContractCard\CardFooterItems. --}}
                    @if (count($card->warnings) > 0)
                        <div class="vk-detail-tags">
                            <x-card.footer variant="ledger" :warnings="$card->warnings" />
                        </div>
                    @endif
                </div>

                {{-- Static per-consumption cost table. Server-rendered for every visitor
                     regardless of the interactive selection, because "paljonko tämä sopimus
                     maksaa 18 000 kWh kulutuksella" is a search query and the answer has to be
                     in the initial HTML. Same calculation path as the hero price. --}}
                @if (! empty($consumptionCostTable))
                    <div class="min-w-0 lg:pt-2">
                        <table class="vk-detail-cost">
                            <caption>Arvioitu kustannus eri vuosikulutuksilla</caption>
                            <thead>
                                <tr>
                                    <th scope="col" class="text-left">Vuosikulutus</th>
                                    <th scope="col" class="text-right">€/kk</th>
                                    <th scope="col" class="text-right">€/vuosi</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($consumptionCostTable as $row)
                                    @php $isRowSelected = $row['consumption'] === $consumption; @endphp
                                    <tr class="{{ $isRowSelected ? 'is-selected' : '' }}">
                                        <th scope="row">
                                            <span class="vk-detail-cost-who">
                                                @if (isset($homeArt[$row['consumption']]))
                                                    <img src="{{ Season::art($homeArt[$row['consumption']]) }}" alt="" width="38" height="38" loading="lazy" decoding="async">
                                                @endif
                                                <span>{{ number_format($row['consumption'], 0, ',', ' ') }} kWh · {{ $row['hint'] }}</span>
                                            </span>
                                        </th>
                                        @if ($row['total_cost'] !== null)
                                            <td class="vk-detail-cost-month">{{ number_format($row['monthly_cost'], 2, ',', ' ') }}</td>
                                            <td class="vk-detail-cost-year vk-detail-cost-loopcell">
                                                {{ number_format($row['total_cost'], 0, ',', ' ') }}
                                                @if ($isRowSelected)
                                                    {{-- Hand-drawn fox loop around the selected row's figures. --}}
                                                    <svg class="vk-detail-cost-loop" viewBox="0 0 250 54" preserveAspectRatio="none" aria-hidden="true" focusable="false">
                                                        <path d="M226 8 C 190 0, 50 1, 18 10 C 2 16, 4 42, 30 48 C 80 56, 200 54, 234 44 C 252 36, 250 14, 214 6" fill="none" style="stroke: var(--color-fox)" stroke-width="2.2" stroke-linecap="round" vector-effect="non-scaling-stroke" />
                                                    </svg>
                                                @endif
                                            </td>
                                        @else
                                            <td class="vk-detail-cost-na" colspan="2">Ei saatavilla tällä kulutuksella</td>
                                        @endif
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                        <p class="vk-detail-cost-note">
                            {{ ($calculatedCost['contract_term'] ?? null) !== null ? 'Vuositasolle laskettu vertailuhinta' : '12 kuukauden arvio' }} ilman siirtomaksuja, {{ $contract->target_group === 'Company' ? 'hinnat ilman ALV:tä' : 'hinnat sisältävät ALV:n' }}. Valittu kulutus on korostettu.
                        </p>
                    </div>
                @endif
            </div>

            {{-- The counterfactual: the alternative the visitor is really deciding against.
                 Sentence generated in ContractDetail from typed fields; the two bars draw the
                 same two figures (aria-hidden, the sentence carries them). --}}
            @if ($spotCounterfactual)
                @php
                    $cfSelf = $spotCounterfactual['self_cost'] ?? null;
                    $cfRef = $spotCounterfactual['reference_cost'] ?? null;
                    $cfMax = ($cfSelf !== null && $cfRef !== null) ? max($cfSelf, $cfRef, 1) : null;
                    $cfRefColour = ($spotCounterfactual['reference_bucket'] ?? null) === 'kiintea' ? 'var(--color-cat-fixed)' : 'var(--color-cat-spot)';
                @endphp
                <div class="vk-detail-versus">
                    <div class="flex flex-col gap-2.5">
                        <p class="vk-detail-text">{{ $spotCounterfactual['text'] }}</p>
                        <a href="{{ $spotCounterfactual['url'] }}" class="vk-action vk-focus">
                            <span>{{ $spotCounterfactual['label'] }} →</span>
                            <x-ink.wave width="200" stroke="2.4" />
                        </a>
                    </div>
                    @if ($cfMax !== null)
                        <div class="vk-detail-bars" aria-hidden="true">
                            <div>
                                <p class="vk-detail-bar-label text-fox-deep">Tämä sopimus</p>
                                <div class="vk-detail-bar-row">
                                    <span class="vk-detail-bar" style="--w: {{ round($cfSelf / $cfMax * 100, 1) }}; background-color: var(--color-fox)"></span>
                                    <span class="vk-detail-bar-value text-fox-deep">{{ number_format($cfSelf, 0, ',', ' ') }} €</span>
                                </div>
                            </div>
                            <div>
                                <p class="vk-detail-bar-label" style="color: {{ $cfRefColour }}">{{ $spotCounterfactual['reference_name'] }}</p>
                                <div class="vk-detail-bar-row">
                                    <span class="vk-detail-bar" style="--w: {{ round($cfRef / $cfMax * 100, 1) }}; background-color: {{ $cfRefColour }}; opacity: .75"></span>
                                    <span class="vk-detail-bar-value" style="color: {{ $cfRefColour }}">{{ number_format($cfRef, 0, ',', ' ') }} €</span>
                                </div>
                            </div>
                        </div>
                    @endif
                </div>
            @endif
        </section>

        {{-- ============================ Näin hinta on kehittynyt (Paper 4HF-0) ============================
             One module, one chart. The payload is
             ContractDetail::getPriceDevelopmentProperty(); do not compute chart geometry in this
             template again. Colours: the contract is the fox line, the reference a dashed granite
             line (Paper board), never colour alone: the legend names both. --}}
        @if (count($priceHistory) > 0 || count($contractHistory) > 1 || ! $isActive)
            @php
                // Which component the version-to-version delta marks describe.
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
                // read the wrong row's price into the delta mark.
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

            <section id="hintakehitys" class="vk-detail-section">
                <div>
                    <x-site.section-heading wave="300">Näin hinta on kehittynyt</x-site.section-heading>
                    @if (! empty($priceDevelopment['subtitle']))
                        <p class="vk-detail-sub">{{ $priceDevelopment['subtitle'] }}</p>
                    @endif
                </div>

                @if ($chart)
                    {{-- Legend, so series identity is never colour alone. --}}
                    <ul class="vk-detail-legend">
                        <li>
                            <span class="vk-detail-legend-line" aria-hidden="true"></span>
                            <span>{{ $chart['series_label'] }}</span>
                        </li>
                        @if ($chart['reference_path'] !== '')
                            <li>
                                <span class="vk-detail-legend-dash" aria-hidden="true"></span>
                                <span>{{ $chart['reference_label'] }}</span>
                            </li>
                        @endif
                    </ul>
                @endif

                <div class="vk-detail-chart-grid">
                    <div class="min-w-0">
                        @if ($chart)
                            <div class="relative" x-data="{ open: false, title: '', series: '', reference: '', tipX: 0, tipY: 0 }" x-ref="wrap">
                                <div class="overflow-x-auto">
                                    <div class="min-w-[560px]">
                                        <svg viewBox="{{ $chart['view_box'] }}" width="100%" class="block h-auto tabular-nums"
                                             role="img" aria-label="{{ $chart['aria_label'] }}">
                                            {{-- Gridlines --}}
                                            <g style="stroke: var(--color-edge)" stroke-width="1">
                                                @foreach ($chart['y_ticks'] as $tick)
                                                    <line x1="{{ $chart['plot']['left'] }}" y1="{{ $tick['y'] }}" x2="{{ $chart['plot']['right'] + 60 }}" y2="{{ $tick['y'] }}"/>
                                                @endforeach
                                            </g>

                                            {{-- Signed zero baseline. Only drawn when the data actually
                                                 crosses zero, so a positive-only chart shows no false rule. --}}
                                            @if ($chart['zero_y'] !== null)
                                                <line x1="{{ $chart['plot']['left'] }}" y1="{{ $chart['zero_y'] }}" x2="{{ $chart['plot']['right'] + 60 }}" y2="{{ $chart['zero_y'] }}"
                                                      style="stroke: var(--color-ink)" stroke-width="1.6"/>
                                                <text x="{{ $chart['plot']['left'] - 8 }}" y="{{ $chart['zero_y'] + 4 }}" font-size="12" style="fill: var(--color-ink-muted)" text-anchor="end">0</text>
                                            @endif

                                            {{-- Y axis --}}
                                            <g font-size="12" style="fill: var(--color-ink-muted)" text-anchor="end">
                                                @foreach ($chart['y_ticks'] as $tick)
                                                    <text x="{{ $chart['plot']['left'] - 8 }}" y="{{ $tick['y'] + 4 }}">{{ $tick['label'] }}</text>
                                                @endforeach
                                            </g>
                                            <text x="16" y="18" font-size="12" style="fill: var(--color-ink-muted)">{{ $chart['unit'] }}</text>

                                            {{-- Inked baseline under the plot (spec §5 "Charts"). --}}
                                            <path d="M{{ $chart['plot']['left'] }} {{ $chart['plot']['bottom'] + 0.5 }} H{{ $chart['plot']['right'] + 60 }}" fill="none" style="stroke: var(--color-ink)" stroke-width="2" stroke-linecap="round" />

                                            {{-- X axis --}}
                                            <g font-size="12" font-weight="700" style="fill: var(--color-ink-body)" text-anchor="middle">
                                                @foreach ($chart['rows'] as $row)
                                                    <text x="{{ $row['label_x'] }}" y="{{ $chart['plot']['bottom'] + 28 }}">{{ $row['label'] }}</text>
                                                @endforeach
                                            </g>

                                            {{-- Market reference: dashed granite, with a direct label --}}
                                            @if ($chart['reference_path'] !== '')
                                                <path d="{{ $chart['reference_path'] }}" fill="none" style="stroke: var(--color-granite)"
                                                      stroke-width="2.6" stroke-dasharray="6 5" stroke-linecap="round" stroke-linejoin="round"/>
                                                @if ($chart['reference_end_label'])
                                                    <text x="{{ $chart['reference_end_label']['x'] }}" y="{{ $chart['reference_end_label']['y'] }}"
                                                          font-size="13" font-weight="800" style="fill: var(--color-granite)">{{ $chart['reference_end_label']['text'] }}</text>
                                                @endif
                                            @endif

                                            {{-- The contract itself: the fox line --}}
                                            <path d="{{ $chart['series_path'] }}" fill="none" style="stroke: var(--color-fox)"
                                                  stroke-width="3.2" stroke-linecap="round" stroke-linejoin="round"/>
                                            @if ($chart['show_points'])
                                                <g style="fill: var(--color-fox)">
                                                    @foreach ($chart['series_points'] as $point)
                                                        <circle cx="{{ $point['x'] }}" cy="{{ $point['y'] }}" r="4"/>
                                                    @endforeach
                                                </g>
                                            @endif
                                            @if ($chart['series_end_label'])
                                                <text x="{{ $chart['series_end_label']['x'] }}" y="{{ $chart['series_end_label']['y'] }}"
                                                      font-size="13" font-weight="800" style="fill: var(--color-fox-deep)">{{ $chart['series_end_label']['text'] }}</text>
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

                                <div x-show="open" x-cloak :style="'left: ' + tipX + 'px; top: ' + tipY + 'px'" class="vk-detail-tooltip">
                                    <strong class="block" x-text="title"></strong>
                                    <span x-show="series !== ''" class="block">
                                        {{ $chart['tooltip_series_label'] }}:
                                        <strong class="tabular-nums" x-text="series + ' {{ $chart['unit'] }}'"></strong>
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
                            {{-- Honest empty state: say the window is too short, never draw a flat
                                 line through one observation. --}}
                            <p class="vk-detail-text">{{ $priceDevelopment['message'] }}</p>
                        @endif
                    </div>

                    {{-- Seller behaviour record beside the chart. Only facts whose data exists are
                         built, and every figure in them is c/kWh or €/kk, never a percentage. --}}
                    @if (! empty($priceDevelopment['facts']) || ! empty($priceDevelopment['note']))
                        <div class="flex flex-col gap-4 lg:pt-1.5">
                            @if (! empty($priceDevelopment['facts']))
                                <ul class="vk-detail-facts">
                                    @foreach ($priceDevelopment['facts'] as $fact)
                                        <li>{{ $fact }}</li>
                                    @endforeach
                                </ul>
                            @endif
                            @if (! empty($priceDevelopment['note']))
                                <p class="vk-detail-note">{{ $priceDevelopment['note'] }}</p>
                            @endif
                        </div>
                    @endif
                </div>

                {{-- Version timeline: date lane, inked rail, version content. --}}
                <ol class="vk-timeline">
                    @if (! $isActive)
                        <li class="vk-tl-item vk-tl-item--gone {{ count($visibleTimeline) === 0 ? 'vk-tl-item--last' : '' }}">
                            <div class="vk-tl-when">
                                <span class="vk-tl-date">Nyt</span>
                            </div>
                            <div class="vk-tl-rail" aria-hidden="true"><span class="vk-tl-node"></span></div>
                            <div class="vk-tl-what">
                                <p class="vk-tl-name">Sopimus ei ole enää myynnissä</p>
                                <p class="vk-tl-meta">
                                    @if ($lastSeenOnSaleDate)
                                        Viimeksi havaittu myynnissä <time datetime="{{ $lastSeenOnSaleDate->format('Y-m-d') }}">{{ $lastSeenOnSaleDate->translatedFormat('j.n.Y') }}</time>.
                                    @else
                                        Viimeinen havainto myynnissä ei ole tiedossa.
                                    @endif
                                </p>
                            </div>
                            <span class="vk-tl-spacer" aria-hidden="true"></span>
                        </li>
                    @endif

                    @foreach ($visibleTimeline as $i => $entry)
                        @include('partials.contract-version-timeline-item', [
                            'entry' => $entry,
                            'showConnector' => $i < count($visibleTimeline) - 1 || count($hiddenTimeline) > 0,
                            'deltaUnit' => $deltaUnit,
                            'deltaSubject' => $deltaSubject,
                            'isOlder' => $i > 0,
                        ])
                    @endforeach
                </ol>

                @if (count($hiddenTimeline) > 0)
                    <details class="vk-timeline-more">
                        <summary class="vk-detail-summary">
                            Näytä {{ count($hiddenTimeline) }} vanhempaa versiota
                            <svg viewBox="0 0 18 18" aria-hidden="true" focusable="false"><path d="M4 7l5 5 5-5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" /></svg>
                        </summary>
                        <ol class="vk-timeline">
                            @foreach ($hiddenTimeline as $i => $entry)
                                @include('partials.contract-version-timeline-item', [
                                    'entry' => $entry,
                                    'showConnector' => $i < count($hiddenTimeline) - 1,
                                    'deltaUnit' => $deltaUnit,
                                    'deltaSubject' => $deltaSubject,
                                    'isOlder' => true,
                                ])
                            @endforeach
                        </ol>
                    </details>
                @endif
            </section>
        @endif

        {{-- ============================ Kannattaako X? (Paper 50Q-0) ============================
             Generated in PHP from typed fields only. Every figure is priced at the comparison
             consumption, so it moves with the picker. It reads as a verdict, so it sits AFTER
             the evidence it is a verdict on. --}}
        @if ($verdict)
            <section id="kannattaako" class="vk-detail-section">
                <div class="vk-detail-verdict">
                    <div class="flex flex-col gap-0.5">
                        <h2 class="vk-detail-verdict-heading">{{ $verdict['heading'] }}</h2>
                        <x-ink.wave width="250" stroke="3.5" />
                    </div>
                    <div
                        class="vk-detail-verdict-body"
                        wire:loading.class.delay="opacity-40"
                        wire:target="setConsumption, directConsumption"
                    >
                        @foreach ($verdict['paragraphs'] as $paragraph)
                            <p class="{{ $loop->first ? 'vk-detail-verdict-lead' : 'vk-detail-verdict-text' }}">{{ $paragraph }}</p>
                        @endforeach

                        @if ($verdict['show_cheaper_link'])
                            <p>
                                <a href="#halvemmat" x-data @click.prevent="{{ $scrollTo('halvemmat') }}" class="vk-action vk-focus min-h-[44px] justify-center">
                                    <span>Katso halvemmat vaihtoehdot <span aria-hidden="true">↓</span></span>
                                    <x-ink.wave width="240" stroke="2.4" />
                                </a>
                            </p>
                        @endif

                        <p class="vk-detail-note">{{ $verdict['basis'] }}</p>
                    </div>
                </div>
            </section>
        @endif

        {{-- ============================ Sähkön alkuperä ja päästöt (Paper 51Y-0) ============================
             ONE environment module with ONE taxonomy (three emission tiers). The driving
             equivalent in kilometres leads; the kilograms stay as the measured metric. The car
             vignette follows the season. --}}
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
                $residualPercent = (float) ($co2Emissions['residual_mix_percent'] ?? 0);

                // ONE taxonomy, three emission tiers: aurora (low), caution ink (middle),
                // price-high (high), each with a sparkle mark and its name.
                if ($emissionFactor < 50) {
                    $severityLabel = $emissionFactor == 0 ? 'Päästötön' : 'Matalat päästöt';
                    $severityClass = 'vk-detail-severity--low';
                } elseif ($emissionFactor < 200) {
                    $severityLabel = 'Keskitason päästöt';
                    $severityClass = 'vk-detail-severity--mid';
                } else {
                    $severityLabel = 'Korkeat päästöt';
                    $severityClass = 'vk-detail-severity--high';
                }

                $source = $contract->electricitySource;
                $hasSourceData = $source && (
                    ($source->renewable_total ?? 0) > 0 || ($source->nuclear_total ?? 0) > 0 || ($source->fossil_total ?? 0) > 0
                );
            @endphp
            <section id="ymparisto" class="vk-detail-section">
                <x-site.section-heading wave="300">Sähkön alkuperä ja päästöt</x-site.section-heading>

                <div
                    class="vk-detail-emissions"
                    wire:loading.class.delay="opacity-40"
                    wire:target="setConsumption, directConsumption"
                >
                    <div class="vk-detail-drive">
                        @if ($emissionFactor == 0)
                            <div class="vk-detail-drive-figure">
                                <p class="vk-detail-km">0 <span class="vk-detail-co2-unit">kg CO₂e vuodessa</span></p>
                                <p class="vk-detail-km-caption">Tämän sopimuksen sähköntuotannolla ei ole suoria CO₂-päästöjä.</p>
                            </div>
                        @else
                            @if ($drivingKm > 0)
                                <div class="vk-detail-car" aria-hidden="true">
                                    <img src="{{ Season::art('contract-emissions-car') }}" alt="" width="240" height="160" loading="lazy" decoding="async">
                                    <svg width="100%" height="14" viewBox="0 0 260 14" preserveAspectRatio="none" focusable="false">
                                        <path d="M2 6 C 70 4, 150 8, 258 5" fill="none" style="stroke: var(--color-ink)" stroke-width="2" stroke-linecap="round" vector-effect="non-scaling-stroke" />
                                        <path d="M20 11 H44 M70 11 H94 M120 11 H144 M170 11 H194 M220 11 H244" fill="none" style="stroke: var(--color-field-edge)" stroke-width="2" stroke-linecap="round" vector-effect="non-scaling-stroke" />
                                    </svg>
                                </div>
                            @endif
                            {{-- The driving equivalent leads, not the kilograms: nobody holds a sense
                                 of scale for a yearly CO₂e total of an invisible product. --}}
                            <div class="vk-detail-drive-figure">
                                <p class="vk-detail-km">{{ number_format($drivingKm, 0, ',', ' ') }} km</p>
                                <p class="vk-detail-km-caption">ajoa bensiiniautolla<br>vastaa sopimuksen vuosipäästöjä</p>
                            </div>
                        @endif
                    </div>

                    <div class="vk-detail-co2">
                        @if ($emissionFactor != 0)
                            {{-- The figure and its unit are one token. --}}
                            <p class="vk-detail-co2-figure whitespace-nowrap">{{ number_format($annualEmissionsKg, 0, ',', ' ') }} kg CO₂e</p>
                        @endif
                        <p class="vk-detail-severity {{ $severityClass }}">
                            <x-ink.sparkle size="12" color="currentColor" />
                            {{ $severityLabel }} · {{ number_format($emissionFactor, 0, ',', '') }} g/kWh
                        </p>
                        <p class="vk-detail-note">Luku on laskettu valitsemallasi {{ number_format($consumption, 0, ',', ' ') }} kWh vuosikulutuksella.</p>
                    </div>
                </div>

                <div class="vk-detail-columns pt-2">
                    <div class="vk-detail-stack">
                        {{-- Origin breakdown --}}
                        @if ($hasSourceData)
                            <dl class="vk-detail-shares">
                                @foreach ([
                                    ['Uusiutuva', $source->renewable_total, 'var(--color-aurora)'],
                                    ['Ydinvoima', $source->nuclear_total, 'var(--color-lake)'],
                                    ['Fossiilinen', $source->fossil_total, 'var(--color-price-high)'],
                                ] as [$label, $share, $barColour])
                                    @if ($share && $share > 0)
                                        <div>
                                            <div class="vk-detail-share-head">
                                                <dt>{{ $label }}</dt>
                                                <dd>{{ number_format($share, 0, ',', ' ') }} %</dd>
                                            </div>
                                            <div class="vk-detail-share-track" aria-hidden="true">
                                                <div class="vk-detail-share-fill" style="width: {{ min($share, 100) }}%; background-color: {{ $barColour }}"></div>
                                            </div>
                                        </div>
                                    @endif
                                @endforeach
                            </dl>

                            @if ($source->renewable_total && $source->renewable_total > 0)
                                <dl class="vk-detail-subshares">
                                    @foreach ([
                                        ['Tuulivoima', $source->renewable_wind],
                                        ['Vesivoima', $source->renewable_hydro],
                                        ['Aurinkovoima', $source->renewable_solar],
                                        ['Biomassa', $source->renewable_biomass],
                                    ] as [$label, $share])
                                        @if ($share && $share > 0)
                                            <div class="flex items-baseline">
                                                <dt>{{ $label }}</dt>
                                                <dd>{{ number_format($share, 0, ',', ' ') }} %</dd>
                                            </div>
                                        @endif
                                    @endforeach
                                </dl>
                            @endif
                        @elseif ($residualPercent > 0)
                            {{-- No seller breakdown: the share the residual mix covers. --}}
                            <dl class="vk-detail-shares">
                                <div>
                                    <div class="vk-detail-share-head">
                                        <dt>Jäännösjakauma</dt>
                                        <dd>{{ number_format($residualPercent, 0, ',', '') }} %</dd>
                                    </div>
                                    <div class="vk-detail-share-track" aria-hidden="true">
                                        <div class="vk-detail-share-fill" style="width: {{ min($residualPercent, 100) }}%; background-color: var(--color-granite)"></div>
                                    </div>
                                </div>
                            </dl>
                        @endif

                        @if ($drivingKm > 0)
                            <p>
                                <a href="/sahkosopimus/fossiiliton" class="vk-action vk-focus min-h-[44px] justify-center">
                                    <span>Katso vähäpäästöiset sopimukset →</span>
                                    <x-ink.wave width="270" stroke="2.4" />
                                </a>
                            </p>
                        @endif
                    </div>

                    <div class="vk-detail-stack">
                        @if ($residualPercent > 0)
                            <p class="vk-detail-text">
                                Myyjä ei ole eritellyt
                                <strong class="font-extrabold text-ink tabular-nums">{{ number_format($residualPercent, 0, ',', '') }} %</strong>
                                tämän sopimuksen sähkön alkuperästä, joten se osuus lasketaan jäännösjakaumalla: sillä sähköllä, joka jää jäljelle kun alkuperätakuilla myyty tuotanto on poistettu. Se ei kerro, millaista sähköä juuri sinulle toimitetaan.
                            </p>
                        @elseif ($emissionFactor > $physicalAverage)
                            <p class="vk-detail-text">
                                Päästökerroin perustuu myyjän ilmoittamiin energialähteisiin. Suomen sähköverkon fyysinen keskipäästö on noin {{ number_format($physicalAverage, 0) }} g/kWh.
                            </p>
                        @endif
                        @unless ($hasSourceData)
                            <p class="vk-detail-small">
                                Sähkön alkuperätietoja ei ole saatavilla tälle sopimukselle, joten päästölaskennassa käytetään Suomen jäännösjakaumaa.
                            </p>
                        @endunless

                        <details>
                            <summary class="vk-detail-summary">
                                Näytä laskennan yksityiskohdat
                                <svg viewBox="0 0 18 18" aria-hidden="true" focusable="false"><path d="M4 7l5 5 5-5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" /></svg>
                            </summary>

                            <div class="mt-3 flex flex-col gap-5">
                                <div>
                                    <h3 class="vk-detail-h3">Päästöt energialähteittäin</h3>
                                    <dl class="vk-detail-rowlist mt-1">
                                        @foreach ($co2Emissions['emissions_by_source'] as $sourceKey => $emissionsKg)
                                            <div>
                                                <dt>{{ $sourceLabels[$sourceKey] ?? $sourceKey }}</dt>
                                                <dd>{{ number_format($emissionsKg, 1, ',', ' ') }} kg CO₂e</dd>
                                            </div>
                                        @endforeach
                                    </dl>
                                </div>

                                <div>
                                    <h3 class="vk-detail-h3">Käytetyt päästökertoimet</h3>
                                    <dl class="vk-detail-rowlist mt-1">
                                        @foreach ($co2Emissions['emissions_by_source'] as $sourceKey => $emissionsKg)
                                            @if (isset($emissionFactorSources[$sourceKey]))
                                                <div>
                                                    <dt>
                                                        {{ $sourceLabels[$sourceKey] ?? $sourceKey }}
                                                        <span class="text-ink-muted">({{ $emissionFactorSources[$sourceKey]['source'] }})</span>
                                                    </dt>
                                                    <dd>{{ number_format($emissionFactorSources[$sourceKey]['value'], 0, ',', ' ') }} g/kWh</dd>
                                                </div>
                                            @endif
                                        @endforeach
                                    </dl>
                                </div>

                                <ul class="vk-detail-small flex flex-col gap-1">
                                    <li>Fossiilisten polttoaineiden päästökertoimet: Tilastokeskus ja IPCC Guidelines for National GHG Inventories.</li>
                                    <li>Suomen tuotannon keskiarvo (noin {{ number_format($physicalAverage, 0) }} g/kWh): Fingrid ja Tilastokeskus 2024.</li>
                                    <li>Jäännösjakauman päästökerroin: kansallinen jäännösjakauma 2024.</li>
                                    <li>Uusiutuvat ja ydinvoima: EU:n alkuperätakuujärjestelmän mukainen 0 g/kWh.</li>
                                </ul>
                            </div>
                        </details>
                    </div>
                </div>
            </section>
        @endif

        {{-- ============================ Sopimusehdot lyhyesti (Paper 56J-0) ============================
             One flat list of the terms Voltikka actually holds, then the seller and the
             seller's own description COLLAPSED. Rows come from
             ContractDetail::getContractTermsProperty(), which returns only rows whose data
             exists. Do not add a second terms list anywhere on this page. --}}
        <section id="sopimusehdot" class="vk-detail-section">
            <div class="vk-detail-columns">
                <div class="vk-detail-stack">
                    <div>
                        <x-site.section-heading wave="270">Sopimusehdot lyhyesti</x-site.section-heading>
                        <p class="vk-detail-sub">Poimittu myyjän ilmoittamista sopimustiedoista.</p>
                    </div>

                    @if (! empty($contractTerms))
                        <dl class="vk-detail-terms">
                            @foreach ($contractTerms as $term)
                                <div>
                                    <dt>{{ $term['label'] }}</dt>
                                    <dd>{{ $term['value'] }}</dd>
                                </div>
                            @endforeach
                        </dl>
                    @endif

                    @if ($contract->microproduction_buys && $contract->microproduction_default)
                        <div>
                            <p class="vk-detail-kicker">Pientuotanto</p>
                            <p class="vk-detail-text mt-1">{{ $contract->microproduction_default }}</p>
                        </div>
                    @endif

                    <p class="vk-detail-check">Tarkista ajantasaiset ehdot myyjän sivuilta ennen tilausta.</p>
                </div>

                <div class="vk-detail-seller">
                    {{-- Myyjän tiedot: the terms section is where a reader looks for who they
                         would be buying from. --}}
                    @if ($contract->company)
                        <div class="flex flex-col gap-1.5">
                            <p class="vk-detail-kicker">Myyjä</p>
                            <p class="flex flex-wrap items-center gap-3">
                                @if ($hasLogo)
                                    <x-company-logo variant="ledger" :company="$contract->company" :name="$contract->company->name" />
                                @endif
                                @if ($companyInternalUrl)
                                    <a href="{{ $companyInternalUrl }}" class="vk-detail-seller-name vk-focus">{{ $contract->company->name }}</a>
                                @else
                                    <span class="vk-detail-seller-name">{{ $contract->company->name }}</span>
                                @endif
                            </p>
                            @if ($contract->company->street_address)
                                <p class="vk-detail-text">{{ $contract->company->street_address }}, {{ $contract->company->postal_code }} {{ $contract->company->postal_name }}</p>
                            @endif
                            @if ($contract->company->company_url)
                                <p>
                                    <a href="{{ $contract->company->company_url }}" target="_blank" rel="noopener noreferrer" class="vk-detail-url vk-focus inline-flex min-h-[44px] items-center">
                                        {{ $contract->company->company_url }}
                                    </a>
                                </p>
                            @endif
                        </div>
                    @endif

                    {{-- Internal comparison links for the contract's duration, metering and
                         pricing model. Mapping: Support\ContractInternalLinks. --}}
                    @if (! empty($heroBadgeLinks))
                        <div class="flex flex-col gap-1.5">
                            <p class="vk-detail-kicker">Vertaa samankaltaisia</p>
                            <p class="vk-detail-links">
                                @foreach ($heroBadgeLinks as $badge)
                                    @if ($badge['url'])
                                        <a href="{{ $badge['url'] }}" class="vk-focus">{{ $badge['label'] }}</a>
                                    @else
                                        <span class="inline-flex min-h-[32px] items-center">{{ $badge['label'] }}</span>
                                    @endif
                                @endforeach
                            </p>
                        </div>
                    @endif

                    {{-- The seller's own description, collapsed. Both bodies come from
                         App\Support\ContractContentSanitizer, never from the raw column. --}}
                    @if ($descriptionHtml || $descriptionText)
                        <details class="vk-detail-rule-disclosure">
                            <summary class="vk-detail-rule-summary">
                                Myyjän oma kuvaus sopimuksesta
                                <svg viewBox="0 0 18 18" aria-hidden="true" focusable="false"><path d="M4 7l5 5 5-5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" /></svg>
                            </summary>
                            @if ($descriptionHtml)
                                <div class="vk-detail-prose">
                                    {!! $descriptionHtml !!}
                                </div>
                            @endif
                            @if ($descriptionText)
                                <p class="vk-detail-prose whitespace-pre-line">{{ $descriptionText }}</p>
                            @endif
                        </details>
                    @endif
                </div>
            </div>
        </section>

        {{-- ============================ Usein kysyttyä (Paper 597-0) ============================
             The list is ContractDetail::getFaqItemsProperty(), which also builds the FAQPage
             JSON-LD, so the visible answers and the schema cannot drift. The pricing-mechanism
             item carries #faq-miten because the header's category label links to it. --}}
        @if (! empty($faqItems))
            <section
                id="usein-kysyttya"
                class="vk-detail-section vk-detail-faq"
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
                <x-site.section-heading wave="240">Usein kysyttyä</x-site.section-heading>

                <div class="vk-detail-faq-list">
                    @foreach ($faqItems as $faq)
                        <details id="{{ $faq['id'] }}" class="vk-detail-faq-item scroll-mt-24">
                            <summary class="vk-detail-faq-q">
                                <span>{{ $faq['question'] }}</span>
                                <svg viewBox="0 0 18 18" aria-hidden="true" focusable="false"><path d="M4 7l5 5 5-5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" /></svg>
                            </summary>
                            <p class="vk-detail-faq-a">{{ $faq['answer'] }}</p>
                        </details>
                    @endforeach
                </div>
            </section>
        @endif

        {{-- ============================ Halvemmat vaihtoehdot (Paper 6ES-0) ============================
             Ledger rows, not tiles: logo, name and seller, €/kk with the saving, "Katso →". --}}
        @if (! empty($alternativeTiles))
            <section id="halvemmat" class="vk-detail-section">
                <div>
                    <x-site.section-heading wave="280">
                        @if ($heroVerdict && $heroVerdict['rank'] > 1)
                            Halvemmat vaihtoehdot
                        @else
                            Vaihtoehdot vertailusta
                        @endif
                    </x-site.section-heading>
                    <p class="vk-detail-text mt-1">
                        @if ($heroVerdict && $heroVerdict['rank'] > 1)
                            {{ number_format($heroVerdict['rank'] - 1, 0, ',', ' ') }} sopimusta on halvempia {{ number_format($comparisonConsumption, 0, ',', ' ') }} kWh vuosikulutuksella. Tässä halvimmat, sekä halvin samantyyppinen.
                        @else
                            Hinnat {{ number_format($comparisonConsumption, 0, ',', ' ') }} kWh vuosikulutuksella.
                        @endif
                    </p>
                </div>

                <div
                    class="vk-detail-alts"
                    wire:loading.class.delay="opacity-40"
                    wire:target="setConsumption, directConsumption"
                >
                    @foreach ($alternativeTiles as $alt)
                        @php
                            $altContract = $alt['contract'];
                            $altCompany = $altContract->company;
                            $altMonthly = number_format($alt['total_cost'] / 12, 2, ',', ' ');
                        @endphp
                        <a
                            href="{{ route('contract.detail', ['contractId' => $altContract->id]) }}?kulutus={{ $comparisonConsumption }}"
                            class="vk-detail-alt"
                        >
                            <span class="vk-detail-alt-logo">
                                <x-company-logo variant="ledger" :company="$altCompany" :name="$altCompany?->name ?: $altContract->name" />
                            </span>
                            <span class="vk-detail-alt-name">
                                @if ($alt['tag'])
                                    <span class="vk-detail-alt-tag {{ $catClass }}">
                                        <x-ink.glyph :bucket="$bucketValue" size="24" />
                                        {{ $alt['tag'] }}
                                    </span>
                                @endif
                                {{-- Same name normalization as the H1 and both card templates, so a
                                     shouted name is calm wherever Voltikka prints it. --}}
                                <span class="vk-detail-alt-title">{{ $this->displayNameFor($altContract->name) }}</span>
                                <span class="vk-detail-alt-company">{{ $altCompany?->name }}</span>
                            </span>
                            <span class="vk-detail-alt-price">
                                <span class="vk-detail-alt-figure">{{ $altMonthly }} <span class="vk-detail-alt-unit">€/kk</span></span>
                                @if (abs($alt['savings']) >= 1)
                                    @if ($alt['savings'] >= 1)
                                        <span class="vk-detail-alt-saving">Säästä n. {{ number_format($alt['savings'], 0, ',', ' ') }} €/v</span>
                                    @else
                                        <span class="vk-detail-alt-saving vk-detail-alt-saving--more">{{ number_format(abs($alt['savings']), 0, ',', ' ') }} €/v kalliimpi</span>
                                    @endif
                                @endif
                            </span>
                            <span class="vk-detail-alt-action" aria-hidden="true">
                                <span class="vk-katso">
                                    Katso →
                                    <x-ink.wave width="86" stroke="2.6" color="var(--color-ink)" />
                                </span>
                            </span>
                        </a>
                    @endforeach
                </div>

                <p>
                    <a href="{{ $listingUrl }}" class="vk-action vk-focus min-h-[44px] justify-center">
                        <span>
                            @if ($heroVerdict)
                                Vertaa kaikkia {{ number_format($heroVerdict['total'], 0, ',', ' ') }} sopimusta →
                            @else
                                Vertaa kaikkia sopimuksia →
                            @endif
                        </span>
                        <x-ink.wave width="270" stroke="2.6" />
                    </a>
                </p>
            </section>
        @endif

        {{-- Closing method statement. It deliberately does NOT repeat the no-commission line:
             that is stated once beside the CTA and once in the site footer. --}}
        <p class="vk-detail-method">
            Hintatiedot päivittyvät päivittäin ja 12 kuukauden arviot lasketaan samalla menetelmällä kaikille sopimuksille.
            <a href="/tietoa#menetelma" class="vk-text-link vk-focus">Näin laskemme</a>
        </p>
    </div>

    {{-- Sticky mobile CTA bar. It appears only once the header CTA has actually scrolled PAST
         the top of the viewport (`bottom < 0`), never merely because the CTA is below the fold,
         and it hides again over the alternatives and the footer so it cannot cover the cheaper
         options it would be competing with. --}}
    @if ($sellerCta && $this->isActive && ! $isExcludedPricing && ($calculatedCost['total_cost'] ?? null) !== null)
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
            class="vk-detail-sticky sm:hidden"
        >
            <span class="min-w-0 tabular-nums">
                <span class="vk-detail-sticky-price">
                    {{ number_format(($calculatedCost['total_cost'] ?? 0) / 12, 2, ',', ' ') }} €/kk
                </span>
                <span class="vk-detail-sticky-meta">
                    {{ ($calculatedCost['contract_term'] ?? null) !== null ? 'Vuositasolle laskettu vertailuhinta' : '12 kk '.($isEstimatePricing ? 'arvio' : 'hinta') }} · {{ number_format($consumption, 0, ',', ' ') }} kWh
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
                class="vk-detail-cta"
            >
                Myyjän sivuille
            </a>
        </div>
    @endif
</div>
