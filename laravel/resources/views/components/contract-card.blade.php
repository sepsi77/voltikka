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
])

@php
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

    // Get electricity source
    $source = $contract->electricitySource;

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
                        $callouts[] = ['text' => 'Edullinen marginaali', 'style' => 'bg-emerald-50 text-emerald-700 border-emerald-200'];
                    } elseif ($spotMargin >= $percentiles['spot_margin']['p85']) {
                        $callouts[] = ['text' => 'Kallis marginaali', 'style' => 'bg-amber-50 text-amber-700 border-amber-200'];
                    }
                }
                break;

            case 'FixedPrice':
                if ($generalPrice !== null && isset($percentiles['fixed_energy'])) {
                    if ($generalPrice <= $percentiles['fixed_energy']['p15']) {
                        $callouts[] = ['text' => 'Edullinen energianhinta', 'style' => 'bg-emerald-50 text-emerald-700 border-emerald-200'];
                    } elseif ($generalPrice >= $percentiles['fixed_energy']['p85']) {
                        $callouts[] = ['text' => 'Kallis energianhinta', 'style' => 'bg-amber-50 text-amber-700 border-amber-200'];
                    }
                }
                break;

            case 'Seasonal':
                if ($seasonalWinterPrice !== null && isset($percentiles['seasonal_winter'])) {
                    if ($seasonalWinterPrice <= $percentiles['seasonal_winter']['p15']) {
                        $callouts[] = ['text' => 'Edullinen talvihinta', 'style' => 'bg-emerald-50 text-emerald-700 border-emerald-200'];
                    } elseif ($seasonalWinterPrice >= $percentiles['seasonal_winter']['p85']) {
                        $callouts[] = ['text' => 'Kallis talvihinta', 'style' => 'bg-amber-50 text-amber-700 border-amber-200'];
                    }
                }
                break;

            case 'TimeOfUse':
                if ($dayTimePrice !== null && isset($percentiles['time_day'])) {
                    if ($dayTimePrice <= $percentiles['time_day']['p15']) {
                        $callouts[] = ['text' => 'Edullinen päivähinta', 'style' => 'bg-emerald-50 text-emerald-700 border-emerald-200'];
                    } elseif ($dayTimePrice >= $percentiles['time_day']['p85']) {
                        $callouts[] = ['text' => 'Kallis päivähinta', 'style' => 'bg-amber-50 text-amber-700 border-amber-200'];
                    }
                }
                break;
        }

        // Monthly fee is evaluated for all contract types
        if ($monthlyFee > 0 && isset($percentiles['monthly_fee'])) {
            if ($monthlyFee <= $percentiles['monthly_fee']['p15']) {
                $callouts[] = ['text' => 'Edullinen perusmaksu', 'style' => 'bg-emerald-50 text-emerald-700 border-emerald-200'];
            } elseif ($monthlyFee >= $percentiles['monthly_fee']['p85']) {
                $callouts[] = ['text' => 'Kallis perusmaksu', 'style' => 'bg-amber-50 text-amber-700 border-amber-200'];
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
    <div class="flex flex-col lg:flex-row items-start lg:items-center gap-4">
        {{-- Rank Number (subtle, only for top 3) --}}
        @if ($showRank && $rank !== null && $rank <= 3)
            <div class="hidden lg:flex flex-shrink-0 w-8 h-8 items-center justify-center rounded-full {{ $rank === 1 ? 'bg-coral-100 text-coral-700' : 'bg-slate-100 text-slate-500' }}">
                <span class="text-xs font-bold">{{ $rank }}</span>
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
                    onerror="this.onerror=null; this.src='https://placehold.co/64x48/e2e8f0/64748b?text={{ substr($contract->company?->name ?? 'N/A', 0, 2) }}'"
                >
            @else
                <div class="w-16 h-12 bg-slate-100 rounded-lg flex items-center justify-center flex-shrink-0">
                    <span class="text-slate-500 text-xs font-bold">{{ substr($contract->company?->name ?? 'N/A', 0, 3) }}</span>
                </div>
            @endif
            <div class="flex flex-col min-w-0 flex-1">
                <div class="flex items-center gap-2">
                    <h5 class="text-xl lg:text-lg font-bold text-slate-900 truncate tracking-tight">
                        {{ $contract->name }}
                    </h5>
                    @if ($featured && $rank > 1)
                        <span class="hidden lg:inline-flex items-center px-2 py-0.5 bg-coral-50 text-coral-700 text-[10px] font-bold uppercase tracking-wider rounded border border-coral-200 flex-shrink-0">
                            Kärkisija
                        </span>
                    @endif
                </div>
                <div class="flex items-center gap-2 mt-0.5">
                    <p class="text-sm text-slate-500 truncate">
                        {{ $contract->company?->name }}
                    </p>
                    {{-- Pricing type icons for Spot and FixedPrice only --}}
                    @if ($contract->pricing_model === 'Spot')
                        <span class="inline-flex items-center gap-1 text-coral-600" title="Pörssisähkö">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 013 19.875v-6.75zM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V8.625zM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V4.125z"/>
                            </svg>
                            <span class="text-xs font-semibold">Pörssi</span>
                        </span>
                    @elseif ($contract->pricing_model === 'FixedPrice')
                        <span class="inline-flex items-center gap-1 text-blue-600" title="Kiinteä hinta">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 11.25v7.5"/>
                            </svg>
                            <span class="text-xs font-semibold">Kiinteä</span>
                        </span>
                    @endif
                </div>
            </div>
        </div>

        {{-- Total Cost + CTA --}}
        <div class="flex flex-wrap items-center gap-x-6 gap-y-3 lg:flex-nowrap lg:gap-6 w-full lg:w-auto lg:ml-auto lg:justify-end">
            @if ($totalCost !== null)
                <div class="order-first w-full pb-3 border-b border-slate-100 lg:order-none lg:w-[170px] lg:pb-0 lg:border-b-0 lg:text-right">
                    <p class="lg:hidden text-[10px] font-bold uppercase tracking-[0.18em] text-coral-600 mb-1">
                        12 kk hinta
                        @if ($isSpotContract)
                            <span class="font-medium normal-case text-slate-400">· arvio</span>
                        @endif
                    </p>
                    <div class="inline-flex items-baseline gap-2">
                        <span class="text-4xl lg:text-5xl font-extrabold {{ $featured ? 'text-coral-600' : 'text-slate-900' }} tabular-nums leading-none">
                            {{ number_format($totalCost, 0, ',', ' ') }}
                        </span>
                        <span class="text-lg lg:text-base font-medium text-slate-400">€/12 kk</span>
                    </div>
                    <p class="hidden lg:block text-xs text-slate-500 mt-1">
                        12 kk hinta sis. tarjoukset
                        @if ($isSpotContract)
                            <span class="text-slate-400">· arvio</span>
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
                class="hidden lg:inline-flex items-center justify-center gap-2 font-bold px-6 py-3 rounded-xl transition-all w-[130px] {{ $featured ? 'bg-gradient-to-r from-coral-500 to-coral-600 hover:from-coral-400 hover:to-coral-500 text-white shadow-lg shadow-coral-500/20' : 'border-2 border-slate-200 text-slate-600 hover:border-coral-400 hover:text-coral-600' }}"
            >
                Katso
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 8l4 4m0 0l-4 4m4-4H3"></path>
                </svg>
            </a>
        </div>
    </div>

    {{-- Footer: callouts + promotion + green indicator + consumption limit --}}
    <div class="flex flex-wrap items-center gap-2 mt-4 pt-4 border-t border-slate-100">
        {{-- Smart callout badges (percentile-based) --}}
        @foreach ($callouts as $callout)
            <span class="inline-flex items-center px-2.5 py-1 text-xs font-bold rounded-lg border {{ $callout['style'] }}">
                {{ $callout['text'] }}
            </span>
        @endforeach

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
            <span class="inline-flex items-center gap-2 px-3 py-1.5 {{ $featured ? 'bg-gradient-to-r from-amber-100 to-yellow-100' : 'bg-amber-50' }} text-amber-800 border border-amber-200 text-xs font-bold rounded-lg uppercase">
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

        <a href="{{ route('contract.detail', $contract->id) }}" class="lg:hidden w-full mt-2 flex items-center justify-center gap-2 font-bold px-5 py-3 rounded-xl transition-all {{ $featured ? 'bg-gradient-to-r from-coral-500 to-coral-600 text-white shadow-lg shadow-coral-500/20' : 'border-2 border-slate-200 text-slate-600' }}">
            Katso
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 8l4 4m0 0l-4 4m4-4H3"></path>
            </svg>
        </a>
    </div>

</div>
