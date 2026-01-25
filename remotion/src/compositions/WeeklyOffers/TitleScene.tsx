import {
  interpolate,
  spring,
  useCurrentFrame,
  useVideoConfig,
} from "remotion";
import { Tag, Zap } from "lucide-react";

// Brand colors - Light theme matching DailySpotPrice
const CORAL = "#f97316";
const GREEN = "#22c55e";

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
 * - Light background matching DailySpotPrice (slate-50)
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

  // Frame 0 shows final state for thumbnail, then animation starts from frame 1
  const isThumbnailFrame = frame === 0;
  const animationFrame = frame > 0 ? frame - 1 : 0;

  // Animation sequence (2.5s total):
  // 1. Title drops in (0s)
  // 2. Dates appear (0.15s)
  // 3. Badge appears (0.4s)
  // 4. Offers count badge (0.7s)
  // If thumbnail frame, all springs = 1 (final state)

  const titleSpring = isThumbnailFrame
    ? 1
    : spring({
        frame: animationFrame,
        fps,
        config: SPRING_SNAP,
      });

  const dateSpring = isThumbnailFrame
    ? 1
    : spring({
        frame: animationFrame - 0.15 * fps,
        fps,
        config: SPRING_SNAP,
      });

  const badgeSpring = isThumbnailFrame
    ? 1
    : spring({
        frame: animationFrame - 0.4 * fps,
        fps,
        config: { damping: 12, stiffness: 100 },
      });

  const countSpring = isThumbnailFrame
    ? 1
    : spring({
        frame: animationFrame - 0.7 * fps,
        fps,
        config: SPRING_FLOW,
      });

  return (
    <div
      className="flex flex-col h-full w-full"
      style={{ fontFamily: "var(--font-primary)" }}
    >
      {/* Main content area - centered */}
      <div className="flex-1 flex flex-col items-center justify-center px-16">
        {/* === TITLE: "Sähkötarjoukset" === */}
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
            Sähkötarjoukset
          </h1>
        </div>

        {/* Week dates */}
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
            {weekFormatted}
          </p>
        </div>

        {/* === BADGE: "Viikon parhaat alennukset" === */}
        <div
          className="mb-12"
          style={{
            opacity: badgeSpring,
            transform: `scale(${interpolate(badgeSpring, [0, 1], [0.8, 1])})`,
          }}
        >
          <div
            className="px-12 py-5 rounded-full flex items-center gap-5"
            style={{
              backgroundColor: CORAL,
              boxShadow: `0 8px 30px ${CORAL}50`,
            }}
          >
            <Tag size={44} strokeWidth={2.5} className="text-white" />
            <span
              className="text-white font-black uppercase tracking-wide"
              style={{ fontSize: "36px" }}
            >
              Viikon parhaat alennukset
            </span>
          </div>
        </div>

        {/* Offers count indicator */}
        {offersCount > 0 && (
          <div
            className="flex items-center justify-center gap-3 px-10 py-6 rounded-2xl"
            style={{
              background: "rgba(34, 197, 94, 0.15)",
              border: `2px solid ${GREEN}`,
              opacity: countSpring,
              transform: `translateY(${interpolate(countSpring, [0, 1], [20, 0])}px)`,
            }}
          >
            <span
              className="text-5xl font-bold"
              style={{ color: GREEN }}
            >
              {offersCount}
            </span>
            <span
              className="text-3xl font-medium"
              style={{ color: "#16a34a" }}
            >
              {offersCount === 1 ? "tarjous" : "tarjousta"} saatavilla
            </span>
          </div>
        )}
      </div>

      {/* === LOWER THIRD: Voltikka branding === */}
      <LowerThird />
    </div>
  );
};

// Lower third component - broadcast style branding (matching DailySpotPrice)
const LowerThird: React.FC = () => {
  const frame = useCurrentFrame();
  const { fps } = useVideoConfig();

  // Frame 0 shows final state for thumbnail
  const isThumbnailFrame = frame === 0;
  const animationFrame = frame > 0 ? frame - 1 : 0;

  const barSpring = isThumbnailFrame
    ? 1
    : spring({
        frame: animationFrame - 0.3 * fps,
        fps,
        config: { damping: 20, stiffness: 150 },
      });

  const logoSpring = isThumbnailFrame
    ? 1
    : spring({
        frame: animationFrame - 0.5 * fps,
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
