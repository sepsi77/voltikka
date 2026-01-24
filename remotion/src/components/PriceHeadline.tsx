import {
  interpolate,
  spring,
  useCurrentFrame,
  useVideoConfig,
} from "remotion";
import { TrendingDown, TrendingUp, Minus, Zap } from "lucide-react";

type PriceHeadlineProps = {
  averagePrice: number;
  changePercent: number | null;
  date: string;
};

// Spring configurations
const SNAP = { damping: 25, stiffness: 300 };
const FLOW = { damping: 20, stiffness: 150 };

// Brand colors
const CORAL = "#f97316";
const GREEN = "#22c55e";
const RED = "#ef4444";

type DayRating = {
  label: string;
  color: string;
  Icon: React.FC<{ size?: number; strokeWidth?: number; className?: string }>;
};

// Determine day rating based on comparison to 30-day average
const getDayRating = (changePercent: number | null): DayRating => {
  if (changePercent === null) {
    return { label: "NORMAALI PÄIVÄ", color: CORAL, Icon: Minus };
  }
  if (changePercent <= -10) {
    return { label: "HALPA PÄIVÄ", color: GREEN, Icon: TrendingDown };
  }
  if (changePercent >= 10) {
    return { label: "KALLIS PÄIVÄ", color: RED, Icon: TrendingUp };
  }
  return { label: "NORMAALI PÄIVÄ", color: CORAL, Icon: Minus };
};

export const PriceHeadline: React.FC<PriceHeadlineProps> = ({
  averagePrice,
  changePercent,
  date,
}) => {
  const frame = useCurrentFrame();
  const { fps } = useVideoConfig();

  const rating = getDayRating(changePercent);

  // === STAGGERED ANIMATIONS ===

  // 1. Title drops in
  const titleSpring = spring({
    frame,
    fps,
    config: SNAP,
  });

  // 2. Date
  const dateSpring = spring({
    frame: frame - 0.15 * fps,
    fps,
    config: SNAP,
  });

  // 3. Verdict badge
  const verdictSpring = spring({
    frame: frame - 0.4 * fps,
    fps,
    config: { damping: 12, stiffness: 100 },
  });

  // 4. Price card
  const cardSpring = spring({
    frame: frame - 0.7 * fps,
    fps,
    config: { damping: 12, stiffness: 100 },
  });

  // 5. Price number counts up
  const priceSpring = spring({
    frame: frame - 0.9 * fps,
    fps,
    config: FLOW,
  });

  // 6. Comparison badge
  const comparisonSpring = spring({
    frame: frame - 1.2 * fps,
    fps,
    config: FLOW,
  });

  // Count-up animation for price
  const displayPrice = interpolate(priceSpring, [0, 1], [0, averagePrice]);

  return (
    <div
      className="flex flex-col h-full w-full"
      style={{ fontFamily: "var(--font-primary)" }}
    >
      {/* Main content area - centered */}
      <div className="flex-1 flex flex-col items-center justify-center px-16">
        {/* === TITLE: "Pörssisähkö tänään" === */}
        <div
          className="text-center mb-4"
          style={{
            opacity: titleSpring,
            transform: `scale(${interpolate(titleSpring, [0, 1], [0.85, 1])})`,
          }}
        >
          <h1
            className="font-black"
            style={{
              fontSize: "100px",
              color: CORAL,
              lineHeight: 1,
              letterSpacing: "-0.02em",
            }}
          >
            Pörssisähkö tänään
          </h1>
        </div>

        {/* Date */}
        <div
          className="mb-12"
          style={{
            opacity: dateSpring,
            transform: `translateY(${interpolate(dateSpring, [0, 1], [-10, 0])}px)`,
          }}
        >
          <p
            className="text-4xl font-medium"
            style={{ color: "#64748b" }}
          >
            {date}
          </p>
        </div>

        {/* === VERDICT BADGE === */}
        <div
          className="mb-12"
          style={{
            opacity: verdictSpring,
            transform: `scale(${interpolate(verdictSpring, [0, 1], [0.8, 1])})`,
          }}
        >
          <div
            className="px-12 py-5 rounded-full flex items-center gap-5"
            style={{
              backgroundColor: rating.color,
              boxShadow: `0 8px 30px ${rating.color}50`,
            }}
          >
            <rating.Icon size={44} strokeWidth={3} className="text-white" />
            <span
              className="text-white font-black uppercase tracking-wide"
              style={{ fontSize: "40px" }}
            >
              {rating.label}
            </span>
          </div>
        </div>

        {/* === HERO: Giant price card === */}
        <div
          className="rounded-3xl px-24 py-16"
          style={{
            width: 680,
            background: "linear-gradient(135deg, #1e293b 0%, #0f172a 100%)",
            boxShadow: "0 30px 60px rgba(0, 0, 0, 0.25)",
            opacity: cardSpring,
            transform: `scale(${interpolate(cardSpring, [0, 1], [0.9, 1])})`,
          }}
        >
          {/* Context label */}
          <div
            className="text-slate-400 text-3xl font-bold uppercase tracking-widest mb-6 text-center"
          >
            Päivän keskihinta
          </div>

          {/* THE PRICE - Massive */}
          <div className="flex items-baseline justify-center">
            <span
              className="font-black"
              style={{
                fontSize: "200px",
                lineHeight: 0.9,
                opacity: priceSpring,
                letterSpacing: "-0.03em",
                color: rating.color,
              }}
            >
              {displayPrice.toFixed(1).replace(".", ",")}
            </span>
            <span
              className="text-slate-400 font-bold ml-4"
              style={{ fontSize: "56px" }}
            >
              c/kWh
            </span>
          </div>

          {/* Comparison to 30-day average */}
          {changePercent !== null && (
            <div
              className="mt-8 text-center"
              style={{
                opacity: comparisonSpring,
                transform: `translateY(${interpolate(comparisonSpring, [0, 1], [10, 0])}px)`,
              }}
            >
              <span
                className="text-3xl font-semibold"
                style={{ color: changePercent < 0 ? GREEN : changePercent > 0 ? RED : "#94a3b8" }}
              >
                {changePercent < 0 ? "↓" : changePercent > 0 ? "↑" : ""}
                {" "}
                {Math.abs(changePercent).toFixed(0)}% vs. 30pv keskiarvo
              </span>
            </div>
          )}
        </div>
      </div>

      {/* === LOWER THIRD: Voltikka branding === */}
      <LowerThird />
    </div>
  );
};

