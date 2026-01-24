import {
  AbsoluteFill,
  interpolate,
  spring,
  useCurrentFrame,
  useVideoConfig,
} from "remotion";
import { Zap, TrendingDown, TrendingUp } from "lucide-react";

// Brand colors
const CORAL = "#f97316";
const BG_LIGHT = "#f8fafc";
const GREEN = "#22c55e";
const RED = "#ef4444";

// Spring configurations
const SNAP = { damping: 25, stiffness: 300 };
const FLOW = { damping: 20, stiffness: 150 };

type PriceChartProps = {
  hours: string[];
  prices: number[];
  colors: string[];
  cheapestHour: { hour: number; price: number; label: string } | null;
  expensiveHour: { hour: number; price: number; label: string } | null;
  averagePrice: number | null;
};

export const PriceChart: React.FC<PriceChartProps> = ({
  hours,
  prices,
  colors,
  cheapestHour,
  expensiveHour,
}) => {
  const frame = useCurrentFrame();
  const { fps } = useVideoConfig();

  if (prices.length === 0) {
    return null;
  }

  const maxPrice = Math.max(...prices);
  const minPrice = Math.min(...prices);
  const priceRange = maxPrice - minPrice || 1;

  // === PHASED ANIMATION TIMELINE ===
  // Phase 1 (0-1.5s): Title + bars grow
  // Phase 2 (1.5-3s): Cheapest zone callout
  // Phase 3 (3-4s): Expensive zone callout
  // Phase 4 (4-5s): Summary insight

  const titleSpring = spring({ frame, fps, config: SNAP });

  // Cheapest callout appears at 1.5s
  const cheapCalloutSpring = spring({
    frame: frame - 1.5 * fps,
    fps,
    config: FLOW,
  });

  // Expensive callout appears at 3s
  const expensiveCalloutSpring = spring({
    frame: frame - 3 * fps,
    fps,
    config: FLOW,
  });

  // Lower third
  const lowerThirdSpring = spring({
    frame: frame - 0.3 * fps,
    fps,
    config: FLOW,
  });

  // Chart dimensions - maximize vertical space
  // Video is 1080p (1920x1080), footer is 90px, title area ~100px
  // Available height: 1080 - 90 (footer) - 100 (title) = 890px
  const chartHeight = 650;
  const barWidth = 36;

  // Find indices for cheapest and expensive hours
  const cheapestIndex = cheapestHour ? hours.findIndex(h => parseInt(h) === cheapestHour.hour) : -1;
  const expensiveIndex = expensiveHour ? hours.findIndex(h => parseInt(h) === expensiveHour.hour) : -1;

  return (
    <AbsoluteFill style={{ backgroundColor: BG_LIGHT, fontFamily: "var(--font-primary)" }}>
      {/* Background pattern */}
      <div
        className="absolute inset-0"
        style={{
          backgroundImage: `
            radial-gradient(circle at 20% 20%, rgba(249, 115, 22, 0.03) 0%, transparent 50%),
            radial-gradient(circle at 80% 80%, rgba(249, 115, 22, 0.02) 0%, transparent 40%)
          `,
        }}
      />

      {/* Title - tighter spacing */}
      <div
        className="absolute top-6 left-0 right-0 text-center"
        style={{
          opacity: titleSpring,
          transform: `translateY(${interpolate(titleSpring, [0, 1], [-20, 0])}px)`,
        }}
      >
        <h2 className="text-5xl font-black" style={{ color: "#1e293b" }}>
          Päivän <span style={{ color: CORAL }}>tuntihinnat</span>
        </h2>
      </div>

      {/* Chart area - positioned to maximize vertical space */}
      <div
        className="absolute left-1/2 flex items-end justify-center"
        style={{
          top: 90,
          bottom: 130, // Above footer (90px) + axis labels (40px)
          height: chartHeight,
          transform: "translateX(-50%)",
          gap: 4,
        }}
      >
        {prices.map((price, index) => {
          // Wave animation - bars grow from left to right
          const barDelay = index * 0.02;
          const barSpring = spring({
            frame: frame - barDelay * fps - 0.15 * fps,
            fps,
            config: { damping: 14, stiffness: 90 },
          });

          const heightPercent = ((price - minPrice) / priceRange) * 75 + 25;
          const barHeight = (heightPercent / 100) * chartHeight * barSpring;

          const isCheapest = index === cheapestIndex;
          const isExpensive = index === expensiveIndex;

          // Highlight glow pulses when callout appears
          const cheapGlow = isCheapest ? cheapCalloutSpring : 0;
          const expensiveGlow = isExpensive ? expensiveCalloutSpring : 0;

          return (
            <div
              key={index}
              className="relative flex flex-col items-center"
              style={{ width: barWidth }}
            >
              {/* The bar */}
              <div
                className="absolute bottom-0 rounded-t"
                style={{
                  width: barWidth - 2,
                  height: barHeight,
                  backgroundColor: colors[index],
                  boxShadow: isCheapest && cheapGlow > 0.5
                    ? `0 0 ${20 + cheapGlow * 15}px rgba(34, 197, 94, ${0.4 + cheapGlow * 0.3})`
                    : isExpensive && expensiveGlow > 0.5
                      ? `0 0 ${20 + expensiveGlow * 15}px rgba(239, 68, 68, ${0.4 + expensiveGlow * 0.3})`
                      : "none",
                  transform: isCheapest && cheapGlow > 0.5
                    ? `scaleY(${1 + cheapGlow * 0.05})`
                    : isExpensive && expensiveGlow > 0.5
                      ? `scaleY(${1 + expensiveGlow * 0.05})`
                      : "scaleY(1)",
                  transformOrigin: "bottom",
                }}
              />

              {/* Hour label every 3 hours */}
              {index % 3 === 0 && (
                <div
                  className="absolute text-slate-500 text-sm font-semibold"
                  style={{
                    bottom: -28,
                    opacity: barSpring,
                  }}
                >
                  {hours[index]}
                </div>
              )}
            </div>
          );
        })}
      </div>

      {/* PHASE 2: Cheapest zone callout (appears at 1.5s) - positioned above title */}
      {cheapestHour && cheapestIndex >= 0 && (
        <div
          className="absolute flex flex-col items-center"
          style={{
            left: `calc(50% + ${(cheapestIndex - 12) * (barWidth + 4)}px)`,
            top: 70,
            opacity: cheapCalloutSpring,
            transform: `translateX(-50%) translateY(${interpolate(cheapCalloutSpring, [0, 1], [-15, 0])}px)`,
          }}
        >
          <div
            className="flex items-center gap-2 px-5 py-2.5 rounded-full"
            style={{
              backgroundColor: GREEN,
              boxShadow: "0 4px 20px rgba(34, 197, 94, 0.5)",
            }}
          >
            <TrendingDown size={22} strokeWidth={3} className="text-white" />
            <span className="text-white font-bold text-xl">
              {cheapestHour.price.toFixed(1)} c/kWh
            </span>
          </div>
          <div
            className="w-0.5 bg-green-400"
            style={{
              height: interpolate(cheapCalloutSpring, [0, 1], [0, 25]),
              opacity: 0.7,
            }}
          />
        </div>
      )}

      {/* PHASE 3: Expensive zone callout (appears at 3s) - positioned above title */}
      {expensiveHour && expensiveIndex >= 0 && (
        <div
          className="absolute flex flex-col items-center"
          style={{
            left: `calc(50% + ${(expensiveIndex - 12) * (barWidth + 4)}px)`,
            top: 70,
            opacity: expensiveCalloutSpring,
            transform: `translateX(-50%) translateY(${interpolate(expensiveCalloutSpring, [0, 1], [-15, 0])}px)`,
          }}
        >
          <div
            className="flex items-center gap-2 px-5 py-2.5 rounded-full"
            style={{
              backgroundColor: RED,
              boxShadow: "0 4px 20px rgba(239, 68, 68, 0.5)",
            }}
          >
            <TrendingUp size={22} strokeWidth={3} className="text-white" />
            <span className="text-white font-bold text-xl">
              {expensiveHour.price.toFixed(1)} c/kWh
            </span>
          </div>
          <div
            className="w-0.5 bg-red-400"
            style={{
              height: interpolate(expensiveCalloutSpring, [0, 1], [0, 25]),
              opacity: 0.7,
            }}
          />
        </div>
      )}

      {/* Lower third */}
      <div
        className="absolute bottom-0 left-0 right-0"
        style={{
          height: 90,
          opacity: lowerThirdSpring,
          transform: `translateY(${interpolate(lowerThirdSpring, [0, 1], [90, 0])}px)`,
        }}
      >
        <div className="absolute inset-0" style={{ background: "#0f172a" }} />
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
          <span className="font-black tracking-tight" style={{ fontSize: "36px", color: "white" }}>
            Voltikka.fi
          </span>
          <span className="ml-auto font-medium" style={{ fontSize: "26px", color: "#94a3b8" }}>
            Suomen kattavin energiapalvelu
          </span>
        </div>
      </div>
    </AbsoluteFill>
  );
};
