@props([
    'contract',
    'consumption' => null,
    'prices' => null,
    'detailConsumption' => null,
])

{{--
    The #1 slot on the homepage, /sahkosopimus, every SEO listing page and
    /halvin-sahkosopimus.

    It shares App\Services\ContractCard\ContractCardPresenter with contract-card.blade.php.
    Before that, this file held its own copy of the derivation and had drifted: no
    price-increase warning, no market-reset figures, and no estimate marker beyond a
    spot-only caption. The most-seen card on the site was the least honest one. Any card
    fact belongs in the presenter so this cannot happen again.

    The featured treatment is the coral border, the rank badge and the coral price, per
    DESIGN.md's featured variant. It is deliberately NOT the old full-bleed coral gradient:
    the type band is a tinted surface that states the pricing category, and a coral gradient
    underneath it would make the sky/violet category tints unreadable.
--}}
@php
    $card = app(\App\Services\ContractCard\ContractCardPresenter::class)->present(
        contract: $contract,
        prices: $prices ?? [],
        consumption: $consumption,
        detailConsumption: $detailConsumption,
    );
@endphp

<article class="relative w-full overflow-hidden rounded-2xl border-2 border-coral-200 bg-white shadow-lg shadow-coral-500/10 transition-all duration-200 hover:shadow-card-hover motion-reduce:transition-none">
    <div class="flex items-center gap-2 bg-gradient-to-r from-coral-500 to-coral-600 px-5 py-2.5 text-sm font-bold text-white sm:px-6">
        <svg class="h-4 w-4 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20" aria-hidden="true">
            <path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"/>
        </svg>
        Halvin sähkösopimus
    </div>

    <x-card.band :band="$card->band" :estimate="$card->estimate" />

    <div class="flex flex-wrap items-center gap-x-8 gap-y-6 px-5 py-6 sm:px-6 sm:py-7">
        <div class="flex min-w-[15rem] flex-[1.1] items-center gap-4">
            <x-company-logo
                :company="$card->company"
                :name="$card->companyName"
                class="h-14 w-[4.5rem] rounded-xl bg-slate-100 text-sm font-bold text-slate-500"
                img-class="bg-white rounded-xl"
            />

            <div class="min-w-0 flex-1">
                <h3 class="line-clamp-2 text-xl font-extrabold leading-tight text-slate-900 sm:line-clamp-1">{{ $card->contractName }}</h3>
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

        <x-card.receipt :lines="$card->receiptLines" />

        @if ($card->monthlyCost !== null)
            @php
                [$monthlyInt, $monthlyDec] = explode(',', number_format($card->monthlyCost, 1, ',', ' '), 2);
            @endphp
            {{-- Same responsive shape as the standard card: a full-width total row under a
                 dashed rule on a phone, a right-aligned column beside the CTA from sm up. See
                 contract-card.blade.php for why. --}}
            <div class="flex w-full items-center gap-4 border-t border-dashed border-slate-300 pt-4 sm:ml-auto sm:w-auto sm:justify-end sm:border-l sm:border-t-0 sm:pl-6 sm:pt-0">
                {{-- The decimal is de-emphasised by SIZE, not by a paler coral. coral-400 on
                     white is 2.16:1 and fails even the 3:1 large-text bar; coral-600 passes at
                     3.99:1 and the size step already carries the hierarchy. --}}
                <div class="flex w-full flex-wrap items-baseline justify-between gap-x-4 tabular-nums sm:block sm:w-auto sm:text-right">
                    <div class="whitespace-nowrap font-extrabold leading-none text-coral-600">
                        <span class="text-[2.4rem]">{{ $monthlyInt }}</span><span class="text-[1.5rem]">,{{ $monthlyDec }}</span><span class="ml-1 text-base font-semibold text-slate-500">€/kk</span>
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

                <a
                    href="{{ $card->detailUrl }}"
                    class="hidden items-center justify-center rounded-xl bg-gradient-to-r from-coral-500 to-coral-600 px-6 py-3 text-base font-bold text-white shadow-coral transition-all hover:from-coral-400 hover:to-coral-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-coral-500 sm:inline-flex"
                >
                    Katso<span class="sr-only"> sopimus {{ $card->contractName }}</span>
                </a>
            </div>
        @endif

        <a
            href="{{ $card->detailUrl }}"
            class="flex w-full items-center justify-center gap-2 rounded-xl bg-gradient-to-r from-coral-500 to-coral-600 px-5 py-3 text-base font-bold text-white shadow-coral transition-all focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-coral-500 sm:hidden"
        >
            Katso<span class="sr-only"> sopimus {{ $card->contractName }}</span>
            <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 8l4 4m0 0l-4 4m4-4H3"></path>
            </svg>
        </a>
    </div>

    <x-card.footer :warnings="$card->warnings" :facts="$card->facts" />
</article>
