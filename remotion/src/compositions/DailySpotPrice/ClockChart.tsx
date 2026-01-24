import {
  AbsoluteFill,
  interpolate,
  spring,
  useCurrentFrame,
  useVideoConfig,
} from "remotion";
import { Zap } from "lucide-react";

// Brand colors
const BG_LIGHT = "#f8fafc";

// Color thresholds relative to 30-day average
function getRelativeColor(price: number, avg30d: number): string {
  const diff = ((price - avg30d) / avg30d) * 100;

  if (diff <= -30) return "#15803d"; // very cheap: dark green
  if (diff <= -15) return "#22c55e"; // cheap: green
  if (diff <= -5) return "#86efac";  // slightly cheap: light green
  if (diff <= 5) return "#fde047";   // around average: yellow
  if (diff <= 15) return "#fb923c";  // slightly expensive: orange
  if (diff <= 30) return "#ef4444";  // expensive: red
  return "#b91c1c";                  // very expensive: dark red
}

// Generate SVG path for a clock segment
function getSegmentPath(hourIndex: number, innerR: number, outerR: number): string {
  const startAngle = (hourIndex * 15 - 90) * Math.PI / 180;
  const endAngle = ((hourIndex + 1) * 15 - 90) * Math.PI / 180;
  const cx = 200, cy = 200;

  const x1 = cx + innerR * Math.cos(startAngle);
  const y1 = cy + innerR * Math.sin(startAngle);
  const x2 = cx + outerR * Math.cos(startAngle);
  const y2 = cy + outerR * Math.sin(startAngle);
  const x3 = cx + outerR * Math.cos(endAngle);
  const y3 = cy + outerR * Math.sin(endAngle);
  const x4 = cx + innerR * Math.cos(endAngle);
  const y4 = cy + innerR * Math.sin(endAngle);

  return `M${x1},${y1} L${x2},${y2} A${outerR},${outerR} 0 0,1 ${x3},${y3} L${x4},${y4} A${innerR},${innerR} 0 0,0 ${x1},${y1}`;
}

type ClockChartProps = {
  prices: number[];
  avg30d: number;
  date: string;
};

