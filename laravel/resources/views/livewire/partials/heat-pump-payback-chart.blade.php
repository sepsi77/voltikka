{{-- Collapsible cumulative-cost payback chart for one alternative.
     Expects: $alt. Reads $this->currentSystem and $calculationPeriod from the parent scope.
     Baseline (current system) draws in neutral slate; the evaluated option draws in coral. --}}
<div x-data="{ showChart: false }" class="mt-4">
    <button
        @click="showChart = !showChart"
        class="flex items-center gap-2 text-sm text-coral-600 hover:text-coral-700 font-medium"
    >
        <svg class="w-4 h-4 transition-transform" :class="{ 'rotate-90': showChart }" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
        </svg>
        <span x-text="showChart ? 'Piilota takaisinmaksukäyrä' : 'Näytä takaisinmaksukäyrä'"></span>
    </button>

    <div x-show="showChart" x-collapse class="mt-4">
        @php
            $currentAnnual = $this->currentSystem['annualCost'];
            $altAnnual = $alt['annualCost'];
            $investment = $alt['investment'];
            $years = $calculationPeriod;
            $maxCost = max($currentAnnual * $years, $investment + $altAnnual * $years);

            // SVG geometry
            $chartWidth = 320;
            $chartHeight = 180;
            $padding = 40;
            $graphWidth = $chartWidth - $padding * 2;
            $graphHeight = $chartHeight - $padding * 2;

            $currentPoints = [];
            $altPoints = [];
            for ($y = 0; $y <= $years; $y++) {
                $x = $padding + ($y / $years) * $graphWidth;
                $currentCost = $currentAnnual * $y;
                $altCost = $investment + $altAnnual * $y;
                $currentY = $padding + $graphHeight - ($maxCost > 0 ? ($currentCost / $maxCost) * $graphHeight : 0);
                $altY = $padding + $graphHeight - ($maxCost > 0 ? ($altCost / $maxCost) * $graphHeight : 0);
                $currentPoints[] = round($x, 1) . ',' . round($currentY, 1);
                $altPoints[] = round($x, 1) . ',' . round($altY, 1);
            }
            $currentPath = implode(' ', $currentPoints);
            $altPath = implode(' ', $altPoints);

            $paybackX = null;
            $paybackY = null;
            if ($alt['paybackYears'] && $alt['paybackYears'] <= $years && $maxCost > 0) {
                $paybackX = $padding + ($alt['paybackYears'] / $years) * $graphWidth;
                $paybackCost = $investment + $altAnnual * $alt['paybackYears'];
                $paybackY = $padding + $graphHeight - ($paybackCost / $maxCost) * $graphHeight;
            }
        @endphp

        <div class="bg-slate-50 rounded-lg p-4">
            <svg viewBox="0 0 {{ $chartWidth }} {{ $chartHeight }}" class="w-full h-auto" role="img" aria-label="Kumulatiivisten kustannusten kehitys: nykyinen järjestelmä vs {{ $alt['label'] }}">
                {{-- Grid lines --}}
                @for ($i = 0; $i <= 4; $i++)
                    <line x1="{{ $padding }}" y1="{{ $padding + $i * $graphHeight / 4 }}" x2="{{ $chartWidth - $padding }}" y2="{{ $padding + $i * $graphHeight / 4 }}" stroke="#e2e8f0" stroke-width="1" />
                @endfor

                {{-- Y-axis labels (thousands of euros) --}}
                @for ($i = 0; $i <= 4; $i++)
                    <text x="{{ $padding - 5 }}" y="{{ $padding + $i * $graphHeight / 4 + 4 }}" text-anchor="end" style="font-size: 9px; fill: #64748b;">{{ number_format($maxCost * (4 - $i) / 4 / 1000, 0) }}k</text>
                @endfor

                {{-- X-axis labels (years) --}}
                @foreach ([0, 5, 10, 15] as $yr)
                    @if ($yr <= $years)
                        <text x="{{ $padding + ($yr / $years) * $graphWidth }}" y="{{ $chartHeight - $padding + 15 }}" text-anchor="middle" style="font-size: 9px; fill: #64748b;">{{ $yr }}v</text>
                    @endif
                @endforeach

                {{-- Current system line (neutral baseline) --}}
                <polyline points="{{ $currentPath }}" fill="none" stroke="#94a3b8" stroke-width="2" />
                {{-- Alternative line (coral: the option being evaluated) --}}
                <polyline points="{{ $altPath }}" fill="none" stroke="#f97316" stroke-width="2" />

                {{-- Payback crossover --}}
                @if ($paybackX && $paybackY)
                    <circle cx="{{ round($paybackX, 1) }}" cy="{{ round($paybackY, 1) }}" r="5" fill="#ea580c" />
                    <line x1="{{ round($paybackX, 1) }}" y1="{{ $padding }}" x2="{{ round($paybackX, 1) }}" y2="{{ $chartHeight - $padding }}" stroke="#ea580c" stroke-width="1" stroke-dasharray="4" />
                @endif
            </svg>

            {{-- Legend --}}
            <div class="flex flex-wrap gap-4 mt-3 text-xs">
                <div class="flex items-center gap-1">
                    <span class="w-3 h-0.5 bg-slate-400"></span>
                    <span class="text-slate-600">Nykyinen ({{ $this->currentSystem['label'] }})</span>
                </div>
                <div class="flex items-center gap-1">
                    <span class="w-3 h-0.5 bg-coral-500"></span>
                    <span class="text-slate-600">{{ $alt['label'] }}</span>
                </div>
                @if ($paybackX)
                    <div class="flex items-center gap-1">
                        <span class="w-2 h-2 rounded-full bg-coral-600"></span>
                        <span class="text-slate-600">Takaisinmaksu {{ number_format($alt['paybackYears'], 1, ',', ' ') }} v</span>
                    </div>
                @endif
            </div>
        </div>
    </div>
</div>
