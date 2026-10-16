@props([
    'contract',
    'rank' => null,
    'featured' => false,
    'cheapest' => false,
    'consumption' => null,
    'showRank' => true,
    'showEmissions' => true,
    'showEnergyBadges' => true,
    'showSpotBadge' => true,
    'prices' => null,
    'percentiles' => [],
    'billMode' => false,
    'periodComparison' => null,
    'detailConsumption' => null,
])

{{--
    The ledger contract entry (redesign; design/voltikka-design-system.md §5 "Ledger contract
    entry", Paper board 2J3-0 results list). It replaced the "Kuitti" card: no card, border,
    shadow or hover lift. One row per contract: optional kicker, category line, main row
    (rank + logo | name | price parts | price | Katso), tag line, then the inked hairline the
    listing's `.vk-ledger` list contract expects. Styles: resources/css/redesign-ledger.css.

    `cheapest` is the variant of the #1 slot (featured-contract-card.blade.php renders this
    template with it): the "Halvin sähkösopimus" kicker, the fox-deep price inside the
    hand-drawn fox loop, and the fox-deep "Katso".

    ALL derivation lives in App\Services\ContractCard\ContractCardPresenter. This template
    reads a view model and renders it. Do not add price logic, category logic or Finnish
    copy here: the reason the presenter exists is that the two card templates each carried
    ~120 lines of the same PHP and silently drifted apart, leaving the featured card with no
    integrity warning, no market-reset figures and no estimate marker.

    The presenter never lazy-loads a relation. Listing pages batch-load company /
    electricitySource / latest priceComponents; a lazy load here would make every row of a
    20-item list an N+1 query.

    `featured`, `percentiles`, `showEmissions`, `showEnergyBadges` and `showSpotBadge` are
    retained as no-op props so existing callers do not break. `rank` is shown in the rank
    lane when `showRank` is true; rank 1 is fox-deep.

    - The percentile callouts were removed deliberately (inconsistent across pages, half
      unreachable, order-dependent); contracts:calculate-percentiles and its other consumers
      are unchanged.
    - Emissions and energy source are one tag stating the real figure.

    See tasks/contract-card-redesign/decisions.md and tasks/redesign-implementation/.
--}}
@php
    $card = app(\App\Services\ContractCard\ContractCardPresenter::class)->present(
        contract: $contract,
        prices: $prices ?? [],
        consumption: $consumption,
        detailConsumption: $detailConsumption,
        billMode: $billMode,
    );
    $displayRank = $showRank && $rank !== null ? (int) $rank : null;
@endphp

