{{--
    The pricing-type filter: a disclosure "Hinnoittelu: …" with four checkboxes inside.

    Why it is not in `partials/contract-filters.blade.php`: the comparison list is spot-heavy by
    ranking, and a visitor who wants price certainty must find a way out quickly. It used to be
    an always-visible row; the user removed that rule on 2026-10-23 because the controls above
    the list were too crowded (critique of /sahkosopimus). It is now the FIRST trigger of the
    disclosure row, its label states the current selection, and four small category glyphs make
    it findable at a glance (from 1024px; a phone has no room for them). A pill selection must still not open the "Rajaa hakua" accordion
    (see `ContractsList::hasActiveAccordionFilters()`).

    Inside `.vk-disclosures` the wrapper is `display: contents` (trigger in the row, panel below
    the row on a laptop, under its trigger on a phone). Used alone (homepage) it is a plain block.

    Panel shape (Paper "R4 · Controls · Hinnoittelu open"): one control row under the label
    "Näytä vain". Each choice is a drawn checkbox, the inked price-shape glyph of the category
    (<x-ink.glyph>), the name in the category colour and a one-line meaning in ink-body. A
    selected choice fills its checkbox with the category colour and draws a white check. The
    glyph shape and the name carry the meaning, so the row works without colour vision.

    Copy rule: every Finnish string for this filter lives in `$pricingBucketPills` below, never
    inline further down and never in a component. "Jaksoittain vaihtuva hinta" is a locked user
    decision; do not reword it. Sub-lines are short restatements of `ContractCardCopy::band()`.
    "Kiinteä hinta" says "hinta tiedossa etukäteen", not "ei muutu": an open-ended fixed-price
    contract can change after advance notice (root AGENTS.md, FixedPrice SEO pages).

    Dual behaviour (root AGENTS.md, "Filter Links (Dual Behavior)"): where the page opts in
    with `$showSeoFilterLinks` and no filter is active, the three buckets that own a canonical
    SEO page render as crawlable `<a href>` (they stay in the HTML while the panel is closed);
    a click still stays on the page through `wire:click.prevent`. Any active filter turns every
    choice back into a plain Livewire toggle, so filter combinations never become crawlable URLs.

    No per-bucket counts: the listing applies its energy-source and consumption-range filters
    in PHP after the query, so an honest count needs the whole filtered set re-resolved per
    bucket, not one grouped query. That is too expensive for a cached default page.
--}}
@php
    use App\Services\ContractCard\Enums\PricingBucket;

    $pricingBucketPills = [
        [
            'key' => PricingBucket::Spot->value,
            'label' => 'Pörssisähkö',
            'sub' => 'muuttuu joka tunti',
            'seoUrl' => '/sahkosopimus/porssisahko',
        ],
        [
            'key' => PricingBucket::MarketReset->value,
            'label' => 'Jaksoittain vaihtuva hinta',
            'sub' => 'muuttuu kuukausittain tai harvemmin',
            'seoUrl' => null,
        ],
        [
            'key' => PricingBucket::ConsumptionEffect->value,
            'label' => 'Kulutusvaikutus',
            'sub' => 'kiinteä hinta ± käyttöaika',
            'seoUrl' => '/sahkosopimus/kulutusvaikutus',
        ],
        [
            'key' => PricingBucket::Fixed->value,
            'label' => 'Kiinteä hinta',
            'sub' => 'hinta tiedossa etukäteen',
            'seoUrl' => '/sahkosopimus/kiintea-hinta',
        ],
    ];

    // Links only on an opted-in page in the clean, unfiltered state.
    $useSeoPillLinks = ($this->showSeoFilterLinks ?? false) && ! $this->hasActiveFilters();

    // Trigger state: "kaikki" for none or all four, the name for one, a count for more.
    // The label "Hinnoittelu" is a user decision (2026-10-23); "Hinnan tyyppi" did not read well.
    $selectedPills = array_values(array_filter($pricingBucketPills, fn ($pill) => $this->isPricingBucketSelected($pill['key'])));
    $pricingTypeState = match (true) {
        $selectedPills === [], count($selectedPills) === count($pricingBucketPills) => 'kaikki',
        count($selectedPills) === 1 => $selectedPills[0]['label'],
        default => count($selectedPills).' valittu',
    };
    $triggerGlyphs = $pricingTypeState === 'kaikki' ? $pricingBucketPills : $selectedPills;
