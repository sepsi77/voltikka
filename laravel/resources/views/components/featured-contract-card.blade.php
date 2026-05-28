@props([
    'contract',
    'consumption' => null,
    'prices' => null,
])

@php
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
        $dayTimePrice = $contract->priceComponents
            ->where('price_component_type', 'DayTime')
            ->sortByDesc('price_date')
            ->first()?->price;
        $seasonalWinterPrice = $contract->priceComponents
            ->where('price_component_type', 'SeasonalWinterDay')
            ->sortByDesc('price_date')
            ->first()?->price;
    } else {
        $generalPrice = $priceData['General']['price'] ?? null;
        $monthlyFee = $priceData['Monthly']['price'] ?? 0;
        $dayTimePrice = $priceData['DayTime']['price'] ?? null;
        $seasonalWinterPrice = $priceData['SeasonalWinterDay']['price'] ?? null;
    }

    // Get calculated cost data if available
    $calculatedCost = $contract->calculated_cost ?? [];
    $totalCost = $calculatedCost['total_cost'] ?? null;
    $discountSavingsTotal = $calculatedCost['discount_savings_total'] ?? 0;
    $includesDiscounts = $calculatedCost['includes_discounts'] ?? false;
    $isSpotContract = $calculatedCost['is_spot_contract'] ?? false;
    $spotMargin = $calculatedCost['spot_price_margin'] ?? null;
    $spotPriceDayAvg = $calculatedCost['spot_price_day_avg'] ?? null;
    $spotPriceNightAvg = $calculatedCost['spot_price_night_avg'] ?? null;

    // Monthly cost = annual / 12 (€/kk lead, €/v secondary)
    $monthlyCost = $totalCost !== null ? $totalCost / 12 : null;

    // Use only already-loaded relations in cards. Listing pages batch-load these
    // relations; falling back to lazy loads here turns every featured card into
    // an electricity_sources/company N+1 risk when passed a slim contract model.
    $company = $contract->relationLoaded('company') ? $contract->company : null;
    $source = $contract->relationLoaded('electricitySource') ? $contract->electricitySource : null;

    // Pricing-type display label (Pörssisähkö, Aikasähkö, ...).
    $pricingTypeLabel = match (true) {
        $contract->pricing_model === 'Spot' => 'Pörssisähkö',
        $contract->pricing_model === 'Hybrid' => 'Hybridisähkö',
        $contract->metering === 'Time' => 'Aikasähkö',
        $contract->metering === 'Season' => 'Kausisähkö',
        $contract->pricing_model === 'FixedPrice' => 'Kiinteähintainen sähkö',
        default => null,
    };

    // Duration label.
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

    // Headline energy unit price.
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

    // Calculate emissions if consumption is provided
    $emissionFactor = $contract->emission_factor ?? 0;
    $annualEmissionsKg = $consumption ? round($emissionFactor * $consumption / 1000) : 0;
    $isZeroEmission = $emissionFactor == 0;
@endphp

