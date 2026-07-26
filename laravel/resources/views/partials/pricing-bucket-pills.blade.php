{{--
    The visible pricing-type filter: four toggle pills above the contract list.

    Why it is here and not in `partials/contract-filters.blade.php`: the comparison list is
    spot-heavy by ranking, and a visitor who wants price certainty found no way out because
    every filter sat inside the collapsed "Rajaa hakua" accordion. This row is the one filter
    that is always visible, so it must stay OUTSIDE that accordion, and a pill selection must
    not open it (see `ContractsList::hasActiveAccordionFilters()`).

    The buckets come from `App\Services\ContractCard\Enums\PricingBucket` and the query from
    `PricingCategoryResolver::scopeBucket()`, so the pill, the card band it lists and the
    `<x-card.legend />` swatches cannot drift. A selected pill wears its own category tint
    (`PricingCategory::tint()`, the same sky / violet / slate axis DESIGN.md documents for the
    band); unselected pills stay quiet in the existing filter-button idiom, so the tint reads
    as "this category is on" rather than decoration.

    Copy rule: every Finnish string for this row lives in `$pricingBucketPills` below, never
    inline further down and never in a component. "Päivittyvä hinta" + "kvartaali- ja
    kuukausisähkö" is a locked user decision; do not reword it. Sub-lines are shortened
    restatements of `ContractCardCopy::band()` so pill and card say the same thing.

    Dual behaviour (root AGENTS.md, "Filter Links (Dual Behavior)"): where the page opts in
    with `$showSeoFilterLinks` and no filter is active, the three buckets that own a canonical
    SEO page render as crawlable `<a href>`; a click still stays on the page through
    `wire:click.prevent`. Any active filter turns every pill back into a plain Livewire
    toggle, so filter combinations never become crawlable URLs. Päivittyvä hinta has no
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
            'seoUrl' => '/sahkosopimus/porssisahko',
        ],
        [
            'key' => PricingBucket::MarketReset->value,
            'label' => 'Päivittyvä hinta',
            'sub' => 'kvartaali- ja kuukausisähkö',
            'seoUrl' => null,
        ],
        [
            'key' => PricingBucket::ConsumptionEffect->value,
            'label' => 'Kulutusvaikutus',
            'sub' => 'kiinteä hinta + käyttöajan vaikutus',
            'seoUrl' => '/sahkosopimus/kulutusvaikutus',
        ],
        [
            'key' => PricingBucket::Fixed->value,
            'label' => 'Kiinteä hinta',
            'sub' => 'energian hinta ei muutu',
            'seoUrl' => '/sahkosopimus/kiintea-hinta',
        ],
    ];

    // Links only on an opted-in page in the clean, unfiltered state.
    $useSeoPillLinks = ($this->showSeoFilterLinks ?? false) && ! $this->hasActiveFilters();
@endphp
<div class="mb-4">
    <p id="pricing-bucket-filter-label" class="mb-2 text-sm font-semibold text-slate-600">
        Miten hinta käyttäytyy?
    </p>

    <div role="group" aria-labelledby="pricing-bucket-filter-label" class="grid grid-cols-2 gap-2 sm:grid-cols-4">
        @foreach ($pricingBucketPills as $pill)
            @php
                $isSelected = $this->isPricingBucketSelected($pill['key']);
                $isLink = $useSeoPillLinks && $pill['seoUrl'] !== null;

                // The tint comes from the bucket's own card category, so the pill, the legend
                // and the card band can never name three different colours for one contract.
                $tintClass = $isSelected
                    ? match (PricingBucket::from($pill['key'])->category()->tint()) {
                        'market' => 'bg-sky-100 border-sky-400 text-sky-900',
                        'usage' => 'bg-violet-100 border-violet-400 text-violet-900',
                        default => 'bg-slate-100 border-slate-400 text-slate-900',
                    }
                    : 'bg-white border-slate-200 text-slate-700 hover:border-slate-300 hover:bg-slate-50';

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
                class="block w-full rounded-xl border px-3 py-2.5 text-left transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-coral-500 {{ $tintClass }}"
            >
                <span class="flex items-start justify-between gap-1.5">
                    <span class="text-sm font-bold leading-tight">{{ $pill['label'] }}</span>
                    @if ($isSelected)
                        <svg class="mt-0.5 h-4 w-4 flex-shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="M5 13l4 4L19 7"/>
                        </svg>
                    @endif
                </span>
                <span class="mt-1 block text-xs leading-snug {{ $isSelected ? 'opacity-75' : 'text-slate-500' }}">{{ $pill['sub'] }}</span>
            </{{ $tag }}>
        @endforeach
    </div>
</div>
