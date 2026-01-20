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
