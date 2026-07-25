@props([
    'contract',
    'rank' => null,
    'featured' => false,
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
    The contract card ("Kuitti" anatomy): type band, then identity + itemised receipt lines
    + price stub, then the footer strip.

    ALL derivation lives in App\Services\ContractCard\ContractCardPresenter. This template
    reads a view model and renders it. Do not add price logic, category logic or Finnish
    copy here: the reason the presenter exists is that this file and
    featured-contract-card.blade.php each carried ~120 lines of the same PHP and silently
    drifted apart, leaving the featured card with no integrity warning, no market-reset
    figures and no estimate marker.

    The presenter never lazy-loads a relation. Listing pages batch-load company /
    electricitySource / latest priceComponents; a lazy load here would make every row of a
    20-item list an N+1 query.

    `rank`, `showRank`, `percentiles`, `showEmissions`, `showEnergyBadges` and `showSpotBadge`
    are retained as no-op props so existing callers do not break.

    - Rank badges were removed. They carried no information the sort order does not already
      give, and because the badge rendered only for ranks 1-3 inside the identity row, it
      pushed the logo ~37px right on the first three cards and back left on the rest, so
      nothing lined up down the list.
    - The percentile callouts were removed deliberately (inconsistent across pages, half
      unreachable, order-dependent); contracts:calculate-percentiles and its other consumers
      are unchanged.
    - Emissions and energy source are now one footer fact tag stating the real figure.

    See tasks/contract-card-redesign/decisions.md.
--}}
@php
    $card = app(\App\Services\ContractCard\ContractCardPresenter::class)->present(
        contract: $contract,
        prices: $prices ?? [],
        consumption: $consumption,
        detailConsumption: $detailConsumption,
        billMode: $billMode,
    );
@endphp

