import {
  AbsoluteFill,
  interpolate,
  spring,
  useCurrentFrame,
  useVideoConfig,
} from "remotion";

// Brand colors
const BG_DARK = "#0f172a"; // slate-900
const CORAL = "#f97316";

/**
 * SignOffScene - Closing scene with Voltikka.fi branding
 *
 * Reused from DailySpotPrice for brand consistency.
 */
export const SignOffScene: React.FC = () => {
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
    <AbsoluteFill
      style={{
        fontFamily: "var(--font-primary)",
      }}
    >
      <div
        className="absolute inset-0 flex flex-col items-center justify-center"
        style={{
          opacity: bgIn,
          background: BG_DARK,
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
          <span style={{ color: CORAL, fontSize: 32 }}>⚡</span>
          <span className="text-3xl font-semibold" style={{ color: CORAL }}>
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
    </AbsoluteFill>
  );
};