export const ClockChart: React.FC<ClockChartProps> = ({
  prices,
  avg30d,
  date,
}) => {
  const frame = useCurrentFrame();
  const { fps } = useVideoConfig();

  // Animation sequence (following progressive disclosure):
  // 1. Title (0s)
  // 2. Center/30d avg (0.4s) - needs 1-1.5s to absorb
  // 3. Hour markers (1.5s) - after center has landed
  // 4. Hour segments sweep (2s, each +0.05s)
  // 5. Legend (after segments done ~3.5s)

  const titleSpring = spring({
    frame,
    fps,
    config: { damping: 25, stiffness: 300 },
  });

  const centerSpring = spring({
    frame: frame - 0.4 * fps,
    fps,
    config: { damping: 20, stiffness: 180 },
  });

  // Hour markers appear after center has landed (~1s absorption time)
  const hourMarkersSpring = spring({
    frame: frame - 1.5 * fps,
    fps,
    config: { damping: 22, stiffness: 200 },
  });

  // Segments start after hour markers set the context
  const segmentsStartTime = 2.0; // seconds

  // Legend appears after all segments (24 × 0.05 = 1.2s + start time + buffer)
  const legendSpring = spring({
    frame: frame - 3.8 * fps,
    fps,
    config: { damping: 20, stiffness: 150 },
  });

  const footerSpring = spring({
    frame: frame - 0.3 * fps,
    fps,
    config: { damping: 20, stiffness: 150 },
  });

  // Clock dimensions
  const innerRadius = 105;
  const outerRadius = 175;

  return (
    <AbsoluteFill style={{ backgroundColor: BG_LIGHT, fontFamily: "var(--font-primary)" }}>
      {/* Subtle background */}
      <div
        className="absolute inset-0"
        style={{
          background: "radial-gradient(circle at 50% 45%, rgba(249,115,22,0.03) 0%, transparent 60%)",
        }}
      />

      {/* Title */}
      <div
        className="absolute left-0 right-0 text-center"
        style={{
          top: 80,
          opacity: titleSpring,
          transform: `translateY(${interpolate(titleSpring, [0, 1], [-20, 0])}px)`,
        }}
      >
        <h1 className="text-7xl font-black" style={{ color: "#1e293b" }}>
          Tänään vs. <span style={{ color: "#f97316" }}>keskiarvo</span>
        </h1>
        <p className="text-3xl text-slate-500 mt-4 font-medium">{date}</p>
      </div>

      {/* Clock container - centered between title and footer */}
      <div
        className="absolute inset-0 flex items-center justify-center"
        style={{ paddingTop: 180, paddingBottom: 350 }}
      >
        <div
          className="relative"
          style={{
            width: 750,
            height: 750,
          }}
        >
          {/* Clock SVG */}
          <svg viewBox="0 0 400 400" className="w-full h-full">
            {/* Hour segments - sweep around the clock */}
            {prices.map((price, i) => {
              // Staggered reveal: each segment appears 0.05s after the previous
              // Creates a "filling" effect around the clock starting from midnight
              const segmentDelay = i * 0.05;
              const segmentSpring = spring({
                frame: frame - (segmentsStartTime + segmentDelay) * fps,
                fps,
                config: { damping: 14, stiffness: 100 },
              });

              const color = getRelativeColor(price, avg30d);

              // Animate both the outer radius AND opacity for a nice reveal
              const animatedOuter = interpolate(
                segmentSpring,
                [0, 1],
                [innerRadius + 5, outerRadius]
              );

              // Scale from center for a "pop" effect
              const scaleProgress = interpolate(segmentSpring, [0, 0.5, 1], [0.7, 1.05, 1]);

              return (
                <g
                  key={i}
                  style={{
                    transformOrigin: "200px 200px",
                    transform: `scale(${scaleProgress})`,
                    opacity: segmentSpring,
                  }}
                >
                  <path
                    d={getSegmentPath(i, innerRadius, animatedOuter)}
                    fill={color}
                    stroke={BG_LIGHT}
                    strokeWidth={2}
                  />
                </g>
              );
            })}

            {/* Center circle with pop effect */}
            <circle
              cx={200}
              cy={200}
              r={95}
              fill="#0f172a"
              style={{
                opacity: centerSpring,
                transform: `scale(${interpolate(centerSpring, [0, 0.6, 1], [0, 1.08, 1])})`,
                transformOrigin: "200px 200px",
              }}
            />

            {/* Center text */}
            <g style={{ opacity: centerSpring }}>
              <text
                x={200}
                y={168}
                textAnchor="middle"
                fill="#94a3b8"
                fontSize={14}
                fontWeight={600}
                letterSpacing="0.05em"
              >
                30 PV KESKIARVO
              </text>
              <text
                x={200}
                y={218}
                textAnchor="middle"
                fill="white"
                fontSize={52}
                fontWeight={900}
              >
                {avg30d.toFixed(1)}
              </text>
              <text
                x={200}
                y={248}
                textAnchor="middle"
                fill="#64748b"
                fontSize={16}
                fontWeight={600}
              >
                c/kWh
              </text>
            </g>

            {/* Hour markers - positioned outside the segments */}
            <g
              style={{
                opacity: hourMarkersSpring,
                transform: `scale(${interpolate(hourMarkersSpring, [0, 1], [0.9, 1])})`,
                transformOrigin: "200px 200px",
              }}
            >
              <text x={200} y={16} textAnchor="middle" fill="#475569" fontSize={22} fontWeight={700}>00</text>
              <text x={390} y={205} textAnchor="middle" fill="#475569" fontSize={22} fontWeight={700}>06</text>
              <text x={200} y={397} textAnchor="middle" fill="#475569" fontSize={22} fontWeight={700}>12</text>
              <text x={10} y={205} textAnchor="middle" fill="#475569" fontSize={22} fontWeight={700}>18</text>
            </g>
          </svg>
        </div>
      </div>

      {/* Legend */}
      <div
        className="absolute left-0 right-0 flex flex-col items-center"
        style={{
          bottom: 140,
          opacity: legendSpring,
          transform: `translateY(${interpolate(legendSpring, [0, 1], [15, 0])}px)`,
        }}
      >
        <div className="flex justify-center gap-16 mb-6">
          <div className="flex items-center gap-4">
            <div className="w-8 h-8 rounded-full bg-[#22c55e]" />
            <div className="flex flex-col">
              <span className="text-slate-700 font-bold text-2xl">Alle keskiarvon</span>
              <span className="text-slate-500 text-xl">Halvempi tunti</span>
            </div>
          </div>
          <div className="flex items-center gap-4">
            <div className="w-8 h-8 rounded-full bg-[#ef4444]" />
            <div className="flex flex-col">
              <span className="text-slate-700 font-bold text-2xl">Yli keskiarvon</span>
              <span className="text-slate-500 text-xl">Kalliimpi tunti</span>
            </div>
          </div>
        </div>
      </div>

      {/* Footer - matching first scene */}
      <div
        className="absolute bottom-0 left-0 right-0"
        style={{
          height: 90,
          opacity: footerSpring,
          transform: `translateY(${interpolate(footerSpring, [0, 1], [90, 0])}px)`,
        }}
      >
        <div className="absolute inset-0 bg-[#0f172a]" />
        <div
          className="absolute top-0 left-0 right-0"
          style={{
            height: 4,
            background: "linear-gradient(90deg, #f97316 0%, #ea580c 100%)",
          }}
        />
        <div className="relative h-full flex items-center px-12">
          <div
            className="rounded-full flex items-center justify-center mr-5"
            style={{
              width: 56,
              height: 56,
              background: "linear-gradient(135deg, #f97316 0%, #ea580c 100%)",
            }}
          >
            <Zap size={32} strokeWidth={2.5} className="text-white" fill="white" />
          </div>
          <span
            className="font-black tracking-tight"
            style={{ fontSize: "36px", color: "white" }}
          >
            Voltikka.fi
          </span>
          <span
            className="ml-auto font-medium"
            style={{ fontSize: "26px", color: "#94a3b8" }}
          >
            Suomen kattavin energiapalvelu
          </span>
        </div>
      </div>
    </AbsoluteFill>
  );
};
