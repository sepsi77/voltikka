{{--
    The visible pricing-type filter: one control row of four checkboxes above the contract list.

    Why it is here and not in `partials/contract-filters.blade.php`: the comparison list is
    spot-heavy by ranking, and a visitor who wants price certainty found no way out because
    every filter sat inside the collapsed "Rajaa hakua" accordion. This row is the one filter
    that is always visible, so it must stay OUTSIDE that accordion, and a pill selection must
    not open it (see `ContractsList::hasActiveAccordionFilters()`).

    Shape (redesign, Paper board 2J3-0 "Controls · compact", second row): one control row
    under the label "Miten hinta käyttäytyy?". Each choice is a drawn checkbox, the 44px
    inked price-shape glyph of the category (<x-ink.glyph>), the name in the category colour
    and a one-line meaning in ink-body. A selected choice fills its checkbox with the
    category colour and draws a white check. The glyph shape and the name carry the meaning,
    so the row works without colour vision. No cells, tiles or tints: structure is the
    hairline of the control block (design spec §1 "no pills or badges").

    Copy rule: every Finnish string for this row lives in `$pricingBucketPills` below, never
    inline further down and never in a component. "Jaksoittain vaihtuva hinta" + "kvartaali- ja
    kuukausisähkö" is a locked user decision; do not reword it. Sub-lines are shortened
    restatements of `ContractCardCopy::band()` so pill and card say the same thing. They sit
    at 13px ink-body (Paper board value; ink-body passes 4.5:1 at that size).

    Dual behaviour (root AGENTS.md, "Filter Links (Dual Behavior)"): where the page opts in
    with `$showSeoFilterLinks` and no filter is active, the three buckets that own a canonical
    SEO page render as crawlable `<a href>`; a click still stays on the page through
    `wire:click.prevent`. Any active filter turns every pill back into a plain Livewire
    toggle, so filter combinations never become crawlable URLs. Jaksoittain vaihtuva hinta has no
    canonical page and is a toggle in every state.

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
            'icon' => 'wave',
            'seoUrl' => '/sahkosopimus/porssisahko',
        ],
        [
            'key' => PricingBucket::MarketReset->value,
            'label' => 'Jaksoittain vaihtuva hinta',
            'sub' => 'muuttuu kuukausittain tai harvemmin',
            'icon' => 'period',
            'seoUrl' => null,
        ],
        [
            'key' => PricingBucket::ConsumptionEffect->value,
            'label' => 'Kulutusvaikutus',
            'sub' => 'kiinteä hinta ± käyttöaika',
            'icon' => 'pulse',
            'seoUrl' => '/sahkosopimus/kulutusvaikutus',
        ],
        [
            'key' => PricingBucket::Fixed->value,
            'label' => 'Kiinteä hinta',
            'sub' => 'energian hinta ei muutu',
            'icon' => 'lock',
            'seoUrl' => '/sahkosopimus/kiintea-hinta',
        ],
    ];

    // Links only on an opted-in page in the clean, unfiltered state.
    $useSeoPillLinks = ($this->showSeoFilterLinks ?? false) && ! $this->hasActiveFilters();
@endphp
{{-- The second row of the controls block; the primary sequence is consumption, price
     behaviour, then the disclosure row (availability, bill, filters). --}}
<div class="vk-ctl-row vk-ctl-row--buckets">
    <p id="pricing-bucket-filter-label" class="vk-ctl-label">
        Miten hinta käyttäytyy?
    </p>

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
