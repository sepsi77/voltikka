<div>
    <!-- Hero Section - Dark slate background -->
    <section class="bg-slate-950 -mx-4 sm:-mx-6 lg:-mx-8 mb-8">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="py-12 lg:py-16">
                <!-- Back Link -->
                <a href="/" class="inline-flex items-center text-slate-300 hover:text-white font-medium mb-6">
                    <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"></path>
                    </svg>
                    Takaisin sopimuksiin
                </a>

                <div class="flex flex-col md:flex-row md:items-start gap-6">
                    <!-- Company Logo & Info -->
                    <div class="flex items-center gap-4">
                        @if ($contract->company?->getLogoUrl())
                            <div class="bg-white p-3 rounded-xl">
                                <img
                                    src="{{ $contract->company->getLogoUrl() }}"
                                    alt="{{ $contract->company->name }}"
                                    class="h-16 w-auto object-contain"
                                >
                            </div>
                        @else
                            <div class="h-16 w-16 bg-slate-700 rounded-xl flex items-center justify-center">
                                <span class="text-slate-300 text-lg font-bold">{{ substr($contract->company?->name ?? 'N/A', 0, 2) }}</span>
                            </div>
                        @endif
                    </div>

                    <!-- Contract Info -->
                    <div class="flex-grow">
                        <h1 class="text-2xl md:text-3xl font-bold text-white mb-2">{{ $contract->name }}</h1>
                        <p class="text-lg text-slate-300 mb-3">{{ $contract->company?->name }}</p>

                        <!-- Contract Type & Metering Badges -->
                        <div class="flex flex-wrap gap-2 mb-4">
                            <span class="inline-flex items-center px-3 py-1 rounded-lg text-sm font-medium {{ $contract->contract_type === 'Spot' ? 'bg-coral-500/20 text-coral-300' : ($contract->contract_type === 'Fixed' ? 'bg-green-500/20 text-green-300' : 'bg-slate-700 text-slate-300') }}">
                                {{ $contract->contract_type }}
                            </span>
                            <span class="inline-flex items-center px-3 py-1 rounded-lg text-sm font-medium bg-slate-700 text-slate-300">
                                {{ $contract->metering }}
                            </span>
                            @if ($contract->fixed_time_range)
                                <span class="inline-flex items-center px-3 py-1 rounded-lg text-sm font-medium bg-coral-500/20 text-coral-300">
                                    {{ $contract->fixed_time_range }}
                                </span>
                            @endif
                        </div>

                        @if ($contract->short_description)
                            <p class="text-slate-300">{{ $contract->short_description }}</p>
                        @endif
                    </div>

                    <!-- Action Buttons -->
                    <div class="flex flex-col gap-3">
                        @if ($contract->order_link)
                            <a
                                href="{{ $contract->order_link }}"
                                target="_blank"
                                rel="noopener noreferrer"
                                class="inline-flex items-center justify-center px-6 py-3.5 bg-gradient-to-r from-coral-500 to-coral-600 hover:from-coral-400 hover:to-coral-500 text-white font-bold rounded-xl shadow-coral transition-all"
                            >
                                Tilaa sopimus
                                <svg class="w-4 h-4 ml-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"></path>
                                </svg>
                            </a>
                        @endif
                        @if ($contract->product_link)
                            <a
                                href="{{ $contract->product_link }}"
                                target="_blank"
                                rel="noopener noreferrer"
                                class="inline-flex items-center justify-center px-6 py-3.5 bg-white hover:bg-slate-100 text-slate-900 font-bold rounded-xl transition-colors"
                            >
                                Lisätietoja
                                <svg class="w-4 h-4 ml-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"></path>
                                </svg>
                            </a>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    </section>

    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- Left Column: Pricing & Cost Calculator -->
        <div class="lg:col-span-2 space-y-6">
            <!-- Consumption Selector -->
            <div class="bg-white rounded-2xl shadow-sm border border-slate-100 p-6">
                <h2 class="text-lg font-semibold text-slate-900 mb-4">Arvioitu kulutus</h2>

                @if ($contract->hasConsumptionLimits())
                    <div class="mb-4 p-3 bg-amber-50 border border-amber-200 rounded-xl text-sm text-amber-800">
                        <div class="flex items-center gap-2">
                            <svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                            </svg>
                            <span>
                                Tämän sopimuksen kulutusrajat:
                                @if ($contract->consumption_limitation_min_x_kwh_per_y !== null && $contract->consumption_limitation_max_x_kwh_per_y !== null)
                                    {{ number_format($contract->consumption_limitation_min_x_kwh_per_y, 0, ',', ' ') }} - {{ number_format($contract->consumption_limitation_max_x_kwh_per_y, 0, ',', ' ') }} kWh/vuosi
                                @elseif ($contract->consumption_limitation_min_x_kwh_per_y !== null)
                                    vähintään {{ number_format($contract->consumption_limitation_min_x_kwh_per_y, 0, ',', ' ') }} kWh/vuosi
                                @elseif ($contract->consumption_limitation_max_x_kwh_per_y !== null)
                                    enintään {{ number_format($contract->consumption_limitation_max_x_kwh_per_y, 0, ',', ' ') }} kWh/vuosi
                                @endif
                            </span>
                        </div>
                    </div>
                @endif

                <div class="flex flex-wrap gap-3">
                    @foreach ($presets as $label => $value)
                        <button
                            wire:click="setConsumption({{ $value }})"
                            class="px-4 py-2.5 rounded-xl font-medium transition-all {{ $consumption === $value ? 'bg-gradient-to-r from-coral-500 to-coral-600 text-white shadow-coral' : 'bg-slate-100 text-slate-700 hover:bg-slate-200' }}"
                        >
                            {{ $label }} ({{ $value }} kWh)
                        </button>
                    @endforeach
                </div>
                <p class="mt-4 text-sm text-slate-500">
                    Valittu kulutus: <span class="font-semibold">{{ number_format($consumption, 0, ',', ' ') }} kWh/vuosi</span>
                </p>

                <!-- Calculated Cost -->
                <div class="mt-6 p-4 bg-coral-50 border border-coral-200 rounded-xl">
                    <div class="flex justify-between items-center">
                        <span class="text-slate-600">Vuosikustannus</span>
                        <span class="text-3xl font-extrabold text-coral-600">{{ number_format($calculatedCost['total_cost'] ?? 0, 0, ',', ' ') }} EUR</span>
                    </div>
                    <div class="flex justify-between items-center mt-2 text-sm text-slate-500">
                        <span>Keskimäärin kuukaudessa</span>
                        <span>{{ number_format(($calculatedCost['total_cost'] ?? 0) / 12, 0, ',', ' ') }} EUR/kk</span>
                    </div>
                </div>
            </div>

            <!-- Price Breakdown -->
            <div class="bg-white rounded-2xl shadow-sm border border-slate-100 p-6">
                <h2 class="text-lg font-semibold text-slate-900 mb-4">Hintatiedot</h2>

                @if ($contract->metering === 'General')
                    <!-- General metering -->
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
                @elseif ($contract->metering === 'Seasonal')
                    <!-- Seasonal metering -->
                    <div class="space-y-4">
                        @if (isset($latestPrices['SeasonalWinter']))
                            <div class="flex justify-between items-center py-3 border-b border-slate-100">
                                <div>
                                    <span class="text-slate-600">Talvi</span>
                                    <span class="text-sm text-slate-400 ml-2">(marras-maaliskuu, päivä)</span>
                                </div>
                                <span class="text-xl font-semibold text-slate-900">{{ number_format($latestPrices['SeasonalWinter']['price'], 2, ',', ' ') }} c/kWh</span>
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

            <!-- Price History -->
            @if (count($priceHistory) > 0 && collect($priceHistory)->flatten(1)->count() > count($priceHistory))
                <div class="bg-white rounded-2xl shadow-sm border border-slate-100 p-6">
                    <h2 class="text-lg font-semibold text-slate-900 mb-4">Hintahistoria</h2>
                    <div class="space-y-4">
                        @foreach ($priceHistory as $type => $history)
                            @if (count($history) > 1)
                                <div>
                                    <h3 class="text-sm font-medium text-slate-700 mb-2">{{ $type }}</h3>
                                    <div class="overflow-x-auto">
                                        <table class="min-w-full text-sm">
                                            <thead>
                                                <tr class="text-left text-slate-500">
                                                    <th class="py-2 pr-4">Päivämäärä</th>
                                                    <th class="py-2">Hinta</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                @foreach ($history as $record)
                                                    <tr class="border-t border-slate-100">
                                                        <td class="py-2 pr-4 text-slate-600">{{ $record['date'] }}</td>
                                                        <td class="py-2 font-medium text-slate-900">{{ number_format($record['price'], 2, ',', ' ') }}</td>
                                                    </tr>
                                                @endforeach
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            @endif
                        @endforeach
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

        <!-- Right Column: Energy Source & Company Info -->
        <div class="space-y-6">
            <!-- Electricity Source -->
            @if ($contract->electricitySource)
                <div class="bg-white rounded-2xl shadow-sm border border-slate-100 p-6">
                    <h2 class="text-lg font-semibold text-slate-900 mb-4">Sähkön alkuperä</h2>

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
                </div>
            @endif

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
                    // Car driving equivalency: average Finnish passenger car emits ~170g CO2/km
                    $drivingKm = $annualEmissionsKg > 0 ? round($annualEmissionsKg * 1000 / 170) : 0;
                    // Gauge calculation: 0-400+ scale, cap at 400 for display
                    $gaugeMax = 400;
                    $gaugePercent = min(100, ($emissionFactor / $gaugeMax) * 100);
                    // Needle rotation: -90deg (left/0) to +90deg (right/400+)
                    $needleRotation = -90 + ($gaugePercent * 1.8);
                    // Finland baseline (residual mix)
                    $finlandBaseline = 390.93;
                    $baselinePercent = min(100, ($finlandBaseline / $gaugeMax) * 100);
                @endphp
                <div class="bg-white rounded-2xl shadow-sm border border-slate-100 p-6">
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
                        <!-- Semi-circular gauge -->
                        <div class="flex flex-col items-center mb-6">
                            <div class="relative w-48 h-24 mb-2">
                                <!-- Gauge background arc -->
                                <div class="absolute inset-0 overflow-hidden">
                                    <div class="w-48 h-48 rounded-full"
                                         style="background: conic-gradient(from 180deg, #22c55e 0deg, #22c55e 45deg, #84cc16 45deg, #84cc16 90deg, #eab308 90deg, #eab308 135deg, #f97316 135deg, #f97316 160deg, #ef4444 160deg, #ef4444 180deg, transparent 180deg);">
                                    </div>
                                </div>
                                <!-- Inner white circle to create arc effect -->
                                <div class="absolute left-1/2 bottom-0 -translate-x-1/2 w-32 h-32 rounded-full bg-white"></div>
                                <!-- Needle -->
                                <div class="absolute left-1/2 bottom-0 origin-bottom transition-transform duration-700 ease-out"
                                     style="transform: translateX(-50%) rotate({{ $needleRotation }}deg);">
                                    <div class="w-1 h-20 bg-slate-800 rounded-full mx-auto"></div>
                                    <div class="w-3 h-3 bg-slate-800 rounded-full -mt-1 mx-auto"></div>
                                </div>
                                <!-- Center pivot -->
                                <div class="absolute left-1/2 bottom-0 -translate-x-1/2 translate-y-1/2 w-4 h-4 bg-white border-2 border-slate-800 rounded-full"></div>
                                <!-- Scale labels -->
                                <div class="absolute -left-2 bottom-0 text-xs text-slate-500 font-medium">0</div>
                                <div class="absolute -right-4 bottom-0 text-xs text-slate-500 font-medium">400+</div>
                            </div>
                            <div class="text-center">
                                <span class="text-2xl font-bold {{ $emissionFactor < 100 ? 'text-green-600' : ($emissionFactor < 200 ? 'text-lime-600' : ($emissionFactor < 300 ? 'text-amber-600' : ($emissionFactor < 350 ? 'text-orange-600' : 'text-red-600'))) }}">
                                    {{ number_format($emissionFactor, 0, ',', ' ') }}
                                </span>
                                <span class="text-slate-500 text-sm ml-1">gCO₂/kWh</span>
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
                            <div class="text-sm text-slate-600 mb-2">Vertailu Suomen keskiarvoon</div>
                            <div class="relative h-8 bg-gradient-to-r from-green-200 via-yellow-200 to-red-200 rounded-lg overflow-hidden">
                                <!-- Finland baseline marker -->
                                <div class="absolute top-0 bottom-0 w-0.5 bg-slate-600 z-10"
                                     style="left: {{ $baselinePercent }}%;">
                                    <div class="absolute -top-6 left-1/2 -translate-x-1/2 whitespace-nowrap text-xs text-slate-600 font-medium">
                                        Suomen keskiarvo
                                    </div>
                                </div>
                                <!-- This contract marker -->
                                <div class="absolute top-1 bottom-1 w-3 rounded {{ $emissionFactor < $finlandBaseline ? 'bg-green-600' : 'bg-red-600' }} z-20 transition-all duration-500"
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
                                @if ($emissionFactor < $finlandBaseline)
                                    <span class="text-green-600 font-medium">{{ number_format($finlandBaseline - $emissionFactor, 0, ',', ' ') }} gCO₂/kWh pienempi kuin keskiarvo</span>
                                @elseif ($emissionFactor > $finlandBaseline)
                                    <span class="text-red-600 font-medium">{{ number_format($emissionFactor - $finlandBaseline, 0, ',', ' ') }} gCO₂/kWh suurempi kuin keskiarvo</span>
                                @else
                                    <span class="text-slate-600 font-medium">Sama kuin Suomen keskiarvo</span>
                                @endif
                            </div>
                        </div>
                    @endif

                    @if ($co2Emissions['residual_mix_percent'] > 0)
                        <div class="flex items-start gap-2 text-amber-700 bg-amber-50 rounded-lg p-3 text-sm mt-3">
                            <svg class="w-5 h-5 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                            </svg>
                            <span>
                                {{ number_format($co2Emissions['residual_mix_percent'], 0, ',', ' ') }}% sähkön alkuperästä on erittelemätöntä.
                                Tälle osalle käytetään Suomen jäännösjakauman päästökerrointa (390,93 gCO₂/kWh).
                            </span>
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
                                <p>• Jäännösjakauman päästökerroin: Energiavirasto, "National Residual Mix 2024" (julkaistu kesäkuu 2025)</p>
                                <p>• Uusiutuvat ja ydinvoima: EU:n alkuperätakuujärjestelmän mukainen 0 gCO₂/kWh</p>
                            </div>
                        </div>
                    </details>
                </div>
            @endif

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