<article @class([
    'vk-entry',
    'vk-entry--cheapest' => $cheapest,
    'vk-entry--over-limit' => $card->exceedsConsumptionLimit,
    'vk-entry--bill' => $billMode,
])>
    <div class="vk-entry-body">
        @if ($cheapest)
            <p class="vk-entry-kicker">
                <x-ink.sparkle size="13" color="var(--color-fox)" />
                Halvin sähkösopimus
            </p>
        @endif

        <x-card.band variant="ledger" :band="$card->band" :bucket="$card->bucket" :estimate="$card->estimate" />

        <div class="vk-entry-main">
            {{-- Rank lane: rank above the logo. --}}
            <div class="vk-entry-rank">
                @if ($displayRank !== null)
                    <span @class(['vk-rank', 'vk-rank--first' => $displayRank === 1])><span class="sr-only">Sija </span>{{ $displayRank }}</span>
                @endif
                <x-company-logo variant="ledger" :company="$card->company" :name="$card->companyName" />
            </div>

            <div class="vk-entry-name">
                {{-- Full name, no clamp: promotion wording sits at the end of the name ("...
                     (Tarjous: ilmainen perusmaksu 3 kk)") and answers "why is this cheap?". --}}
                <h3 class="vk-entry-title">{{ $card->contractName }}</h3>
                <p class="vk-entry-meta">
                    @foreach ($card->metaParts as $part)
                        @if (! $loop->first)
                            <span aria-hidden="true">·</span>
                        @endif
                        <span>{{ $part }}</span>
                    @endforeach
                </p>
            </div>

            {{-- Price parts. Hidden in bill mode: there the anchor is the billing-period cost,
                 and annual component rates next to it invite a false comparison. The lane stays,
                 so the prices line up down the list. --}}
            <div class="vk-entry-parts">
                @unless ($billMode)
                    <x-card.receipt variant="ledger" :lines="$card->receiptLines" />
                @endunless
            </div>

            <div class="vk-entry-price">
                @if ($billMode && $periodComparison)
                    {{-- Bill ("Maksatko liikaa") mode: same-period €/kk plus savings against the
                         visitor's own bill. Period figures are facts for that billing period
                         (spot uses the period's realised hourly prices); framed
                         "laskutusjaksollasi" so a winter bill's higher €/kk is not read as a
                         typical going-forward monthly cost. --}}
                    @php
                        $bcMonthly = $periodComparison['period_monthly_cost'];
                        $bcSaving = $periodComparison['period_monthly_saving'];
                        $bcIsSpot = $periodComparison['is_spot'] ?? false;
                        [$bcInt, $bcDec] = explode(',', number_format($bcMonthly, 1, ',', ' '), 2);
                        $bcPeriodTip = 'Arvioitu kuukausihinta laskutusjaksosi kulutuksella ja toteutuneilla hinnoilla, suhteutettuna 30 vuorokauteen.'
                            .($bcIsSpot ? ' Pörssisopimuksen hinta perustuu jakson toteutuneisiin tuntihintoihin (oletuksena tasainen tuntikulutus).' : '');
                    @endphp
                    <p class="vk-price">
                        <span class="vk-price-int">{{ $bcInt }}</span><span class="vk-price-dec">,{{ $bcDec }}</span><span class="vk-price-unit">€/kk</span>
                    </p>
                    <p>
                        <x-info-tip :text="$bcPeriodTip">{{ $bcIsSpot ? 'laskutusjaksollasi · arvio' : 'laskutusjaksollasi' }}</x-info-tip>
                    </p>
                    @if ($bcSaving > 0.005)
                        <p class="vk-price-saving">Säästö {{ number_format($bcSaving, 1, ',', ' ') }} €/kk</p>
                    @elseif ($bcSaving < -0.005)
                        <p>{{ number_format(abs($bcSaving), 1, ',', ' ') }} €/kk kalliimpi</p>
                    @else
                        <p>Sama hinta</p>
                    @endif
                @elseif ($card->monthlyCost !== null)
                    @php
                        [$monthlyInt, $monthlyDec] = explode(',', number_format($card->monthlyCost, 1, ',', ' '), 2);
                    @endphp
                    <p class="vk-price">
                        <span class="vk-price-int">{{ $monthlyInt }}</span><span class="vk-price-dec">,{{ $monthlyDec }}</span><span class="vk-price-unit">€/kk</span>
                        @if ($cheapest)
                            {{-- Hand-drawn fox loop around the cheapest price (Paper path). --}}
                            <svg class="vk-price-loop" viewBox="0 0 176 62" preserveAspectRatio="none" aria-hidden="true" focusable="false">
                                <path d="M152 10 C 124 0, 44 0, 17 12 C 1 22, 6 49, 41 56 C 84 64, 148 59, 165 45 C 178 33, 171 15, 139 7" fill="none" style="stroke: var(--color-fox)" stroke-width="2.4" stroke-linecap="round" vector-effect="non-scaling-stroke" />
                            </svg>
                        @endif
                    </p>
                    <p>{{ number_format($card->totalCost, 0, ',', ' ') }} €/v</p>
                    @if ($card->discountSavings !== null)
                        <p class="vk-price-saving">
                            <x-info-tip :text="$card->discountExplanation">{{ $card->benefitIsEstimate ? 'Arvioitu säästö' : 'Säästö' }}</x-info-tip>
                            {{ number_format($card->discountSavings, 0, ',', ' ') }} € / {{ $card->discountPeriodLabel }}
                        </p>
                        @if ($card->baseTotalCost !== null)
                            <p class="vk-price-normal">{{ $card->normalPriceIsEstimated ? 'arvio ilman tarjousta' : 'ilman tarjousta' }} {{ number_format($card->baseTotalCost, 0, ',', ' ') }} € / {{ $card->discountPeriodLabel }}</p>
                        @endif
                    @endif
                @endif
            </div>

            {{-- The one link of the entry. The visible "Katso →" plus the sr-only contract name
                 give it a unique accessible name in a list of entries. --}}
            <div class="vk-entry-action">
                <a href="{{ $card->detailUrl }}" class="vk-katso vk-focus">
                    <span>Katso<span class="sr-only"> sopimus {{ $card->contractName }}</span> <span aria-hidden="true">→</span></span>
                    <x-ink.wave width="86" stroke="2.6" :color="$cheapest ? 'var(--color-fox)' : 'var(--color-ink)'" />
                </a>
            </div>
        </div>

        <x-card.footer variant="ledger" :warnings="$card->warnings" :facts="$card->facts" />
    </div>

    <x-ink.hairline class="vk-ledger-sep" />
</article>
