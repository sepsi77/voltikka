import {
  AbsoluteFill,
  interpolate,
  spring,
  useCurrentFrame,
  useVideoConfig,
  Sequence,
} from "remotion";
import type { DailySpotPriceProps } from "../../types";
import { ClockChart } from "./ClockChart";
import { RecommendationsScene } from "./RecommendationsScene";
import { PriceHeadline } from "../../components/PriceHeadline";

// Brand colors
const BG_LIGHT = "#f8fafc"; // slate-50

export const DailySpotPrice: React.FC<DailySpotPriceProps> = ({ data }) => {
  const { fps } = useVideoConfig();

  // Section timing (16.5 seconds total)
  const HEADLINE_END = 3.5; // 0-3.5s: The Verdict - average price + day rating
  const CHART_END = 8.5; // 3.5-8.5s: Hourly prices chart
  const RECOMMENDATIONS_END = 14; // 8.5-14s: Best times for appliances
  const SIGNOFF_START = 14; // 14-16.5s: Sign-off

  // Get the cheapest hour range from EV charging or from consecutive appliances
  const cheapestRange =
    data.ev_charging?.time_label ||
    data.appliances.laundry?.time_label ||
    (data.statistics.cheapest_hour
      ? `${data.statistics.cheapest_hour.label}`
      : null);

  return (
    <AbsoluteFill
      style={{
        backgroundColor: BG_LIGHT,
        fontFamily: "var(--font-primary)",
      }}
    >
      {/* Subtle background pattern */}
      <div
        className="absolute inset-0"
        style={{
          backgroundImage: `
            radial-gradient(circle at 20% 20%, rgba(249, 115, 22, 0.03) 0%, transparent 50%),
            radial-gradient(circle at 80% 80%, rgba(249, 115, 22, 0.02) 0%, transparent 40%)
          `,
        }}
      />

      {/* === SECTION 1: The Verdict (0-3.5s) === */}
      <Sequence durationInFrames={HEADLINE_END * fps}>
        <PriceHeadline
          averagePrice={data.statistics.average ?? 0}
          changePercent={data.comparison.change_from_30d_percent}
          date={data.date.formatted}
        />
      </Sequence>

      {/* === SECTION 2: Clock Chart (4-9s) === */}
      <Sequence
        from={HEADLINE_END * fps}
        durationInFrames={(CHART_END - HEADLINE_END) * fps}
      >
        <ClockChart
          prices={data.chart.prices}
          avg30d={data.comparison.rolling_30d_average ?? 8.0}
          date={data.date.formatted}
        />
      </Sequence>

      {/* === SECTION 3: Best Times (9-13.5s) === */}
      <Sequence
        from={CHART_END * fps}
        durationInFrames={(RECOMMENDATIONS_END - CHART_END) * fps}
      >
        <RecommendationsScene
          cheapestWindow={cheapestRange ?? "02–05"}
          appliances={[
            ...(data.appliances.sauna
              ? [
                  {
                    emoji: "🧖",
                    name: "Sauna",
                    time: data.appliances.sauna.best_hour_label,
                    isOptimal: false, // Sauna usually evening, not in cheapest window
                  },
                ]
              : []),
            ...(data.ev_charging
              ? [
                  {
                    emoji: "🔌",
                    name: "Sähköauton lataus",
                    time: data.ev_charging.time_label,
                    isOptimal: true,
                  },
                ]
              : []),
            ...(data.appliances.laundry
              ? [
                  {
                    emoji: "🧺",
                    name: "Pyykinpesu",
                    time: data.appliances.laundry.time_label,
                    isOptimal: true,
                  },
                ]
              : []),
            ...(data.appliances.dishwasher
              ? [
                  {
                    emoji: "🍽️",
                    name: "Astianpesukone",
                    time: data.appliances.dishwasher.time_label,
                    isOptimal: true,
                  },
                ]
              : []),
          ]}
        />
      </Sequence>

      {/* === SECTION 4: Sign-off (14.5-17s) === */}
      <Sequence from={SIGNOFF_START * fps}>
        <SignOffScene />
      </Sequence>
    </AbsoluteFill>
  );
};

// Sign-off scene with staggered animations
const SignOffScene: React.FC = () => {
  const frame = useCurrentFrame();
  const { fps } = useVideoConfig();

  // Staggered animation sequence
  const bgIn = spring({
    frame,
    fps,
    config: { damping: 30, stiffness: 200 },
  });

  const badgeIn = spring({
    frame: frame - 0.2 * fps,
    fps,
    config: { damping: 20, stiffness: 150 },
  });

  const logoIn = spring({
    frame: frame - 0.5 * fps,
    fps,
    config: { damping: 18, stiffness: 120 },
  });

  const descIn = spring({
    frame: frame - 1.0 * fps,
    fps,
    config: { damping: 20, stiffness: 150 },
  });

  return (
    <div
      className="absolute inset-0 flex flex-col items-center justify-center"
      style={{
        opacity: bgIn,
        background: "#0f172a",
      }}
    >
      {/* Gradient glow at bottom */}
      <div
        className="absolute bottom-0 left-0 right-0"
        style={{
          height: "60%",
          background:
            "radial-gradient(ellipse at 50% 100%, rgba(249, 115, 22, 0.15) 0%, transparent 70%)",
          opacity: bgIn,
        }}
      />

      {/* Tagline badge */}
      <div
        className="flex items-center gap-4 px-8 py-4 rounded-full mb-12"
        style={{
          background: "rgba(249, 115, 22, 0.15)",
          border: "2px solid rgba(249, 115, 22, 0.3)",
          opacity: badgeIn,
          transform: `translateY(${interpolate(badgeIn, [0, 1], [-15, 0])}px)`,
        }}
      >
        <span style={{ color: "#f97316", fontSize: 32 }}>⚡</span>
        <span className="text-3xl font-semibold" style={{ color: "#f97316" }}>
          Suomen kattavin energiapalvelu
        </span>
      </div>

      {/* Main branding */}
      <div
        className="font-black text-white mb-12"
        style={{
          fontSize: 140,
          opacity: logoIn,
          transform: `scale(${interpolate(logoIn, [0, 0.7, 1], [0.8, 1.02, 1])})`,
          letterSpacing: "-0.02em",
        }}
      >
        Voltikka.fi
      </div>

      {/* Description text */}
      <div
        className="text-center px-12"
        style={{
          maxWidth: 900,
          opacity: descIn,
          transform: `translateY(${interpolate(descIn, [0, 1], [15, 0])}px)`,
        }}
      >
        <span className="text-4xl leading-relaxed" style={{ color: "#94a3b8" }}>
          Vertaile sähkösopimuksia, seuraa pörssihintoja, laske aurinkopaneelien
          tuotto ja löydä paras lämpöpumppu. Kaikki yhdessä paikassa.
        </span>
      </div>
    </div>
  );
};
