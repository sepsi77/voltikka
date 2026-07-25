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

@php
    // Deep-link the visitor's consumption so the detail page price reflects the
    // same consumption they were using on the listing. Only append ?kulutus=
    // when it differs from the detail page's 5000 kWh default, to keep the
    // common URL clean. The detail page canonical is param-free regardless, so
    // these variants stay non-indexable.
    $detailLinkConsumption = (int) ($detailConsumption ?? $consumption ?? 0);
    $detailUrl = ($detailLinkConsumption > 0 && $detailLinkConsumption !== 5000)
        ? route('contract.detail', ['contractId' => $contract->id, 'kulutus' => $detailLinkConsumption])
        : route('contract.detail', $contract->id);

    // Check if contract exceeds consumption limit
    $exceedsConsumptionLimit = $contract->exceeds_consumption_limit ?? false;

    // Get prices from props or extract from contract's priceComponents
    $priceData = $prices ?? [];
    if (empty($priceData) && $contract->relationLoaded('priceComponents')) {
        $generalPrice = $contract->priceComponents
            ->where('price_component_type', 'General')
            ->sortByDesc('price_date')
            ->first()?->price;
        $monthlyFee = $contract->priceComponents
            ->where('price_component_type', 'Monthly')
            ->sortByDesc('price_date')
            ->first()?->price ?? 0;
        $seasonalWinterPrice = $contract->priceComponents
            ->where('price_component_type', 'SeasonalWinterDay')
            ->sortByDesc('price_date')
            ->first()?->price;
        $seasonalOtherPrice = $contract->priceComponents
            ->where('price_component_type', 'SeasonalOther')
            ->sortByDesc('price_date')
            ->first()?->price;
        $dayTimePrice = $contract->priceComponents
            ->where('price_component_type', 'DayTime')
            ->sortByDesc('price_date')
            ->first()?->price;
        $nightTimePrice = $contract->priceComponents
            ->where('price_component_type', 'NightTime')
            ->sortByDesc('price_date')
            ->first()?->price;
    } else {
        $generalPrice = $priceData['General']['price'] ?? null;
        $monthlyFee = $priceData['Monthly']['price'] ?? 0;
        $seasonalWinterPrice = $priceData['SeasonalWinterDay']['price'] ?? null;
        $seasonalOtherPrice = $priceData['SeasonalOther']['price'] ?? null;
        $dayTimePrice = $priceData['DayTime']['price'] ?? null;
        $nightTimePrice = $priceData['NightTime']['price'] ?? null;
    }

    // Get calculated cost data if available
    $calculatedCost = $contract->calculated_cost ?? [];
    $totalCost = $calculatedCost['total_cost'] ?? null;
    $baseTotalCost = $calculatedCost['base_total_cost'] ?? null;
    $discountSavingsTotal = $calculatedCost['discount_savings_total'] ?? 0;
    $includesDiscounts = $calculatedCost['includes_discounts'] ?? false;
    $isSpotContract = $calculatedCost['is_spot_contract'] ?? false;
    $spotMargin = $calculatedCost['spot_price_margin'] ?? null;
    $spotPriceDayAvg = $calculatedCost['spot_price_day_avg'] ?? null;
    $spotPriceNightAvg = $calculatedCost['spot_price_night_avg'] ?? null;

    // Spot "· arvio" tooltip: show the actual assumed spot baseline + margin so the
    // estimate is not an opaque number (different spot products can share a baseline).
    $spotArvioTip = 'Pörssisähkön vuosiarvio perustuu liukuvaan 12 kk toteutuneeseen pörssikeskihintaan';
    if ($spotPriceDayAvg !== null && $spotPriceNightAvg !== null) {
        $spotArvioTip .= ' (päivä ' . number_format($spotPriceDayAvg, 1, ',', ' ') . ' c, yö ' . number_format($spotPriceNightAvg, 1, ',', ' ') . ' c, sis. alv)';
    }
    if ($spotMargin !== null && $spotMargin > 0) {
        $spotArvioTip .= ' lisättynä sopimuksen marginaalilla ' . number_format($spotMargin, 2, ',', ' ') . ' c/kWh';
    }
    $spotArvioTip .= '. Toteutunut hinta vaihtelee.';

    // Monthly average from annual estimate (Finnish comparison sites lead with €/kk)
    $monthlyCost = $totalCost !== null ? $totalCost / 12 : null;

    // Use only already-loaded relations in cards. Listing pages batch-load these
    // relations; falling back to lazy loads here turns every card into a
    // price_components/electricity_sources/company N+1 risk when another caller
    // passes a slim contract model.
    $company = $contract->relationLoaded('company') ? $contract->company : null;
    $source = $contract->relationLoaded('electricitySource') ? $contract->electricitySource : null;

    // Pricing-type display label: combines pricing_model + metering into the
    // single Finnish word users search for (Pörssisähkö, Aikasähkö, ...).
    $pricingTypeLabel = match (true) {
        $contract->pricing_model === 'Spot' => 'Pörssisähkö',
        $contract->pricing_model === 'Hybrid' => 'Hybridisähkö',
        $contract->metering === 'Time' => 'Aikasähkö',
        $contract->metering === 'Season' => 'Kausisähkö',
        $contract->pricing_model === 'FixedPrice' => 'Kiinteähintainen sähkö',
        default => null,
    };

    // Duration label: "Toistaiseksi voimassa" or "Määräaikainen N kk".
    $durationLabel = match (true) {
        $contract->contract_type === 'OpenEnded' => 'Toistaiseksi voimassa',
        $contract->contract_type === 'FixedTerm' => match ($contract->fixed_time_range) {
            'Below6' => 'Määräaikainen alle 6 kk',
            'Fixed6' => 'Määräaikainen 6 kk',
            'Between711' => 'Määräaikainen 7–11 kk',
            'Fixed12' => 'Määräaikainen 12 kk',
            'Between1323' => 'Määräaikainen 13–23 kk',
            'Fixed24' => 'Määräaikainen 24 kk',
            'Over24' => 'Määräaikainen yli 24 kk',
            default => 'Määräaikainen',
        },
        default => null,
    };

    // Headline energy unit price (one c/kWh figure per card, chosen by type).
    $headlineEnergyPrice = null;
    $headlineEnergyLabel = 'Energia';
    if ($isSpotContract && $spotMargin !== null) {
        $headlineEnergyPrice = $spotMargin;
        $headlineEnergyLabel = 'Marginaali';
    } elseif ($contract->metering === 'Time' && $dayTimePrice !== null && $dayTimePrice > 0) {
        $headlineEnergyPrice = $dayTimePrice;
        $headlineEnergyLabel = 'Päivähinta';
    } elseif ($contract->metering === 'Season' && $seasonalWinterPrice !== null && $seasonalWinterPrice > 0) {
        $headlineEnergyPrice = $seasonalWinterPrice;
        $headlineEnergyLabel = 'Talvihinta';
    } elseif ($generalPrice !== null && $generalPrice > 0) {
        $headlineEnergyPrice = $generalPrice;
    }

    // In bill (period) mode the annual `calculated_cost` (and its is-spot flag)
    // is absent, so a spot contract's low General rate would mislabel as
    // "Energia". Use the period row's spot flag to restore the "Marginaali" label.
    if ($billMode && ($periodComparison['is_spot'] ?? false) && $headlineEnergyPrice !== null) {
        $headlineEnergyLabel = 'Marginaali';
    }

    // Market-reset (monthly/quarterly/seasonal) products: the headline c/kWh above is the
    // KNOWN current-period price. The estimated 12-month equivalent is shown as a separate,
    // quieter figure so the estimate is never read as a contractual price. Copy is generated
    // from the typed reset_estimate payload, never from an interpretation summary.
    $resetEstimate = is_array($calculatedCost['reset_estimate'] ?? null) ? $calculatedCost['reset_estimate'] : null;
    $resetEquivalentLabel = $billMode ? null : \App\Services\CanonicalPricing\MarketReset\ResetEstimateCopy::cardEquivalent($resetEstimate);
    $resetEquivalentTip = $resetEquivalentLabel !== null ? \App\Services\CanonicalPricing\MarketReset\ResetEstimateCopy::cardTooltip($resetEstimate) : null;

    // Determine emissions color for left border
    $emissionFactor = $contract->emission_factor ?? 0;
    if ($featured) {
        $borderColorClass = 'border-l-coral-500';
        $borderWidth = 'border-l-[6px]';
    } elseif ($emissionFactor < 100) {
        $borderColorClass = 'border-l-emissions-low';
        $borderWidth = 'border-l-4';
    } elseif ($emissionFactor < 300) {
        $borderColorClass = 'border-l-emissions-medium';
        $borderWidth = 'border-l-4';
    } else {
        $borderColorClass = 'border-l-emissions-high';
        $borderWidth = 'border-l-4';
    }

    $isZeroEmission = $emissionFactor == 0;

    // Smart callout badges based on percentiles
    $callouts = [];
    if (!empty($percentiles)) {
        switch ($contract->pricing_model) {
            case 'Spot':
                if ($spotMargin !== null && isset($percentiles['spot_margin'])) {
                    if ($spotMargin <= $percentiles['spot_margin']['p15']) {
                        $callouts[] = ['text' => 'Edullinen marginaali', 'style' => 'text-emerald-700'];
                    } elseif ($spotMargin >= $percentiles['spot_margin']['p85']) {
                        $callouts[] = ['text' => 'Kallis marginaali', 'style' => 'text-amber-700'];
                    }
                }
                break;

            case 'FixedPrice':
                if ($generalPrice !== null && isset($percentiles['fixed_energy'])) {
                    if ($generalPrice <= $percentiles['fixed_energy']['p15']) {
                        $callouts[] = ['text' => 'Edullinen energianhinta', 'style' => 'text-emerald-700'];
                    } elseif ($generalPrice >= $percentiles['fixed_energy']['p85']) {
                        $callouts[] = ['text' => 'Kallis energianhinta', 'style' => 'text-amber-700'];
                    }
                }
                break;

            case 'Seasonal':
                if ($seasonalWinterPrice !== null && isset($percentiles['seasonal_winter'])) {
                    if ($seasonalWinterPrice <= $percentiles['seasonal_winter']['p15']) {
                        $callouts[] = ['text' => 'Edullinen talvihinta', 'style' => 'text-emerald-700'];
                    } elseif ($seasonalWinterPrice >= $percentiles['seasonal_winter']['p85']) {
                        $callouts[] = ['text' => 'Kallis talvihinta', 'style' => 'text-amber-700'];
                    }
                }
                break;

            case 'TimeOfUse':
                if ($dayTimePrice !== null && isset($percentiles['time_day'])) {
                    if ($dayTimePrice <= $percentiles['time_day']['p15']) {
                        $callouts[] = ['text' => 'Edullinen päivähinta', 'style' => 'text-emerald-700'];
                    } elseif ($dayTimePrice >= $percentiles['time_day']['p85']) {
                        $callouts[] = ['text' => 'Kallis päivähinta', 'style' => 'text-amber-700'];
                    }
                }
                break;
        }

        // Monthly fee is evaluated for all contract types
        if ($monthlyFee > 0 && isset($percentiles['monthly_fee'])) {
            if ($monthlyFee <= $percentiles['monthly_fee']['p15']) {
                $callouts[] = ['text' => 'Edullinen perusmaksu', 'style' => 'text-emerald-700'];
            } elseif ($monthlyFee >= $percentiles['monthly_fee']['p85']) {
                $callouts[] = ['text' => 'Kallis perusmaksu', 'style' => 'text-amber-700'];
            }
        }

        // Limit callouts based on card tier — featured cards show more detail
        $maxCallouts = $featured ? 2 : 1;
        if (count($callouts) > $maxCallouts) {
            $callouts = array_slice($callouts, 0, $maxCallouts);
        }
    }
