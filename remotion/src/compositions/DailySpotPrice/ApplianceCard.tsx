import {
  interpolate,
  spring,
  useCurrentFrame,
  useVideoConfig,
} from "remotion";
import { Flame, Car, Shirt, UtensilsCrossed } from "lucide-react";

// Brand colors
const CORAL = "#f97316";

// Spring configurations
const FLOW = { damping: 20, stiffness: 150 };

const iconMap = {
  sauna: Flame,
  ev: Car,
  laundry: Shirt,
  dishwasher: UtensilsCrossed,
} as const;

type ApplianceType = keyof typeof iconMap;

type ApplianceCardProps = {
  type: ApplianceType;
  title: string;
  time: string;
  savings: number;
  delay: number; // delay in seconds
};

export const ApplianceCard: React.FC<ApplianceCardProps> = ({
  type,
  title,
  time,
  savings,
  delay,
}) => {
  const frame = useCurrentFrame();
  const { fps } = useVideoConfig();

  const Icon = iconMap[type];

  const cardSpring = spring({
    frame: frame - delay * fps,
    fps,
    config: FLOW,
  });

  const scale = interpolate(cardSpring, [0, 1], [0.9, 1]);
  const opacity = interpolate(cardSpring, [0, 1], [0, 1]);
  const translateY = interpolate(cardSpring, [0, 1], [20, 0]);

  // Count-up animation for savings
  const savingsProgress = spring({
    frame: frame - (delay + 0.3) * fps,
    fps,
    config: { damping: 25, stiffness: 100 },
  });
  const displaySavings = interpolate(savingsProgress, [0, 1], [0, savings]);

  return (
    <div
      className="bg-white rounded-xl p-5"
      style={{
        opacity,
        transform: `scale(${scale}) translateY(${translateY}px)`,
        boxShadow: "0 4px 20px rgba(0, 0, 0, 0.08)",
        border: "1px solid #e2e8f0",
        fontFamily: "var(--font-primary)",
      }}
    >
      <div className="flex items-center gap-4">
        <div
          className="rounded-lg flex items-center justify-center"
          style={{
            width: 52,
            height: 52,
            backgroundColor: "#fff7ed",
          }}
        >
          <Icon size={28} strokeWidth={2} style={{ color: CORAL }} />
        </div>
        <div className="flex-1">
          <div
            className="text-lg font-semibold"
            style={{ color: "#1e293b" }}
          >
            {title}
          </div>
          <div
            className="text-2xl font-bold mt-1"
            style={{ color: CORAL }}
          >
            {time}
          </div>
        </div>
      </div>
      {savings > 0 && (
        <div
          className="mt-3 pt-3"
          style={{ borderTop: "1px solid #e2e8f0" }}
        >
          <div
            className="text-base"
            style={{ color: "#22c55e" }}
          >
            Säästä jopa{" "}
            <span className="font-bold">
              {displaySavings.toFixed(2).replace(".", ",")} €
            </span>
          </div>
        </div>
      )}
    </div>
  );
};
