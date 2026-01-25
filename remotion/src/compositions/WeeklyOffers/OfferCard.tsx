import {
  AbsoluteFill,
  interpolate,
  spring,
  useCurrentFrame,
  useVideoConfig,
  Img,
} from "remotion";
import type { ContractOffer } from "../../types";

// Brand colors
const BG_DARK = "#0f172a"; // slate-900
const CORAL = "#f97316";
const GREEN = "#22c55e";
const GREEN_LIGHT = "#86efac";

// Spring configurations
const SPRING_SNAP = { damping: 25, stiffness: 300 };
const SPRING_FLOW = { damping: 20, stiffness: 150 };

type OfferCardProps = {
  offer: ContractOffer;
  cardIndex: number;
  totalCards: number;
};

/**
 * Format discount value for display
 * - Percentage: "-15%"
 * - Absolute: "-0,50 c/kWh"
 */
function formatDiscount(discount: ContractOffer["discount"]): string {
  if (!discount) return "";

  if (discount.is_percentage) {
    return `-${discount.value}%`;
  }

  // Absolute value in cents - use Finnish comma
  const formatted = discount.value.toFixed(2).replace(".", ",");
  return `-${formatted} c/kWh`;
}

/**
 * Format discount subtext
 * - "3 ensimmäistä kuukautta"
 * - "Voimassa 31.3.2026 asti"
 */
function formatDiscountSubtext(discount: ContractOffer["discount"]): string {
  if (!discount) return "";

  if (discount.n_first_months) {
    return `${discount.n_first_months} ensimmäistä kuukautta`;
  }

  if (discount.until_date) {
    // Parse date and format as Finnish
    const date = new Date(discount.until_date);
    const day = date.getDate();
    const month = date.getMonth() + 1;
    const year = date.getFullYear();
    return `Voimassa ${day}.${month}.${year} asti`;
  }

  return "Rajoitettu tarjous";
}

/**
 * Format EUR amount for display
 */
function formatEur(amount: number): string {
  return `${Math.round(amount)} €`;
}

/**
 * OfferCard - Full-screen card showing contract offer details
 *
 * Art Direction:
 * - Dark background with subtle gradient
 * - Discount badge is the visual focal point (coral background)
 * - Pricing table shows costs at 3 consumption levels
 * - Savings section in green for positive emphasis
 * - Company logo provides brand recognition
 */
