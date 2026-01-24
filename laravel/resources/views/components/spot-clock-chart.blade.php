@props([
    'prices' => [],
    'avg30d' => null,
])

@php
    /**
     * Calculate the color for a price based on its percentage difference from the average.
     *
     * Color tiers (7-tier scale):
     * - <=−30%: #15803d (dark green)
     * - <=−15%: #22c55e (green)
     * - <=−5%: #86efac (light green)
     * - <=+5%: #fde047 (yellow)
     * - <=+15%: #fb923c (orange)
     * - <=+30%: #ef4444 (red)
     * - >+30%: #b91c1c (dark red)
     */
    function getClockSegmentColor(float $price, float $avg): string
    {
        if ($avg <= 0) return '#fde047'; // Default to yellow if no average

        $percentDiff = (($price - $avg) / $avg) * 100;

        if ($percentDiff <= -30) return '#15803d';
        if ($percentDiff <= -15) return '#22c55e';
        if ($percentDiff <= -5) return '#86efac';
        if ($percentDiff <= 5) return '#fde047';
        if ($percentDiff <= 15) return '#fb923c';
        if ($percentDiff <= 30) return '#ef4444';
        return '#b91c1c';
    }

    /**
     * Generate SVG arc path for a clock segment.
     *
     * @param int $hour Hour (0-23)
     * @param float $innerRadius Inner radius of the segment
     * @param float $outerRadius Outer radius of the segment
     * @param float $cx Center X
     * @param float $cy Center Y
     * @return string SVG path data
     */
    function getClockSegmentPath(int $hour, float $innerRadius, float $outerRadius, float $cx, float $cy): string
    {
        // Each hour is 15 degrees (360/24)
        // Start at top (midnight = -90 degrees in standard coords)
        $segmentAngle = 15;
        $startAngle = ($hour * $segmentAngle) - 90;
        $endAngle = $startAngle + $segmentAngle;

        // Convert to radians
        $startRad = deg2rad($startAngle);
        $endRad = deg2rad($endAngle);

        // Calculate the four corners of the segment
        $x1 = $cx + $innerRadius * cos($startRad);
        $y1 = $cy + $innerRadius * sin($startRad);
        $x2 = $cx + $outerRadius * cos($startRad);
        $y2 = $cy + $outerRadius * sin($startRad);
        $x3 = $cx + $outerRadius * cos($endRad);
        $y3 = $cy + $outerRadius * sin($endRad);
        $x4 = $cx + $innerRadius * cos($endRad);
        $y4 = $cy + $innerRadius * sin($endRad);

        // Arc flags: large-arc=0 (less than 180°), sweep=1 (clockwise)
        $largeArc = 0;
        $sweep = 1;

        // Build the path: start at inner-start, line to outer-start, arc to outer-end, line to inner-end, arc back
        return sprintf(
            'M %.2f %.2f L %.2f %.2f A %.2f %.2f 0 %d %d %.2f %.2f L %.2f %.2f A %.2f %.2f 0 %d %d %.2f %.2f Z',
            $x1, $y1,           // Move to inner start
            $x2, $y2,           // Line to outer start
            $outerRadius, $outerRadius, $largeArc, $sweep, $x3, $y3,  // Arc to outer end
            $x4, $y4,           // Line to inner end
            $innerRadius, $innerRadius, $largeArc, 0, $x1, $y1         // Arc back to inner start (counter-clockwise)
        );
    }

    // SVG dimensions and positioning
    $viewBoxSize = 400;
    $cx = 200;
    $cy = 200;
    $innerRadius = 105;
    $outerRadius = 175;
    $centerRadius = 85;

    // Index prices by hour for easy lookup
    $pricesByHour = [];
    foreach ($prices as $price) {
        $pricesByHour[$price['hour']] = $price['price_with_vat'];
    }

    // Generate segment data
    $segments = [];
    for ($hour = 0; $hour < 24; $hour++) {
        $price = $pricesByHour[$hour] ?? null;
        if ($price !== null && $avg30d !== null) {
            $segments[] = [
                'hour' => $hour,
                'price' => $price,
                'color' => getClockSegmentColor($price, $avg30d),
                'path' => getClockSegmentPath($hour, $innerRadius, $outerRadius, $cx, $cy),
            ];
        }
    }

    // Hour marker positions (00, 06, 12, 18)
    $hourMarkers = [
        ['hour' => 0, 'label' => '00', 'angle' => -90],
        ['hour' => 6, 'label' => '06', 'angle' => 0],
        ['hour' => 12, 'label' => '12', 'angle' => 90],
        ['hour' => 18, 'label' => '18', 'angle' => 180],
    ];

    $markerRadius = $outerRadius + 18;