<article class="group relative w-full overflow-hidden rounded-2xl border bg-white transition-all duration-200 hover:-translate-y-0.5 hover:shadow-card-hover motion-reduce:transition-none motion-reduce:hover:translate-y-0 {{ $card->exceedsConsumptionLimit ? 'opacity-75' : '' }} {{ $featured ? 'border-coral-200' : 'border-slate-200' }}">
    <x-card.band :band="$card->band" :estimate="$card->estimate" />

    <div class="flex flex-wrap items-center gap-x-8 gap-y-6 px-5 py-6 sm:px-6 sm:py-7">
        {{-- Identity --}}
        <div class="flex min-w-[15rem] flex-[1.1] items-center gap-4">
            <x-company-logo
                :company="$card->company"
                :name="$card->companyName"
                class="h-12 w-16 rounded-xl bg-slate-100 text-xs font-bold text-slate-500"
                img-class="bg-white rounded-xl"
            />

            <div class="min-w-0 flex-1">
                {{-- Two lines on a phone, one on wider screens. Promotion wording lives at the
                     end of the name ("... (Tarjous: ilmainen perusmaksu 3 kk)"), so a single
                     truncated line on mobile cut off the part that answers "why is this
                     cheap?". --}}
                <h3 class="line-clamp-2 text-lg font-bold leading-tight text-slate-900 sm:line-clamp-1">{{ $card->contractName }}</h3>
                <p class="mt-0.5 flex flex-wrap gap-x-2 text-sm leading-snug text-slate-500">
                    @foreach ($card->metaParts as $part)
                        @if (! $loop->first)
                            <span class="text-slate-300" aria-hidden="true">·</span>
                        @endif
                        <span class="whitespace-nowrap">{{ $part }}</span>
                    @endforeach
                </p>
            </div>
        </div>

        {{-- Receipt lines. Hidden in bill mode: there the anchor is the billing-period cost,
             and annual component rates next to it invite a false comparison. --}}
        @unless ($billMode)
            <x-card.receipt :lines="$card->receiptLines" />
        @endunless

        {{-- Price stub.

             On a phone this is a full-width total row under a dashed rule: the €/kk figure on
             the left, its qualifiers baseline-aligned on the right. It used to keep the desktop
             shape (`justify-between` around a right-aligned block, with the inline CTA hidden
             below sm), which left one child in the row, so the block shrank to its content and
             sat as a narrow ragged island in the left half of the card.

             From sm up the rule turns vertical and the stub returns to a right-aligned column
             beside the CTA. --}}
        <div class="flex w-full items-center gap-4 border-t border-dashed border-slate-300 pt-4 sm:ml-auto sm:w-auto sm:justify-end sm:border-l sm:border-t-0 sm:pl-6 sm:pt-0">
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
                <div class="flex w-full flex-wrap items-baseline justify-between gap-x-4 tabular-nums sm:block sm:w-auto sm:text-right">
                    <div class="whitespace-nowrap font-extrabold leading-none text-slate-900">
                        <span class="text-[2.1rem]">{{ $bcInt }}</span><span class="text-[1.35rem] text-slate-500">,{{ $bcDec }}</span><span class="ml-1 text-base font-semibold text-slate-500">€/kk</span>
                    </div>
                    <div class="text-right sm:mt-1.5">
                        <p class="text-sm text-slate-500">
                            <x-info-tip :text="$bcPeriodTip" trigger-class="text-slate-500">{{ $bcIsSpot ? 'laskutusjaksollasi · arvio' : 'laskutusjaksollasi' }}</x-info-tip>
                        </p>
                        @if ($bcSaving > 0.005)
                            <p class="mt-1 text-sm font-semibold text-slate-700">Säästö {{ number_format($bcSaving, 1, ',', ' ') }} €/kk</p>
                        @elseif ($bcSaving < -0.005)
                            <p class="mt-1 text-sm text-slate-500">{{ number_format(abs($bcSaving), 1, ',', ' ') }} €/kk kalliimpi</p>
                        @else
                            <p class="mt-1 text-sm text-slate-500">Sama hinta</p>
                        @endif
                    </div>
                </div>
            @elseif ($card->monthlyCost !== null)
                @php
                    [$monthlyInt, $monthlyDec] = explode(',', number_format($card->monthlyCost, 1, ',', ' '), 2);
                @endphp
                {{-- Ink stays slate-900 whatever the rank. Coral belongs to the one featured
                     card; three more coral prices and CTAs under it pushed the page past
                     DESIGN.md's ~10% coral rule and made the same action carry two different
                     weights purely by position. --}}
                <div class="flex w-full flex-wrap items-baseline justify-between gap-x-4 tabular-nums sm:block sm:w-auto sm:text-right">
                    <div class="whitespace-nowrap font-extrabold leading-none text-slate-900">
                        <span class="text-[2.1rem]">{{ $monthlyInt }}</span><span class="text-[1.35rem] text-slate-500">,{{ $monthlyDec }}</span><span class="ml-1 text-base font-semibold text-slate-500">€/kk</span>
                    </div>
                    <div class="text-right sm:mt-1.5">
                        <p class="text-sm text-slate-500">{{ number_format($card->totalCost, 0, ',', ' ') }} €/v</p>
                        @if ($card->discountSavings !== null)
                            <p class="mt-1 text-sm font-semibold text-emerald-700">
                                <x-info-tip text="Säästö = tarjouksen tuoma alennus verrattuna saman sopimuksen normaalihintaan ensimmäisen vuoden aikana." trigger-class="text-emerald-700">Säästö</x-info-tip>
                                {{ number_format($card->discountSavings, 0, ',', ' ') }} €/v
                            </p>
                            @if ($card->baseTotalCost !== null)
                                <p class="mt-0.5 text-sm text-slate-500">ilman tarjousta {{ number_format($card->baseTotalCost, 0, ',', ' ') }} €/v</p>
                            @endif
                        @endif
                    </div>
                </div>
            @endif

            <a
                href="{{ $card->detailUrl }}"
                class="hidden items-center justify-center rounded-xl border-2 border-slate-200 px-5 py-3 text-base font-bold text-slate-600 transition-colors hover:border-coral-400 hover:text-coral-600 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-coral-500 sm:inline-flex"
            >
                Katso<span class="sr-only"> sopimus {{ $card->contractName }}</span>
            </a>
        </div>

        {{-- Mobile CTA: full width, because the stub's inline button is hidden below sm. --}}
        <a
            href="{{ $card->detailUrl }}"
            class="flex w-full items-center justify-center gap-2 rounded-xl border-2 border-slate-200 px-5 py-3 text-base font-bold text-slate-600 transition-colors focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-coral-500 sm:hidden"
        >
            Katso<span class="sr-only"> sopimus {{ $card->contractName }}</span>
            <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 8l4 4m0 0l-4 4m4-4H3"></path>
            </svg>
        </a>
    </div>

    <x-card.footer :warnings="$card->warnings" :facts="$card->facts" />
</article>