<div class="relative overflow-hidden bg-gradient-to-br from-coral-500 via-coral-500 to-coral-600 rounded-3xl shadow-2xl shadow-coral-500/30">
    {{-- Decorative blobs --}}
    <div class="absolute inset-0 pointer-events-none">
        <div class="absolute -top-12 -right-12 w-48 h-48 bg-white/10 rounded-full blur-2xl"></div>
        <div class="absolute -bottom-8 -left-8 w-32 h-32 bg-coral-400/30 rounded-full blur-xl"></div>
    </div>

    <div class="relative p-8">
        {{-- Badge --}}
        <div class="inline-flex items-center gap-2 bg-white/20 backdrop-blur-sm px-4 py-2 rounded-full text-sm font-bold text-white mb-6">
            <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20">
                <path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"/>
            </svg>
            #1 Halvin sähkösopimus
        </div>

        <div class="flex flex-col lg:flex-row lg:items-center gap-6">
            {{-- Company Logo and Contract Info --}}
            <div class="flex items-center gap-5 flex-1 min-w-0">
                @if ($company?->getLogoUrl())
                    <div class="w-20 h-16 bg-white rounded-xl p-2 shadow-lg flex-shrink-0">
                        <img
                            src="{{ $company->getLogoUrl() }}"
                            alt="{{ $company->name }}"
                            class="w-full h-full object-contain"
                            loading="lazy"
                            onerror="this.onerror=null; this.src='https://placehold.co/80x64/ffffff/coral?text={{ substr($company?->name ?? 'N/A', 0, 2) }}'"
                        >
                    </div>
                @else
                    <div class="w-20 h-16 bg-white/20 rounded-xl flex items-center justify-center flex-shrink-0">
                        <span class="text-white text-sm font-bold">{{ substr($company?->name ?? 'N/A', 0, 3) }}</span>
                    </div>
                @endif
                <div class="min-w-0">
                    <h3 class="text-2xl font-extrabold text-white mb-1 truncate">
                        {{ $contract->name }}
                    </h3>
                    <p class="text-white/90 text-sm lg:text-base leading-snug">
                        <span>{{ $company?->name ?? $contract->company_name }}</span>
                        @if ($pricingTypeLabel)
                            <span class="text-white/40 px-1">·</span>
                            <span>{{ $pricingTypeLabel }}</span>
                        @endif
                        @if ($durationLabel)
                            <span class="text-white/40 px-1">·</span>
                            <span>{{ $durationLabel }}</span>
                        @endif
                    </p>
                </div>
            </div>

            {{-- Pricing Section: c/kWh + perusmaksu + total --}}
            <div class="flex flex-wrap lg:flex-nowrap items-end gap-x-6 gap-y-4 lg:gap-7">
                @if ($headlineEnergyPrice !== null)
                    <div class="flex flex-col">
                        <span class="text-[11px] font-semibold uppercase tracking-wider text-coral-100/80">
                            {{ $headlineEnergyLabel }}
                        </span>
                        <span class="text-base lg:text-lg font-semibold text-white/95 tabular-nums whitespace-nowrap leading-tight mt-1">
                            {{ number_format($headlineEnergyPrice, 2, ',', ' ') }}<span class="ml-1 text-xs lg:text-sm font-medium text-coral-100">c/kWh</span>
                        </span>
                    </div>
                @endif

                <div class="flex flex-col">
                    <span class="text-[11px] font-semibold uppercase tracking-wider text-coral-100/80">Perusmaksu</span>
                    <span class="text-base lg:text-lg font-semibold text-white/95 tabular-nums whitespace-nowrap leading-tight mt-1">
                        {{ number_format($monthlyFee, 2, ',', ' ') }}<span class="ml-1 text-xs lg:text-sm font-medium text-coral-100">€</span>
                    </span>
                </div>

                @if ($monthlyCost !== null)
                    <div class="flex flex-col lg:border-l lg:border-white/25 lg:pl-7">
                        <span class="text-[11px] font-bold uppercase tracking-[0.16em] text-coral-100">
                            Kuukausihinta
                            @if ($isSpotContract)
                                <span class="font-medium normal-case tracking-normal text-coral-200">· arvio</span>
                            @endif
                        </span>
                        <div class="inline-flex items-baseline gap-1.5 whitespace-nowrap mt-1">
                            <span class="text-4xl lg:text-[2.75rem] font-black text-white tabular-nums leading-none">
                                {{ number_format($monthlyCost, 1, ',', ' ') }}
                            </span>
                            <span class="text-lg lg:text-base font-medium text-coral-100">€/kk</span>
                        </div>
                        <p class="text-xs lg:text-sm text-coral-50 tabular-nums mt-1.5">
                            {{ number_format($totalCost, 0, ',', ' ') }} €/v · sis. tarjoukset
                        </p>
                        @if ($includesDiscounts && $discountSavingsTotal > 0)
                            <p class="text-xs text-white/90 font-semibold mt-1">
                                Säästö {{ number_format($discountSavingsTotal, 0, ',', ' ') }} €/v
                            </p>
                        @endif
                    </div>
                @endif
            </div>
        </div>

        {{-- Bottom Section: quiet inline tags + CTA --}}
        <div class="flex flex-wrap items-center justify-between gap-4 mt-6 pt-6 border-t border-white/20 text-xs">
            <div class="flex flex-wrap items-center gap-x-4 gap-y-2 text-coral-50">
                {{-- CO2 emissions --}}
                @if ($consumption !== null)
                    @if ($isZeroEmission)
                        <span class="inline-flex items-center gap-1.5 font-medium">
                            <svg class="w-3.5 h-3.5" fill="currentColor" viewBox="0 0 24 24">
                                <path d="M17 8C8 10 5.9 16.17 3.82 21.34l1.89.66.95-2.3c.48.17.98.3 1.34.3C19 20 22 3 22 3c-1 2-8 2.25-13 3.25S2 11.5 2 13.5s1.75 3.75 1.75 3.75C7 8 17 8 17 8z"/>
                            </svg>
                            0 kg CO2/v
                        </span>
                    @else
                        <span class="inline-flex items-center gap-1.5 font-medium">
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M3 15a4 4 0 004 4h9a5 5 0 10-.1-9.999 5.002 5.002 0 10-9.78 2.096A4.001 4.001 0 003 15z"></path>
                            </svg>
                            {{ number_format($annualEmissionsKg, 0, ',', ' ') }} kg CO2/v
                        </span>
                    @endif
                @endif

                {{-- Energy source --}}
                @if ($source)
                    @if ($source->renewable_total && $source->renewable_total > 0)
                        <span class="inline-flex items-center font-medium">
                            Uusiutuva {{ number_format($source->renewable_total, 0) }}%
                        </span>
                    @endif
                    @if ($source->nuclear_total && $source->nuclear_total > 0)
                        <span class="inline-flex items-center font-medium">
                            Ydinvoima {{ number_format($source->nuclear_total, 0) }}%
                        </span>
                    @endif
                @endif
            </div>

            {{-- CTA Button --}}
            <a
                href="{{ route('contract.detail', $contract->id) }}"
                class="inline-flex items-center gap-2 bg-white hover:bg-coral-50 text-coral-600 font-bold px-6 py-3 rounded-xl transition-all shadow-lg"
            >
                Katso
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 8l4 4m0 0l-4 4m4-4H3"></path>
                </svg>
            </a>
        </div>
    </div>
</div>