@endphp

<div class="group relative w-full p-6 {{ $exceedsConsumptionLimit ? 'bg-slate-50 opacity-75' : 'bg-white' }} border border-slate-100 rounded-2xl {{ $borderWidth }} {{ $borderColorClass }} {{ $featured ? 'border-coral-200' : '' }} transition-all duration-200 hover:-translate-y-0.5 hover:shadow-card-hover">
    <div class="flex flex-col lg:flex-row lg:items-center gap-5 lg:gap-6">
        {{-- Rank Number (subtle, only for top 3) --}}
        @if ($showRank && $rank !== null && $rank <= 3)
            <div class="hidden lg:flex flex-shrink-0 w-8 h-8 items-center justify-center rounded-full {{ $rank === 1 ? 'bg-coral-100 text-coral-700' : 'bg-slate-100 text-slate-500' }}">
                <span class="text-xs font-bold">{{ $rank }}</span>
            </div>
        @endif

        {{-- Identity: logo + contract name + meta line --}}
        <div class="flex items-center gap-4 w-full lg:w-auto lg:flex-1 min-w-0">
            @if ($company?->getLogoUrl())
                <img
                    src="{{ $company->getLogoUrl() }}"
                    alt="{{ $company->name }}"
                    class="w-16 h-12 object-contain flex-shrink-0"
                    loading="lazy"
                    onerror="this.onerror=null; this.src='https://placehold.co/64x48/e2e8f0/64748b?text={{ substr($company?->name ?? 'N/A', 0, 2) }}'"
                >
            @else
                <div class="w-16 h-12 bg-slate-100 rounded-lg flex items-center justify-center flex-shrink-0">
                    <span class="text-slate-500 text-xs font-bold">{{ substr($company?->name ?? 'N/A', 0, 3) }}</span>
                </div>
            @endif
            <div class="flex flex-col min-w-0 flex-1">
                <h5 class="text-lg lg:text-lg font-bold text-slate-900 truncate tracking-tight">
                    {{ $contract->name }}
                </h5>
                <p class="mt-0.5 text-sm text-slate-600 leading-snug">
                    <span>{{ $company?->name ?? $contract->company_name }}</span>
                    @if ($pricingTypeLabel)
                        <span class="text-slate-300 px-1">·</span>
                        <span>{{ $pricingTypeLabel }}</span>
                    @endif
                    @if ($durationLabel)
                        <span class="text-slate-300 px-1">·</span>
                        <span>{{ $durationLabel }}</span>
                    @endif
                </p>
            </div>
        </div>

        {{-- Metrics + total cost + CTA --}}
        <div class="flex flex-wrap items-center gap-x-6 gap-y-4 lg:flex-nowrap lg:gap-7 w-full lg:w-auto lg:ml-auto lg:justify-end">
            {{-- Inline supporting metrics: Energia (c/kWh) and Perusmaksu (€). --}}
            {{-- Fixed-width columns on lg+ so labels/values line up vertically down the list. --}}
            {{-- Kept visually quiet so the big €/kk total stays the headline. --}}
            <div class="flex items-center gap-x-6 gap-y-3 lg:gap-7 order-2 lg:order-none">
                <div class="flex flex-col lg:w-[7rem]">
                    @if ($headlineEnergyPrice !== null)
                        <span class="text-[11px] font-semibold uppercase tracking-wider text-slate-400">
                            {{ $resetEquivalentLabel !== null ? $headlineEnergyLabel.' nyt' : $headlineEnergyLabel }}
                        </span>
                        <span class="text-sm lg:text-base font-semibold text-slate-700 tabular-nums whitespace-nowrap leading-tight mt-0.5">
                            {{ number_format($headlineEnergyPrice, 2, ',', ' ') }}<span class="ml-1 text-xs font-medium text-slate-500">c/kWh</span>
                        </span>
                        {{-- Market-reset products: estimated 12-month equivalent, kept visually --}}
                        {{-- subordinate to the known current-period price above it. --}}
                        @if ($resetEquivalentLabel !== null)
                            <span class="text-[11px] font-medium text-slate-500 tabular-nums whitespace-nowrap leading-tight mt-0.5">
                                @if ($resetEquivalentTip)
                                    <x-info-tip :text="$resetEquivalentTip" trigger-class="text-slate-500">{{ $resetEquivalentLabel }}</x-info-tip>
                                @else
                                    {{ $resetEquivalentLabel }}
                                @endif
                            </span>
                        @endif
                    @endif
                </div>

                <div class="flex flex-col lg:w-[6rem]">
                    <span class="text-[11px] font-semibold uppercase tracking-wider text-slate-400">
                        Perusmaksu
                    </span>
                    <span class="text-sm lg:text-base font-semibold text-slate-700 tabular-nums whitespace-nowrap leading-tight mt-0.5">
                        {{ number_format($monthlyFee, 2, ',', ' ') }}<span class="ml-1 text-xs font-medium text-slate-500">€</span>
                    </span>
                </div>
            </div>

            {{-- Bill ("Maksatko liikaa") mode: same-period €/kk + savings vs the user's bill. --}}
            {{-- Period figures are facts for the billing period (spot uses the period's --}}
            {{-- realized hourly prices); framed "laskutusjaksollasi" so a winter bill's --}}
            {{-- higher €/kk is not read as a typical going-forward monthly cost. --}}
            @if ($billMode && $periodComparison)
                @php
                    $bcMonthly = $periodComparison['period_monthly_cost'];
                    $bcSaving = $periodComparison['period_monthly_saving'];
                    $bcIsSpot = $periodComparison['is_spot'] ?? false;
                    $bcMonthlyDisplay = number_format($bcMonthly, 1, ',', ' ');
                    [$bcInt, $bcDec] = explode(',', $bcMonthlyDisplay, 2);
                    // €/kk is the only euro figure on the card (matches the "Sinun
                    // sopimuksesi" anchor + savings). It is the billing-period cost
                    // normalized to 30 days; the tooltip explains that basis without
                    // a second, slightly-different euro number.
                    $bcPeriodTip = 'Arvioitu kuukausihinta laskutusjaksosi kulutuksella ja toteutuneilla hinnoilla, suhteutettuna 30 vuorokauteen.'
                        .($bcIsSpot ? ' Pörssisopimuksen hinta perustuu jakson toteutuneisiin tuntihintoihin (oletuksena tasainen tuntikulutus).' : '');
                    $bcCaption = $bcIsSpot ? 'laskutusjaksollasi · arvio' : 'laskutusjaksollasi';
                @endphp
                <div class="order-1 lg:order-none w-full lg:w-[220px] pb-4 lg:pb-0 border-b lg:border-b-0 border-slate-100 lg:text-right">
                    <p class="lg:hidden text-[11px] font-bold uppercase tracking-[0.18em] text-slate-500 mb-1">
                        Laskutusjaksollasi {{ $bcIsSpot ? '· arvio' : '' }}
                    </p>
                    <div class="inline-flex items-baseline gap-1.5 whitespace-nowrap font-extrabold tabular-nums leading-none text-slate-900">
                        <span>
                            <span class="text-4xl lg:text-[2.6rem]">{{ $bcInt }}</span><span class="text-2xl lg:text-[1.65rem] text-slate-400">,{{ $bcDec }}</span>
                        </span>
                        <span class="text-lg lg:text-base font-medium text-slate-400">€/kk</span>
                    </div>
                    <p class="hidden lg:block text-xs text-slate-500 mt-1.5">
                        <x-info-tip :text="$bcPeriodTip" trigger-class="text-slate-400">{{ $bcCaption }}</x-info-tip>
                    </p>
                    @if ($bcSaving > 0.005)
                        <p class="text-sm font-semibold text-slate-700 tabular-nums mt-1">
                            Säästö {{ number_format($bcSaving, 1, ',', ' ') }} €/kk
                        </p>
                    @elseif ($bcSaving < -0.005)
                        <p class="text-sm text-slate-500 tabular-nums mt-1">
                            {{ number_format(abs($bcSaving), 1, ',', ' ') }} €/kk kalliimpi
                        </p>
                    @else
                        <p class="text-sm text-slate-500 tabular-nums mt-1">Sama hinta</p>
                    @endif
                </div>

            {{-- Total cost: €/kk primary, €/v secondary. --}}
            {{-- Fixed lg width so spot rows (extra "· arvio" tail) line up with non-spot rows. --}}
            @elseif ($monthlyCost !== null)
                <div class="order-1 lg:order-none w-full lg:w-[220px] pb-4 lg:pb-0 border-b lg:border-b-0 border-slate-100 lg:text-right">
                    <p class="lg:hidden text-[11px] font-bold uppercase tracking-[0.18em] text-coral-600 mb-1">
                        <x-info-tip text="Kuukausihinta = arvioitu 12 kk keskikustannus jaettuna 12:lla, sisältää tarjoukset. Hinnat sis. alv 25,5 %, siirtomaksu ei sisälly.">Kuukausihinta</x-info-tip>
                        @if ($isSpotContract)
                            <x-info-tip :text="$spotArvioTip" trigger-class="font-medium normal-case text-slate-400">· arvio</x-info-tip>
                        @endif
                    </p>
                    @php
                        $monthlyDisplay = number_format($monthlyCost, 1, ',', ' ');
                        [$monthlyInt, $monthlyDec] = explode(',', $monthlyDisplay, 2);
                    @endphp
                    <div class="inline-flex items-baseline gap-1.5 whitespace-nowrap font-extrabold tabular-nums leading-none {{ $featured ? 'text-coral-600' : 'text-slate-900' }}">
                        <span>
                            <span class="text-4xl lg:text-[2.6rem]">{{ $monthlyInt }}</span><span class="text-2xl lg:text-[1.65rem] {{ $featured ? 'text-coral-400' : 'text-slate-400' }}">,{{ $monthlyDec }}</span>
                        </span>
                        <span class="text-lg lg:text-base font-medium text-slate-400">€/kk</span>
                    </div>
                    <p class="text-xs text-slate-500 tabular-nums mt-1 lg:mt-1.5">
                        {{ number_format($totalCost, 0, ',', ' ') }} €/v
                        <span class="text-slate-400">· sis. tarjoukset</span>
                        @if ($isSpotContract)
                            <x-info-tip :text="$spotArvioTip" trigger-class="text-slate-400">· arvio</x-info-tip>
                        @endif
                    </p>
                    @if ($includesDiscounts && $discountSavingsTotal > 0)
                        <p class="text-xs text-emerald-600 font-semibold mt-1">
                            <x-info-tip text="Säästö = tarjouksen tuoma alennus verrattuna saman sopimuksen normaalihintaan ensimmäisen vuoden aikana.">Säästö</x-info-tip> {{ number_format($discountSavingsTotal, 0, ',', ' ') }} €/v
                        </p>
                        @if ($baseTotalCost !== null)
                            <p class="text-[11px] text-slate-400 tabular-nums mt-0.5">ilman tarjousta {{ number_format($baseTotalCost, 0, ',', ' ') }} €/v</p>
                        @endif
                    @endif
                </div>
            @endif

            <a
                href="{{ $detailUrl }}"
                class="hidden lg:inline-flex items-center justify-center gap-2 font-bold px-6 py-3 rounded-xl transition-all w-[130px] order-3 lg:order-none {{ $featured ? 'bg-gradient-to-r from-coral-500 to-coral-600 hover:from-coral-400 hover:to-coral-500 text-white shadow-lg shadow-coral-500/20' : 'border-2 border-slate-200 text-slate-600 hover:border-coral-400 hover:text-coral-600' }}"
            >
                Katso
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 8l4 4m0 0l-4 4m4-4H3"></path>
                </svg>
            </a>
        </div>
    </div>

    @php
        $hasCardDiscount = $contract->pricing_has_discounts
            || ($contract->relationLoaded('priceComponents') && $contract->hasActiveDiscounts());
        $discountInfo = $contract->relationLoaded('priceComponents') ? $contract->getActiveDiscountInfo() : null;
        $showGreenIndicator = $isZeroEmission || ($source && $source->renewable_total >= 50);

        // Canonical pricing labels (only present when CANONICAL_PRICING_ENABLED).
        $canonicalCost = is_array($contract->calculated_cost ?? null) ? $contract->calculated_cost : [];
        $pricingIntegrity = is_array($contract->pricing_integrity ?? null) ? $contract->pricing_integrity : null;
        $integrityLabel = $pricingIntegrity['card_label'] ?? null;
        $comparability = $contract->comparability ?? null;
        $termMonths = $canonicalCost['term_months'] ?? null;
        $isCanonicalEstimate = ($canonicalCost['is_estimate'] ?? false) === true;

        $hasFooterContent = count($callouts) > 0 || $exceedsConsumptionLimit || $hasCardDiscount || $showGreenIndicator
            || $integrityLabel !== null || $comparability === 'term_price_only' || $comparability === 'base_only_hybrid' || $isCanonicalEstimate;
    @endphp

    {{-- Footer: quiet inline tags. Visual weight here must stay below the price/metrics row. --}}
    @if ($hasFooterContent)
        <div class="flex flex-wrap items-center gap-x-4 gap-y-2 mt-4 pt-4 border-t border-slate-100 text-xs">
            {{-- Percentile callouts --}}
            @foreach ($callouts as $callout)
                <span class="inline-flex items-center font-medium {{ $callout['style'] }}">
                    {{ $callout['text'] }}
                </span>
            @endforeach

            {{-- Discount / promo --}}
            @if ($hasCardDiscount)
                <span class="inline-flex items-center gap-1.5 font-medium text-amber-700">
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z"></path>
                    </svg>
                    @if ($discountInfo && $discountInfo['n_first_months'])
                        {{ $discountInfo['n_first_months'] }} kk tarjous
                    @else
                        Tarjous
                    @endif
                </span>
            @endif

            {{-- Clean-energy indicator --}}
            @if ($showGreenIndicator)
                <span class="inline-flex items-center gap-1.5 font-medium text-emerald-700"
                      title="{{ $isZeroEmission ? 'Päästötön sähkö' : 'Uusiutuvaa energiaa ' . number_format($source->renewable_total, 0) . '%' }}">
                    <svg class="w-3.5 h-3.5" fill="currentColor" viewBox="0 0 24 24">
                        <path d="M17 8C8 10 5.9 16.17 3.82 21.34l1.89.66.95-2.3c.48.17.98.3 1.34.3C19 20 22 3 22 3c-1 2-8 2.25-13 3.25S2 11.5 2 13.5s1.75 3.75 1.75 3.75C7 8 17 8 17 8z"/>
                    </svg>
                    {{ $isZeroEmission ? 'Päästötön' : 'Vihreä' }}
                </span>
            @endif

            {{-- Deceptive-pricing warning: firm amber caveat, same weight as the consumption-limit tag --}}
            @if ($integrityLabel)
                <span class="inline-flex items-center gap-1.5 font-semibold text-amber-800">
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path>
                    </svg>
                    {{ $integrityLabel }}
                </span>
            @endif

            {{-- Fixed-term with an undisclosed continuation price --}}
            @if ($comparability === 'term_price_only' && $termMonths)
                <span class="inline-flex items-center font-medium text-slate-500">
                    {{ $termMonths }} kk sopimus – hinta sen jälkeen ei tiedossa
                </span>
            @endif

            {{-- Hybrid contract priced without the consumption effect --}}
            @if ($comparability === 'base_only_hybrid')
                <span class="inline-flex items-center font-medium text-slate-500">
                    Ei sisällä kulutusvaikutusta
                </span>
            @endif

            {{-- Estimate marker for spot / recurring-reset totals --}}
            @if ($isCanonicalEstimate && $comparability !== 'term_price_only' && $comparability !== 'base_only_hybrid')
                <span class="inline-flex items-center font-medium text-slate-400">
                    Arvio
                </span>
            @endif

            {{-- Consumption-limit warning stays a touch firmer because it's an honest caveat --}}
            @if ($exceedsConsumptionLimit)
                <span class="inline-flex items-center gap-1.5 font-semibold text-amber-800">
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path>
                    </svg>
                    Max {{ number_format($contract->consumption_limitation_max_x_kwh_per_y, 0, ',', ' ') }} kWh/v
                </span>
            @endif
        </div>
    @endif

    {{-- Mobile CTA stays full-width; rendered outside the footer-content guard so it is always reachable. --}}
    <a href="{{ $detailUrl }}" class="lg:hidden w-full mt-4 flex items-center justify-center gap-2 font-bold px-5 py-3 rounded-xl transition-all {{ $featured ? 'bg-gradient-to-r from-coral-500 to-coral-600 text-white shadow-lg shadow-coral-500/20' : 'border-2 border-slate-200 text-slate-600' }}">
        Katso
        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 8l4 4m0 0l-4 4m4-4H3"></path>
        </svg>
    </a>

</div>
