import { Tag, Zap } from "lucide-react";

// Brand colors - Light theme matching DailySpotPrice
const CORAL = "#f97316";
const GREEN = "#22c55e";

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
 * - The settled title stays visible from frame zero, including thumbnails
 */
export const TitleScene: React.FC<TitleSceneProps> = ({
  weekFormatted,
  offersCount,
}) => {
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
  return (
    <div
      className="absolute bottom-0 left-0 right-0"
      style={{
        height: 90,
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
