{{-- Collapsible cumulative-cost payback chart for one alternative (Paper board C1D-0).
     Expects: $alt. Reads $this->currentSystem and $calculationPeriod from the parent scope.
     Two cumulative lines on an inked baseline: the current system in field-edge grey, the
     evaluated option in fox. The payback crossover is an aurora point with a dashed drop line. --}}
<div x-data="{ showChart: false }" class="energy-payback">
    <div class="energy-payback-side">
        <button
            @click="showChart = !showChart"
            :aria-expanded="showChart.toString()"
            class="energy-payback-toggle vk-focus"
        >
            <svg width="14" height="14" viewBox="0 0 14 14" class="transition-transform motion-reduce:transition-none" :class="{ 'rotate-90': showChart }" aria-hidden="true" focusable="false"><path d="M5 3.5l3.5 3.5L5 10.5" fill="none" style="stroke: var(--color-fox-deep)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" /></svg>
            <span x-text="showChart ? 'Piilota takaisinmaksukäyrä' : 'Näytä takaisinmaksukäyrä'">Näytä takaisinmaksukäyrä</span>
        </button>

        <ul x-show="showChart" x-cloak class="energy-payback-legend">
            <li><i class="energy-line energy-line--current"></i>Nykyinen ({{ $this->currentSystem['label'] }})</li>
            <li><i class="energy-line energy-line--option"></i>{{ $alt['label'] }}</li>
            @if ($alt['paybackYears'] && $alt['paybackYears'] <= $calculationPeriod)
                <li><i class="energy-dot"></i>Takaisinmaksu {{ number_format($alt['paybackYears'], 1, ',', ' ') }} v</li>
            @endif
        </ul>
    </div>

    <div x-show="showChart" x-collapse class="energy-payback-chart">
        @php
            $currentAnnual = $this->currentSystem['annualCost'];
            $altAnnual = $alt['annualCost'];
            $investment = $alt['investment'];
            $years = max(1, (int) $calculationPeriod);
            $maxCost = max($currentAnnual * $years, $investment + $altAnnual * $years);

            // SVG geometry (board: 560x220, plot from x 50 to 540 and y 20 to 200).
            $chartWidth = 560;
            $chartHeight = 230;
            $left = 50;
            $right = 540;
            $top = 20;
            $bottom = 200;
            $graphWidth = $right - $left;
            $graphHeight = $bottom - $top;
            $x = fn ($year) => round($left + ($year / $years) * $graphWidth, 1);
            $y = fn ($cost) => round($bottom - ($maxCost > 0 ? ($cost / $maxCost) * $graphHeight : 0), 1);

            $paybackX = null;
            $paybackY = null;
            if ($alt['paybackYears'] && $alt['paybackYears'] <= $years && $maxCost > 0) {
                $paybackX = $x($alt['paybackYears']);
                $paybackY = $y($investment + $altAnnual * $alt['paybackYears']);
            }
            $yearTicks = array_values(array_filter([0, 5, 10, 15, 20, 25, 30], fn ($tick) => $tick <= $years));
        @endphp

        <svg viewBox="0 0 {{ $chartWidth }} {{ $chartHeight }}" class="h-auto w-full" role="img" aria-label="Kumulatiivisten kustannusten kehitys: nykyinen järjestelmä vs {{ $alt['label'] }}">
            {{-- Grid lines and y labels (thousands of euros) --}}
            @for ($i = 1; $i <= 4; $i++)
                @php $gridY = $bottom - $i * $graphHeight / 4; @endphp
                <path d="M{{ $left }} {{ $gridY }} H{{ $right }}" style="stroke: var(--color-edge)" />
                <text x="{{ $left - 8 }}" y="{{ $gridY + 4 }}" text-anchor="end" class="energy-chart-label">{{ number_format($maxCost * $i / 4 / 1000, 0) }}k</text>
            @endfor

            {{-- Year marks --}}
            @foreach ($yearTicks as $tick)
                @if ($tick > 0 && $tick < $years)
                    <path d="M{{ $x($tick) }} {{ $top }} V{{ $bottom }}" style="stroke: var(--color-edge)" stroke-dasharray="2 3" />
                @endif
                <text x="{{ $x($tick) }}" y="{{ $bottom + 20 }}" text-anchor="middle" class="energy-chart-label">{{ $tick }}v</text>
            @endforeach

            {{-- Inked axes --}}
            <path d="M{{ $left }} {{ $bottom }} C 200 199, 380 201, {{ $right }} 199.4 M{{ $left }} {{ $top }} V{{ $bottom }}" fill="none" style="stroke: var(--color-ink)" stroke-width="1.6" stroke-linecap="round" />

            {{-- Current system line (neutral baseline) --}}
            <path d="M{{ $x(0) }} {{ $y(0) }} L{{ $x($years) }} {{ $y($currentAnnual * $years) }}" fill="none" style="stroke: var(--color-field-edge)" stroke-width="2.5" stroke-linecap="round" />
            {{-- Alternative line (fox: the option being evaluated) --}}
            <path d="M{{ $x(0) }} {{ $y($investment) }} L{{ $x($years) }} {{ $y($investment + $altAnnual * $years) }}" fill="none" style="stroke: var(--color-fox)" stroke-width="3" stroke-linecap="round" />

            {{-- Payback crossover --}}
            @if ($paybackX && $paybackY)
                <path d="M{{ $paybackX }} {{ $paybackY + 7 }} V{{ $bottom - 1 }}" style="stroke: var(--color-aurora)" stroke-width="1.5" stroke-dasharray="3 3" />
                <circle cx="{{ $paybackX }}" cy="{{ $paybackY }}" r="7" style="fill: var(--color-aurora); stroke: var(--color-ink)" stroke-width="1.5" />
            @endif
        </svg>
    </div>
</div>
