<div>
    <!-- Hero Section - Dark slate background -->
    <section class="bg-slate-950 -mx-4 sm:-mx-6 lg:-mx-8 mb-8">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="py-12 lg:py-16">
                <h1 class="max-w-2xl mb-4 text-4xl font-extrabold text-white tracking-tight leading-none md:text-5xl xl:text-6xl">
                    <span class="text-coral-400">Pörssisähkön</span> hinta
                </h1>
                <p class="max-w-2xl mb-6 text-slate-300 md:text-lg lg:text-xl">
                    Seuraa sähkön pörssihinnan kehitystä ja löydä päivän edullisimmat tunnit.
                </p>
            </div>
        </div>
    </section>

    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">

    @if ($loading)
        <div class="flex items-center justify-center py-12">
            <svg class="animate-spin h-8 w-8 text-coral-500" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
            </svg>
            <span class="ml-3 text-slate-600">Ladataan hintatietoja...</span>
        </div>
    @elseif ($error)
        <div class="bg-red-50 border border-red-200 rounded-lg p-4 mb-8">
            <p class="text-red-700">{{ $error }}</p>
        </div>
    @elseif (empty($hourlyPrices))
        <div class="bg-yellow-50 border border-yellow-200 rounded-lg p-4 mb-8">
            <p class="text-yellow-700">Hintatietoja ei ole vielä saatavilla. Tiedot päivitetään automaattisesti.</p>
        </div>
    @else
        <!-- Current Price Hero Card -->
        @if ($currentPrice)
            <div class="bg-gradient-to-r from-coral-500 to-coral-600 rounded-2xl shadow-lg p-6 md:p-8 mb-8 text-white">
                <div class="flex flex-col md:flex-row md:items-center md:justify-between">
                    <div>
                        <p class="text-coral-100 text-sm uppercase tracking-wider mb-1">Tämänhetkinen hinta</p>
                        <p class="text-4xl md:text-5xl font-bold">
                            {{ number_format($currentPrice['price_with_tax'] ?? 0, 2, ',', ' ') }}
                            <span class="text-2xl">c/kWh</span>
                        </p>
                        <p class="text-coral-100 mt-2">
                            {{ now('Europe/Helsinki')->format('H') }}:00 - {{ now('Europe/Helsinki')->addHour()->format('H') }}:00
                            <span class="ml-2 bg-white/20 px-2 py-1 rounded text-xs">Nyt</span>
                        </p>
                    </div>
                    <div class="mt-4 md:mt-0 text-right">
                        <p class="text-coral-100 text-sm">ALV 0%</p>
                        <p class="text-2xl font-semibold">{{ number_format($currentPrice['price_without_tax'] ?? 0, 2, ',', ' ') }} c/kWh</p>
                    </div>
                </div>
            </div>
        @endif

        <!-- Price Comparison Cards -->
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-8">
            <!-- Today's Average -->
            <div class="bg-white p-5 rounded-2xl border border-slate-100 shadow-sm hover:border-coral-300 transition-colors">
                <div class="flex items-center justify-between mb-2">
                    <span class="text-sm font-medium text-slate-500 uppercase tracking-wider">Tänään</span>
                    @if ($historicalComparison['change_from_yesterday_percent'] !== null)
                        @php
                            $change = $historicalComparison['change_from_yesterday_percent'];
                            $isPositive = $change > 0;
                        @endphp
                        <span class="text-xs font-medium px-2 py-1 rounded-full {{ $isPositive ? 'bg-red-100 text-red-700' : 'bg-green-100 text-green-700' }}">
                            {{ $isPositive ? '+' : '' }}{{ number_format($change, 1, ',', ' ') }}%
                        </span>
                    @endif
                </div>
                <p class="text-2xl md:text-3xl font-bold text-slate-900">
                    @if ($todayStatistics['average'] !== null)
                        {{ number_format($todayStatistics['average'], 2, ',', ' ') }}
                    @else
                        -
                    @endif
                    <span class="text-base font-normal text-slate-500">c/kWh</span>
                </p>
                <p class="text-sm text-slate-500 mt-1">Keskihinta (ALV 0%)</p>
            </div>

            <!-- Yesterday's Average -->
            <div class="bg-white p-5 rounded-2xl border border-slate-100 shadow-sm">
                <span class="text-sm font-medium text-slate-500 uppercase tracking-wider">Eilen</span>
                <p class="text-2xl md:text-3xl font-bold text-slate-900 mt-2">
                    @if ($historicalComparison['yesterday_average'] !== null)
                        {{ number_format($historicalComparison['yesterday_average'], 2, ',', ' ') }}
                    @else
                        -
                    @endif
                    <span class="text-base font-normal text-slate-500">c/kWh</span>
                </p>
                <p class="text-sm text-slate-500 mt-1">Keskihinta (ALV 0%)</p>
            </div>

            <!-- Weekly Average -->
            <div class="bg-white p-5 rounded-2xl border border-slate-100 shadow-sm">
                <div class="flex items-center justify-between mb-2">
                    <span class="text-sm font-medium text-slate-500 uppercase tracking-wider">Viikon ka.</span>
                    @if ($historicalComparison['change_from_weekly_percent'] !== null)
                        @php
                            $change = $historicalComparison['change_from_weekly_percent'];
                            $isPositive = $change > 0;
                        @endphp
                        <span class="text-xs font-medium px-2 py-1 rounded-full {{ $isPositive ? 'bg-red-100 text-red-700' : 'bg-green-100 text-green-700' }}">
                            {{ $isPositive ? '+' : '' }}{{ number_format($change, 1, ',', ' ') }}%
                        </span>
                    @endif
                </div>
                <p class="text-2xl md:text-3xl font-bold text-slate-900">
                    @if ($historicalComparison['weekly_average'] !== null)
                        {{ number_format($historicalComparison['weekly_average'], 2, ',', ' ') }}
                    @else
                        -
                    @endif
                    <span class="text-base font-normal text-slate-500">c/kWh</span>
                </p>
                <p class="text-sm text-slate-500 mt-1">
                    @if ($historicalComparison['weekly_days_available'] > 0)
                        {{ $historicalComparison['weekly_days_available'] }} päivää
                    @else
                        Ei dataa
                    @endif
                </p>
            </div>
        </div>

        <!-- Hourly Price Chart -->
        @if (!empty($chartData['labels']))
            <div class="bg-white rounded-2xl shadow-sm border border-slate-100 p-4 md:p-6 mb-8">
                <h3 class="text-lg font-semibold text-slate-900 mb-4">Päivän tuntihinnat</h3>
                <div class="h-64 md:h-80">
                    <canvas id="priceChart"></canvas>
                </div>
            </div>
        @endif

        <!-- Statistics Section -->
        @if ($todayStatistics['average'] !== null)
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-8">
                <div class="bg-slate-50 p-4 rounded-lg">
                    <p class="text-sm text-slate-500">Keskihinta</p>
                    <p class="text-lg font-semibold">{{ number_format($todayStatistics['average'], 2, ',', ' ') }} c/kWh</p>
                </div>
                <div class="bg-slate-50 p-4 rounded-lg">
                    <p class="text-sm text-slate-500">Mediaani</p>
                    <p class="text-lg font-semibold">{{ number_format($todayStatistics['median'], 2, ',', ' ') }} c/kWh</p>
                </div>
                <div class="bg-green-50 p-4 rounded-lg">
                    <p class="text-sm text-slate-500">Alin</p>
                    <p class="text-lg font-semibold text-green-700">{{ number_format($todayStatistics['min'], 2, ',', ' ') }} c/kWh</p>
                </div>
                <div class="bg-red-50 p-4 rounded-lg">
                    <p class="text-sm text-slate-500">Ylin</p>
                    <p class="text-lg font-semibold text-red-700">{{ number_format($todayStatistics['max'], 2, ',', ' ') }} c/kWh</p>
                </div>
            </div>
        @endif

        <!-- Best Hours Cards -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4 mb-8">
            <!-- Cheapest Hour -->
            <div class="bg-green-50 p-5 rounded-xl border-2 border-green-200">
                <div class="flex items-center mb-3">
                    <span class="bg-green-200 p-2 rounded-lg">
                        <svg class="w-5 h-5 text-green-700" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                        </svg>
                    </span>
                    <h4 class="ml-3 font-semibold text-slate-900">Edullisin tunti</h4>
                </div>
                @if ($cheapestHour)
                    @php
                        $cheapHour = $cheapestHour['helsinki_hour'];
                        $nextHour = ($cheapHour + 1) % 24;
                    @endphp
                    <p class="text-2xl font-bold text-green-700">
                        {{ str_pad($cheapHour, 2, '0', STR_PAD_LEFT) }}-{{ str_pad($nextHour, 2, '0', STR_PAD_LEFT) }}
                    </p>
                    <p class="text-sm text-slate-600">{{ number_format($cheapestHour['price_without_tax'] ?? 0, 2, ',', ' ') }} c/kWh (ALV 0%)</p>
                @else
                    <p class="text-slate-500">-</p>
                @endif
            </div>

            <!-- Most Expensive Hour -->
            <div class="bg-red-50 p-5 rounded-xl border-2 border-red-200">
                <div class="flex items-center mb-3">
                    <span class="bg-red-200 p-2 rounded-lg">
                        <svg class="w-5 h-5 text-red-700" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path>
                        </svg>
                    </span>
                    <h4 class="ml-3 font-semibold text-slate-900">Kallein tunti</h4>
                </div>
                @if ($mostExpensiveHour)
                    @php
                        $expensiveHour = $mostExpensiveHour['helsinki_hour'];
                        $nextExpHour = ($expensiveHour + 1) % 24;
                    @endphp
                    <p class="text-2xl font-bold text-red-700">
                        {{ str_pad($expensiveHour, 2, '0', STR_PAD_LEFT) }}-{{ str_pad($nextExpHour, 2, '0', STR_PAD_LEFT) }}
                    </p>
                    <p class="text-sm text-slate-600">{{ number_format($mostExpensiveHour['price_without_tax'] ?? 0, 2, ',', ' ') }} c/kWh (ALV 0%)</p>
                @else
                    <p class="text-slate-500">-</p>
                @endif
            </div>

            <!-- Price Volatility -->
            <div class="bg-yellow-50 p-5 rounded-xl border-2 border-yellow-200">
                <div class="flex items-center mb-3">
                    <span class="bg-yellow-200 p-2 rounded-lg">
                        <svg class="w-5 h-5 text-yellow-700" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"></path>
                        </svg>
                    </span>
                    <h4 class="ml-3 font-semibold text-slate-900">Hintavaihtelu</h4>
                </div>
                @if ($priceVolatility['range'] !== null)
                    <p class="text-2xl font-bold text-yellow-700">
                        {{ number_format($priceVolatility['range'], 2, ',', ' ') }} c/kWh
                    </p>
                    <p class="text-sm text-slate-600">Vaihteluväli (min-max)</p>
                @else
                    <p class="text-slate-500">-</p>
                @endif
            </div>
        </div>

        <!-- Cheapest Remaining Hours & EV Charging Section -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
            <!-- Cheapest Remaining Hours -->
            @if (!empty($cheapestRemainingHours))
                <div class="bg-white rounded-2xl shadow-sm border border-slate-100 p-5">
                    <h3 class="text-lg font-semibold text-slate-900 mb-4">Edullisimmat tunnit</h3>
                    <p class="text-sm text-slate-500 mb-4">Tulevat edullisimmat tunnit (sis. huomisen)</p>
                    <div class="space-y-2">
                        @foreach ($cheapestRemainingHours as $index => $hour)
                            @php
                                $hourNum = $hour['helsinki_hour'];
                                $nextHourNum = ($hourNum + 1) % 24;
                                $isTomorrow = $hour['helsinki_date'] !== now('Europe/Helsinki')->format('Y-m-d');
                            @endphp
                            <div class="flex items-center justify-between py-2 {{ $index === 0 ? 'bg-green-50 -mx-2 px-2 rounded' : '' }}">
                                <span class="font-medium text-slate-900">
                                    {{ str_pad($hourNum, 2, '0', STR_PAD_LEFT) }}:00 - {{ str_pad($nextHourNum, 2, '0', STR_PAD_LEFT) }}:00
                                    @if ($isTomorrow)
                                        <span class="ml-2 text-xs bg-blue-100 text-blue-800 px-2 py-1 rounded">Huomenna</span>
                                    @endif
                                </span>
                                <span class="text-green-700 font-semibold">
                                    {{ number_format($hour['price_without_tax'], 2, ',', ' ') }} c/kWh
                                </span>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif

            <!-- EV Charging Section -->
            @if ($bestConsecutiveHours)
                <div class="bg-white rounded-2xl shadow-sm border border-slate-100 p-5">
                    <div class="flex items-center mb-4">
                        <span class="bg-coral-100 p-2 rounded-lg">
                            <svg class="w-6 h-6 text-coral-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"></path>
                            </svg>
                        </span>
                        <h3 class="ml-3 text-lg font-semibold text-slate-900">Sähköauton lataus</h3>
                    </div>
                    <p class="text-sm text-slate-500 mb-4">Parhaat 3 peräkkäistä tuntia lataukseen</p>

                    @php
                        $startHour = $bestConsecutiveHours['start_hour'];
                        $endHour = ($bestConsecutiveHours['end_hour'] + 1) % 24;
                    @endphp

                    <div class="bg-coral-50 rounded-lg p-4 mb-4">
                        <p class="text-sm text-coral-600 mb-1">Suositeltu latausaika</p>
                        <p class="text-2xl font-bold text-coral-700">
                            {{ str_pad($startHour, 2, '0', STR_PAD_LEFT) }}:00 - {{ str_pad($endHour, 2, '0', STR_PAD_LEFT) }}:00
                        </p>
                        <p class="text-sm text-slate-600 mt-1">
                            Keskihinta: {{ number_format($bestConsecutiveHours['average_price'], 2, ',', ' ') }} c/kWh
                        </p>
                    </div>

                    @if ($potentialSavings)
                        <div class="border-t border-slate-200 pt-4">
                            <p class="text-sm font-medium text-slate-700 mb-2">Mahdollinen säästö</p>
                            <p class="text-lg font-bold text-green-600">
                                {{ number_format($potentialSavings['savings_euros'], 2, ',', ' ') }} EUR
                            </p>
                            <p class="text-xs text-slate-500">
                                Verrattuna päivän keskihintaan ({{ number_format($potentialSavings['total_kwh'], 1, ',', ' ') }} kWh @ 3,7 kW)
                            </p>
                        </div>
                    @endif
                </div>
            @endif
        </div>

        <!-- Hourly Prices Table -->
        <div class="bg-white rounded-2xl shadow-sm border border-slate-100 mb-8">
            <div class="p-4 border-b border-slate-200">
                <h3 class="text-lg font-semibold text-slate-900">Tuntihinnat</h3>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full">
                    <thead class="bg-slate-50">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-medium text-slate-500 uppercase tracking-wider">Tunti</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-slate-500 uppercase tracking-wider">Hinta (ALV 0%)</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-slate-500 uppercase tracking-wider">Hinta (sis. ALV)</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200">
                        @foreach ($hourlyPrices as $price)
                            @php
                                $hour = $price['helsinki_hour'];
                                $currentHourNow = (int) now('Europe/Helsinki')->format('H');
                                $todayDate = now('Europe/Helsinki')->format('Y-m-d');
                                $isCurrentHour = $currentHourNow === $hour && $price['helsinki_date'] === $todayDate;
                                $isTomorrow = $price['helsinki_date'] !== $todayDate;
                                $vatPercent = round($price['vat_rate'] * 100, 1);

                                // Color coding
                                $priceValue = $price['price_without_tax'];
                                $min = $todayMinMax['min'] ?? 0;
                                $max = $todayMinMax['max'] ?? 0;
                                $range = $max - $min;
                                if ($range > 0) {
                                    $normalized = ($priceValue - $min) / $range;
                                } else {
                                    $normalized = 0.5;
                                }
                            @endphp
                            <tr class="{{ $isCurrentHour ? 'bg-coral-50' : '' }}">
                                <td class="px-4 py-3 whitespace-nowrap text-sm {{ $isCurrentHour ? 'font-bold text-coral-700' : 'text-slate-900' }}">
                                    {{ str_pad($hour, 2, '0', STR_PAD_LEFT) }}:00 - {{ str_pad(($hour + 1) % 24, 2, '0', STR_PAD_LEFT) }}:00
                                    @if ($isCurrentHour)
                                        <span class="ml-2 text-xs bg-coral-200 text-coral-800 px-2 py-1 rounded">Nyt</span>
                                    @endif
                                    @if ($isTomorrow)
                                        <span class="ml-2 text-xs bg-blue-100 text-blue-800 px-2 py-1 rounded">Huomenna</span>
                                    @endif
                                </td>
                                <td class="px-4 py-3 whitespace-nowrap text-sm">
                                    <span class="inline-flex items-center">
                                        @if ($normalized < 0.33)
                                            <span class="w-2 h-2 rounded-full bg-green-500 mr-2"></span>
                                        @elseif ($normalized < 0.66)
                                            <span class="w-2 h-2 rounded-full bg-yellow-500 mr-2"></span>
                                        @else
                                            <span class="w-2 h-2 rounded-full bg-red-500 mr-2"></span>
                                        @endif
                                        <span class="text-slate-900">{{ number_format($price['price_without_tax'] ?? 0, 2, ',', ' ') }} c/kWh</span>
                                    </span>
                                </td>
                                <td class="px-4 py-3 whitespace-nowrap text-sm text-slate-900">
                                    {{ number_format($price['price_with_tax'] ?? 0, 2, ',', ' ') }} c/kWh
                                    <span class="text-xs text-slate-400">(ALV {{ $vatPercent }}%)</span>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>

    <!-- CSV Download Button -->
        <div class="flex justify-end mb-8">
            <button
                wire:click="downloadCsv"
                wire:loading.attr="disabled"
                class="inline-flex items-center px-4 py-2 border border-slate-300 rounded-lg shadow-sm text-sm font-medium text-slate-700 bg-white hover:bg-slate-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-coral-500 disabled:opacity-50"
            >
                <svg class="w-5 h-5 mr-2 text-slate-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"></path>
                </svg>
                <span wire:loading.remove wire:target="downloadCsv">Lataa CSV</span>
                <span wire:loading wire:target="downloadCsv">Ladataan...</span>
            </button>
        </div>

        <!-- Historical Data Section (Lazy Loaded) -->
        <section class="mb-8">
            @if (!$historicalDataLoaded)
                <!-- Load Historical Data Button -->
                <div class="bg-slate-50 rounded-lg p-6 text-center">
                    <h3 class="text-lg font-semibold text-slate-900 mb-2">Historialliset hintatiedot</h3>
                    <p class="text-slate-600 mb-4">Näytä viikon hintakehitys, kuukausivertailu ja vuosivertailu.</p>
                    <button
                        wire:click="loadHistoricalData"
                        wire:loading.attr="disabled"
                        class="inline-flex items-center px-6 py-3 border border-transparent rounded-xl shadow-sm text-sm font-medium text-white bg-gradient-to-r from-coral-500 to-coral-600 hover:from-coral-400 hover:to-coral-500 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-coral-500 disabled:opacity-50"
                    >
                        <svg wire:loading.remove wire:target="loadHistoricalData" class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"></path>
                        </svg>
                        <svg wire:loading wire:target="loadHistoricalData" class="animate-spin w-5 h-5 mr-2" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                        </svg>
                        <span wire:loading.remove wire:target="loadHistoricalData">Lataa historiatiedot</span>
                        <span wire:loading wire:target="loadHistoricalData">Ladataan...</span>
                    </button>
                </div>
            @else
                <!-- Weekly Price Chart -->
                @if (!empty($weeklyChartData['labels']))
                    <div class="bg-white rounded-2xl shadow-sm border border-slate-100 p-4 md:p-6 mb-6">
                        <h3 class="text-lg font-semibold text-slate-900 mb-4">Viikon hintakehitys</h3>
                        <p class="text-sm text-slate-500 mb-4">Päivittäiset keskihinnat viimeiseltä 7 päivältä</p>
                        <div class="h-64 md:h-80">
                            <canvas id="weeklyPriceChart"></canvas>
                        </div>
                    </div>
                @endif

                <!-- Monthly and Year Comparison -->
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
                    <!-- Monthly Comparison -->
                    @if (!empty($monthlyComparison))
                        <div class="bg-white rounded-2xl shadow-sm border border-slate-100 p-5">
                            <h3 class="text-lg font-semibold text-slate-900 mb-4">Kuukausivertailu</h3>
                            <div class="grid grid-cols-2 gap-4">
                                <!-- Current Month -->
                                <div class="bg-blue-50 p-4 rounded-lg">
                                    <p class="text-sm text-slate-500">{{ $monthlyComparison['current_month_name'] }}</p>
                                    <p class="text-2xl font-bold text-blue-700">
                                        @if ($monthlyComparison['current_month_average'] !== null)
                                            {{ number_format($monthlyComparison['current_month_average'], 2, ',', ' ') }}
                                        @else
                                            -
                                        @endif
                                        <span class="text-sm font-normal">c/kWh</span>
                                    </p>
                                    <p class="text-xs text-slate-400">{{ $monthlyComparison['current_month_days'] }} päivää</p>
                                </div>

                                <!-- Last Month -->
                                <div class="bg-slate-50 p-4 rounded-lg">
                                    <p class="text-sm text-slate-500">{{ $monthlyComparison['last_month_name'] }}</p>
                                    <p class="text-2xl font-bold text-slate-700">
                                        @if ($monthlyComparison['last_month_average'] !== null)
                                            {{ number_format($monthlyComparison['last_month_average'], 2, ',', ' ') }}
                                        @else
                                            -
                                        @endif
                                        <span class="text-sm font-normal">c/kWh</span>
                                    </p>
                                    <p class="text-xs text-slate-400">{{ $monthlyComparison['last_month_days'] }} päivää</p>
                                </div>
                            </div>

                            @if ($monthlyComparison['change_percent'] !== null)
                                @php
                                    $change = $monthlyComparison['change_percent'];
                                    $isPositive = $change > 0;
                                @endphp
                                <div class="mt-4 p-3 rounded-lg {{ $isPositive ? 'bg-red-50' : 'bg-green-50' }}">
                                    <p class="text-sm {{ $isPositive ? 'text-red-700' : 'text-green-700' }}">
                                        <span class="font-medium">{{ $isPositive ? '+' : '' }}{{ number_format($change, 1, ',', ' ') }}%</span>
                                        verrattuna edelliseen kuukauteen
                                    </p>
                                </div>
                            @endif
                        </div>
                    @endif

                    <!-- Year-over-Year Comparison -->
                    @if (!empty($yearOverYearComparison) && $yearOverYearComparison['has_last_year_data'])
                        <div class="bg-white rounded-2xl shadow-sm border border-slate-100 p-5">
                            <h3 class="text-lg font-semibold text-slate-900 mb-4">Vuosivertailu</h3>
                            <p class="text-sm text-slate-500 mb-4">{{ $yearOverYearComparison['month_name'] }}</p>
                            <div class="grid grid-cols-2 gap-4">
                                <!-- Current Year -->
                                <div class="bg-blue-50 p-4 rounded-lg">
                                    <p class="text-sm text-slate-500">{{ $yearOverYearComparison['current_year'] }}</p>
                                    <p class="text-2xl font-bold text-blue-700">
                                        @if ($yearOverYearComparison['current_year_average'] !== null)
                                            {{ number_format($yearOverYearComparison['current_year_average'], 2, ',', ' ') }}
                                        @else
                                            -
                                        @endif
                                        <span class="text-sm font-normal">c/kWh</span>
                                    </p>
                                </div>

                                <!-- Last Year -->
                                <div class="bg-slate-50 p-4 rounded-lg">
                                    <p class="text-sm text-slate-500">{{ $yearOverYearComparison['last_year'] }}</p>
                                    <p class="text-2xl font-bold text-slate-700">
                                        @if ($yearOverYearComparison['last_year_average'] !== null)
                                            {{ number_format($yearOverYearComparison['last_year_average'], 2, ',', ' ') }}
                                        @else
                                            -
                                        @endif
                                        <span class="text-sm font-normal">c/kWh</span>
                                    </p>
                                </div>
                            </div>

                            @if ($yearOverYearComparison['change_percent'] !== null)
                                @php
                                    $yoyChange = $yearOverYearComparison['change_percent'];
                                    $isYoyPositive = $yoyChange > 0;
                                @endphp
                                <div class="mt-4 p-3 rounded-lg {{ $isYoyPositive ? 'bg-red-50' : 'bg-green-50' }}">
                                    <p class="text-sm {{ $isYoyPositive ? 'text-red-700' : 'text-green-700' }}">
                                        <span class="font-medium">{{ $isYoyPositive ? '+' : '' }}{{ number_format($yoyChange, 1, ',', ' ') }}%</span>
                                        verrattuna samaan kuukauteen vuonna {{ $yearOverYearComparison['last_year'] }}
                                    </p>
                                </div>
                            @endif
                        </div>
                    @endif
                </div>
            @endif
        </section>
    @endif

    <!-- Information Section -->
    <div class="bg-white rounded-2xl shadow-sm border border-slate-100 p-6">
        <h3 class="text-2xl font-bold text-slate-900 mb-4">
            Mikä on pörssisähkö ja miten hinta muodostuu?
        </h3>
        <p class="text-slate-700 mb-4">
            Tällä sivulla esitetyt hintatiedot ovat Pohjoismaiden ja Baltian maiden sähköpörssi Nordpoolin määrittämiä sähkön spot-hintoja.
            Kaupankäynnissä jokaisella päivän tunnilla on aina oma hintansa.
        </p>
        <p class="text-slate-700 mb-4">
            Hinnan määräytyminen Pohjoismaissa perustuu energialähteiden (vesivoima, tuulivoima, ydinvoima ja voimapolttoaineet hiili, öljy, maakaasu)
            tuotantoon neljällä markkina-alueella (Suomi, Norja, Ruotsi, Tanska) sekä niihin liittyvien päästöoikeuksien (päästökauppa) sääntelyyn,
            sähkönkulutukseen ja markkinapsykologiaan.
        </p>

        <h3 class="text-xl font-bold text-slate-900 mt-6 mb-4">
            Milloin seuraavan päivän hinnat julkaistaan?
        </h3>
        <p class="text-slate-700">
            Seuraavan päivän hinnat julkaistaan noin klo 13:45 Suomen aikaa. Uudet hinnat päivitetään tälle sivulle pian julkaisun jälkeen.
        </p>

        <h3 class="text-xl font-bold text-slate-900 mt-6 mb-4">
            ALV-muutokset
        </h3>
        <p class="text-slate-700">
            1.9.2024 alkaen sähkön arvonlisävero on 25,5%. Hinnat ajalta 1.12.2022 - 30.4.2023 sisältävät ALV:n 10% (väliaikainen alennus).
            Hinnat ajalta 1.5.2023 - 31.8.2024 sisältävät ALV:n 24%.
        </p>
    </div>
    </div>
