{{--
    Appliance "best time" entry on /spot-price (Paper board EBO-0 "Kodin energiavinkit").
    No card: a villager does the task above the time window. Only /spot-price uses this partial.
    Required vars:
      - $title:        appliance name (e.g. "Saunan lämmitys")
      - $assumption:   one-line assumption + permitted window (e.g. "Illalla 17–22, 8 kW kiuas")
      - $startHour:    int 0–23
      - $endHour:      int 0–23 (inclusive end of the cheap window)
      - $startDate:    ?string Y-m-d for the first recommended hour
      - $endDate:      ?string Y-m-d for the last recommended hour
      - $cost:         array with keys (one of):
                         ['type' => 'cents', 'value' => float]   for total cents
                         ['type' => 'rate',  'value' => float]   for c/kWh
      - $diffPercent:  ?float, signed, vs 30 pv ka (-40.5 = 40.5% halvempi)
      - $savingsEuros:    ?float, euros saved compared to the worst case in the same permitted window
      - $comparisonLabel: string, what "kallein aika" means for this card.
      - $art:             season-bound villager art name from scripts/build-design-art.sh
--}}
@php
    $hStart = str_pad($startHour, 2, '0', STR_PAD_LEFT);
    $hEnd   = str_pad(($endHour + 1) % 24, 2, '0', STR_PAD_LEFT);

    $startDateValue = $startDate ?? null;
    $endDateValue = $endDate ?? null;
    $today = now('Europe/Helsinki')->toDateString();
    $tomorrow = now('Europe/Helsinki')->addDay()->toDateString();

    $formatTipDate = function (?string $date) use ($today, $tomorrow): ?string {
        if (!$date) {
            return null;
        }

        return match ($date) {
            $today => 'tänään',
            $tomorrow => 'huomenna',
            default => \Carbon\Carbon::parse($date, 'Europe/Helsinki')->isoFormat('dd D.M.'),
        };
    };

    $startDateLabel = $formatTipDate($startDateValue);
    $endDateLabel = $formatTipDate($endDateValue);
    $dateLabel = $startDateLabel;
    if ($startDateLabel && $endDateLabel && $startDateValue !== $endDateValue) {
        $dateLabel = $startDateLabel . '–' . $endDateLabel;
    }
@endphp
<article class="spot-tip">
    <div class="spot-tip-art">
        <img
            src="{{ \App\Support\Season::art($art) }}"
            alt=""
            width="180"
            height="180"
            loading="lazy"
            decoding="async"
            class="relative z-[1] h-full w-full object-contain"
        >
        {{-- Phone: each villager stands on its own piece of the season ground line. --}}
        <x-site.spot-ground class="spot-tip-ground lg:hidden" />
    </div>

    <h3 class="pt-1.5 font-display text-[18px] font-extrabold leading-[22px] text-ink sm:text-[20px] sm:leading-6">{{ $title }}</h3>
    <p class="font-display text-[34px] font-extrabold leading-[38px] tracking-[-0.02em] text-ink tabular-nums sm:text-[40px] sm:leading-[44px]">
        {{ $hStart }}–{{ $hEnd }}
    </p>
    @if ($dateLabel)
        <p class="text-[13px] font-extrabold leading-4 uppercase tracking-[0.08em] text-fox-deep">{{ $dateLabel }}</p>
    @endif
    <p class="text-[13px] leading-4 text-ink-muted">{{ $assumption }}</p>

    <p class="flex flex-wrap items-baseline justify-center gap-x-2 pt-1.5 tabular-nums">
        <span class="text-[17px] font-extrabold leading-[22px] text-ink">
            @if ($cost['type'] === 'cents')
                {{ number_format($cost['value'], 0, ',', ' ') }} senttiä
            @else
                {{ number_format($cost['value'], 2, ',', ' ') }} c/kWh
            @endif
        </span>
        @if ($diffPercent !== null)
            @if ($diffPercent < -5)
                <span class="text-[14px] font-extrabold leading-[18px] text-aurora">↓ {{ number_format(abs($diffPercent), 0, ',', ' ') }} %</span>
            @elseif ($diffPercent > 5)
                <span class="text-[14px] font-extrabold leading-[18px] text-price-high">↑ {{ number_format($diffPercent, 0, ',', ' ') }} %</span>
            @endif
        @endif
    </p>

    @if ($savingsEuros !== null && $savingsEuros > 0)
        <p class="text-[14px] leading-[18px] text-ink-body tabular-nums">
            Säästät {{ number_format($savingsEuros, 2, ',', ' ') }} €
        </p>
        <p class="text-[13px] leading-4 text-ink-muted">{{ $comparisonLabel }}</p>
    @endif
</article>