@endphp
<div class="vk-disclosure vk-disclosure--pricing" x-data="{ pricingTypeOpen: false }">
    <button
        type="button"
        @click="pricingTypeOpen = !pricingTypeOpen"
        :aria-expanded="pricingTypeOpen ? 'true' : 'false'"
        aria-expanded="false"
        aria-controls="pricing-bucket-panel"
        class="vk-disclosure-trigger"
        data-pricing-type-trigger
    >
        <span class="vk-type-glyphs" aria-hidden="true">
            @foreach ($triggerGlyphs as $pill)
                <x-ink.glyph :bucket="$pill['key']" size="22" class="vk-type-glyph" />
            @endforeach
        </span>
        <span class="vk-disclosure-title">Hinnoittelu: {{ $pricingTypeState }}</span>
        <svg class="vk-chevron" viewBox="0 0 18 18" aria-hidden="true" focusable="false"><path d="M4 7l5 5 5-5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" /></svg>
    </button>

    <div id="pricing-bucket-panel" x-show="pricingTypeOpen" x-collapse x-cloak class="vk-disclosure-panel vk-disclosure-panel--buckets">
        <div class="vk-ctl-row vk-ctl-row--buckets">
            <p id="pricing-bucket-filter-label" class="vk-ctl-label">Näytä vain</p>

            <div
                role="group"
                aria-labelledby="pricing-bucket-filter-label"
                class="vk-buckets"
            >
                @foreach ($pricingBucketPills as $pill)
                    @php
                        $isSelected = $this->isPricingBucketSelected($pill['key']);
                        $isLink = $useSeoPillLinks && $pill['seoUrl'] !== null;

                        // Category colour token per bucket (design spec §2 "Map colours and pricing
                        // categories"); used only for the name and the checked mark.
                        $categoryColor = match ($pill['key']) {
                            PricingBucket::Spot->value => 'var(--color-cat-spot)',
                            PricingBucket::MarketReset->value => 'var(--color-cat-periodic)',
                            PricingBucket::ConsumptionEffect->value => 'var(--color-cat-usage)',
                            default => 'var(--color-cat-fixed)',
                        };

                        // One element, two tags: the pill body must not be duplicated between the
                        // crawlable and the interactive form, or the two will drift.
                        $tag = $isLink ? 'a' : 'button';
                    @endphp

                    <{{ $tag }}
                        data-pricing-bucket-pill="{{ $pill['key'] }}"
                        @if ($isLink)
                            href="{{ $pill['seoUrl'] }}"
                            wire:click.prevent="togglePricingBucket('{{ $pill['key'] }}')"
                        @else
                            type="button"
                            wire:click="togglePricingBucket('{{ $pill['key'] }}')"
                        @endif
                        aria-pressed="{{ $isSelected ? 'true' : 'false' }}"
                        class="vk-bucket"
                    >
                        @if ($isSelected)
                            <svg class="vk-mark" viewBox="0 0 22 22" aria-hidden="true" focusable="false"><rect x="1" y="1" width="20" height="20" rx="5" style="fill: {{ $categoryColor }}" /><path d="M6.5 11.5 L9.6 14.3 L15.6 7.8" fill="none" style="stroke: var(--color-snow-white)" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" /></svg>
                        @else
                            <svg class="vk-mark" viewBox="0 0 22 22" aria-hidden="true" focusable="false"><rect x="2" y="2" width="18" height="18" rx="5" style="fill: var(--color-snow-white); stroke: var(--color-field-edge)" stroke-width="2" /></svg>
                        @endif
                        <x-ink.glyph :bucket="$pill['key']" size="44" class="vk-bucket-glyph" />
                        <span class="min-w-0">
                            <span class="vk-bucket-name" style="color: {{ $categoryColor }}">{{ $pill['label'] }}</span>
                            <span class="vk-bucket-sub">{{ $pill['sub'] }}</span>
                        </span>
                    </{{ $tag }}>
                @endforeach
            </div>
        </div>
    </div>
</div>
