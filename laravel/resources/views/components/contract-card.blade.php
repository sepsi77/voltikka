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
    } else {
        $generalPrice = $priceData['General']['price'] ?? null;
        $monthlyFee = $priceData['Monthly']['price'] ?? 0;
    }

    // Get calculated cost data if available
    $calculatedCost = $contract->calculated_cost ?? [];
    $totalCost = $calculatedCost['total_cost'] ?? null;
    $isSpotContract = $calculatedCost['is_spot_contract'] ?? false;
    $spotMargin = $calculatedCost['spot_price_margin'] ?? null;

    // Get electricity source
    $source = $contract->electricitySource;

    // Determine emissions color for left border
    $fossilPercent = $source?->fossil_total ?? 0;
    if ($featured) {
        $borderColorClass = 'border-l-coral-500';
        $borderWidth = 'border-l-[6px]';
    } elseif ($fossilPercent == 0) {
        $borderColorClass = 'border-l-emissions-low';
        $borderWidth = 'border-l-4';
    } elseif ($fossilPercent <= 30) {
        $borderColorClass = 'border-l-emissions-medium';
        $borderWidth = 'border-l-4';
    } else {
        $borderColorClass = 'border-l-emissions-high';
        $borderWidth = 'border-l-4';
    }

    // Calculate emissions if consumption is provided
    $emissionFactor = $contract->emission_factor ?? 0;
    $annualEmissionsKg = $consumption ? round($emissionFactor * $consumption / 1000) : 0;
    $isZeroEmission = $emissionFactor == 0;
    $emissionColorClass = $isZeroEmission
        ? 'bg-green-100 text-green-700 border-green-200'
        : ($emissionFactor < 100
            ? 'bg-green-50 text-green-600 border-green-100'
            : ($emissionFactor < 300
                ? 'bg-amber-50 text-amber-700 border-amber-100'
                : 'bg-red-50 text-red-700 border-red-100'));
@endphp

