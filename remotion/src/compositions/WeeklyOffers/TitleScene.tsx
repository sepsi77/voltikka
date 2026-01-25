import {
  AbsoluteFill,
  interpolate,
  spring,
  useCurrentFrame,
  useVideoConfig,
} from "remotion";
import { Tag } from "lucide-react";

// Brand colors
const BG_DARK = "#0f172a"; // slate-900
const CORAL = "#f97316";

// Spring configurations (from DailySpotPrice patterns)
const SPRING_SNAP = { damping: 25, stiffness: 300 };
const SPRING_FLOW = { damping: 20, stiffness: 150 };

type TitleSceneProps = {
  weekFormatted: string;
  offersCount: number;
};

/**
 * TitleScene - Opening scene for WeeklyOffers video
 *
 * Art Direction:
 * - Full-screen dark slate background creates contrast
 * - Coral accent on key words draws attention
 * - Staggered reveal creates anticipation
 * - Frame 0 shows final state for thumbnail generation
 */
export const TitleScene: React.FC<TitleSceneProps> = ({
  weekFormatted,
  offersCount,
}) => {
  const frame = useCurrentFrame();
  const { fps } = useVideoConfig();

  // Animation sequence (2.5s total):
  // 1. Background glow fades in (0s)
  // 2. Badge appears (0.2s)
  // 3. Headline reveals (0.5s)
  // 4. Week dates fade in (0.9s)
  // 5. Offers count badge (1.2s)

  const bgGlow = spring({
    frame,
    fps,
    config: SPRING_FLOW,
  });

  const badgeSpring = spring({
    frame: frame - 0.2 * fps,
    fps,
    config: SPRING_SNAP,
  });

  const headlineSpring = spring({
    frame: frame - 0.5 * fps,
    fps,
    config: SPRING_SNAP,
  });

  const datesSpring = spring({
    frame: frame - 0.9 * fps,
    fps,
    config: SPRING_FLOW,
  });

  const countSpring = spring({
    frame: frame - 1.2 * fps,
    fps,
    config: SPRING_FLOW,
  });

  return (
    <AbsoluteFill
      style={{
        backgroundColor: BG_DARK,
        fontFamily: "var(--font-primary)",
      }}
    >
      {/* Gradient glow at bottom */}
      <div
        className="absolute bottom-0 left-0 right-0"
        style={{
          height: "60%",
          background: `radial-gradient(ellipse at 50% 100%, rgba(249, 115, 22, 0.15) 0%, transparent 70%)`,
          opacity: bgGlow,
        }}
      />

      {/* Top glow accent */}
      <div
        className="absolute top-0 left-0 right-0"
        style={{
          height: "30%",
          background: `radial-gradient(ellipse at 50% 0%, rgba(249, 115, 22, 0.08) 0%, transparent 60%)`,
          opacity: bgGlow,
        }}
      />

      {/* Content container */}
      <div className="absolute inset-0 flex flex-col items-center justify-center">
        {/* Badge */}
        <div
          className="flex items-center gap-4 px-8 py-4 rounded-full mb-10"
          style={{
            background: "rgba(249, 115, 22, 0.15)",
            border: "2px solid rgba(249, 115, 22, 0.3)",
            opacity: badgeSpring,
            transform: `translateY(${interpolate(badgeSpring, [0, 1], [-20, 0])}px)`,
          }}
        >
          <Tag size={32} style={{ color: CORAL }} strokeWidth={2.5} />
          <span className="text-3xl font-semibold" style={{ color: CORAL }}>
            Viikon parhaat alennukset
          </span>
        </div>

        {/* Main headline */}
        <h1
          className="text-center mb-8"
          style={{
            fontSize: 110,
            fontWeight: 900,
            color: "white",
            lineHeight: 1.1,
            opacity: headlineSpring,
            transform: `scale(${interpolate(headlineSpring, [0, 0.7, 1], [0.85, 1.02, 1])})`,
          }}
        >
          <span style={{ color: CORAL }}>Sähkö</span>tarjoukset
        </h1>

        {/* Week dates */}
        <div
          className="text-center mb-16"
          style={{
            opacity: datesSpring,
            transform: `translateY(${interpolate(datesSpring, [0, 1], [15, 0])}px)`,
          }}
        >
          <span
            className="text-5xl font-medium"
            style={{ color: "#94a3b8" }}
          >
            {weekFormatted}
          </span>
        </div>

        {/* Offers count indicator */}
        {offersCount > 0 && (
          <div
            className="flex items-center justify-center gap-3 px-8 py-5 rounded-2xl"
            style={{
              background: "rgba(34, 197, 94, 0.15)",
              border: "2px solid rgba(34, 197, 94, 0.3)",
              opacity: countSpring,
              transform: `translateY(${interpolate(countSpring, [0, 1], [20, 0])}px)`,
            }}
          >
            <span
              className="text-4xl font-bold"
              style={{ color: "#22c55e" }}
            >
              {offersCount}
            </span>
            <span
              className="text-3xl font-medium"
              style={{ color: "#86efac" }}
            >
              {offersCount === 1 ? "tarjous" : "tarjousta"} saatavilla
            </span>
          </div>
        )}
      </div>
    </AbsoluteFill>
  );
};