// Lower third component - broadcast style branding
const LowerThird: React.FC = () => {
  const frame = useCurrentFrame();
  const { fps } = useVideoConfig();

  const barSpring = spring({
    frame: frame - 0.3 * fps,
    fps,
    config: { damping: 20, stiffness: 150 },
  });

  const logoSpring = spring({
    frame: frame - 0.5 * fps,
    fps,
    config: { damping: 25, stiffness: 200 },
  });

  return (
    <div
      className="absolute bottom-0 left-0 right-0"
      style={{
        height: 90,
        opacity: barSpring,
        transform: `translateY(${interpolate(barSpring, [0, 1], [90, 0])}px)`,
      }}
    >
      {/* Background bar - dark */}
      <div
        className="absolute inset-0"
        style={{
          background: "#0f172a",
        }}
      />

      {/* Coral accent line at top */}
      <div
        className="absolute top-0 left-0 right-0"
        style={{
          height: 4,
          background: "linear-gradient(90deg, #f97316 0%, #ea580c 100%)",
        }}
      />

      {/* Content */}
      <div
        className="relative h-full flex items-center px-12"
        style={{
          opacity: logoSpring,
        }}
      >
        {/* Logo/Icon - coral circle with lightning */}
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

        {/* Site name */}
        <span
          className="font-black tracking-tight"
          style={{ fontSize: "36px", color: "white" }}
        >
          Voltikka.fi
        </span>

        {/* Tagline - right side */}
        <span
          className="ml-auto font-medium"
          style={{ fontSize: "26px", color: "#94a3b8" }}
        >
          Suomen kattavin energiapalvelu
        </span>
      </div>
    </div>
  );
};