</div>

@push('scripts')
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
    // Initialize daily price chart
    @if (!empty($chartData['labels']))
    document.addEventListener('DOMContentLoaded', function() {
        const ctx = document.getElementById('priceChart');
        if (ctx) {
            new Chart(ctx, {
                type: 'bar',
                data: @json($chartData),
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            display: false
                        },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    return context.parsed.y.toFixed(2).replace('.', ',') + ' c/kWh';
                                }
                            }
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            title: {
                                display: true,
                                text: 'c/kWh (ALV 0%)'
                            },
                            ticks: {
                                callback: function(value) {
                                    return value.toFixed(1).replace('.', ',');
                                }
                            }
                        },
                        x: {
                            title: {
                                display: true,
                                text: 'Kellonaika'
                            }
                        }
                    }
                }
            });
        }
    });
    @endif

    // Initialize weekly price chart (if historical data is loaded)
    @if ($historicalDataLoaded && !empty($weeklyChartData['labels']))
    document.addEventListener('DOMContentLoaded', function() {
        initWeeklyChart();
    });

    // Also handle Livewire updates
    document.addEventListener('livewire:navigated', function() {
        initWeeklyChart();
    });

    function initWeeklyChart() {
        const weeklyCtx = document.getElementById('weeklyPriceChart');
        if (weeklyCtx && !weeklyCtx.chart) {
            weeklyCtx.chart = new Chart(weeklyCtx, {
                type: 'line',
                data: @json($weeklyChartData),
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    interaction: {
                        mode: 'index',
                        intersect: false,
                    },
                    plugins: {
                        legend: {
                            display: true,
                            position: 'top',
                        },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    return context.dataset.label + ': ' + context.parsed.y.toFixed(2).replace('.', ',') + ' c/kWh';
                                }
                            }
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: false,
                            title: {
                                display: true,
                                text: 'c/kWh (ALV 0%)'
                            },
                            ticks: {
                                callback: function(value) {
                                    return value.toFixed(1).replace('.', ',');
                                }
                            }
                        },
                        x: {
                            title: {
                                display: true,
                                text: 'Päivämäärä'
                            }
                        }
                    }
                }
            });
        }
    }
    @endif

    // Reinitialize charts when Livewire updates the component
    document.addEventListener('livewire:initialized', function() {
        Livewire.hook('commit', ({ component, commit, respond, succeed, fail }) => {
            succeed(({ snapshot, effect }) => {
                // After successful update, re-init charts
                setTimeout(() => {
                    const weeklyCtx = document.getElementById('weeklyPriceChart');
                    if (weeklyCtx && !weeklyCtx.chart) {
                        initWeeklyChart && initWeeklyChart();
                    }
                }, 100);
            });
        });
    });
</script>
@endpush