@endphp

<div class="bg-white rounded-2xl shadow-sm border border-slate-100 p-4 sm:p-6">
    <h3 class="text-lg font-semibold text-slate-900 mb-4 text-center">Tuntihinnat vs. 30 pv keskiarvo</h3>

    <div class="flex justify-center">
        <svg
            viewBox="0 0 {{ $viewBoxSize }} {{ $viewBoxSize }}"
            class="w-full max-w-[280px] sm:max-w-[350px]"
            role="img"
            aria-label="24-tunnin kellotaulukko, jossa tuntihinnat verrattuna 30 päivän keskiarvoon"
        >
            <!-- Hour segments -->
            <g class="clock-segments">
                @foreach ($segments as $index => $segment)
                    <path
                        d="{{ $segment['path'] }}"
                        fill="{{ $segment['color'] }}"
                        class="clock-segment"
                        style="--segment-index: {{ $index }}"
                        data-hour="{{ $segment['hour'] }}"
                        data-price="{{ number_format($segment['price'], 2) }}"
                    >
                        <title>{{ sprintf('%02d:00', $segment['hour']) }} - {{ number_format($segment['price'], 2) }} c/kWh</title>
                    </path>
                @endforeach
            </g>

            <!-- Center circle with average -->
            <g class="clock-center">
                <circle
                    cx="{{ $cx }}"
                    cy="{{ $cy }}"
                    r="{{ $centerRadius }}"
                    fill="#0f172a"
                    stroke="#334155"
                    stroke-width="2"
                />
                <text
                    x="{{ $cx }}"
                    y="{{ $cy - 20 }}"
                    text-anchor="middle"
                    fill="#94a3b8"
                    font-size="11"
                    font-weight="500"
                >30 PV KESKIARVO</text>
                <text
                    x="{{ $cx }}"
                    y="{{ $cy + 12 }}"
                    text-anchor="middle"
                    fill="#f1f5f9"
                    font-size="28"
                    font-weight="700"
                >{{ number_format($avg30d ?? 0, 2) }}</text>
                <text
                    x="{{ $cx }}"
                    y="{{ $cy + 32 }}"
                    text-anchor="middle"
                    fill="#94a3b8"
                    font-size="12"
                >c/kWh</text>
            </g>

            <!-- Hour markers -->
            <g class="clock-markers">
                @foreach ($hourMarkers as $marker)
                    @php
                        $rad = deg2rad($marker['angle']);
                        $mx = $cx + $markerRadius * cos($rad);
                        $my = $cy + $markerRadius * sin($rad);
                    @endphp
                    <text
                        x="{{ $mx }}"
                        y="{{ $my + 4 }}"
                        text-anchor="middle"
                        fill="#475569"
                        font-size="13"
                        font-weight="600"
                    >{{ $marker['label'] }}</text>
                @endforeach
            </g>
        </svg>
    </div>

    <!-- Legend -->
    <div class="clock-legend flex justify-center gap-6 mt-4 text-sm">
        <div class="flex items-center gap-2">
            <span class="w-3 h-3 rounded-full bg-green-500"></span>
            <span class="text-slate-600">Alle keskiarvon</span>
        </div>
        <div class="flex items-center gap-2">
            <span class="w-3 h-3 rounded-full bg-red-500"></span>
            <span class="text-slate-600">Yli keskiarvon</span>
        </div>
    </div>
</div>
