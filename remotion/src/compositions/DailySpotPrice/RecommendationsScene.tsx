import {
  AbsoluteFill,
  interpolate,
  spring,
  useCurrentFrame,
  useVideoConfig,
} from "remotion";
import { Zap } from "lucide-react";

const BG_LIGHT = "#f8fafc";
const CORAL = "#f97316";
const GREEN = "#22c55e";

type ApplianceItem = {
  emoji: string;
  name: string;
  time: string;
  isOptimal: boolean; // true if time falls within cheapest window
};

type RecommendationsSceneProps = {
  cheapestWindow: string;
  appliances: ApplianceItem[];
};

export const RecommendationsScene: React.FC<RecommendationsSceneProps> = ({
  cheapestWindow,
  appliances,
}) => {
  const frame = useCurrentFrame();
  const { fps } = useVideoConfig();

  // Animation sequence (progressive disclosure):
  // 1. Title (0s)
  // 2. Hero cheapest window (0.4s)
  // 3. List items staggered (1s+)
  // 4. Footer

  const titleSpring = spring({
    frame,
    fps,
    config: { damping: 25, stiffness: 300 },
  });

  const heroSpring = spring({
    frame: frame - 0.4 * fps,
    fps,
    config: { damping: 18, stiffness: 150 },
  });

  const footerSpring = spring({
    frame: frame - 0.3 * fps,
    fps,
    config: { damping: 20, stiffness: 150 },
  });

  return (
    <AbsoluteFill style={{ backgroundColor: BG_LIGHT, fontFamily: "var(--font-primary)" }}>
      {/* Title */}
      <div
        className="absolute left-0 right-0 text-center"
        style={{
          top: 120,
          opacity: titleSpring,
          transform: `translateY(${interpolate(titleSpring, [0, 1], [-20, 0])}px)`,
        }}
      >
        <h1 className="text-7xl font-black" style={{ color: "#1e293b" }}>
          Ajoita <span style={{ color: CORAL }}>oikein</span>
        </h1>
      </div>

      {/* Hero: Cheapest window */}
      <div
        className="absolute left-0 right-0 flex justify-center"
        style={{
          top: 250,
          opacity: heroSpring,
          transform: `scale(${interpolate(heroSpring, [0, 0.6, 1], [0.8, 1.02, 1])})`,
        }}
      >
        <div
          className="rounded-3xl px-20 py-10 text-center"
          style={{
            background: "linear-gradient(135deg, #1e293b 0%, #0f172a 100%)",
            boxShadow: "0 20px 50px rgba(0, 0, 0, 0.2)",
          }}
        >
          <div className="text-slate-400 text-3xl font-semibold mb-3">
            Halvimmat tunnit
          </div>
          <div className="text-white text-8xl font-black">
            {cheapestWindow}
          </div>
        </div>
      </div>

      {/* Appliance list */}
      <div className="absolute left-12 right-12 space-y-6" style={{ top: 520 }}>
        {appliances.map((appliance, index) => {
          // Stagger items 0.8s apart - gives time to read each line
          const itemSpring = spring({
            frame: frame - (1.2 + index * 0.8) * fps,
            fps,
            config: { damping: 20, stiffness: 150 },
          });

          return (
            <div
              key={appliance.name}
              className="flex items-center bg-white rounded-2xl px-10 py-6"
              style={{
                boxShadow: "0 4px 15px rgba(0, 0, 0, 0.08)",
                opacity: itemSpring,
                transform: `translateX(${interpolate(itemSpring, [0, 1], [-30, 0])}px)`,
              }}
            >
              <span className="text-5xl mr-6">{appliance.emoji}</span>
              <span className="text-3xl font-semibold text-slate-700 flex-1">
                {appliance.name}
              </span>
              <span
                className="text-4xl font-black"
                style={{ color: appliance.isOptimal ? GREEN : CORAL }}
              >
                {appliance.time}
              </span>
            </div>
          );
        })}
      </div>

      {/* Footer */}
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
