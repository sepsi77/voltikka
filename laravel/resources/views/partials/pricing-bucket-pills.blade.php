{{--
    The visible pricing-type filter: one segmented rail of four toggles above the contract list.

    Why it is here and not in `partials/contract-filters.blade.php`: the comparison list is
    spot-heavy by ranking, and a visitor who wants price certainty found no way out because
    every filter sat inside the collapsed "Rajaa hakua" accordion. This row is the one filter
    that is always visible, so it must stay OUTSIDE that accordion, and a pill selection must
    not open it (see `ContractsList::hasActiveAccordionFilters()`).

    Shape: a single `rounded-xl` rail with `slate-200` hairlines between equal cells, not four
    separate bordered cards. Four cards read as a second card grid stacked above the contract
    cards, which is exactly what the page does not need; one rail reads as a control. The
    hairlines are `gap-px` over a `slate-200` background, so the same markup gives 2x2 below
    `sm` and 1x4 above it without per-cell border rules.

    The buckets come from `App\Services\ContractCard\Enums\PricingBucket` and the query from
    `PricingCategoryResolver::scopeBucket()`, so the pill, the card band it lists and the
    `<x-card.legend />` swatches cannot drift. A selected cell wears its own category tint
    (`PricingCategory::tint()`, the same sky / violet / slate axis DESIGN.md documents for the
    band): tint-100 fill, a 1px inset tint-400 ring, tint-900 label. The fill alone sits about
    1.05:1 from white, so the ring is what makes "on" readable across the row; it replaces the
    old per-card border and stays inside the rail's own geometry. Unselected cells carry no
    tint at all, so colour always means "this category is active".

    The leading glyph is the category icon at rest and swaps to a check when selected, in the
    same 16px slot. Same trick as the card band leading its copy with an icon, and it gives the
    selected state a second, saturated signal without adding width or shifting the label.

    Copy rule: every Finnish string for this row lives in `$pricingBucketPills` below, never
    inline further down and never in a component. "Jaksoittain vaihtuva hinta" + "kvartaali- ja
    kuukausisähkö" is a locked user decision; do not reword it. Sub-lines are shortened
    restatements of `ContractCardCopy::band()` so pill and card say the same thing, and they
    sit at 14px `slate-500`, the DESIGN.md floor for secondary copy, not at 12px.

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
{{-- Keep the rail close to the availability row so the primary comparison choices
     (consumption, price behavior, availability) read as one sequence before the
     secondary tools (bill comparison, filters). --}}
<div class="mb-5">
    <p id="pricing-bucket-filter-label" class="mb-2 text-sm font-semibold text-slate-600">
        Miten hinta käyttäytyy?
    </p>

    <div
        role="group"
        aria-labelledby="pricing-bucket-filter-label"
        class="grid grid-cols-2 gap-px overflow-hidden rounded-xl border border-slate-200 bg-slate-200 sm:grid-cols-4"
    >
        @foreach ($pricingBucketPills as $pill)
            @php
                $isSelected = $this->isPricingBucketSelected($pill['key']);
                $isLink = $useSeoPillLinks && $pill['seoUrl'] !== null;

                // The tint comes from the bucket's own card category, so the pill, the legend
                // and the card band can never name three different colours for one contract.
                $tint = PricingBucket::from($pill['key'])->category()->tint();

                $cellClass = $isSelected
                    ? match ($tint) {
                        'market' => 'bg-sky-100 text-sky-900 ring-1 ring-inset ring-sky-400',
                        'usage' => 'bg-violet-100 text-violet-900 ring-1 ring-inset ring-violet-400',
                        default => 'bg-slate-100 text-slate-900 ring-1 ring-inset ring-slate-400',
                    }
                    : 'bg-white text-slate-800 hover:bg-slate-50';

                $glyphClass = $isSelected
                    ? match ($tint) {
                        'market' => 'text-sky-600',
                        'usage' => 'text-violet-600',
                        default => 'text-slate-600',
                    }
                    : 'text-slate-400';

                $subClass = $isSelected
                    ? match ($tint) {
                        'market' => 'text-sky-700',
                        'usage' => 'text-violet-700',
                        default => 'text-slate-600',
                    }
                    : 'text-slate-500';

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
                class="block px-3 py-3 text-left transition-colors sm:px-4 focus:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-coral-500 {{ $cellClass }}"
            >
                <span class="flex items-start gap-2 sm:gap-2.5">
                    <span class="mt-0.5 flex-shrink-0 {{ $glyphClass }}" aria-hidden="true">
                        @if ($isSelected)
                            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="M5 13l4 4L19 7"/></svg>
                        @else
                            @switch($pill['icon'])
                                @case('wave')
                                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M2 12c2.5 0 2.5-4 5-4s2.5 4 5 4 2.5-4 5-4 2.5 4 5 4"/></svg>
                                    @break
                                @case('period')
                                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M3 10h18M8 3v4M16 3v4"/></svg>
                                    @break
                                @case('pulse')
                                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12h4l3-8 4 16 3-8h4"/></svg>
                                    @break
                                @default
                                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><rect x="5" y="11" width="14" height="9" rx="2"/><path d="M8 11V7a4 4 0 018 0v4"/></svg>
                            @endswitch
                        @endif
                    </span>
                    <span class="min-w-0">
                        <span class="block text-[15px] font-bold leading-tight">{{ $pill['label'] }}</span>
                        <span class="mt-1 block text-sm leading-snug {{ $subClass }}">{{ $pill['sub'] }}</span>
                    </span>
                </span>
            </{{ $tag }}>
        @endforeach
    </div>
</div>