<div class="group relative w-full p-6 bg-white border border-slate-100 rounded-2xl {{ $borderWidth }} {{ $borderColorClass }} {{ $featured ? 'border-coral-200' : '' }} transition-all duration-200 hover:-translate-y-0.5 hover:shadow-card-hover">
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
        <div class="flex items-center gap-4 flex-1 min-w-0">
            @if ($contract->company?->getLogoUrl())
                <img
                    src="{{ $contract->company->getLogoUrl() }}"
                    alt="{{ $contract->company->name }}"
                    class="w-16 h-12 object-contain flex-shrink-0"
                    onerror="this.onerror=null; this.src='https://placehold.co/64x48?text=logo'"
                >
            @else
                <div class="w-16 h-12 bg-slate-100 rounded-lg flex items-center justify-center flex-shrink-0">
                    <span class="text-slate-500 text-xs font-bold">{{ substr($contract->company?->name ?? 'N/A', 0, 3) }}</span>
                </div>
            @endif
            <div class="flex flex-col min-w-0">
                <h5 class="text-lg font-bold text-slate-900 truncate">
                    {{ $contract->name }}
                </h5>
                <p class="text-sm text-slate-500">
                    {{ $contract->company?->name }}
                </p>
            </div>
        </div>

        {{-- Pricing Grid --}}
        <div class="flex flex-wrap lg:flex-nowrap items-center gap-4 lg:gap-6 w-full lg:w-auto">
            @if ($isSpotContract && $spotMargin !== null)
                {{-- Spot contract: show margin --}}
                <div class="text-left">
                    <div class="text-lg font-bold text-slate-900 tabular-nums">
                        {{ number_format($spotMargin, 2, ',', ' ') }} <span class="text-sm font-normal text-slate-400">c/kWh</span>
                    </div>
                    <p class="text-xs text-slate-500 uppercase tracking-wide">Marginaali</p>
                </div>
            @elseif ($generalPrice !== null)
                {{-- Fixed price contract --}}
                <div class="text-left">
                    <div class="text-lg font-bold text-slate-900 tabular-nums">
                        {{ number_format($generalPrice, 2, ',', ' ') }} <span class="text-sm font-normal text-slate-400">c/kWh</span>
                    </div>
                    <p class="text-xs text-slate-500 uppercase tracking-wide">Energia</p>
                </div>
            @endif

            <div class="text-left">
                <div class="text-lg font-bold text-slate-900 tabular-nums">
                    {{ number_format($monthlyFee, 2, ',', ' ') }} <span class="text-sm font-normal text-slate-400">{{ "\u{20AC}" }}/kk</span>
                </div>
                <p class="text-xs text-slate-500 uppercase tracking-wide">Perusmaksu</p>
            </div>

            {{-- Total Cost - Featured gets coral color --}}
            @if ($totalCost !== null)
                <div class="text-left lg:border-l lg:border-slate-200 lg:pl-6">
                    <div class="text-2xl font-extrabold {{ $featured ? 'text-coral-600' : 'text-slate-900' }} tabular-nums">
                        {{ number_format($totalCost, 0, ',', ' ') }} <span class="text-sm font-normal text-slate-400">{{ "\u{20AC}" }}/v</span>
                    </div>
                    <p class="text-xs text-slate-500 uppercase tracking-wide">
                        Vuosikustannus
                        @if ($isSpotContract)
                            <span class="normal-case">(arvio)</span>
                        @endif
                    </p>
                </div>
            @endif

            {{-- CTA Button --}}
            <a
                href="{{ route('contract.detail', $contract->id) }}"
                class="hidden lg:inline-flex items-center gap-2 bg-gradient-to-r from-coral-500 to-coral-600 hover:from-coral-400 hover:to-coral-500 text-white font-bold px-5 py-3 rounded-xl transition-all shadow-lg shadow-coral-500/20"
            >
                Katso
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 8l4 4m0 0l-4 4m4-4H3"></path>
                </svg>
            </a>
        </div>
    </div>

    {{-- Energy Source Badges, CO2 Emissions and Mobile CTA --}}
    <div class="flex flex-wrap items-center gap-2 mt-4 pt-4 border-t border-slate-100">
        {{-- CO2 Emissions Badge --}}
        @if ($showEmissions && $consumption !== null)
            @if ($isZeroEmission)
                {{-- Special zero-emission badge with leaf icon --}}
                <span class="inline-flex items-center gap-2 px-3 py-1.5 bg-green-100 text-green-700 border border-green-200 text-xs font-bold rounded-lg" title="Paastöton sähkö - 0 gCO2/kWh">
                    <svg class="w-4 h-4 text-green-600" fill="currentColor" viewBox="0 0 24 24">
                        <path d="M17 8C8 10 5.9 16.17 3.82 21.34l1.89.66.95-2.3c.48.17.98.3 1.34.3C19 20 22 3 22 3c-1 2-8 2.25-13 3.25S2 11.5 2 13.5s1.75 3.75 1.75 3.75C7 8 17 8 17 8z"/>
                    </svg>
                    <span class="font-extrabold">0 kg</span>
                    <span class="text-green-600 font-medium">CO2/v</span>
                </span>
            @else
                {{-- Standard emissions badge --}}
                <span class="inline-flex items-center gap-2 px-3 py-1.5 {{ $emissionColorClass }} border text-xs font-bold rounded-lg" title="Arvioitu paastökerroin: {{ number_format($emissionFactor, 0) }} gCO2/kWh">
                    <svg class="w-3.5 h-3.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 15a4 4 0 004 4h9a5 5 0 10-.1-9.999 5.002 5.002 0 10-9.78 2.096A4.001 4.001 0 003 15z"></path>
                    </svg>
                    <span class="font-extrabold">{{ number_format($annualEmissionsKg, 0, ',', ' ') }} kg</span>
                    <span class="opacity-75 font-medium">CO2/v</span>
                    <span class="text-[10px] opacity-60 font-normal">({{ number_format($emissionFactor, 0) }} g/kWh)</span>
                </span>
            @endif
        @endif

        {{-- Energy Source Badges --}}
        @if ($showEnergyBadges && $source)
            @if ($source->renewable_total && $source->renewable_total > 0)
                <span class="inline-block px-3 py-1.5 bg-green-100 text-green-700 text-xs font-bold rounded-lg uppercase">
                    Uusiutuva {{ number_format($source->renewable_total, 0) }}%
                </span>
            @endif
            @if ($source->nuclear_total && $source->nuclear_total > 0)
                <span class="inline-block px-3 py-1.5 bg-green-100 text-green-700 text-xs font-bold rounded-lg uppercase">
                    Ydinvoima {{ number_format($source->nuclear_total, 0) }}%
                </span>
            @endif
            @if ($source->fossil_total && $source->fossil_total > 0)
                <span class="inline-block px-3 py-1.5 bg-slate-100 text-slate-600 text-xs font-medium rounded-lg uppercase">
                    Fossiilinen {{ number_format($source->fossil_total, 0) }}%
                </span>
            @endif
        @endif

        {{-- Spot Contract Badge --}}
        @if ($showSpotBadge && $isSpotContract)
            <span class="inline-block px-3 py-1.5 bg-coral-50 text-coral-700 text-xs font-bold rounded-lg uppercase">
                Pörssi
            </span>
        @endif

        {{-- Mobile CTA --}}
        <a
            href="{{ route('contract.detail', $contract->id) }}"
            class="lg:hidden w-full mt-2 flex items-center justify-center gap-2 bg-gradient-to-r from-coral-500 to-coral-600 hover:from-coral-400 hover:to-coral-500 text-white font-bold px-5 py-3 rounded-xl transition-all shadow-lg shadow-coral-500/20"
        >
            Katso sopimus
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 8l4 4m0 0l-4 4m4-4H3"></path>
            </svg>
        </a>
    </div>

</div>
