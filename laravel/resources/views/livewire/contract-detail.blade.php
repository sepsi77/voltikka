<div>
    {{-- Schema.org structured data --}}
    <x-schema-markup :schemas="$schemas" />

    @php
        use App\Support\ContractLabels;

        $rank = $liveRank ?? $this->priceRank;
        $totalContracts = $liveTotalContracts ?? $this->totalContracts;
        $companyName = $contract->company?->name ?? '';
        $primaryCtaUrl = $contract->order_link ?: $contract->product_link;
        $secondaryCtaUrl = ($contract->order_link && $contract->product_link) ? $contract->product_link : null;

        $emissionFactor = $co2Emissions['emission_factor_g_per_kwh'] ?? null;
        $annualEmissionsKg = $co2Emissions['total_emissions_kg'] ?? null;
        // Average Finnish car fleet: ~140 gCO2/km (Traficom/Sitra)
        $heroDrivingKm = ($annualEmissionsKg && $annualEmissionsKg > 0) ? round($annualEmissionsKg * 1000 / 140) : 0;
        $heroGaugePercent = $emissionFactor !== null ? min(100, ($emissionFactor / 400) * 100) : 0;
        $heroCo2SeverityLabel = null;
        $heroCo2SeverityClass = 'bg-slate-500/15 text-slate-300 border-slate-500/30';
        if ($emissionFactor !== null) {
            if ($emissionFactor == 0) {
                $heroCo2SeverityLabel = 'Päästötön';
                $heroCo2SeverityClass = 'bg-emerald-500/15 text-emerald-300 border-emerald-500/30';
            } elseif ($emissionFactor < 50) {
                $heroCo2SeverityLabel = 'Matala';
                $heroCo2SeverityClass = 'bg-lime-500/15 text-lime-300 border-lime-500/30';
            } elseif ($emissionFactor < 200) {
                $heroCo2SeverityLabel = 'Keskitaso';
                $heroCo2SeverityClass = 'bg-amber-500/15 text-amber-300 border-amber-500/30';
            } else {
                $heroCo2SeverityLabel = 'Korkea';
                $heroCo2SeverityClass = 'bg-red-500/15 text-red-300 border-red-500/30';
            }
        }
    @endphp

    <!-- Hero Section - Dark slate background -->
    <section class="bg-slate-950 mb-6 text-white">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8 lg:py-10">
            <!-- Back Link -->
            <a href="/sahkosopimus" class="inline-flex items-center text-slate-300 hover:text-white text-sm mb-6">
                <svg class="w-4 h-4 mr-1.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"></path>
                </svg>
                Takaisin sopimuksiin
            </a>

            <!-- Title row -->
            <div class="flex items-start gap-4 mb-8">
                @if ($contract->company?->getLogoUrl())
                    <div class="bg-white rounded-xl flex-shrink-0 h-16 w-16 p-2 flex items-center justify-center">
                        <img
                            src="{{ $contract->company->getLogoUrl() }}"
                            alt="{{ $contract->company->name }}"
                            class="h-full w-full object-contain"
                            loading="lazy"
                        >
                    </div>
                @else
                    <div class="h-16 w-16 bg-slate-800 rounded-xl flex items-center justify-center flex-shrink-0 border border-slate-700">
                        <span class="text-slate-200 text-lg font-bold">{{ mb_substr($companyName ?: 'N/A', 0, 2) }}</span>
                    </div>
                @endif
                <div class="min-w-0 flex-1">
                    <h1 class="text-2xl md:text-3xl font-bold text-white leading-tight">{{ $contract->name }}</h1>
                    <p class="text-slate-200 text-base mt-1">{{ $companyName }}</p>
                    <div class="flex flex-wrap gap-2 mt-3">
                        @if ($contract->contract_type)
                            <span class="px-2.5 py-1 rounded-md text-xs font-medium bg-white/10 text-slate-100 border border-white/20">
                                {{ ContractLabels::contractType($contract->contract_type) }}
                            </span>
                        @endif
                        @if ($contract->metering)
                            <span class="px-2.5 py-1 rounded-md text-xs font-medium bg-white/10 text-slate-100 border border-white/20">
                                {{ ContractLabels::metering($contract->metering) }}
                            </span>
                        @endif
                        @if ($contract->pricing_model && $contract->pricing_model !== 'FixedPrice')
                            <span class="px-2.5 py-1 rounded-md text-xs font-medium bg-white/10 text-slate-100 border border-white/20">
                                {{ ContractLabels::pricingModel($contract->pricing_model) }}
                            </span>
                        @endif
                        @if ($contract->fixed_time_range)
                            <span class="px-2.5 py-1 rounded-md text-xs font-medium bg-white/10 text-slate-100 border border-white/20">
                                {{ ContractLabels::fixedTimeRange($contract->fixed_time_range) }}
                            </span>
                        @endif
                    </div>
                </div>
            </div>

            <!-- Hero body: cost block  |  sustainability block -->
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-x-16 gap-y-10 lg:divide-x lg:divide-white/10">
                <!-- Left two-thirds: rank + cost + CTA -->
                <div class="lg:col-span-2 lg:pr-16">
                    @if ($rank && $totalContracts)
                        <div class="mb-6 flex flex-wrap items-center gap-x-6 gap-y-3">
                            <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-sm font-semibold bg-amber-500/20 text-amber-200 border border-amber-400/40 whitespace-nowrap">
                                <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20">
                                    <path d="M10 15l-5.878 3.09 1.123-6.545L.49 6.91l6.572-.955L10 0l2.939 5.955 6.572.955-4.755 4.635 1.123 6.545z"/>
                                </svg>
                                #{{ $rank }} halvin · {{ number_format($totalContracts, 0, ',', ' ') }} sopimuksesta
                            </span>
                            @if ($rank > 1)
                                <a href="/sahkosopimus" class="text-sm font-medium text-coral-300 hover:text-coral-200 underline underline-offset-2">
                                    Katso {{ $rank - 1 }} halvempaa sopimusta →
                                </a>
                            @endif
                        </div>
                    @endif

                    <div
                        class="flex flex-wrap items-baseline gap-x-5 gap-y-2 transition-opacity duration-150"
                        wire:loading.class.delay="opacity-40"
                        wire:target="setConsumption"
                    >
                        <div class="text-5xl sm:text-6xl md:text-7xl font-extrabold text-white tracking-tight leading-none">
                            {{ number_format($calculatedCost['total_cost'] ?? 0, 0, ',', ' ') }} €
                        </div>
                        <div class="text-base text-slate-200">
                            vuodessa · ≈ {{ number_format(($calculatedCost['total_cost'] ?? 0) / 12, 0, ',', ' ') }} €/kk · {{ number_format($consumption, 0, ',', ' ') }} kWh vuosikulutuksella
                        </div>
                    </div>

                    @if ($primaryCtaUrl)
                        <div class="mt-10">
                            <div class="flex flex-wrap items-center gap-x-8 gap-y-4">
                                <a
                                    href="{{ $primaryCtaUrl }}"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    @click="$track('Contract Order Clicked', {
                                        contract_id: {{ $contract->id }},
                                        company: '{{ addslashes($companyName) }}',
                                        pricing_model: '{{ $contract->pricing_model }}'
                                    })"
                                    class="inline-flex items-center justify-center gap-3 px-10 py-5 rounded-xl font-bold text-lg text-white bg-gradient-to-r from-coral-500 to-coral-600 hover:from-coral-400 hover:to-coral-500 shadow-coral transition-all"
                                >
                                    Siirry myyjän sivuille
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"></path>
                                    </svg>
                                </a>
                                @if ($secondaryCtaUrl)
                                    <a
                                        href="{{ $secondaryCtaUrl }}"
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        @click="$track('Contract Info Clicked', {
                                            contract_id: {{ $contract->id }},
                                            company: '{{ addslashes($companyName) }}'
                                        })"
                                        class="text-sm font-medium text-slate-200 hover:text-white underline underline-offset-2"
                                    >
                                        Lisätietoja sopimuksesta →
                                    </a>
                                @endif
                            </div>
                            <div class="mt-3 text-sm text-slate-400">Tilaus tehdään suoraan sähköyhtiön sivuilla</div>
                        </div>
                    @endif
                </div>

                <!-- Right third: sustainability -->
                @if ($emissionFactor !== null)
                    <aside class="pt-8 border-t border-white/10 lg:pt-0 lg:border-t-0 lg:pl-16">
                        <div class="flex items-center gap-2 mb-3">
                            <h3 class="text-sm font-semibold text-slate-300 uppercase tracking-wider">Ympäristövaikutus</h3>
                            @if ($heroCo2SeverityLabel)
                                <span class="text-[11px] uppercase tracking-wider px-2 py-0.5 rounded border font-semibold {{ $heroCo2SeverityClass }}">{{ $heroCo2SeverityLabel }}</span>
                            @endif
                        </div>

                        <div class="flex items-baseline gap-2">
                            <div class="text-5xl font-extrabold text-white tracking-tight leading-none">
                                {{ number_format($annualEmissionsKg ?? 0, 0, ',', ' ') }}
                            </div>
                            <div class="text-slate-200 text-base">kg CO₂/vuosi</div>
                        </div>

                        @if ($heroDrivingKm > 0)
                            <div class="mt-3 flex items-center gap-2 text-base text-slate-200">
                                <svg class="w-5 h-5 text-slate-400 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 17h8m-8 0a2 2 0 11-4 0 2 2 0 014 0zm12 0a2 2 0 11-4 0 2 2 0 014 0zM3 13l2-7h14l2 7M3 13v4h18v-4M3 13h18"/>
                                </svg>
                                <span>Vastaa <span class="font-semibold text-white">{{ number_format($heroDrivingKm, 0, ',', ' ') }} km</span> henkilöautolla</span>
                            </div>
                        @endif

                        <a href="/sahkosopimus/fossiiliton" class="mt-5 inline-flex items-center text-sm font-medium text-coral-300 hover:text-coral-200">
                            Katso päästöttömät sopimukset →
                        </a>
                    </aside>
                @endif
            </div>
        </div>
    </section>

    <!-- Consumption picker (moved out of hero) -->
    <section id="consumption-picker" class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 pt-2 pb-4 scroll-mt-20">
        <div class="bg-white rounded-xl border border-slate-200 p-4 flex flex-wrap items-center gap-x-5 gap-y-3">
            <span class="text-sm font-semibold text-slate-700 whitespace-nowrap">Hinta kulutuksella:</span>
            <div class="flex flex-wrap gap-2">
                @foreach ($presets as $label => $value)
                    <button
                        wire:click="setConsumption({{ $value }})"
                        wire:loading.attr="disabled"
                        wire:target="setConsumption"
                        class="relative px-3 py-1.5 rounded-lg text-sm font-medium transition border disabled:cursor-wait {{ $consumption === $value ? 'bg-coral-500 text-white border-coral-500 shadow-sm' : 'bg-white text-slate-700 border-slate-200 hover:bg-slate-50' }}"
                    >
                        <span wire:loading.delay.remove wire:target="setConsumption({{ $value }})">
                            {{ $label }} · {{ number_format($value, 0, ',', ' ') }} kWh
                        </span>
                        <span wire:loading.delay wire:target="setConsumption({{ $value }})" class="inline-flex items-center gap-2">
                            <svg class="w-3.5 h-3.5 animate-spin" viewBox="0 0 24 24" fill="none">
                                <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-opacity="0.25" stroke-width="3"/>
                                <path d="M22 12a10 10 0 0 1-10 10" stroke="currentColor" stroke-width="3" stroke-linecap="round"/>
                            </svg>
                            Päivitetään…
                        </span>
                    </button>
                @endforeach
            </div>
        </div>
    </section>

    {{-- Inactive contract banner --}}
    @if(!$this->isActive)
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 pt-8">
        <div class="bg-amber-50 border-l-4 border-amber-400 p-4 rounded-r-lg">
            <div class="flex items-center">
                <svg class="w-5 h-5 text-amber-400 mr-3 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path>
                </svg>
                <p class="text-sm text-amber-700">
                    Tämä sopimus ei ole enää tarjolla.
                </p>
            </div>
        </div>
    </div>
    @endif

    <!-- Cheaper alternatives row -->
    @if ($cheaperContracts->isNotEmpty())
        <section id="halvemmat" class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 pt-2 pb-6">
            <div class="flex items-end justify-between mb-4 gap-4">
                <div>
                    <h2 class="text-xl font-bold text-slate-900">
                        @if ($rank && $rank > 1)
                            {{ $rank - 1 }} halvempaa vaihtoehtoa
                        @else
                            Halvemmat vaihtoehdot
                        @endif
                    </h2>
                    <p class="text-sm text-slate-500">{{ number_format($consumption, 0, ',', ' ') }} kWh vuosikulutuksella</p>
                </div>
                <a href="/sahkosopimus" class="hidden sm:inline text-sm font-semibold text-coral-600 hover:text-coral-700 whitespace-nowrap">Vertaa kaikkia →</a>
            </div>

            <div
                class="grid grid-cols-2 md:grid-cols-4 gap-3 md:gap-4 transition-opacity duration-150"
                wire:loading.class.delay="opacity-40"
                wire:target="setConsumption"
            >
                @foreach ($cheaperContracts as $alt)
                    @php
                        $altContract = $alt['contract'];
                        $altCompany = $altContract->company;
                        $altLogoUrl = $altCompany?->getLogoUrl();
                        $altInitials = mb_substr($altCompany?->name ?: $altContract->name, 0, 1);
                    @endphp
                    <a
                        href="{{ route('contract.detail', ['contractId' => $altContract->id]) }}"
                        class="bg-white rounded-xl border border-slate-200 hover:border-coral-300 hover:shadow-md transition p-4 flex flex-col min-w-0"
                    >
                        <div class="flex items-center gap-2 mb-2 min-w-0">
                            @if ($altLogoUrl)
                                <div class="h-8 w-8 flex-shrink-0 rounded bg-white flex items-center justify-center overflow-hidden">
                                    <img src="{{ $altLogoUrl }}" alt="{{ $altCompany?->name }}" class="h-full w-full object-contain" loading="lazy">
                                </div>
                            @else
                                <div class="h-8 w-8 flex-shrink-0 bg-slate-100 rounded flex items-center justify-center text-xs font-bold text-slate-600">
                                    {{ $altInitials }}
                                </div>
                            @endif
                            <span class="text-xs text-slate-500 truncate flex-1">{{ $altCompany?->name }}</span>
                            <span class="text-[10px] font-semibold {{ $alt['rank'] <= 3 ? 'text-amber-600' : 'text-slate-500' }} flex-shrink-0">#{{ $alt['rank'] }}</span>
                        </div>
                        <div class="font-semibold text-slate-900 text-sm leading-snug overflow-hidden" style="display:-webkit-box;-webkit-line-clamp:3;-webkit-box-orient:vertical;">
                            {{ $altContract->name }}
                        </div>
                        <div class="mt-auto pt-4">
                            <div class="flex items-baseline gap-1">
                                <span class="text-2xl font-extrabold text-slate-900">
                                    {{ number_format($alt['total_cost'], 0, ',', ' ') }} €
                                </span>
                                <span class="text-xs text-slate-500">/v</span>
                            </div>
                            <div class="mt-1 inline-block text-xs font-semibold px-2 py-0.5 rounded {{ $alt['savings'] > 0 ? 'bg-emerald-50 text-emerald-700' : 'invisible' }}">
                                Säästä {{ number_format(max($alt['savings'], 0), 0, ',', ' ') }} €
                            </div>
                        </div>
                    </a>
                @endforeach
            </div>

            <a href="/sahkosopimus" class="sm:hidden mt-4 block text-center text-sm font-semibold text-coral-600">Vertaa kaikkia sopimuksia →</a>
        </section>
    @endif

    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- Left Column: Pricing & Cost Calculator -->
        <div class="lg:col-span-2 space-y-6">
            <!-- Price Breakdown -->
            <div class="bg-white rounded-2xl shadow-sm border border-slate-100 p-6">
                <h2 class="text-lg font-semibold text-slate-900 mb-4">Hintatiedot</h2>

                {{-- Promotion/Discount Info Banner --}}
                @if ($contract->hasActiveDiscounts())
                    @php
                        $discountInfo = $contract->getActiveDiscountInfo();
                    @endphp
                    <div class="mb-4 p-4 bg-gradient-to-r from-amber-50 to-yellow-50 border border-amber-200 rounded-xl">
                        <div class="flex items-start gap-3">
                            <div class="flex-shrink-0">
                                <svg class="w-5 h-5 text-amber-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z"/>
                                </svg>
                            </div>
                            <div class="flex-1">
                                <p class="font-semibold text-amber-800">
                                    @if ($discountInfo && $discountInfo['n_first_months'])
                                        Tarjous: {{ $discountInfo['n_first_months'] }} ensimmäistä kuukautta
                                    @else
                                        Tarjoussopimus
                                    @endif
                                </p>
                                <p class="text-sm text-amber-700 mt-1">
                                    @if ($discountInfo)
                                        @if ($discountInfo['value'] && $discountInfo['is_percentage'])
                                            -{{ number_format($discountInfo['value'], 0) }}% alennus
                                        @elseif ($discountInfo['value'])
                                            -{{ number_format($discountInfo['value'], 2, ',', ' ') }} c/kWh alennus
                                        @endif
                                        @if ($discountInfo['until_date'])
                                            <span class="ml-2 text-amber-600">
                                                Voimassa {{ $discountInfo['until_date']->format('d.m.Y') }} asti
                                            </span>
                                        @endif
                                    @else
                                        Tällä sopimuksella on voimassa oleva tarjous.
                                    @endif
                                </p>
                            </div>
                        </div>
                    </div>
                @endif

                @if ($calculatedCost['is_spot_contract'] ?? false)
                    {{-- Spot contract pricing --}}
                    <div class="space-y-4">
                        {{-- Spot price info banner --}}
                        <div class="p-3 bg-coral-50 border border-coral-200 rounded-xl text-sm text-coral-800 mb-4">
                            <div class="flex items-start gap-2">
                                <svg class="w-5 h-5 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                </svg>
                                <span>Pörssisähkösopimuksessa energian hinta vaihtelee tunneittain. Alla oleva arvio perustuu 365 päivän keskihintaan.</span>
                            </div>
                        </div>

                        {{-- Margin (company's markup) --}}
                        <div class="flex justify-between items-center py-3 border-b border-slate-100">
                            <div>
                                <span class="text-slate-600">Marginaali</span>
                                <span class="text-sm text-slate-400 ml-2">(yhtiön lisä)</span>
                            </div>
                            <span class="text-xl font-semibold text-slate-900">{{ number_format($calculatedCost['spot_price_margin'] ?? 0, 2, ',', ' ') }} c/kWh</span>
                        </div>

                        {{-- Spot price averages --}}
                        @if (isset($calculatedCost['spot_price_day_avg']) && isset($calculatedCost['spot_price_night_avg']))
                            <div class="flex justify-between items-center py-3 border-b border-slate-100">
                                <div>
                                    <span class="text-slate-600">Spot-hinta päivä</span>
                                    <span class="text-sm text-slate-400 ml-2">(365pv ka.)</span>
                                </div>
                                <span class="text-lg font-medium text-slate-700">{{ number_format($calculatedCost['spot_price_day_avg'], 2, ',', ' ') }} c/kWh</span>
                            </div>
                            <div class="flex justify-between items-center py-3 border-b border-slate-100">
                                <div>
                                    <span class="text-slate-600">Spot-hinta yö</span>
                                    <span class="text-sm text-slate-400 ml-2">(365pv ka.)</span>
                                </div>
                                <span class="text-lg font-medium text-slate-700">{{ number_format($calculatedCost['spot_price_night_avg'], 2, ',', ' ') }} c/kWh</span>
                            </div>
                        @endif

                        {{-- Total energy price (spot + margin) --}}
                        @php
                            $margin = $calculatedCost['spot_price_margin'] ?? 0;
                            $spotDay = $calculatedCost['spot_price_day_avg'] ?? 0;
                            $spotNight = $calculatedCost['spot_price_night_avg'] ?? 0;
                            $totalDayPrice = $spotDay + $margin;
                            $totalNightPrice = $spotNight + $margin;
                            // Weighted average: 85% day, 15% night (typical household)
                            $avgTotalPrice = ($totalDayPrice * 0.85) + ($totalNightPrice * 0.15);
                        @endphp
                        <div class="flex justify-between items-center py-3 border-b border-slate-100 bg-slate-50 -mx-6 px-6">
                            <div>
                                <span class="text-slate-900 font-medium">Energiahinta (arvio)</span>
                                <span class="text-sm text-slate-500 ml-2">(spot + marginaali)</span>
                            </div>
                            <span class="text-xl font-bold text-coral-600">{{ number_format($avgTotalPrice, 2, ',', ' ') }} c/kWh</span>
                        </div>

                        {{-- Monthly fee --}}
                        @if (isset($latestPrices['Monthly']))
                            <div class="flex justify-between items-center py-3 border-b border-slate-100">
                                <span class="text-slate-600">Perusmaksu</span>
                                <span class="text-xl font-semibold text-slate-900">{{ number_format($latestPrices['Monthly']['price'], 2, ',', ' ') }} EUR/kk</span>
                            </div>
                        @endif
                    </div>
                @elseif ($contract->metering === 'General')
                    <!-- General metering (non-spot) -->
                    <div class="space-y-4">
                        @if (isset($latestPrices['General']))
                            <div class="flex justify-between items-center py-3 border-b border-slate-100">
                                <span class="text-slate-600">Energiahinta</span>
                                <span class="text-xl font-semibold text-slate-900">{{ number_format($latestPrices['General']['price'], 2, ',', ' ') }} c/kWh</span>
                            </div>
                        @endif
                        @if (isset($latestPrices['Monthly']))
                            <div class="flex justify-between items-center py-3 border-b border-slate-100">
                                <span class="text-slate-600">Perusmaksu</span>
                                <span class="text-xl font-semibold text-slate-900">{{ number_format($latestPrices['Monthly']['price'], 2, ',', ' ') }} EUR/kk</span>
                            </div>
                        @endif
                    </div>
                @elseif ($contract->metering === 'Time')
                    <!-- Time-based metering -->
                    <div class="space-y-4">
                        @if (isset($latestPrices['DayTime']))
                            <div class="flex justify-between items-center py-3 border-b border-slate-100">
                                <div>
                                    <span class="text-slate-600">Päiväsähkö</span>
                                    <span class="text-sm text-slate-400 ml-2">(07:00-22:00)</span>
                                </div>
                                <span class="text-xl font-semibold text-slate-900">{{ number_format($latestPrices['DayTime']['price'], 2, ',', ' ') }} c/kWh</span>
                            </div>
                        @endif
                        @if (isset($latestPrices['NightTime']))
                            <div class="flex justify-between items-center py-3 border-b border-slate-100">
                                <div>
                                    <span class="text-slate-600">Yösähkö</span>
                                    <span class="text-sm text-slate-400 ml-2">(22:00-07:00)</span>
                                </div>
                                <span class="text-xl font-semibold text-slate-900">{{ number_format($latestPrices['NightTime']['price'], 2, ',', ' ') }} c/kWh</span>
                            </div>
                        @endif
                        @if (isset($latestPrices['Monthly']))
                            <div class="flex justify-between items-center py-3 border-b border-slate-100">
                                <span class="text-slate-600">Perusmaksu</span>
                                <span class="text-xl font-semibold text-slate-900">{{ number_format($latestPrices['Monthly']['price'], 2, ',', ' ') }} EUR/kk</span>
                            </div>
                        @endif
                    </div>
                @elseif ($contract->metering === 'Season')
                    <!-- Seasonal metering -->
                    <div class="space-y-4">
                        @if (isset($latestPrices['SeasonalWinterDay']))
                            <div class="flex justify-between items-center py-3 border-b border-slate-100">
                                <div>
                                    <span class="text-slate-600">Talvi</span>
                                    <span class="text-sm text-slate-400 ml-2">(marras-maaliskuu, päivä)</span>
                                </div>
                                <span class="text-xl font-semibold text-slate-900">{{ number_format($latestPrices['SeasonalWinterDay']['price'], 2, ',', ' ') }} c/kWh</span>
                            </div>
                        @endif
                        @if (isset($latestPrices['SeasonalOther']))
                            <div class="flex justify-between items-center py-3 border-b border-slate-100">
                                <div>
                                    <span class="text-slate-600">Muu aika</span>
                                    <span class="text-sm text-slate-400 ml-2">(muut ajat)</span>
                                </div>
                                <span class="text-xl font-semibold text-slate-900">{{ number_format($latestPrices['SeasonalOther']['price'], 2, ',', ' ') }} c/kWh</span>
                            </div>
                        @endif
                        @if (isset($latestPrices['Monthly']))
                            <div class="flex justify-between items-center py-3 border-b border-slate-100">
                                <span class="text-slate-600">Perusmaksu</span>
                                <span class="text-xl font-semibold text-slate-900">{{ number_format($latestPrices['Monthly']['price'], 2, ',', ' ') }} EUR/kk</span>
                            </div>
                        @endif
                    </div>
                @endif
            </div>

            <!-- Contract history / price development -->
            @if (count($priceHistory) > 0 || count($contractHistory) > 1)
                @php
                    $priceTypeLabels = [
                        'General' => 'Energiahinta',
                        'Monthly' => 'Perusmaksu',
                        'DayTime' => 'Päiväsähkö (07:00-22:00)',
                        'NightTime' => 'Yösähkö (22:00-07:00)',
                        'SeasonalWinterDay' => 'Talvihinta (marras-maaliskuu, päivä)',
                        'SeasonalOther' => 'Muu aika',
                    ];

                    // De-dupe merged chain history: keep only real price changes.
                    $dedupedHistory = [];
                    foreach ($priceHistory as $type => $history) {
                        $sorted = collect($history)->sortBy('date')->values();
                        $previous = null;
                        $rows = [];
                        foreach ($sorted as $record) {
                            if ($previous === null || (float) $record['price'] !== (float) $previous['price']) {
                                $rows[] = $record;
                            }
                            $previous = $record;
                        }
                        if (count($rows) >= 1) {
                            $dedupedHistory[$type] = array_reverse($rows);
                        }
                    }

                    $changes = $priceChangeInfo['changes'];
                    $since = $priceChangeInfo['since'];
                    $latestChange = $priceChangeInfo['latest'];
                @endphp
                <div class="bg-white rounded-2xl shadow-sm border border-slate-100 p-6">
                    <div class="flex items-start justify-between gap-4 flex-wrap">
                        <div class="min-w-0">
                            <h2 class="text-lg font-semibold text-slate-900">Sopimushistoria</h2>
                            <p class="text-sm text-slate-500 mt-1 flex flex-wrap items-center gap-x-1.5 gap-y-0.5">
                                <span>Nykyinen sopimus ja aiemmat tunnetut versiot samasta replacement-ketjusta.</span>
                                @if ($changes === 0)
                                    <span class="inline-block w-2 h-2 rounded-full bg-emerald-500"></span>
                                    <span>Hinta ei ole muuttunut seurannan aikana.</span>
                                @else
                                    <span class="inline-block w-2 h-2 rounded-full bg-amber-500"></span>
                                    <span>
                                        Hinta on muuttunut
                                        <span class="font-semibold text-slate-700">{{ $changes }} {{ $changes === 1 ? 'kerran' : 'kertaa' }}</span>
                                        seurannan aikana.
                                    </span>
                                    @if ($latestChange)
                                        <span class="text-slate-400">
                                            Viimeisin: {{ $priceTypeLabels[$latestChange['type']] ?? $latestChange['type'] }}
                                            {{ number_format($latestChange['from'], 2, ',', ' ') }} → {{ number_format($latestChange['to'], 2, ',', ' ') }}
                                            @if (!empty($latestChange['contract_name']))
                                                · {{ $latestChange['contract_name'] }}
                                            @endif
                                            ({{ $latestChange['date']->format('d.m.Y') }})
                                        </span>
                                    @endif
                                @endif
                                @if ($since)
                                    <span class="text-slate-400">· Seurattu {{ $since->format('d.m.Y') }} alkaen</span>
                                @endif
                            </p>
                        </div>
                    </div>

                    <div class="mt-6 space-y-4">
                        @foreach ($contractHistory as $historyEntry)
                            <article class="rounded-xl border border-slate-200 bg-slate-50/70 p-4">
                                <div class="flex flex-wrap items-start justify-between gap-3">
                                    <div>
                                        <div class="flex flex-wrap items-center gap-2">
                                            <h3 class="text-base font-semibold text-slate-900">{{ $historyEntry['name'] }}</h3>
                                            @if ($historyEntry['is_current'])
                                                <span class="inline-flex items-center rounded-full bg-emerald-100 px-2 py-0.5 text-xs font-semibold text-emerald-700">Nykyinen</span>
                                            @else
                                                <span class="inline-flex items-center rounded-full bg-slate-200 px-2 py-0.5 text-xs font-semibold text-slate-700">Aiempi versio</span>
                                            @endif
                                        </div>
                                        <p class="mt-1 text-sm text-slate-500">
                                            {{ $historyEntry['company'] }}
                                            @if ($historyEntry['latest_price_date'])
                                                · Hinta päivitetty {{ $historyEntry['latest_price_date']->format('d.m.Y') }}
                                            @endif
                                        </p>
                                    </div>
                                </div>

                                <div class="mt-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                                    @forelse ($historyEntry['prices'] as $price)
                                        <div class="rounded-lg border border-slate-200 bg-white px-3 py-2">
                                            <div class="text-xs font-medium uppercase tracking-wide text-slate-500">{{ $price['label'] }}</div>
                                            <div class="mt-1 text-sm font-semibold text-slate-900">{{ number_format($price['price'], 2, ',', ' ') }} {{ $price['unit'] }}</div>
                                        </div>
                                    @empty
                                        <div class="rounded-lg border border-dashed border-slate-200 bg-white px-3 py-2 text-sm text-slate-500">Ei hintatietoja saatavilla.</div>
                                    @endforelse
                                </div>

                                <div class="mt-4 rounded-lg border border-amber-100 bg-amber-50 px-3 py-2">
                                    <div class="text-xs font-medium uppercase tracking-wide text-amber-700">Tarjous</div>
                                    <div class="mt-1 text-sm text-amber-900">
                                        {{ $historyEntry['promotion'] ?: 'Ei tiedossa olevaa tarjousta.' }}
                                    </div>
                                </div>
                            </article>
                        @endforeach
                    </div>

                    @if ($changes > 0)
                        <details class="mt-6 group">
                            <summary class="cursor-pointer inline-flex items-center gap-1.5 text-sm font-semibold text-coral-600 hover:text-coral-700 select-none">
                                <svg class="w-4 h-4 transition-transform group-open:rotate-90" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                                </svg>
                                Näytä hintamuutokset
                            </summary>
                            <div class="mt-4 space-y-4">
                                @foreach ($dedupedHistory as $type => $rows)
                                    @if (count($rows) > 1)
                                        <div>
                                            <h3 class="text-sm font-medium text-slate-700 mb-2">{{ $priceTypeLabels[$type] ?? $type }}</h3>
                                            <div class="overflow-x-auto">
                                                <table class="min-w-full text-sm">
                                                    <thead>
                                                        <tr class="text-left text-slate-500">
                                                            <th class="py-2 pr-4">Sopimus</th>
                                                            <th class="py-2 pr-4">Muutoksen päivä</th>
                                                            <th class="py-2">Hinta</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        @foreach ($rows as $record)
                                                            <tr class="border-t border-slate-100">
                                                                <td class="py-2 pr-4 text-slate-600">{{ $record['contract_name'] ?? '—' }}</td>
                                                                <td class="py-2 pr-4 text-slate-600">{{ \Carbon\Carbon::parse($record['date'])->format('d.m.Y') }}</td>
                                                                <td class="py-2 font-medium text-slate-900">{{ number_format($record['price'], 2, ',', ' ') }} {{ $type === 'Monthly' ? 'EUR/kk' : 'c/kWh' }}</td>
                                                            </tr>
                                                        @endforeach
                                                    </tbody>
                                                </table>
                                            </div>
                                        </div>
                                    @endif
                                @endforeach
                            </div>
                        </details>
                    @endif
                </div>
            @endif

            <!-- Contract Description (moved from top of page) -->
            @if ($contract->extra_information_fi)
                <div class="bg-white rounded-2xl shadow-sm border border-slate-100 p-6">
                    <h2 class="text-lg font-semibold text-slate-900 mb-4">Sopimuksen kuvaus</h2>
                    <div class="prose prose-slate max-w-none prose-a:text-coral-600 hover:prose-a:text-coral-700">
                        {!! $contract->extra_information_fi !!}
                    </div>
                </div>
            @endif

            <!-- Long Description -->
            @if ($contract->long_description)
                <div class="bg-white rounded-2xl shadow-sm border border-slate-100 p-6">
                    <h2 class="text-lg font-semibold text-slate-900 mb-4">Sopimuksen kuvaus</h2>
                    <div class="prose prose-slate max-w-none">
                        <p class="text-slate-700 whitespace-pre-line">{{ $contract->long_description }}</p>
                    </div>
                </div>
            @endif

            <!-- Microproduction Info -->
            @if ($contract->microproduction_buys && $contract->microproduction_default)
                <div class="bg-white rounded-2xl shadow-sm border border-slate-100 p-6">
                    <h2 class="text-lg font-semibold text-slate-900 mb-4">Pientuotanto</h2>
                    <div class="flex items-start gap-3">
                        <div class="flex-shrink-0">
                            <svg class="w-6 h-6 text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                            </svg>
                        </div>
                        <div>
                            <p class="text-slate-700">{{ $contract->microproduction_default }}</p>
                        </div>
                    </div>
                </div>
            @endif
        </div>

        <!-- Right Column: Environmental Impact, Energy Source & Company Info -->
        <div class="space-y-6">
            <!-- CO2 Emissions - Environmental Impact Section -->
            @if (!empty($co2Emissions))
                @php
                    $sourceLabels = [
                        'coal' => 'Kivihiili',
                        'natural_gas' => 'Maakaasu',
                        'oil' => 'Öljy',
                        'peat' => 'Turve',
                        'fossil_generic' => 'Fossiiliset (erittelemätön)',
                        'nuclear' => 'Ydinvoima',
                        'wind' => 'Tuulivoima',
                        'solar' => 'Aurinkovoima',
                        'hydro' => 'Vesivoima',
                        'biomass' => 'Biomassa',
                        'renewable_general' => 'Uusiutuva (erittelemätön)',
                        'renewable_unspecified' => 'Uusiutuva (erittelemätön)',
                        'residual_mix' => 'Jäännösjakauma',
                    ];
                    $emissionFactor = $co2Emissions['emission_factor_g_per_kwh'];
                    $annualEmissionsKg = $co2Emissions['total_emissions_kg'];
                    // Car driving equivalency: average Finnish car fleet emits ~140g CO2/km
                    // (Traficom/Sitra data - reflects actual cars on road, avg age 12-13 years)
                    $drivingKm = $annualEmissionsKg > 0 ? round($annualEmissionsKg * 1000 / 140) : 0;
                    // Horizontal bar calculation: 0-400+ scale, cap at 400 for display
                    $gaugeMax = 400;
                    $gaugePercent = min(100, ($emissionFactor / $gaugeMax) * 100);
                    // Finland benchmarks
                    $physicalAverage = \App\Services\CO2EmissionsCalculator::FINLAND_BENCHMARKS['physical_grid_average']; // 35 gCO₂/kWh
                    $residualMix = \App\Services\CO2EmissionsCalculator::FINLAND_BENCHMARKS['residual_mix']; // 390.93 gCO₂/kWh
                    $physicalAveragePercent = min(100, ($physicalAverage / $gaugeMax) * 100);
                @endphp
                <div id="ymparisto" class="bg-white rounded-2xl shadow-sm border border-slate-100 p-6 scroll-mt-20">
                    <h2 class="text-lg font-semibold text-slate-900 mb-6">Ympäristövaikutus</h2>

                    @if ($emissionFactor == 0)
                        <!-- Zero emissions hero display -->
                        <div class="text-center mb-6">
                            <div class="inline-flex items-center justify-center w-24 h-24 rounded-full bg-gradient-to-br from-green-100 to-emerald-100 mb-4">
                                <svg class="w-12 h-12 text-green-600" fill="currentColor" viewBox="0 0 24 24">
                                    <path d="M17,8C8,10 5.9,16.17 3.82,21.34L5.71,22L6.66,19.7C7.14,19.87 7.64,20 8,20C19,20 22,3 22,3C21,5 14,5.25 9,6.25C4,7.25 2,11.5 2,13.5C2,15.5 3.75,17.25 3.75,17.25C7,8 17,8 17,8Z"/>
                                </svg>
                            </div>
                            <div class="text-4xl font-bold text-green-600 mb-2">0 kg</div>
                            <div class="text-slate-600 mb-1">CO₂-päästöt vuodessa</div>
                            <div class="text-sm text-green-600 font-medium">Päästötön sähkö</div>
                        </div>

                        <div class="bg-green-50 rounded-xl p-4 text-center">
                            <div class="flex items-center justify-center gap-2 text-green-700">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                </svg>
                                <span class="font-medium">Tämän sopimuksen sähköntuotannolla ei ole suoria CO₂-päästöjä</span>
                            </div>
                        </div>
                    @else
                        <!-- Emission factor indicator -->
                        <div class="mb-4">
                            <div class="text-sm text-slate-600 mb-2">Päästökerroin</div>
                            <div class="relative h-6 bg-gradient-to-r from-green-400 via-yellow-400 via-orange-400 to-red-500 rounded-lg overflow-hidden">
                                <!-- This contract marker -->
                                <div class="absolute top-0.5 bottom-0.5 w-2 bg-white border-2 border-slate-800 rounded transition-all duration-500"
                                     style="left: calc({{ $gaugePercent }}% - 4px);">
                                </div>
                            </div>
                            <div class="flex justify-between mt-1 text-xs text-slate-500">
                                <span>0</span>
                                <span class="font-medium {{ $emissionFactor < 100 ? 'text-green-600' : ($emissionFactor < 200 ? 'text-lime-600' : ($emissionFactor < 300 ? 'text-amber-600' : ($emissionFactor < 350 ? 'text-orange-600' : 'text-red-600'))) }}">
                                    {{ number_format($emissionFactor, 0, ',', ' ') }} gCO₂/kWh
                                </span>
                                <span>400+</span>
                            </div>
                        </div>

                        <!-- Annual emissions hero number -->
                        <div class="bg-gradient-to-br from-slate-50 to-slate-100 rounded-xl p-6 text-center mb-4">
                            <div class="text-sm text-slate-500 mb-1">Vuotuiset päästöt ({{ number_format($consumption, 0, ',', ' ') }} kWh)</div>
                            <div class="text-4xl font-bold text-slate-900 mb-3">
                                {{ number_format($annualEmissionsKg, 0, ',', ' ') }} kg
                                <span class="text-lg font-normal text-slate-500">CO₂</span>
                            </div>
                            <div class="flex items-center justify-center gap-2 text-slate-600">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M8.25 18.75a1.5 1.5 0 01-3 0m3 0a1.5 1.5 0 00-3 0m3 0h6m-9 0H3.375a1.125 1.125 0 01-1.125-1.125V14.25m17.25 4.5a1.5 1.5 0 01-3 0m3 0a1.5 1.5 0 00-3 0m3 0h1.125c.621 0 1.129-.504 1.09-1.124a17.902 17.902 0 00-3.213-9.193 2.056 2.056 0 00-1.58-.86H14.25M16.5 18.75h-2.25m0-11.177v-.958c0-.568-.422-1.048-.987-1.106a48.554 48.554 0 00-10.026 0 1.106 1.106 0 00-.987 1.106v7.635m12-6.677v6.677m0 4.5v-4.5m0 0h-12"/>
                                </svg>
                                <span class="text-sm">Vastaa noin <strong>{{ number_format($drivingKm, 0, ',', ' ') }} km</strong> ajoa henkilöautolla</span>
                            </div>
                        </div>

                        <!-- Comparison bar -->
                        <div class="mb-4">
                            <div class="text-sm text-slate-600 mb-2">Vertailu Suomen tuotannon keskiarvoon</div>
                            <div class="relative h-8 bg-gradient-to-r from-green-200 via-yellow-200 to-red-200 rounded-lg overflow-hidden">
                                <!-- Finland physical average marker -->
                                <div class="absolute top-0 bottom-0 w-0.5 bg-green-700 z-10"
                                     style="left: {{ $physicalAveragePercent }}%;">
                                    <div class="absolute -top-6 left-1/2 -translate-x-1/2 whitespace-nowrap text-xs text-green-700 font-medium">
                                        Suomi ~{{ number_format($physicalAverage, 0) }} g
                                    </div>
                                </div>
                                <!-- This contract marker -->
                                <div class="absolute top-1 bottom-1 w-3 rounded {{ $emissionFactor <= $physicalAverage ? 'bg-green-600' : ($emissionFactor < 100 ? 'bg-lime-600' : ($emissionFactor < 200 ? 'bg-amber-500' : 'bg-red-600')) }} z-20 transition-all duration-500"
                                     style="left: calc({{ $gaugePercent }}% - 6px);">
                                </div>
                                <!-- Scale markers -->
                                <div class="absolute bottom-0 left-0 right-0 flex justify-between px-2 text-xs text-slate-500">
                                    <span>0</span>
                                    <span>100</span>
                                    <span>200</span>
                                    <span>300</span>
                                    <span>400+</span>
                                </div>
                            </div>
                            <div class="mt-2 text-sm text-center">
                                @if ($emissionFactor == 0)
                                    <span class="text-green-600 font-medium">Päästötön sähkö – parempi kuin Suomen keskiarvo</span>
                                @elseif ($emissionFactor <= $physicalAverage)
                                    <span class="text-green-600 font-medium">Suomen tuotannon keskiarvoa vastaava tai parempi</span>
                                @elseif ($emissionFactor < 100)
                                    <span class="text-lime-600 font-medium">{{ number_format($emissionFactor - $physicalAverage, 0, ',', ' ') }} gCO₂/kWh suurempi kuin Suomen tuotanto</span>
                                @else
                                    <span class="text-amber-600 font-medium">{{ number_format($emissionFactor - $physicalAverage, 0, ',', ' ') }} gCO₂/kWh suurempi kuin Suomen tuotanto</span>
                                @endif
                            </div>
                        </div>
                    @endif

                    {{-- Explanation of physical vs contractual emissions --}}
                    @if ($co2Emissions['residual_mix_percent'] > 0)
                        <div class="bg-slate-50 rounded-lg p-4 text-sm mt-3 space-y-3">
                            <div class="flex items-start gap-2 text-slate-700">
                                <svg class="w-5 h-5 flex-shrink-0 mt-0.5 text-slate-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                </svg>
                                <div>
                                    <p class="font-medium mb-1">Miksi tämän sopimuksen päästöt ovat korkeat?</p>
                                    <p class="text-slate-600">
                                        {{ number_format($co2Emissions['residual_mix_percent'], 0, ',', ' ') }}% sähkön alkuperästä on erittelemätöntä.
                                        Kun myyjä ei ilmoita sähkön alkuperää, käytetään lain mukaan <strong>jäännösjakaumaa</strong> ({{ number_format($residualMix, 0, ',', ' ') }} gCO₂/kWh).
                                    </p>
                                </div>
                            </div>
                            <div class="border-t border-slate-200 pt-3">
                                <p class="text-slate-600 text-xs">
                                    <strong>Fyysinen vs. sopimuksellinen todellisuus:</strong>
                                    Suomessa tuotetun sähkön todellinen päästökerroin on vain ~{{ number_format($physicalAverage, 0) }} gCO₂/kWh (95% fossiilitonta).
                                    Jäännösjakauma on kuitenkin ~{{ number_format($residualMix, 0) }} gCO₂/kWh, koska puhtaat tuottajat myyvät alkuperätakuunsa erikseen.
                                    Tämän sopimuksen "sopimuksellinen" päästökerroin on siksi {{ round($emissionFactor / $physicalAverage) }}× suurempi kuin verkossa virtaavan sähkön keskiarvo.
                                </p>
                            </div>
                        </div>
                    @elseif ($emissionFactor > 0 && $emissionFactor > $physicalAverage)
                        <div class="bg-blue-50 rounded-lg p-3 text-sm mt-3">
                            <p class="text-blue-700 text-xs">
                                <strong>Huom:</strong> Suomen sähköverkon fyysinen keskipäästö on vain ~{{ number_format($physicalAverage, 0) }} gCO₂/kWh.
                                Tämän sopimuksen päästökerroin ({{ number_format($emissionFactor, 0) }} gCO₂/kWh) perustuu ilmoitettuihin energialähteisiin.
                            </p>
                        </div>
                    @endif

                    <!-- Expandable Details -->
                    <details class="mt-6 border-t border-slate-100 pt-4">
                        <summary class="cursor-pointer text-sm font-medium text-coral-600 hover:text-coral-700 select-none flex items-center gap-1">
                            <svg class="w-4 h-4 transition-transform details-chevron" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                            </svg>
                            Näytä laskennan yksityiskohdat
                        </summary>

                        <div class="mt-4 space-y-4">
                            <!-- Emissions by source -->
                            <div class="bg-slate-50 rounded-lg p-4">
                                <h4 class="text-sm font-medium text-slate-700 mb-3">Päästöt energialähteittäin</h4>
                                <div class="space-y-2">
                                    @foreach ($co2Emissions['emissions_by_source'] as $source => $emissionsKg)
                                        <div class="flex justify-between items-center py-2 border-b border-slate-200 last:border-0">
                                            <span class="text-sm text-slate-600">{{ $sourceLabels[$source] ?? $source }}</span>
                                            <span class="text-sm font-medium {{ $emissionsKg > 0 ? 'text-slate-900' : 'text-green-600' }}">
                                                {{ number_format($emissionsKg, 1, ',', ' ') }} kg CO₂
                                            </span>
                                        </div>
                                    @endforeach
                                </div>
                            </div>

                            <!-- Emission factors used -->
                            <div class="bg-slate-50 rounded-lg p-4">
                                <h4 class="text-sm font-medium text-slate-700 mb-3">Käytetyt päästökertoimet</h4>
                                <div class="space-y-2">
                                    @foreach ($co2Emissions['emissions_by_source'] as $source => $emissionsKg)
                                        @if (isset($emissionFactorSources[$source]))
                                            <div class="flex justify-between items-start py-2 border-b border-slate-200 last:border-0">
                                                <div>
                                                    <span class="text-sm text-slate-600">{{ $sourceLabels[$source] ?? $source }}</span>
                                                    <span class="text-xs text-slate-400 ml-1">({{ $emissionFactorSources[$source]['source'] }})</span>
                                                </div>
                                                <span class="text-sm font-medium text-slate-700">{{ number_format($emissionFactorSources[$source]['value'], 0, ',', ' ') }} gCO₂/kWh</span>
                                            </div>
                                        @endif
                                    @endforeach
                                </div>
                            </div>

                            <!-- Data sources -->
                            <div class="text-xs text-slate-500 space-y-1 pt-2">
                                <h4 class="font-medium text-slate-600 mb-2">Lähteet</h4>
                                <p>• Fossiilisten polttoaineiden päästökertoimet: Tilastokeskus, IPCC Guidelines for National GHG Inventories</p>
                                <p>• Suomen tuotannon keskiarvo (~35 gCO₂/kWh): Fingrid & Tilastokeskus 2024</p>
                                <p>• Jäännösjakauman päästökerroin (391 gCO₂/kWh): Energiavirasto, "National Residual Mix 2024"</p>
                                <p>• Uusiutuvat ja ydinvoima: EU:n alkuperätakuujärjestelmän mukainen 0 gCO₂/kWh</p>
                            </div>
                        </div>
                    </details>
                </div>
            @endif

            <!-- Electricity Source -->
            @php
                $hasSourceData = $contract->electricitySource &&
                    (($contract->electricitySource->renewable_total && $contract->electricitySource->renewable_total > 0) ||
                     ($contract->electricitySource->nuclear_total && $contract->electricitySource->nuclear_total > 0) ||
                     ($contract->electricitySource->fossil_total && $contract->electricitySource->fossil_total > 0));
            @endphp
            <div class="bg-white rounded-2xl shadow-sm border border-slate-100 p-6">
                <h2 class="text-lg font-semibold text-slate-900 mb-4">Sähkön alkuperä</h2>

                @if ($hasSourceData)
                    <!-- Main breakdown -->
                    <div class="space-y-3 mb-6">
                        @if ($contract->electricitySource->renewable_total && $contract->electricitySource->renewable_total > 0)
                            <div>
                                <div class="flex justify-between items-center mb-1">
                                    <span class="text-slate-600">Uusiutuva</span>
                                    <span class="font-semibold text-green-600">{{ number_format($contract->electricitySource->renewable_total, 0, ',', ' ') }}%</span>
                                </div>
                                <div class="w-full bg-slate-200 rounded-full h-2">
                                    <div class="bg-green-500 h-2 rounded-full" style="width: {{ min($contract->electricitySource->renewable_total, 100) }}%"></div>
                                </div>
                            </div>
                        @endif
                        @if ($contract->electricitySource->nuclear_total && $contract->electricitySource->nuclear_total > 0)
                            <div>
                                <div class="flex justify-between items-center mb-1">
                                    <span class="text-slate-600">Ydinvoima</span>
                                    <span class="font-semibold text-blue-600">{{ number_format($contract->electricitySource->nuclear_total, 0, ',', ' ') }}%</span>
                                </div>
                                <div class="w-full bg-slate-200 rounded-full h-2">
                                    <div class="bg-blue-500 h-2 rounded-full" style="width: {{ min($contract->electricitySource->nuclear_total, 100) }}%"></div>
                                </div>
                            </div>
                        @endif
                        @if ($contract->electricitySource->fossil_total && $contract->electricitySource->fossil_total > 0)
                            <div>
                                <div class="flex justify-between items-center mb-1">
                                    <span class="text-slate-600">Fossiilinen</span>
                                    <span class="font-semibold text-red-600">{{ number_format($contract->electricitySource->fossil_total, 0, ',', ' ') }}%</span>
                                </div>
                                <div class="w-full bg-slate-200 rounded-full h-2">
                                    <div class="bg-red-500 h-2 rounded-full" style="width: {{ min($contract->electricitySource->fossil_total, 100) }}%"></div>
                                </div>
                            </div>
                        @endif
                    </div>

                    <!-- Renewable breakdown -->
                    @if ($contract->electricitySource->renewable_total && $contract->electricitySource->renewable_total > 0)
                        <div class="border-t border-slate-100 pt-4">
                            <h3 class="text-sm font-medium text-slate-700 mb-3">Uusiutuvan erittely</h3>
                            <div class="space-y-2 text-sm">
                                @if ($contract->electricitySource->renewable_wind && $contract->electricitySource->renewable_wind > 0)
                                    <div class="flex justify-between">
                                        <span class="text-slate-600">Tuulivoima</span>
                                        <span class="font-medium">{{ number_format($contract->electricitySource->renewable_wind, 0, ',', ' ') }}%</span>
                                    </div>
                                @endif
                                @if ($contract->electricitySource->renewable_hydro && $contract->electricitySource->renewable_hydro > 0)
                                    <div class="flex justify-between">
                                        <span class="text-slate-600">Vesivoima</span>
                                        <span class="font-medium">{{ number_format($contract->electricitySource->renewable_hydro, 0, ',', ' ') }}%</span>
                                    </div>
                                @endif
                                @if ($contract->electricitySource->renewable_solar && $contract->electricitySource->renewable_solar > 0)
                                    <div class="flex justify-between">
                                        <span class="text-slate-600">Aurinkovoima</span>
                                        <span class="font-medium">{{ number_format($contract->electricitySource->renewable_solar, 0, ',', ' ') }}%</span>
                                    </div>
                                @endif
                                @if ($contract->electricitySource->renewable_biomass && $contract->electricitySource->renewable_biomass > 0)
                                    <div class="flex justify-between">
                                        <span class="text-slate-600">Biomassa</span>
                                        <span class="font-medium">{{ number_format($contract->electricitySource->renewable_biomass, 0, ',', ' ') }}%</span>
                                    </div>
                                @endif
                            </div>
                        </div>
                    @endif
                @else
                    <!-- No source data available -->
                    <div class="flex items-start gap-3 text-slate-600 bg-slate-50 rounded-lg p-4">
                        <svg class="w-5 h-5 flex-shrink-0 mt-0.5 text-slate-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                        </svg>
                        <div class="text-sm">
                            <p class="mb-1">Sähkön alkuperätietoja ei ole saatavilla tälle sopimukselle.</p>
                            <p class="text-slate-500">Päästölaskennassa käytetään Suomen jäännösjakaumaa (390,93 gCO₂/kWh).</p>
                        </div>
                    </div>
                @endif
            </div>

            <!-- Company Information -->
            <div class="bg-white rounded-2xl shadow-sm border border-slate-100 p-6">
                <h2 class="text-lg font-semibold text-slate-900 mb-4">Yhtiön tiedot</h2>
                <div class="space-y-3">
                    <div>
                        <span class="text-sm text-slate-500">Yhtiön nimi</span>
                        <p class="text-slate-900 font-medium">{{ $contract->company?->name }}</p>
                    </div>
                    @if ($contract->company?->street_address)
                        <div>
                            <span class="text-sm text-slate-500">Osoite</span>
                            <p class="text-slate-900">{{ $contract->company->street_address }}</p>
                            <p class="text-slate-900">{{ $contract->company->postal_code }} {{ $contract->company->postal_name }}</p>
                        </div>
                    @endif
                    @if ($contract->company?->company_url)
                        <div>
                            <span class="text-sm text-slate-500">Verkkosivu</span>
                            <p>
                                <a href="{{ $contract->company->company_url }}" target="_blank" rel="noopener noreferrer" class="text-coral-600 hover:text-coral-700">
                                    {{ $contract->company->company_url }}
                                </a>
                            </p>
                        </div>
                    @endif
                </div>
            </div>

            <!-- Billing & Terms -->
            <div class="bg-white rounded-2xl shadow-sm border border-slate-100 p-6">
                <h2 class="text-lg font-semibold text-slate-900 mb-4">Laskutus ja ehdot</h2>
                <div class="space-y-3 text-sm">
                    @if ($contract->billing_frequency)
                        <div class="flex justify-between">
                            <span class="text-slate-600">Laskutusväli</span>
                            <span class="font-medium">{{ implode(', ', $contract->billing_frequency) }}</span>
                        </div>
                    @endif
                    <div class="flex justify-between">
                        <span class="text-slate-600">Saatavuus</span>
                        <span class="font-medium">{{ $contract->availability_is_national ? 'Valtakunnallinen' : 'Alueellinen' }}</span>
                    </div>
                    @if ($contract->available_for_existing_users !== null)
                        <div class="flex justify-between">
                            <span class="text-slate-600">Olemassa oleville asiakkaille</span>
                            <span class="font-medium">{{ $contract->available_for_existing_users ? 'Kyllä' : 'Ei' }}</span>
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>
    </div>
</div>
