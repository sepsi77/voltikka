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

        // Verdict framing: lead with evaluation outcome + savings vs. cheapest.
        $cheapestAlt = ($cheaperContracts ?? collect())->isNotEmpty() ? $cheaperContracts->first() : null;
        $maxSavings = $cheapestAlt ? (int) round(max(0, $cheapestAlt['savings'] ?? 0)) : 0;
        $cheapestAltCost = $cheapestAlt ? (int) round($cheapestAlt['total_cost'] ?? 0) : null;
        $cheaperCount = ($rank && $rank > 1) ? $rank - 1 : 0;
        $rankPercentile = ($rank && $totalContracts) ? $rank / $totalContracts : null;
        $verdictTier = 'unknown';
        if ($rankPercentile !== null) {
            if ($rank === 1) $verdictTier = 'cheapest';
            elseif ($rankPercentile <= 0.1) $verdictTier = 'top10';
            elseif ($rankPercentile <= 0.33) $verdictTier = 'good';
            elseif ($rankPercentile <= 0.66) $verdictTier = 'mid';
            else $verdictTier = 'expensive';
        }

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
                        {{-- fixed_time_range already implies FixedTerm; show contract_type only when there's no range --}}
                        @if ($contract->fixed_time_range)
                            <span class="px-2.5 py-1 rounded-md text-xs font-medium bg-white/10 text-slate-100 border border-white/20">
                                {{ ContractLabels::fixedTimeRange($contract->fixed_time_range) }}
                            </span>
                        @elseif ($contract->contract_type)
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
                    </div>
                </div>
            </div>

            <!-- Hero body: cost block  |  sustainability block -->
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-x-16 gap-y-10 lg:divide-x lg:divide-white/10">
                <!-- Left two-thirds: rank + cost + CTA -->
                <div class="lg:col-span-2 lg:pr-16">
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

                    @if ($rank && $totalContracts)
                        @if ($verdictTier === 'cheapest' || $verdictTier === 'top10')
                            {{-- Well-ranked: keep it a single clean pill --}}
                            <div class="mt-6 flex flex-wrap items-center gap-x-5 gap-y-3">
                                <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-sm font-semibold bg-emerald-500/20 text-emerald-200 border border-emerald-400/40 whitespace-nowrap">
                                    <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20" aria-hidden="true">
                                        <path d="M10 15l-5.878 3.09 1.123-6.545L.49 6.91l6.572-.955L10 0l2.939 5.955 6.572.955-4.755 4.635 1.123 6.545z"/>
                                    </svg>
                                    @if ($verdictTier === 'cheapest')
                                        Halvin sopimus · {{ number_format($totalContracts, 0, ',', ' ') }} vertailussa
                                    @else
                                        Yksi halvimmista · #{{ $rank }} / {{ number_format($totalContracts, 0, ',', ' ') }}
                                    @endif
                                </span>
                            </div>
                        @elseif ($cheapestAlt && $cheapestAltCost && $maxSavings > 0)
                            {{-- Mid/expensive: sober side-by-side comparison, not marketing copy --}}
                            <div class="mt-6 rounded-xl bg-gradient-to-br from-white/[0.18] to-white/[0.09] ring-1 ring-inset ring-white/30 shadow-[0_1px_0_0_rgba(255,255,255,0.08)_inset,0_10px_30px_-15px_rgba(0,0,0,0.5)] divide-y divide-white/15 sm:divide-y-0 sm:divide-x sm:grid sm:grid-cols-[1fr_1fr_auto] sm:items-stretch overflow-hidden">
                                <div class="px-4 py-3.5 flex flex-col justify-center">
                                    <div class="text-[11px] font-semibold uppercase tracking-[0.1em] text-slate-300">Hintasijoitus</div>
                                    <div class="mt-1 text-sm text-slate-100 leading-snug">
                                        <span class="font-bold text-white tabular-nums">{{ number_format($cheaperCount, 0, ',', ' ') }}</span>
                                        / {{ number_format($totalContracts, 0, ',', ' ') }} sopimusta on tätä edullisempia
                                        @if ($consumption)
                                            <span class="text-slate-400">({{ number_format($consumption, 0, ',', ' ') }} kWh/v)</span>
                                        @endif
                                    </div>
                                </div>
                                <div class="px-4 py-3.5 flex flex-col justify-center">
                                    <div class="text-[11px] font-semibold uppercase tracking-[0.1em] text-slate-300">Halvin sopimus</div>
                                    <div class="mt-1 flex flex-wrap items-baseline gap-x-2 gap-y-0.5 tabular-nums">
                                        <span class="text-sm font-bold text-white">{{ number_format($cheapestAltCost, 0, ',', ' ') }} €/v</span>
                                        <span class="text-xs font-bold text-emerald-300">
                                            −{{ number_format($maxSavings, 0, ',', ' ') }} €/v
                                        </span>
                                    </div>
                                </div>
                                <a href="/sahkosopimus" class="group px-4 py-3.5 flex items-center justify-between sm:justify-center gap-2 text-sm font-semibold text-coral-200 hover:text-white bg-coral-500/10 hover:bg-coral-500/20 transition-colors">
                                    Vertaa sopimuksia
                                    <svg class="w-4 h-4 group-hover:translate-x-0.5 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M14 5l7 7m0 0l-7 7m7-7H3"/>
                                    </svg>
                                </a>
                            </div>
                        @else
                            {{-- Fallback (no cheaper data): just show the rank cleanly --}}
                            <div class="mt-6">
                                <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-sm font-semibold bg-white/10 text-slate-200 border border-white/20 whitespace-nowrap">
                                    Sijalla #{{ $rank }} / {{ number_format($totalContracts, 0, ',', ' ') }}
                                </span>
                            </div>
                        @endif
                    @endif

                    @if ($primaryCtaUrl)
                        <div class="mt-8">
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

                        @if ($heroDrivingKm > 0)
                            <div class="flex items-baseline gap-2">
                                <div class="text-5xl font-extrabold text-white tracking-tight leading-none tabular-nums">
                                    {{ number_format($heroDrivingKm, 0, ',', ' ') }}
                                </div>
                                <div class="text-slate-200 text-base">km autolla</div>
                            </div>
                            <div class="mt-3 text-sm text-slate-300">
                                Sähkönkulutuksesi vastaa
                                <span class="font-semibold text-white tabular-nums">{{ number_format($annualEmissionsKg, 0, ',', ' ') }} kg</span>
                                CO₂-päästöjä vuodessa.
                            </div>
                        @else
                            <div class="flex items-baseline gap-2">
                                <div class="text-5xl font-extrabold text-white tracking-tight leading-none tabular-nums">0</div>
                                <div class="text-slate-200 text-base">kg CO₂/vuosi</div>
                            </div>
                            <div class="mt-3 text-sm text-slate-300">
                                Tämän sopimuksen sähköntuotannolla ei ole suoria CO₂-päästöjä.
                            </div>
                        @endif

                        @if ($heroDrivingKm > 0)
                            <a href="/sahkosopimus/fossiiliton" class="mt-5 inline-flex items-center text-sm font-medium text-coral-300 hover:text-coral-200">
                                Katso päästöttömät sopimukset →
                            </a>
                        @endif
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
                                        @if ($contract->formatActiveDiscountValue($discountInfo))
                                            {{ $contract->formatActiveDiscountValue($discountInfo) }}
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
                        'DayTime' => 'Päiväsähkö',
                        'NightTime' => 'Yösähkö',
                        'SeasonalWinterDay' => 'Talvihinta',
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
                            $dedupedHistory[$type] = $rows; // oldest → newest
                        }
                    }

                    $since = $priceChangeInfo['since'];

                    // Pick the primary series for the hero chart: prefer General, else DayTime, else first.
                    $primaryType = null;
                    foreach (['General', 'DayTime', 'NightTime', 'SeasonalWinterDay', 'SeasonalOther', 'Monthly'] as $candidate) {
                        if (!empty($dedupedHistory[$candidate]) && count($dedupedHistory[$candidate]) >= 2) {
                            $primaryType = $candidate;
                            break;
                        }
                    }

                    $chartSeries = $primaryType ? $dedupedHistory[$primaryType] : [];
                    $hasChart = count($chartSeries) >= 2;

                    $chartPoints = [];
                    $firstPrice = $lastPrice = $priceDelta = $priceDeltaPct = null;
                    $chartUnit = '';
                    $chartDateFirst = $chartDateLast = null;

                    if ($hasChart) {
                        $prices = array_map(fn ($r) => (float) $r['price'], $chartSeries);
                        $dates = array_map(fn ($r) => \Carbon\Carbon::parse($r['date'])->timestamp, $chartSeries);
                        $minPrice = min($prices);
                        $maxPrice = max($prices);
                        $priceRange = max(0.01, $maxPrice - $minPrice);
                        $minDate = min($dates);
                        $maxDate = max($dates);
                        $dateRange = max(1, $maxDate - $minDate);
                        $w = 300; $h = 80; $padX = 8; $padY = 10;
                        foreach ($chartSeries as $i => $row) {
                            $x = $padX + (($dates[$i] - $minDate) / $dateRange) * ($w - 2 * $padX);
                            $y = ($h - $padY) - (($prices[$i] - $minPrice) / $priceRange) * ($h - 2 * $padY);
                            $chartPoints[] = ['x' => round($x, 2), 'y' => round($y, 2), 'price' => $prices[$i]];
                        }
                        $firstPrice = $prices[0];
                        $lastPrice = end($prices);
                        $priceDelta = $lastPrice - $firstPrice;
                        $priceDeltaPct = $firstPrice > 0 ? (($lastPrice - $firstPrice) / $firstPrice) * 100 : 0;
                        $chartUnit = $primaryType === 'Monthly' ? 'EUR/kk' : 'c/kWh';
                        $chartDateFirst = \Carbon\Carbon::parse($chartSeries[0]['date']);
                        $chartDateLast = \Carbon\Carbon::parse(end($chartSeries)['date']);
                    }

                    // Attach delta-from-previous (older) for each timeline entry (uses primary series).
                    $timeline = [];
                    $lookupPrice = function (array $entry) use ($primaryType, $priceTypeLabels): ?float {
                        if (! $primaryType) return null;
                        $label = $priceTypeLabels[$primaryType] ?? $primaryType;
                        foreach ($entry['prices'] as $p) {
                            if ($p['label'] === $label) return (float) $p['price'];
                        }
                        return null;
                    };
                    foreach ($contractHistory as $i => $entry) {
                        $current = $lookupPrice($entry);
                        $next = $contractHistory[$i + 1] ?? null; // older
                        $previous = $next ? $lookupPrice($next) : null;
                        $delta = null;
                        if ($current !== null && $previous !== null && abs($current - $previous) > 0.0001) {
                            $delta = $current - $previous;
                        }
                        $timeline[] = array_merge($entry, [
                            'delta_to_previous' => $delta,
                        ]);
                    }

                    $chartLinePath = '';
                    $chartAreaPath = '';
                    if ($hasChart) {
                        foreach ($chartPoints as $i => $p) {
                            $chartLinePath .= ($i === 0 ? 'M' : 'L') . "{$p['x']},{$p['y']} ";
                        }
                        $firstX = $chartPoints[0]['x'];
                        $lastX = end($chartPoints)['x'];
                        $chartAreaPath = "M{$firstX},70 " . substr($chartLinePath, 1) . "L{$lastX},70 Z";
                    }

                    $primaryLabel = $primaryType ? ($priceTypeLabels[$primaryType] ?? $primaryType) : '';
                @endphp

                <section class="bg-white rounded-2xl shadow-sm border border-slate-100 p-6 sm:p-7">
                    {{-- Header --}}
                    <header class="flex items-baseline justify-between gap-3 flex-wrap">
                        <div>
                            <h2 class="text-lg font-semibold text-slate-900 tracking-tight">Sopimushistoria</h2>
                            <p class="mt-1 text-sm text-slate-500">
                                <span class="tabular-nums font-medium text-slate-600">{{ count($contractHistory) }}</span>
                                {{ count($contractHistory) === 1 ? 'versio' : 'versiota' }}
                                @if ($since)
                                    <span class="text-slate-300" aria-hidden="true">·</span>
                                    seurattu {{ $since->translatedFormat('j.n.Y') }} alkaen
                                @endif
                            </p>
                        </div>
                    </header>

                    {{-- Hero trajectory --}}
                    @if ($hasChart)
                        @php
                            $priceUp = $priceDelta > 0;
                            $deltaSign = $priceUp ? '+' : '';
                            $deltaToneText = $priceUp ? 'text-amber-700' : 'text-emerald-700';
                            $deltaToneBg = $priceUp ? 'bg-amber-50 ring-amber-200/70' : 'bg-emerald-50 ring-emerald-200/70';
                            $lineStroke = $priceUp ? '#d97706' : '#059669'; // amber-600 / emerald-600
                            $fillStart = $priceUp ? '#fbbf24' : '#34d399';
                        @endphp
                        <figure class="mt-5 rounded-xl border border-slate-200/80 bg-gradient-to-br from-slate-50 to-white p-5 sm:p-6">
                            <div class="flex flex-col-reverse sm:flex-row sm:items-end gap-5 sm:gap-8">
                                {{-- Headline --}}
                                <div class="flex-1 min-w-0">
                                    <div class="text-[11px] font-semibold uppercase tracking-[0.14em] text-slate-500">
                                        {{ $primaryLabel }} · kehitys seurannan aikana
                                    </div>
                                    <div class="mt-3 flex items-baseline gap-2.5 tabular-nums">
                                        <span class="text-xl font-medium text-slate-400">{{ number_format($firstPrice, 2, ',', '') }}</span>
                                        <svg class="w-4 h-4 text-slate-300 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 5l7 7-7 7M5 12h15"/>
                                        </svg>
                                        <span class="text-3xl font-bold text-slate-900 tracking-tight">{{ number_format($lastPrice, 2, ',', '') }}</span>
                                        <span class="text-sm text-slate-500 font-normal">{{ $chartUnit }}</span>
                                    </div>
                                    <div class="mt-3 flex items-center gap-2 flex-wrap text-sm">
                                        <span class="inline-flex items-center gap-1 rounded-full {{ $deltaToneBg }} ring-1 ring-inset px-2.5 py-1 font-semibold {{ $deltaToneText }} tabular-nums">
                                            @if ($priceUp)
                                                <svg class="w-3 h-3" fill="currentColor" viewBox="0 0 16 16" aria-hidden="true"><path d="M8 3l5.5 7H2.5z"/></svg>
                                            @else
                                                <svg class="w-3 h-3" fill="currentColor" viewBox="0 0 16 16" aria-hidden="true"><path d="M8 13L2.5 6h11z"/></svg>
                                            @endif
                                            {{ $deltaSign }}{{ number_format($priceDelta, 2, ',', '') }} {{ $chartUnit }}
                                        </span>
                                        <span class="text-slate-500 tabular-nums">
                                            {{ $deltaSign }}{{ number_format($priceDeltaPct, abs($priceDeltaPct) < 10 ? 1 : 0, ',', '') }} %
                                        </span>
                                        @if ($chartDateFirst && $chartDateLast)
                                            <span class="text-slate-300" aria-hidden="true">·</span>
                                            <span class="text-slate-500 tabular-nums">
                                                {{ $chartDateFirst->translatedFormat('j.n.') }} – {{ $chartDateLast->translatedFormat('j.n.Y') }}
                                            </span>
                                        @endif
                                    </div>
                                </div>

                                {{-- Chart --}}
                                <div class="sm:w-56 shrink-0">
                                    <svg viewBox="0 0 300 80" preserveAspectRatio="none" class="w-full h-16 sm:h-20" role="img" aria-label="{{ $primaryLabel }} kehitys">
                                        <defs>
                                            <linearGradient id="historyFill-{{ $primaryType }}" x1="0" y1="0" x2="0" y2="1">
                                                <stop offset="0%" stop-color="{{ $fillStart }}" stop-opacity="0.22"/>
                                                <stop offset="100%" stop-color="{{ $fillStart }}" stop-opacity="0"/>
                                            </linearGradient>
                                        </defs>
                                        <path d="{{ $chartAreaPath }}" fill="url(#historyFill-{{ $primaryType }})"/>
                                        <path d="{{ $chartLinePath }}" fill="none" stroke="{{ $lineStroke }}" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" vector-effect="non-scaling-stroke"/>
                                        @foreach ($chartPoints as $idx => $p)
                                            @php
                                                $isEdge = $idx === 0 || $idx === count($chartPoints) - 1;
                                            @endphp
                                            <circle cx="{{ $p['x'] }}" cy="{{ $p['y'] }}" r="{{ $isEdge ? 3.5 : 2.5 }}"
                                                fill="{{ $isEdge ? $lineStroke : '#fff' }}"
                                                stroke="{{ $lineStroke }}" stroke-width="{{ $isEdge ? 0 : 1.5 }}"
                                                vector-effect="non-scaling-stroke"/>
                                        @endforeach
                                    </svg>
                                </div>
                            </div>
                        </figure>
                    @endif

                    {{-- Timeline --}}
                    <ol class="mt-6 relative">
                        @foreach ($timeline as $i => $entry)
                            @php
                                $isLast = $i === count($timeline) - 1;
                                $hasDelta = $entry['delta_to_previous'] !== null;
                                $deltaUp = $hasDelta && $entry['delta_to_previous'] > 0;
                            @endphp
                            <li class="relative pl-7 sm:pl-8 {{ $isLast ? 'pb-0' : 'pb-6' }}">
                                {{-- Connector line --}}
                                @if (!$isLast)
                                    <span aria-hidden="true" class="absolute left-[7px] top-5 bottom-0 w-[2px] bg-slate-200 rounded-full"></span>
                                @endif

                                {{-- Node dot --}}
                                <span aria-hidden="true" class="absolute left-0 top-1.5 flex items-center justify-center w-4 h-4">
                                    @if ($entry['is_current'])
                                        <span class="block w-3 h-3 rounded-full bg-coral-500 ring-4 ring-coral-100"></span>
                                    @else
                                        <span class="block w-2.5 h-2.5 rounded-full bg-slate-300 ring-[3px] ring-slate-100"></span>
                                    @endif
                                </span>

                                {{-- Entry content --}}
                                <div class="space-y-1.5">
                                    <div class="flex flex-wrap items-center gap-x-2.5 gap-y-1">
                                        <time class="text-sm font-semibold text-slate-900 tabular-nums">
                                            {{ $entry['latest_price_date']?->translatedFormat('j.n.Y') ?? '—' }}
                                        </time>
                                        @if ($entry['is_current'])
                                            <span class="inline-flex items-center gap-1 rounded-full bg-coral-50 px-2 py-0.5 text-[11px] font-semibold text-coral-700 ring-1 ring-inset ring-coral-600/20">
                                                <span class="w-1.5 h-1.5 rounded-full bg-coral-500"></span>
                                                Nykyinen
                                            </span>
                                        @endif
                                    </div>

                                    <div class="text-sm font-medium text-slate-800">
                                        {{ $entry['name'] }}
                                    </div>

                                    @if (!empty($entry['prices']))
                                        <dl class="flex flex-wrap gap-x-5 gap-y-1 pt-0.5 tabular-nums">
                                            @foreach ($entry['prices'] as $price)
                                                <div class="flex items-baseline gap-1.5">
                                                    <dt class="text-[11px] font-medium uppercase tracking-[0.08em] text-slate-400">{{ $price['label'] }}</dt>
                                                    <dd class="text-sm font-semibold text-slate-900">
                                                        {{ number_format($price['price'], 2, ',', '') }}
                                                        <span class="text-slate-400 font-normal text-[11px] ml-0.5">{{ $price['unit'] }}</span>
                                                    </dd>
                                                </div>
                                            @endforeach
                                        </dl>
                                    @endif

                                    @if (!empty($entry['promotion']))
                                        <p class="mt-1.5 inline-flex items-center gap-1.5 text-xs text-amber-800 bg-amber-50 px-2.5 py-1 rounded-md ring-1 ring-inset ring-amber-200/70">
                                            <svg class="w-3.5 h-3.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4"/>
                                            </svg>
                                            {{ $entry['promotion'] }}
                                        </p>
                                    @endif
                                </div>

                                {{-- Delta chip on connector, between this version and the older one below --}}
                                @if (!$isLast && $hasDelta)
                                    <div class="mt-3 ml-0 -mb-1 inline-flex items-center gap-1.5 pl-0.5">
                                        <span class="inline-flex items-center gap-1 text-[11px] font-semibold tabular-nums rounded-full px-2 py-0.5 ring-1 ring-inset
                                            {{ $deltaUp ? 'bg-amber-50 text-amber-700 ring-amber-200/70' : 'bg-emerald-50 text-emerald-700 ring-emerald-200/70' }}">
                                            @if ($deltaUp)
                                                <svg class="w-2.5 h-2.5" fill="currentColor" viewBox="0 0 16 16" aria-hidden="true"><path d="M8 3l5.5 7H2.5z"/></svg>
                                            @else
                                                <svg class="w-2.5 h-2.5" fill="currentColor" viewBox="0 0 16 16" aria-hidden="true"><path d="M8 13L2.5 6h11z"/></svg>
                                            @endif
                                            {{ $deltaUp ? '+' : '' }}{{ number_format($entry['delta_to_previous'], 2, ',', '') }}
                                            {{ $chartUnit ?: 'c/kWh' }}
                                        </span>
                                        <span class="text-[11px] text-slate-400">{{ $primaryLabel ?: 'hinta' }} muuttui</span>
                                    </div>
                                @endif
                            </li>
                        @endforeach
                    </ol>
                </section>
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
                @php
                    // Severity tier → static Tailwind class strings (avoids dynamic classes that Tailwind can't scan)
                    $severityTier = 'zero';
                    $severityLabel = 'Päästötön';
                    $toneNumber = 'text-emerald-600';
                    $tonePillBg = 'bg-emerald-50';
                    $tonePillText = 'text-emerald-700';
                    $tonePillRing = 'ring-emerald-200/70';
                    $toneDot = 'bg-emerald-500';
                    $toneMarkerRing = 'ring-emerald-600';
                    if ($emissionFactor > 0 && $emissionFactor < 50) {
                        $severityTier = 'low';
                        $severityLabel = 'Matalat päästöt';
                        $toneNumber = 'text-lime-700';
                        $tonePillBg = 'bg-lime-50';
                        $tonePillText = 'text-lime-700';
                        $tonePillRing = 'ring-lime-200/70';
                        $toneDot = 'bg-lime-500';
                        $toneMarkerRing = 'ring-lime-600';
                    } elseif ($emissionFactor >= 50 && $emissionFactor < 200) {
                        $severityTier = 'medium';
                        $severityLabel = 'Keskitaso';
                        $toneNumber = 'text-amber-700';
                        $tonePillBg = 'bg-amber-50';
                        $tonePillText = 'text-amber-700';
                        $tonePillRing = 'ring-amber-200/70';
                        $toneDot = 'bg-amber-500';
                        $toneMarkerRing = 'ring-amber-600';
                    } elseif ($emissionFactor >= 200 && $emissionFactor < 350) {
                        $severityTier = 'high';
                        $severityLabel = 'Korkeat päästöt';
                        $toneNumber = 'text-orange-700';
                        $tonePillBg = 'bg-orange-50';
                        $tonePillText = 'text-orange-700';
                        $tonePillRing = 'ring-orange-200/70';
                        $toneDot = 'bg-orange-500';
                        $toneMarkerRing = 'ring-orange-600';
                    } elseif ($emissionFactor >= 350) {
                        $severityTier = 'very-high';
                        $severityLabel = 'Erittäin korkeat päästöt';
                        $toneNumber = 'text-rose-700';
                        $tonePillBg = 'bg-rose-50';
                        $tonePillText = 'text-rose-700';
                        $tonePillRing = 'ring-rose-200/70';
                        $toneDot = 'bg-rose-500';
                        $toneMarkerRing = 'ring-rose-600';
                    }

                    $vsPhysical = ($physicalAverage > 0 && $emissionFactor > 0) ? $emissionFactor / $physicalAverage : null;
                @endphp
                <section id="ymparisto" class="bg-white rounded-2xl shadow-sm border border-slate-100 p-6 sm:p-7 scroll-mt-20">
                    <h2 class="text-lg font-semibold text-slate-900 tracking-tight mb-6">Ympäristövaikutus</h2>

                    {{-- Severity verdict --}}
                    <div class="inline-flex items-center gap-1.5 rounded-full {{ $tonePillBg }} ring-1 ring-inset {{ $tonePillRing }} px-2.5 py-1 text-[11px] font-semibold uppercase tracking-[0.08em] {{ $tonePillText }}">
                        <span class="w-1.5 h-1.5 rounded-full {{ $toneDot }}"></span>
                        {{ $severityLabel }}
                    </div>

                    {{-- Hero: concrete car-km equivalency --}}
                    @if ($emissionFactor == 0)
                        <div class="mt-4">
                            <div class="flex items-baseline gap-2 tabular-nums">
                                <span class="text-5xl font-bold {{ $toneNumber }} tracking-tight">0</span>
                                <span class="text-sm text-slate-500 font-medium">kg CO₂ vuodessa</span>
                            </div>
                            <p class="mt-3 text-sm text-slate-600 leading-relaxed">
                                Tämän sopimuksen sähköntuotannolla ei ole suoria CO₂-päästöjä.
                                Vuosikulutuksellasi ({{ number_format($consumption, 0, ',', ' ') }} kWh) ei synny päästöjä lainkaan.
                            </p>
                        </div>
                    @else
                        <div class="mt-4">
                            <div class="flex items-baseline gap-2 tabular-nums">
                                <span class="text-[44px] leading-none font-bold {{ $toneNumber }} tracking-tight">
                                    {{ number_format($drivingKm, 0, ',', ' ') }}
                                </span>
                                <span class="text-sm text-slate-500 font-medium">km autolla</span>
                            </div>
                            <p class="mt-3 text-sm text-slate-600 leading-relaxed">
                                Vuosikulutuksesi ({{ number_format($consumption, 0, ',', ' ') }} kWh) tuottaa yhtä paljon CO₂-päästöjä
                                kuin <span class="font-semibold text-slate-800 tabular-nums">{{ number_format($drivingKm, 0, ',', ' ') }} km</span>
                                ajoa keskivertohenkilöautolla (140 g/km).
                            </p>
                        </div>
                    @endif

                    {{-- Supporting stats: annual kg + emission factor --}}
                    @if ($emissionFactor > 0)
                        <dl class="mt-6 pt-5 border-t border-slate-100 grid grid-cols-2 gap-4">
                            <div>
                                <dt class="text-[11px] font-semibold uppercase tracking-[0.08em] text-slate-400">Vuosipäästöt</dt>
                                <dd class="mt-1 tabular-nums">
                                    <span class="text-xl font-semibold text-slate-900">{{ number_format($annualEmissionsKg, 0, ',', ' ') }}</span>
                                    <span class="text-xs text-slate-500 font-medium ml-0.5">kg CO₂</span>
                                </dd>
                            </div>
                            <div>
                                <dt class="text-[11px] font-semibold uppercase tracking-[0.08em] text-slate-400">Päästökerroin</dt>
                                <dd class="mt-1 tabular-nums">
                                    <span class="text-xl font-semibold text-slate-900">{{ number_format($emissionFactor, 0, ',', '') }}</span>
                                    <span class="text-xs text-slate-500 font-medium ml-0.5">gCO₂/kWh</span>
                                </dd>
                            </div>
                        </dl>

                        {{-- Single scale viz --}}
                        <div class="mt-5">
                            <div class="flex items-baseline justify-between text-[11px] font-semibold uppercase tracking-[0.08em] text-slate-400 mb-2.5">
                                <span>Sijoitus suomalaisella asteikolla</span>
                                @if ($vsPhysical && $vsPhysical >= 1.5)
                                    <span class="text-slate-600 tabular-nums normal-case font-semibold tracking-normal">
                                        {{ round($vsPhysical) }}× fyysinen verkko
                                    </span>
                                @endif
                            </div>
                            <div class="relative">
                                <div class="relative h-2 bg-slate-100 rounded-full overflow-hidden">
                                    <div class="absolute inset-y-0 left-0 rounded-full bg-gradient-to-r from-emerald-400 via-amber-400 to-rose-500"
                                         style="width: {{ min(100, ($emissionFactor / 400) * 100) }}%;"></div>
                                </div>

                                {{-- Finnish physical average reference tick --}}
                                <div class="absolute -top-1 -bottom-1 w-px bg-slate-400/70"
                                     style="left: {{ $physicalAveragePercent }}%;"></div>

                                {{-- Current contract marker --}}
                                <div class="absolute top-1/2 -translate-y-1/2 w-4 h-4 rounded-full bg-white ring-2 {{ $toneMarkerRing }} shadow"
                                     style="left: calc({{ min(100, ($emissionFactor / 400) * 100) }}% - 8px);"></div>
                            </div>

                            <div class="relative mt-2 h-7 text-[10px] font-medium text-slate-400 tabular-nums">
                                <span class="absolute left-0 top-0">0</span>
                                <span class="absolute top-0 -translate-x-1/2 text-center leading-tight" style="left: {{ $physicalAveragePercent }}%;">
                                    <span class="block text-slate-600 font-semibold">~{{ number_format($physicalAverage, 0) }}</span>
                                    <span class="block text-[9px] text-slate-400 font-normal">Suomi</span>
                                </span>
                                <span class="absolute right-0 top-0">400+</span>
                            </div>
                        </div>
                    @endif

                    {{-- Residual-mix explainer --}}
                    @if ($co2Emissions['residual_mix_percent'] > 0)
                        <div class="mt-6 pt-5 border-t border-slate-100">
                            <div class="flex items-start gap-2.5">
                                <svg class="w-4 h-4 mt-0.5 shrink-0 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                </svg>
                                <div class="text-sm space-y-2">
                                    <p class="font-semibold text-slate-800">Miksi päästöt ovat korkeat?</p>
                                    <p class="text-slate-600">
                                        <span class="tabular-nums font-semibold text-slate-800">{{ number_format($co2Emissions['residual_mix_percent'], 0, ',', '') }}&nbsp;%</span>
                                        sähkön alkuperästä on erittelemätöntä. Kun myyjä ei ilmoita alkuperää, laskennassa käytetään lain mukaan
                                        <strong class="text-slate-800">jäännösjakaumaa</strong>
                                        ({{ number_format($residualMix, 0) }} gCO₂/kWh). Puhtaat tuottajat myyvät alkuperätakuunsa erikseen,
                                        joten sopimuksellinen päästökerroin on usein paljon suurempi kuin verkossa fyysisesti virtaava sähkö.
                                    </p>
                                </div>
                            </div>
                        </div>
                    @elseif ($emissionFactor > 0 && $emissionFactor > $physicalAverage)
                        <div class="mt-6 pt-5 border-t border-slate-100">
                            <p class="text-sm text-slate-600">
                                Päästökerroin perustuu ilmoitettuihin energialähteisiin. Suomen sähköverkon fyysinen keskipäästö on vain
                                ~{{ number_format($physicalAverage, 0) }} gCO₂/kWh.
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
                </section>
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
