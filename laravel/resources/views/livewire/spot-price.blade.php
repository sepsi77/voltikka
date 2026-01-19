<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
    <!-- Hero Section -->
    <section class="mb-8">
        <h1 class="max-w-2xl mb-4 text-4xl font-bold text-tertiary-500 tracking-tight leading-none md:text-5xl xl:text-6xl">
            Pörssisähkön hinta
        </h1>
        <p class="max-w-2xl mb-6 font-light text-gray-500 md:text-lg lg:text-xl">
            Seuraa sähkön pörssihinnan kehitystä ja löydä päivän edullisimmat tunnit.
        </p>
    </section>

    @if ($loading)
        <div class="flex items-center justify-center py-12">
            <svg class="animate-spin h-8 w-8 text-primary-500" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
            </svg>
            <span class="ml-3 text-gray-600">Ladataan hintatietoja...</span>
        </div>
    @elseif ($error)
        <div class="bg-red-50 border border-red-200 rounded-lg p-4 mb-8">
            <p class="text-red-700">{{ $error }}</p>
        </div>
    @else
        <!-- Price Cards -->
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-8">
            <!-- Current Price -->
            <div class="bg-white p-4 rounded-xl border-2 border-primary-500 shadow">
                <h5 class="mb-2 text-xl font-bold text-gray-900">
                    @if ($currentPrice)
                        {{ number_format($currentPrice['price_with_tax'] ?? 0, 2, ',', ' ') }} c/kWh
                    @else
                        -
                    @endif
                </h5>
                <p class="text-base text-gray-500">
                    Tämänhetkinen pörssihinta ({{ now('Europe/Helsinki')->format('H') }}:00)
                </p>
            </div>

            <!-- Min/Max -->
            <div class="bg-white p-4 rounded-xl border-2 border-primary-500 shadow">
                <h5 class="text-xl font-bold text-gray-900">
                    @if ($todayMinMax['min'] !== null)
                        {{ number_format($todayMinMax['min'], 2, ',', ' ') }} / {{ number_format($todayMinMax['max'], 2, ',', ' ') }} c/kWh
                    @else
                        -
                    @endif
                </h5>
                <p class="text-base text-gray-500">
                    Päivän alin / ylin hinta (ALV 0%)
                </p>
            </div>

            <!-- Cheapest Hour -->
            <div class="bg-white p-4 rounded-xl border-2 border-primary-500 shadow">
                <h5 class="text-xl font-bold text-gray-900">
                    @if ($cheapestHour)
                        @php
                            $cheapHour = $cheapestHour['helsinki_hour'];
                            $nextHour = ($cheapHour + 1) % 24;
                        @endphp
                        {{ str_pad($cheapHour, 2, '0', STR_PAD_LEFT) }}-{{ str_pad($nextHour, 2, '0', STR_PAD_LEFT) }} ({{ number_format($cheapestHour['price_without_tax'] ?? 0, 2, ',', ' ') }} c/kWh)
                    @else
                        -
                    @endif
                </h5>
                <p class="text-base text-gray-500">
                    Edullisin tunti
                </p>
            </div>
        </div>

        <!-- Statistics Section -->
        @if ($todayStatistics['average'] !== null)
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-8">
                <div class="bg-gray-50 p-4 rounded-lg">
                    <p class="text-sm text-gray-500">Keskihinta</p>
                    <p class="text-lg font-semibold">{{ number_format($todayStatistics['average'], 2, ',', ' ') }} c/kWh</p>
                </div>
                <div class="bg-gray-50 p-4 rounded-lg">
                    <p class="text-sm text-gray-500">Mediaani</p>
                    <p class="text-lg font-semibold">{{ number_format($todayStatistics['median'], 2, ',', ' ') }} c/kWh</p>
                </div>
                <div class="bg-green-50 p-4 rounded-lg">
                    <p class="text-sm text-gray-500">Alin</p>
                    <p class="text-lg font-semibold text-green-700">{{ number_format($todayStatistics['min'], 2, ',', ' ') }} c/kWh</p>
                </div>
                <div class="bg-red-50 p-4 rounded-lg">
                    <p class="text-sm text-gray-500">Ylin</p>
                    <p class="text-lg font-semibold text-red-700">{{ number_format($todayStatistics['max'], 2, ',', ' ') }} c/kWh</p>
                </div>
            </div>
        @endif

        <!-- Hourly Prices Table -->
        @if (!empty($hourlyPrices))
            <div class="bg-white rounded-lg shadow border border-gray-200 mb-8">
                <div class="p-4 border-b border-gray-200">
                    <h3 class="text-lg font-semibold text-gray-900">Tuntihinnat</h3>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Tunti</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Hinta (ALV 0%)</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Hinta (sis. ALV)</th>
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
                                @endphp
                                <tr class="{{ $isCurrentHour ? 'bg-primary-50' : '' }}">
                                    <td class="px-4 py-3 whitespace-nowrap text-sm {{ $isCurrentHour ? 'font-bold text-primary-700' : 'text-gray-900' }}">
                                        {{ str_pad($hour, 2, '0', STR_PAD_LEFT) }}:00 - {{ str_pad(($hour + 1) % 24, 2, '0', STR_PAD_LEFT) }}:00
                                        @if ($isCurrentHour)
                                            <span class="ml-2 text-xs bg-primary-200 text-primary-800 px-2 py-1 rounded">Nyt</span>
                                        @endif
                                        @if ($isTomorrow)
                                            <span class="ml-2 text-xs bg-blue-100 text-blue-800 px-2 py-1 rounded">Huomenna</span>
                                        @endif
                                    </td>
                                    <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-900">
                                        {{ number_format($price['price_without_tax'] ?? 0, 2, ',', ' ') }} c/kWh
                                    </td>
                                    <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-900">
                                        {{ number_format($price['price_with_tax'] ?? 0, 2, ',', ' ') }} c/kWh
                                        <span class="text-xs text-gray-400">(ALV {{ $vatPercent }}%)</span>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @else
            <div class="bg-yellow-50 border border-yellow-200 rounded-lg p-4 mb-8">
                <p class="text-yellow-700">Hintatietoja ei ole vielä saatavilla. Tiedot päivitetään automaattisesti.</p>
            </div>
        @endif
    @endif

    <!-- Information Section -->
    <div class="bg-white rounded-lg shadow border border-gray-200 p-6">
        <h3 class="text-2xl font-bold text-gray-900 mb-4">
            Mikä on pörssisähkö ja miten hinta muodostuu?
        </h3>
        <p class="text-gray-700 mb-4">
            Tällä sivulla esitetyt hintatiedot ovat Pohjoismaiden ja Baltian maiden sähköpörssi Nordpoolin määrittämiä sähkön spot-hintoja.
            Kaupankäynnissä jokaisella päivän tunnilla on aina oma hintansa.
        </p>
        <p class="text-gray-700 mb-4">
            Hinnan määräytyminen Pohjoismaissa perustuu energialähteiden (vesivoima, tuulivoima, ydinvoima ja voimapolttoaineet hiili, öljy, maakaasu)
            tuotantoon neljällä markkina-alueella (Suomi, Norja, Ruotsi, Tanska) sekä niihin liittyvien päästöoikeuksien (päästökauppa) sääntelyyn,
            sähkönkulutukseen ja markkinapsykologiaan.
        </p>

        <h3 class="text-xl font-bold text-gray-900 mt-6 mb-4">
            Milloin seuraavan päivän hinnat julkaistaan?
        </h3>
        <p class="text-gray-700">
            Seuraavan päivän hinnat julkaistaan noin klo 13:45 Suomen aikaa. Uudet hinnat päivitetään tälle sivulle pian julkaisun jälkeen.
        </p>

        <h3 class="text-xl font-bold text-gray-900 mt-6 mb-4">
            ALV-muutokset
        </h3>
        <p class="text-gray-700">
            1.9.2024 alkaen sähkön arvonlisävero on 25,5%. Hinnat ajalta 1.12.2022 - 30.4.2023 sisältävät ALV:n 10% (väliaikainen alennus).
            Hinnat ajalta 1.5.2023 - 31.8.2024 sisältävät ALV:n 24%.
        </p>
    </div>
</div>
