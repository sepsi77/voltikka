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
    // Increased viewBox to 440 to ensure hour markers are not cut off
    $viewBoxSize = 440;
    $cx = 220;
    $cy = 220;
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
            $percentDiff = $avg30d > 0 ? (($price - $avg30d) / $avg30d) * 100 : 0;

            // Calculate the center angle of the segment (midpoint) for tooltip positioning
            $segmentAngle = 15;
            $startAngle = ($hour * $segmentAngle) - 90;
            $centerAngle = $startAngle + ($segmentAngle / 2);
            $centerRad = deg2rad($centerAngle);

            // Position tooltip at outer edge of segment
            $tooltipRadius = $outerRadius + 10;
            $tooltipSvgX = $cx + $tooltipRadius * cos($centerRad);
            $tooltipSvgY = $cy + $tooltipRadius * sin($centerRad);

            $segments[] = [
                'hour' => $hour,
                'price' => $price,
                'percentDiff' => round($percentDiff, 1),
                'color' => getClockSegmentColor($price, $avg30d),
                'path' => getClockSegmentPath($hour, $innerRadius, $outerRadius, $cx, $cy),
                'tooltipSvgX' => round($tooltipSvgX, 2),
                'tooltipSvgY' => round($tooltipSvgY, 2),
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

    $markerRadius = $outerRadius + 22;
@endphp

<div class="bg-white rounded-2xl shadow-sm border border-slate-100 p-4 sm:p-6">
    <h3 class="text-lg font-semibold text-slate-900 mb-4 text-center">Tuntihinnat vs. 30 pv keskiarvo</h3>

    <div
        class="flex justify-center relative"
        x-data="{
            hovered: null,
            tooltipX: 0,
            tooltipY: 0,
            segments: {{ Js::from($segments) }},
            viewBoxSize: {{ $viewBoxSize }},
            showTooltip(event, segment) {
                this.hovered = segment;
                // Get the SVG element and its dimensions
                const svg = this.$el.querySelector('svg');
                const svgRect = svg.getBoundingClientRect();
                const containerRect = this.$el.getBoundingClientRect();

                // Calculate scale factor between viewBox and actual SVG size
                const scale = svgRect.width / this.viewBoxSize;

                // Convert SVG coordinates to pixel coordinates relative to container
                const pixelX = (segment.tooltipSvgX * scale) + (svgRect.left - containerRect.left);
                const pixelY = (segment.tooltipSvgY * scale) + (svgRect.top - containerRect.top);

                this.tooltipX = pixelX;
                this.tooltipY = pixelY;
            },
            hideTooltip() {
                this.hovered = null;
            },
            formatDiff(diff) {
                return diff >= 0 ? '+' + diff : diff;
            }
        }"
    >
        <!-- Tooltip -->
        <div
            x-show="hovered"
            x-transition:enter="transition ease-out duration-150"
            x-transition:enter-start="opacity-0 translate-y-1"
            x-transition:enter-end="opacity-100 translate-y-0"
            x-transition:leave="transition ease-in duration-100"
            x-transition:leave-start="opacity-100 translate-y-0"
            x-transition:leave-end="opacity-0 translate-y-1"
            class="clock-tooltip"
            :style="'left: ' + tooltipX + 'px; top: ' + tooltipY + 'px;'"
        >
            <div class="font-semibold" x-text="hovered ? String(hovered.hour).padStart(2, '0') + ':00–' + String((hovered.hour + 1) % 24).padStart(2, '0') + ':00' : ''"></div>
            <div class="flex items-center gap-2">
                <span x-text="hovered ? hovered.price.toFixed(2) + ' c/kWh' : ''"></span>
                <span
                    class="text-xs px-1.5 py-0.5 rounded"
                    :class="hovered && hovered.percentDiff <= 0 ? 'bg-green-500/20 text-green-300' : 'bg-red-500/20 text-red-300'"
                    x-text="hovered ? formatDiff(hovered.percentDiff) + '%' : ''"
                ></span>
            </div>
        </div>

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
                        stroke="#ffffff"
                        stroke-width="1.5"
                        class="clock-segment"
                        style="--segment-index: {{ $index }}"
                        data-hour="{{ $segment['hour'] }}"
                        data-price="{{ number_format($segment['price'], 2) }}"
                        @mouseenter="showTooltip($event, segments[{{ $index }}])"
                        @mouseleave="hideTooltip()"
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
                        font-size="16"
                        font-weight="600"
                    >{{ $marker['label'] }}</text>
                @endforeach
            </g>
        </svg>
    </div>

    <!-- Legend -->
    <div class="clock-legend flex justify-center gap-6 mt-4 text-base">
        <div class="flex items-center gap-2">
            <span class="w-4 h-4 rounded-full bg-green-500"></span>
            <span class="text-slate-600">Alle keskiarvon</span>
        </div>
        <div class="flex items-center gap-2">
            <span class="w-4 h-4 rounded-full bg-red-500"></span>
            <span class="text-slate-600">Yli keskiarvon</span>
        </div>
    </div>
</div>
