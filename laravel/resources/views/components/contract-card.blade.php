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
])

@php
    // Check if contract exceeds consumption limit
    $exceedsConsumptionLimit = $contract->exceeds_consumption_limit ?? false;

    // Get prices from props or extract from contract's priceComponents
    $priceData = $prices ?? [];
    if (empty($priceData) && $contract->relationLoaded('priceComponents')) {
        // Extract prices directly from priceComponents relationship
        $generalPrice = $contract->priceComponents
            ->where('price_component_type', 'General')
            ->sortByDesc('price_date')
            ->first()?->price;
        $monthlyFee = $contract->priceComponents
            ->where('price_component_type', 'Monthly')
            ->sortByDesc('price_date')
            ->first()?->price ?? 0;
        // Seasonal pricing components
        $seasonalWinterPrice = $contract->priceComponents
            ->where('price_component_type', 'SeasonalWinterDay')
            ->sortByDesc('price_date')
            ->first()?->price;
        $seasonalOtherPrice = $contract->priceComponents
            ->where('price_component_type', 'SeasonalOther')
            ->sortByDesc('price_date')
            ->first()?->price;
        // Time-based pricing components
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

    // Calculate total energy price for spot contracts (spot + margin)
    $spotTotalEnergyPrice = null;
    if ($isSpotContract && $spotPriceDayAvg !== null && $spotPriceNightAvg !== null) {
        $margin = $spotMargin ?? 0;
        $totalDayPrice = $spotPriceDayAvg + $margin;
        $totalNightPrice = $spotPriceNightAvg + $margin;
        // Weighted average: 85% day, 15% night (typical household)
        $spotTotalEnergyPrice = ($totalDayPrice * 0.85) + ($totalNightPrice * 0.15);
    }

    // Get electricity source
    $source = $contract->electricitySource;

    // Determine emissions color for left border (based on gCO2/kWh, matches badge thresholds)
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

    // Calculate emissions if consumption is provided
    $annualEmissionsKg = $consumption ? round($emissionFactor * $consumption / 1000) : 0;
    $isZeroEmission = $emissionFactor == 0;
    $emissionColorClass = $isZeroEmission
        ? 'bg-green-100 text-green-700 border-green-200'
        : ($emissionFactor < 100
            ? 'bg-green-50 text-green-600 border-green-100'
            : ($emissionFactor < 300
                ? 'bg-amber-50 text-amber-700 border-amber-100'
                : 'bg-red-50 text-red-700 border-red-100'));

    // Pricing type label mapping
    $pricingTypeMap = [
        'Spot' => ['label' => 'Pörssisähkö', 'class' => 'text-coral-700 bg-coral-50 border-coral-200'],
        'FixedPrice' => ['label' => 'Kiinteä hinta', 'class' => 'text-blue-700 bg-blue-50 border-blue-200'],
        'Hybrid' => ['label' => 'Hybridi', 'class' => 'text-purple-700 bg-purple-50 border-purple-200'],
        'Quarterly' => ['label' => 'Kvartaalisähkö', 'class' => 'text-amber-700 bg-amber-50 border-amber-200'],
        'TimeOfUse' => ['label' => 'Aikasähkö', 'class' => 'text-indigo-700 bg-indigo-50 border-indigo-200'],
        'Seasonal' => ['label' => 'Kausisähkö', 'class' => 'text-teal-700 bg-teal-50 border-teal-200'],
    ];
    $pricingTypeInfo = $pricingTypeMap[$contract->pricing_model] ?? null;
@endphp

<div class="group relative w-full p-6 {{ $exceedsConsumptionLimit ? 'bg-slate-50 opacity-75' : 'bg-white' }} border border-slate-100 rounded-2xl {{ $borderWidth }} {{ $borderColorClass }} {{ $featured ? 'border-coral-200' : '' }} transition-all duration-200 hover:-translate-y-0.5 hover:shadow-card-hover">
    <div class="flex flex-col lg:flex-row items-start lg:items-center gap-4">
        {{-- Rank Number --}}
        @if ($showRank && $rank !== null)
            <div class="hidden lg:block flex-shrink-0 w-12">
                <span class="text-4xl font-extrabold {{ $featured ? 'text-coral-500' : 'text-slate-200' }}">
                    {{ str_pad($rank, 2, '0', STR_PAD_LEFT) }}
                </span>
            </div>
        @endif

        {{-- Company Logo and Contract Name --}}
        <div class="flex items-center gap-4 w-full lg:w-auto lg:flex-1 min-w-0">
            @if ($contract->company?->getLogoUrl())
                <img
                    src="{{ $contract->company->getLogoUrl() }}"
                    alt="{{ $contract->company->name }}"
                    class="w-16 h-12 object-contain flex-shrink-0"
                    loading="lazy"
                    onerror="this.onerror=null; this.src='https://placehold.co/64x48?text=logo'"
                >
            @else
                <div class="w-16 h-12 bg-slate-100 rounded-lg flex items-center justify-center flex-shrink-0">
                    <span class="text-slate-500 text-xs font-bold">{{ substr($contract->company?->name ?? 'N/A', 0, 3) }}</span>
                </div>
            @endif
            <div class="flex flex-col min-w-0 flex-1">
                <h5 class="text-xl lg:text-lg font-bold text-slate-900 truncate tracking-tight">
                    {{ $contract->name }}
                </h5>
                <p class="text-sm text-slate-500 truncate">
                    {{ $contract->company?->name }}
                </p>
                @if ($pricingTypeInfo)
                    <span class="mt-1.5 inline-flex items-center gap-1 self-start px-2 py-0.5 rounded-md text-[11px] font-semibold border {{ $pricingTypeInfo['class'] }}">
                        {{ $pricingTypeInfo['label'] }}
                    </span>
                @endif
            </div>
        </div>

        {{-- Total Cost + CTA --}}
        <div class="flex flex-wrap items-center gap-x-6 gap-y-3 lg:flex-nowrap lg:gap-8 w-full lg:w-auto">
            @if ($totalCost !== null)
                <div class="order-first w-full pb-3 border-b border-slate-100 lg:order-none lg:w-auto lg:pb-0 lg:border-b-0">
                    <p class="lg:hidden text-[10px] font-bold uppercase tracking-[0.18em] text-coral-600 mb-1">
                        12 kk arvio
                        @if ($isSpotContract)
                            <span class="font-medium normal-case text-slate-400">(arvio)</span>
                        @endif
                    </p>
                    <div class="flex items-baseline gap-2">
                        <span class="text-4xl lg:text-5xl font-extrabold {{ $featured ? 'text-coral-600' : 'text-slate-900' }} tabular-nums leading-none">
                            {{ number_format($totalCost, 0, ',', ' ') }}
                        </span>
                        <span class="text-lg lg:text-base font-medium text-slate-400">€/12 kk</span>
                    </div>
                    <p class="hidden lg:block text-xs text-slate-500 uppercase tracking-wide mt-1">
                        12 kk arvio
                        @if ($isSpotContract)
                            <span class="normal-case">(arvio)</span>
                        @endif
                    </p>
                    @if ($includesDiscounts && $discountSavingsTotal > 0)
                        <p class="text-xs text-emerald-600 font-semibold mt-1">
                            Sis. tarjouksen · säästö {{ number_format($discountSavingsTotal, 0, ',', ' ') }} €
                        </p>
                    @endif
                </div>
            @endif

            <a
                href="{{ route('contract.detail', $contract->id) }}"
                class="hidden lg:inline-flex items-center gap-2 bg-gradient-to-r from-coral-500 to-coral-600 hover:from-coral-400 hover:to-coral-500 text-white font-bold px-6 py-3.5 rounded-xl transition-all shadow-lg shadow-coral-500/20"
            >
                Katso
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 8l4 4m0 0l-4 4m4-4H3"></path>
                </svg>
            </a>
        </div>
    </div>

    {{-- Badges: promotion + green indicator + consumption limit only --}}
    <div class="flex flex-wrap items-center gap-2 mt-4 pt-4 border-t border-slate-100">
        @if ($exceedsConsumptionLimit)
            <span class="inline-flex items-center gap-2 px-3 py-1.5 bg-amber-100 text-amber-800 border border-amber-200 text-xs font-bold rounded-lg">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path>
                </svg>
                Max {{ number_format($contract->consumption_limitation_max_x_kwh_per_y, 0, ',', ' ') }} kWh/v
            </span>
        @endif

        @if ($contract->hasActiveDiscounts())
            @php
                $discountInfo = $contract->getActiveDiscountInfo();
            @endphp
            <span class="inline-flex items-center gap-2 px-3 py-1.5 bg-gradient-to-r from-amber-100 to-yellow-100 text-amber-800 border border-amber-200 text-xs font-bold rounded-lg uppercase">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z"></path>
                </svg>
                @if ($discountInfo && $discountInfo['n_first_months'])
                    {{ $discountInfo['n_first_months'] }} kk tarjous
                @else
                    Tarjous
                @endif
            </span>
        @endif

        @if ($isZeroEmission || ($source && $source->renewable_total >= 50))
            <span class="inline-flex items-center gap-1.5 text-green-600" title="{{ $isZeroEmission ? 'Päästötön sähkö' : 'Uusiutuvaa energiaa ' . number_format($source->renewable_total, 0) . '%' }}">
                <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 24 24">
                    <path d="M17 8C8 10 5.9 16.17 3.82 21.34l1.89.66.95-2.3c.48.17.98.3 1.34.3C19 20 22 3 22 3c-1 2-8 2.25-13 3.25S2 11.5 2 13.5s1.75 3.75 1.75 3.75C7 8 17 8 17 8z"/>
                </svg>
                <span class="text-xs font-bold">{{ $isZeroEmission ? 'Päästötön' : 'Vihreä' }}</span>
            </span>
        @endif

        <a href="{{ route('contract.detail', $contract->id) }}" class="lg:hidden w-full mt-2 flex items-center justify-center gap-2 bg-gradient-to-r from-coral-500 to-coral-600 hover:from-coral-400 hover:to-coral-500 text-white font-bold px-5 py-3 rounded-xl transition-all shadow-lg shadow-coral-500/20">
            Katso sopimus
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 8l4 4m0 0l-4 4m4-4H3"></path>
            </svg>
        </a>
    </div>

</div>
