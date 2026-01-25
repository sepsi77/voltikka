import {
  AbsoluteFill,
  interpolate,
  spring,
  useCurrentFrame,
  useVideoConfig,
} from "remotion";
import { Zap, Check } from "lucide-react";

// Brand colors
const BG_LIGHT = "#f8fafc"; // slate-50
const CORAL = "#f97316";
const CORAL_DARK = "#ea580c";
const DARK_SLATE = "#0f172a"; // slate-900

// Spring configurations
const SPRING_FLOW = { damping: 20, stiffness: 150 };
const SPRING_POP = { damping: 15, stiffness: 250 };

/**
 * PromoScene - CTA scene promoting Voltikka.fi
 *
 * Appears after offer cards to convert viewers.
 * Duration: 2.5 seconds
 */
export const PromoScene: React.FC = () => {
  const frame = useCurrentFrame();
  const { fps } = useVideoConfig();

  // Frame 0 shows final state for thumbnail
  const isThumbnailFrame = frame === 0;
  const animationFrame = frame > 0 ? frame - 1 : 0;

  // Animation sequence: staggered entrance
  const bgSpring = isThumbnailFrame
    ? 1
    : spring({
        frame: animationFrame,
        fps,
        config: SPRING_FLOW,
      });

  const headlineSpring = isThumbnailFrame
    ? 1
    : spring({
        frame: animationFrame - 0.2 * fps,
        fps,
        config: SPRING_FLOW,
      });

  const ctaSpring = isThumbnailFrame
    ? 1
    : spring({
        frame: animationFrame - 0.5 * fps,
        fps,
        config: SPRING_POP,
      });

  const bullet1Spring = isThumbnailFrame
    ? 1
    : spring({
        frame: animationFrame - 0.8 * fps,
        fps,
        config: SPRING_FLOW,
      });

  const bullet2Spring = isThumbnailFrame
    ? 1
    : spring({
        frame: animationFrame - 0.95 * fps,
        fps,
        config: SPRING_FLOW,
      });

  const bullet3Spring = isThumbnailFrame
    ? 1
    : spring({
        frame: animationFrame - 1.1 * fps,
        fps,
        config: SPRING_FLOW,
      });

  const lowerThirdSpring = isThumbnailFrame
    ? 1
    : spring({
        frame: animationFrame - 1.2 * fps,
        fps,
        config: SPRING_FLOW,
      });

  const bullets = [
    { text: "Vertaile helposti", spring: bullet1Spring },
    { text: "Säästä rahaa", spring: bullet2Spring },
    { text: "Laske kulutus", spring: bullet3Spring },
  ];

  return (
    <AbsoluteFill
      style={{
        backgroundColor: BG_LIGHT,
        fontFamily: "var(--font-primary)",
        opacity: bgSpring,
      }}
    >
      {/* Subtle gradient pattern */}
      <div
        className="absolute inset-0"
        style={{
          backgroundImage: `
            radial-gradient(circle at 30% 30%, rgba(249, 115, 22, 0.06) 0%, transparent 50%),
            radial-gradient(circle at 70% 70%, rgba(249, 115, 22, 0.04) 0%, transparent 40%)
          `,
          opacity: bgSpring,
        }}
      />

      {/* Main content area */}
      <div
        className="absolute inset-0 flex flex-col items-center justify-center px-16"
        style={{ paddingBottom: 90 }}
      >
        {/* Headline */}
        <h1
          className="font-bold text-center mb-12"
          style={{
            fontSize: 72,
            color: "#0f172a",
            opacity: headlineSpring,
            transform: `translateY(${interpolate(headlineSpring, [0, 1], [-30, 0])}px)`,
          }}
        >
          Löydä lisää tarjouksia!
        </h1>

        {/* CTA Button */}
        <div
          className="rounded-2xl flex items-center gap-6 mb-14"
          style={{
            background: `linear-gradient(135deg, ${CORAL} 0%, ${CORAL_DARK} 100%)`,
            boxShadow: "0 16px 50px rgba(249, 115, 22, 0.4)",
            padding: "32px 64px",
            opacity: ctaSpring,
            transform: `scale(${interpolate(ctaSpring, [0, 0.7, 1], [0.8, 1.05, 1])})`,
          }}
        >
          <div
            className="rounded-full flex items-center justify-center"
            style={{
              width: 64,
              height: 64,
              background: "rgba(255,255,255,0.2)",
            }}
          >
            <Zap size={40} strokeWidth={2.5} className="text-white" fill="white" />
          </div>
          <span
            className="font-black"
            style={{
              fontSize: 56,
              color: "white",
              letterSpacing: "-0.01em",
            }}
          >
            Voltikka.fi
          </span>
        </div>

        {/* Bullet points */}
        <div className="flex flex-col gap-5">
          {bullets.map((bullet, i) => (
            <div
              key={i}
              className="flex items-center gap-5"
              style={{
                opacity: bullet.spring,
                transform: `translateX(${interpolate(bullet.spring, [0, 1], [-20, 0])}px)`,
              }}
            >
              <div
                className="rounded-full flex items-center justify-center"
                style={{
                  width: 44,
                  height: 44,
                  background: CORAL,
                }}
              >
                <Check size={28} strokeWidth={3} className="text-white" />
              </div>
              <span
                className="font-semibold"
                style={{
                  fontSize: 36,
                  color: "#334155",
                }}
              >
                {bullet.text}
              </span>
            </div>
          ))}
        </div>
      </div>

      {/* === LOWER THIRD: Voltikka branding === */}
      <div
        className="absolute bottom-0 left-0 right-0"
        style={{
          height: 90,
          opacity: lowerThirdSpring,
          transform: `translateY(${interpolate(lowerThirdSpring, [0, 1], [90, 0])}px)`,
        }}
      >
        {/* Background bar */}
        <div
          className="absolute inset-0"
          style={{ background: DARK_SLATE }}
        />

        {/* Coral accent line at top */}
        <div
          className="absolute top-0 left-0 right-0"
          style={{
            height: 4,
            background: `linear-gradient(90deg, ${CORAL} 0%, ${CORAL_DARK} 100%)`,
          }}
        />

        {/* Content */}
        <div className="relative h-full flex items-center px-12">
          {/* Logo icon */}
          <div
            className="rounded-full flex items-center justify-center mr-5"
            style={{
              width: 56,
              height: 56,
              background: `linear-gradient(135deg, ${CORAL} 0%, ${CORAL_DARK} 100%)`,
            }}
          >
            <Zap size={32} strokeWidth={2.5} className="text-white" fill="white" />
          </div>

          {/* Site name */}
          <span
            className="font-black tracking-tight"
            style={{ fontSize: 36, color: "white" }}
          >
            Voltikka.fi
          </span>

          {/* Tagline */}
          <span
            className="ml-auto font-medium"
            style={{ fontSize: 26, color: "#94a3b8" }}
          >
            Suomen kattavin energiapalvelu
          </span>
        </div>
      </div>
    </AbsoluteFill>
  );
};
