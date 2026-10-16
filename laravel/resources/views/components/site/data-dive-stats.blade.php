@props([
    'dive',
])
{{--
    Key figures of one contract type on /sahkosopimus/tilastot (round 3, Paper "R3 · Data ·
    Tilastot"): the energy price first and large, then the 30-day change and the change since
    the start of the data. A type that is no longer on sale also shows its last observation
    date. The offer count is not repeated here: the count under the type title states it.
--}}
@php
    $fmtNum = fn ($value, $decimals = 2) => $value === null ? '–' : number_format((float) $value, $decimals, ',', ' ');
    $fmtPct = function (?float $delta) {
        if ($delta === null) {
            return '–';
        }
        $sign = $delta > 0 ? '+' : ($delta < 0 ? '−' : '±');

        return $sign.number_format(abs($delta), 0, ',', ' ').' %';
    };
    $deltaClass = fn (?float $delta) => match (true) {
        $delta === null => 'data-delta--none',
        $delta < -0.5 => 'data-delta--down',
        $delta > 0.5 => 'data-delta--up',
        default => 'data-delta--flat',
    };
    $fiDate = fn ($date) => $date ? \Illuminate\Support\Carbon::parse($date)->translatedFormat('j.n.Y') : '–';
@endphp
<dl {{ $attributes->class(['data-stats']) }}>
    <div class="data-stats-lead">
        <dt>Energiahinta nyt</dt>
        <dd>{{ $fmtNum($dive['current'], 2) }}<span class="data-stats-unit">&nbsp;{{ $dive['unit'] }}</span></dd>
    </div>
    <div>
        <dt>30 päivän muutos</dt>
        <dd class="{{ $deltaClass($dive['delta_30d_pct']) }}">{{ $fmtPct($dive['delta_30d_pct']) }}</dd>
    </div>
    <div>
        <dt>Aineiston alusta</dt>
        <dd class="{{ $deltaClass($dive['delta_since_start_pct']) }}">{{ $fmtPct($dive['delta_since_start_pct']) }}</dd>
    </div>
    @unless ($dive['is_current'])
        <div>
            <dt>Viimeinen havainto</dt>
            <dd class="tabular-nums">{{ $fiDate($dive['latest_observation_date']) }}</dd>
        </div>
    @endunless
</dl>
