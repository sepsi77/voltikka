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
    } else {
        $generalPrice = $priceData['General']['price'] ?? null;
        $monthlyFee = $priceData['Monthly']['price'] ?? 0;
    }

    // Get calculated cost data if available
    $calculatedCost = $contract->calculated_cost ?? [];
    $totalCost = $calculatedCost['total_cost'] ?? null;
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
            <div class="flex items-center gap-5 flex-1">
                @if ($contract->company?->getLogoUrl())
                    <div class="w-20 h-16 bg-white rounded-xl p-2 shadow-lg flex-shrink-0">
                        <img
                            src="{{ $contract->company->getLogoUrl() }}"
                            alt="{{ $contract->company->name }}"
                            class="w-full h-full object-contain"
                            loading="lazy"
                            onerror="this.onerror=null; this.src='https://placehold.co/80x64?text=logo'"
                        >
                    </div>
                @else
                    <div class="w-20 h-16 bg-white/20 rounded-xl flex items-center justify-center flex-shrink-0">
                        <span class="text-white text-sm font-bold">{{ substr($contract->company?->name ?? 'N/A', 0, 3) }}</span>
                    </div>
                @endif
                <div>
                    <h3 class="text-2xl font-extrabold text-white mb-1">
                        {{ $contract->name }}
                    </h3>
                    <p class="text-coral-100 text-lg">
                        {{ $contract->company?->name }}
                    </p>
                </div>
            </div>

            {{-- Pricing Section --}}
            <div class="flex flex-wrap lg:flex-nowrap items-center gap-6">
                @if ($isSpotContract)
                    <div class="text-left">
                        <div class="text-2xl font-extrabold text-white tabular-nums">
                            {{ number_format($spotMargin ?? 0, 2, ',', ' ') }} <span class="text-lg font-normal text-coral-100">c/kWh</span>
                        </div>
                        <p class="text-sm text-coral-100 uppercase tracking-wide">Marginaali</p>
                    </div>
                    @if ($spotTotalEnergyPrice !== null)
                        <div class="text-left">
                            <div class="text-2xl font-extrabold text-white tabular-nums">
                                {{ number_format($spotTotalEnergyPrice, 2, ',', ' ') }} <span class="text-lg font-normal text-coral-100">c/kWh</span>
                            </div>
                            <p class="text-sm text-coral-100 uppercase tracking-wide">Energia <span class="normal-case">(arvio)</span></p>
                        </div>
                    @endif
                @elseif ($generalPrice !== null)
                    @if ($generalPrice == 0 && $contract->consumption_limitation_max_x_kwh_per_y > 0)
                        {{-- Bundled consumption contract (fixed fee includes energy up to a limit) --}}
                        <div class="text-left">
                            <div class="text-2xl font-extrabold text-white tabular-nums">
                                Sis. maksuun
                            </div>
                            <p class="text-sm text-coral-100 uppercase tracking-wide">max {{ number_format($contract->consumption_limitation_max_x_kwh_per_y, 0, ',', ' ') }} kWh/v</p>
                        </div>
                    @elseif ($generalPrice > 0)
                        <div class="text-left">
                            <div class="text-2xl font-extrabold text-white tabular-nums">
                                {{ number_format($generalPrice, 2, ',', ' ') }} <span class="text-lg font-normal text-coral-100">c/kWh</span>
                            </div>
                            <p class="text-sm text-coral-100 uppercase tracking-wide">Energia</p>
                        </div>
                    @endif
                @endif

                <div class="text-left">
                    <div class="text-2xl font-extrabold text-white tabular-nums">
                        {{ number_format($monthlyFee, 2, ',', ' ') }} <span class="text-lg font-normal text-coral-100">{{ "\u{20AC}" }}/kk</span>
                    </div>
                    <p class="text-sm text-coral-100 uppercase tracking-wide">Perusmaksu</p>
                </div>

                @if ($totalCost !== null)
                    <div class="text-left lg:border-l lg:border-white/20 lg:pl-6">
                        <div class="text-3xl font-black text-white tabular-nums">
                            {{ number_format($totalCost, 0, ',', ' ') }} <span class="text-lg font-normal text-coral-100">{{ "\u{20AC}" }}/v</span>
                        </div>
                        <p class="text-sm text-coral-100 uppercase tracking-wide">
                            Vuosikustannus
                            @if ($isSpotContract)
                                <span class="normal-case">(arvio)</span>
                            @endif
                        </p>
                    </div>
                @endif
            </div>
        </div>

        {{-- Bottom Section: Badges and CTA --}}
        <div class="flex flex-wrap items-center justify-between gap-4 mt-6 pt-6 border-t border-white/20">
            <div class="flex flex-wrap items-center gap-2">
                {{-- CO2 Emissions Badge --}}
                @if ($consumption !== null)
                    @if ($isZeroEmission)
                        <span class="inline-flex items-center gap-2 px-3 py-1.5 bg-white/20 backdrop-blur-sm text-white text-xs font-bold rounded-lg">
                            <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 24 24">
                                <path d="M17 8C8 10 5.9 16.17 3.82 21.34l1.89.66.95-2.3c.48.17.98.3 1.34.3C19 20 22 3 22 3c-1 2-8 2.25-13 3.25S2 11.5 2 13.5s1.75 3.75 1.75 3.75C7 8 17 8 17 8z"/>
                            </svg>
                            <span>0 kg CO2/v</span>
                        </span>
                    @else
                        <span class="inline-flex items-center gap-2 px-3 py-1.5 bg-white/20 backdrop-blur-sm text-white text-xs font-bold rounded-lg">
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 15a4 4 0 004 4h9a5 5 0 10-.1-9.999 5.002 5.002 0 10-9.78 2.096A4.001 4.001 0 003 15z"></path>
                            </svg>
                            <span>{{ number_format($annualEmissionsKg, 0, ',', ' ') }} kg CO2/v</span>
                        </span>
                    @endif
                @endif

                {{-- Energy Source Badges --}}
                @if ($source)
                    @if ($source->renewable_total && $source->renewable_total > 0)
                        <span class="inline-block px-3 py-1.5 bg-white/20 backdrop-blur-sm text-white text-xs font-bold rounded-lg uppercase">
                            Uusiutuva {{ number_format($source->renewable_total, 0) }}%
                        </span>
                    @endif
                    @if ($source->nuclear_total && $source->nuclear_total > 0)
                        <span class="inline-block px-3 py-1.5 bg-white/20 backdrop-blur-sm text-white text-xs font-bold rounded-lg uppercase">
                            Ydinvoima {{ number_format($source->nuclear_total, 0) }}%
                        </span>
                    @endif
                @endif

                {{-- Spot Contract Badge --}}
                @if ($isSpotContract)
                    <span class="inline-block px-3 py-1.5 bg-white/20 backdrop-blur-sm text-white text-xs font-bold rounded-lg uppercase">
                        Pörssi
                    </span>
                @endif
            </div>

            {{-- CTA Button --}}
            <a
                href="{{ route('contract.detail', $contract->id) }}"
                class="inline-flex items-center gap-2 bg-white hover:bg-coral-50 text-coral-600 font-bold px-6 py-3 rounded-xl transition-all shadow-lg"
            >
                Katso sopimus
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 8l4 4m0 0l-4 4m4-4H3"></path>
                </svg>
            </a>
        </div>
    </div>
</div>