export const OfferCard: React.FC<OfferCardProps> = ({
  offer,
  cardIndex,
  totalCards,
}) => {
  const frame = useCurrentFrame();
  const { fps } = useVideoConfig();

  // Animation sequence:
  // 1. Card background (0s)
  // 2. Company header (0.15s)
  // 3. Discount badge (0.3s)
  // 4. Contract name (0.45s)
  // 5. Pricing table (0.6s)
  // 6. Savings section (0.8s)

  const bgSpring = spring({
    frame,
    fps,
    config: SPRING_FLOW,
  });

  const headerSpring = spring({
    frame: frame - 0.15 * fps,
    fps,
    config: SPRING_SNAP,
  });

  const discountSpring = spring({
    frame: frame - 0.3 * fps,
    fps,
    config: SPRING_SNAP,
  });

  const nameSpring = spring({
    frame: frame - 0.45 * fps,
    fps,
    config: SPRING_FLOW,
  });

  const tableSpring = spring({
    frame: frame - 0.6 * fps,
    fps,
    config: SPRING_FLOW,
  });

  const savingsSpring = spring({
    frame: frame - 0.8 * fps,
    fps,
    config: SPRING_FLOW,
  });

  // Check if we have any positive savings to display
  const hasSavings =
    offer.savings.apartment > 0 ||
    offer.savings.townhouse > 0 ||
    offer.savings.house > 0;

  return (
    <AbsoluteFill
      style={{
        backgroundColor: BG_DARK,
        fontFamily: "var(--font-primary)",
        opacity: bgSpring,
      }}
    >
      {/* Gradient accent */}
      <div
        className="absolute inset-0"
        style={{
          background: `
            radial-gradient(ellipse at 30% 20%, rgba(249, 115, 22, 0.08) 0%, transparent 50%),
            radial-gradient(ellipse at 70% 80%, rgba(34, 197, 94, 0.06) 0%, transparent 50%)
          `,
          opacity: bgSpring,
        }}
      />

      {/* Card content */}
      <div className="absolute inset-0 flex flex-col px-16 py-20">
        {/* Progress dots */}
        <div
          className="absolute top-10 left-0 right-0 flex justify-center gap-3"
          style={{ opacity: bgSpring }}
        >
          {Array.from({ length: totalCards }).map((_, i) => (
            <div
              key={i}
              className="rounded-full"
              style={{
                width: 16,
                height: 16,
                backgroundColor: i === cardIndex ? CORAL : "rgba(255,255,255,0.2)",
              }}
            />
          ))}
        </div>

        {/* Company header */}
        <div
          className="flex items-center gap-5 mb-8"
          style={{
            opacity: headerSpring,
            transform: `translateY(${interpolate(headerSpring, [0, 1], [-15, 0])}px)`,
          }}
        >
          {offer.company.logo_url && (
            <div
              className="rounded-2xl overflow-hidden flex items-center justify-center"
              style={{
                width: 80,
                height: 80,
                backgroundColor: "white",
                padding: 8,
              }}
            >
              <Img
                src={offer.company.logo_url}
                style={{
                  maxWidth: "100%",
                  maxHeight: "100%",
                  objectFit: "contain",
                }}
              />
            </div>
          )}
          <div>
            <div
              className="text-3xl font-semibold"
              style={{ color: "#94a3b8" }}
            >
              {offer.company.name}
            </div>
            <div
              className="text-xl font-medium"
              style={{ color: "#64748b" }}
            >
              {offer.pricing_model === "Spot"
                ? "Pörssisähkö"
                : offer.pricing_model === "FixedPrice"
                  ? "Kiinteä hinta"
                  : "Hybridi"}
            </div>
          </div>
        </div>

        {/* Discount badge - FOCAL POINT */}
        <div
          className="self-start rounded-2xl px-10 py-6 mb-8"
          style={{
            background: `linear-gradient(135deg, ${CORAL} 0%, #ea580c 100%)`,
            boxShadow: "0 8px 32px rgba(249, 115, 22, 0.4)",
            opacity: discountSpring,
            transform: `scale(${interpolate(discountSpring, [0, 0.7, 1], [0.8, 1.05, 1])})`,
          }}
        >
          <div
            className="text-6xl font-black mb-1"
            style={{ color: "white" }}
          >
            {formatDiscount(offer.discount)}
          </div>
          <div
            className="text-2xl font-medium"
            style={{ color: "rgba(255,255,255,0.85)" }}
          >
            {formatDiscountSubtext(offer.discount)}
          </div>
        </div>

        {/* Contract name */}
        <h2
          className="text-5xl font-bold mb-12"
          style={{
            color: "white",
            opacity: nameSpring,
            transform: `translateY(${interpolate(nameSpring, [0, 1], [10, 0])}px)`,
          }}
        >
          {offer.name}
        </h2>

        {/* Pricing table */}
        <div
          className="rounded-3xl p-8 mb-8"
          style={{
            background: "rgba(255,255,255,0.05)",
            border: "1px solid rgba(255,255,255,0.1)",
            opacity: tableSpring,
            transform: `translateY(${interpolate(tableSpring, [0, 1], [15, 0])}px)`,
          }}
        >
          <div className="text-2xl font-semibold mb-6" style={{ color: "#94a3b8" }}>
            Vuosikustannukset
          </div>

          {/* Column headers */}
          <div className="grid grid-cols-3 gap-4 mb-4">
            <div className="text-center">
              <div className="text-xl font-medium" style={{ color: "#64748b" }}>
                Kerrostalo
              </div>
              <div className="text-lg" style={{ color: "#475569" }}>
                2000 kWh
              </div>
            </div>
            <div className="text-center">
              <div className="text-xl font-medium" style={{ color: "#64748b" }}>
                Rivitalo
              </div>
              <div className="text-lg" style={{ color: "#475569" }}>
                5000 kWh
              </div>
            </div>
            <div className="text-center">
              <div className="text-xl font-medium" style={{ color: "#64748b" }}>
                Omakotitalo
              </div>
              <div className="text-lg" style={{ color: "#475569" }}>
                10000 kWh
              </div>
            </div>
          </div>

          {/* Cost values */}
          <div className="grid grid-cols-3 gap-4">
            <div className="text-center">
              <div className="text-5xl font-bold" style={{ color: "white" }}>
                {formatEur(offer.costs.apartment)}
              </div>
            </div>
            <div className="text-center">
              <div className="text-5xl font-bold" style={{ color: "white" }}>
                {formatEur(offer.costs.townhouse)}
              </div>
            </div>
            <div className="text-center">
              <div className="text-5xl font-bold" style={{ color: "white" }}>
                {formatEur(offer.costs.house)}
              </div>
            </div>
          </div>
        </div>

        {/* Savings section */}
        {hasSavings && (
          <div
            className="rounded-3xl p-8"
            style={{
              background: "rgba(34, 197, 94, 0.1)",
              border: `2px solid rgba(34, 197, 94, 0.3)`,
              opacity: savingsSpring,
              transform: `translateY(${interpolate(savingsSpring, [0, 1], [15, 0])}px)`,
            }}
          >
            <div
              className="text-xl font-bold mb-5 tracking-wider"
              style={{ color: GREEN }}
            >
              SÄÄSTÄT TARJOUKSELLA
            </div>

            <div className="grid grid-cols-3 gap-4">
              <div className="text-center">
                <div
                  className="text-4xl font-bold"
                  style={{ color: GREEN_LIGHT }}
                >
                  {offer.savings.apartment > 0
                    ? formatEur(offer.savings.apartment)
                    : "—"}
                </div>
                <div className="text-lg" style={{ color: GREEN }}>
                  /vuosi
                </div>
              </div>
              <div className="text-center">
                <div
                  className="text-4xl font-bold"
                  style={{ color: GREEN_LIGHT }}
                >
                  {offer.savings.townhouse > 0
                    ? formatEur(offer.savings.townhouse)
                    : "—"}
                </div>
                <div className="text-lg" style={{ color: GREEN }}>
                  /vuosi
                </div>
              </div>
              <div className="text-center">
                <div
                  className="text-4xl font-bold"
                  style={{ color: GREEN_LIGHT }}
                >
                  {offer.savings.house > 0
                    ? formatEur(offer.savings.house)
                    : "—"}
                </div>
                <div className="text-lg" style={{ color: GREEN }}>
                  /vuosi
                </div>
              </div>
            </div>
          </div>
        )}

        {/* Pricing details at bottom */}
        <div
          className="mt-auto flex items-center gap-8 pt-8"
          style={{
            opacity: tableSpring,
            borderTop: "1px solid rgba(255,255,255,0.1)",
          }}
        >
          <div>
            <span className="text-xl" style={{ color: "#64748b" }}>
              Perusmaksu:{" "}
            </span>
            <span className="text-xl font-semibold" style={{ color: "#94a3b8" }}>
              {offer.pricing.monthly_fee.toFixed(2).replace(".", ",")} €/kk
            </span>
          </div>
          {offer.pricing.energy_price !== null && (
            <div>
              <span className="text-xl" style={{ color: "#64748b" }}>
                Energia:{" "}
              </span>
              <span className="text-xl font-semibold" style={{ color: "#94a3b8" }}>
                {offer.pricing.energy_price.toFixed(2).replace(".", ",")} c/kWh
              </span>
            </div>
          )}
        </div>
      </div>
    </AbsoluteFill>
  );
};
