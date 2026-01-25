import { useState } from "react";
import {
  AbsoluteFill,
  interpolate,
  spring,
  useCurrentFrame,
  useVideoConfig,
  Img,
} from "remotion";
import { Building2, Zap } from "lucide-react";
import type { ContractOffer } from "../../types";

// Brand colors
const BG_LIGHT = "#f8fafc"; // slate-50
const CORAL = "#f97316";
const CORAL_DARK = "#ea580c";
const GREEN = "#22c55e";
const DARK_SLATE = "#0f172a"; // slate-900
const DARK_SLATE_LIGHTER = "#1e293b"; // slate-800

// Spring configurations
const SPRING_SNAP = { damping: 25, stiffness: 300 };
const SPRING_FLOW = { damping: 20, stiffness: 150 };
const SPRING_POP = { damping: 15, stiffness: 250 };

type OfferCardProps = {
  offer: ContractOffer;
  cardIndex: number;
  totalCards: number;
};

/**
 * Format discount value for display - split into value and unit
 */
function formatDiscountParts(discount: ContractOffer["discount"]): { value: string; unit: string } {
  if (!discount) return { value: "", unit: "" };

  if (discount.is_percentage) {
    return { value: `-${discount.value}`, unit: "%" };
  }

  // Absolute value in cents - use Finnish comma
  const formatted = discount.value.toFixed(2).replace(".", ",");
  return { value: `-${formatted}`, unit: "c/kWh" };
}

/**
 * Format discount subtext
 */
function formatDiscountSubtext(discount: ContractOffer["discount"]): string | null {
  if (!discount) return null;

  if (discount.n_first_months !== null && discount.n_first_months !== undefined && discount.n_first_months > 0) {
    return `${discount.n_first_months} ensimmäistä kuukautta`;
  }

  if (discount.until_date) {
    const date = new Date(discount.until_date);
    const day = date.getDate();
    const month = date.getMonth() + 1;
    const year = date.getFullYear();
    return `Voimassa ${day}.${month}.${year} asti`;
  }

  return null;
}

/**
 * Format EUR amount for display
 */
function formatEur(amount: number): string {
  return `${Math.round(amount)} €`;
}

/**
 * OfferCard - Bold editorial design with dramatic typography
 */
export const OfferCard: React.FC<OfferCardProps> = ({
  offer,
  cardIndex,
  totalCards,
}) => {
  const frame = useCurrentFrame();
  const { fps } = useVideoConfig();
  const [logoError, setLogoError] = useState(false);

  // Animation springs - staggered entrance
  const bgSpring = spring({
    frame,
    fps,
    config: SPRING_FLOW,
  });

  const dotsSpring = spring({
    frame: frame - 0.1 * fps,
    fps,
    config: SPRING_FLOW,
  });

  const companySpring = spring({
    frame: frame - 0.2 * fps,
    fps,
    config: SPRING_SNAP,
  });

  const discountSpring = spring({
    frame: frame - 0.4 * fps,
    fps,
    config: SPRING_POP,
  });

  const nameSpring = spring({
    frame: frame - 0.7 * fps,
    fps,
    config: SPRING_FLOW,
  });

  const pricingSpring = spring({
    frame: frame - 0.9 * fps,
    fps,
    config: SPRING_FLOW,
  });

  const savingsSpring = spring({
    frame: frame - 1.2 * fps,
    fps,
    config: SPRING_POP,
  });

  const lowerThirdSpring = spring({
    frame: frame - 1.4 * fps,
    fps,
    config: SPRING_FLOW,
  });

  // Get featured savings (townhouse = 5000 kWh)
  const featuredSavings = offer.savings.townhouse;
  const hasSavings = featuredSavings > 0;

  // Parse discount parts
  const discountParts = formatDiscountParts(offer.discount);
  const discountSubtext = formatDiscountSubtext(offer.discount);

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
            radial-gradient(circle at 20% 20%, rgba(249, 115, 22, 0.05) 0%, transparent 50%),
            radial-gradient(circle at 80% 80%, rgba(249, 115, 22, 0.03) 0%, transparent 40%)
          `,
          opacity: bgSpring,
        }}
      />

      {/* Main content - centered vertically with justify-between for even distribution */}
      <div
        className="absolute inset-0 flex flex-col px-12"
        style={{
          paddingTop: 60,
          paddingBottom: 110, // Space for lower third
        }}
      >
        {/* Progress dots */}
        <div
          className="flex justify-center gap-3 mb-10"
          style={{ opacity: dotsSpring }}
        >
          {Array.from({ length: totalCards }).map((_, i) => (
            <div
              key={i}
              className="rounded-full"
              style={{
                width: 18,
                height: 18,
                backgroundColor: i === cardIndex ? CORAL : "rgba(0,0,0,0.15)",
              }}
            />
          ))}
        </div>

        {/* Company header with logo */}
        <div
          className="flex items-center gap-6 mb-8"
          style={{
            opacity: companySpring,
            transform: `translateY(${interpolate(companySpring, [0, 1], [-20, 0])}px)`,
          }}
        >
          {/* Company logo */}
          <div
            className="rounded-2xl overflow-hidden flex items-center justify-center shadow-lg"
            style={{
              width: 88,
              height: 88,
              backgroundColor: "white",
              padding: 8,
              flexShrink: 0,
            }}
          >
            {offer.company.logo_url && !logoError ? (
              <Img
                src={offer.company.logo_url}
                style={{
                  maxWidth: "100%",
                  maxHeight: "100%",
                  objectFit: "contain",
                }}
                onError={() => setLogoError(true)}
              />
            ) : (
              <Building2
                size={48}
                strokeWidth={1.5}
                style={{ color: "#64748b" }}
              />
            )}
          </div>
          <div>
            <div
              className="font-bold"
              style={{
                fontSize: 52,
                color: "#1e293b",
                lineHeight: 1.1,
              }}
            >
              {offer.company.name}
            </div>
            <div
              className="font-medium"
              style={{
                fontSize: 32,
                color: "#64748b",
                marginTop: 4,
              }}
            >
              {offer.pricing_model === "Spot"
                ? "Pörssisähkö"
                : offer.pricing_model === "FixedPrice"
                  ? "Kiinteä hinta"
                  : "Hybridi"}
            </div>
          </div>
        </div>

        {/* === HERO: Discount section === */}
        {offer.discount && (
          <div
            className="rounded-3xl mb-10"
            style={{
              background: `linear-gradient(135deg, ${CORAL} 0%, ${CORAL_DARK} 100%)`,
              boxShadow: "0 12px 40px rgba(249, 115, 22, 0.35)",
              padding: "40px 48px",
              opacity: discountSpring,
              transform: `scale(${interpolate(discountSpring, [0, 0.7, 1], [0.85, 1.03, 1])})`,
            }}
          >
            {/* Discount value - HERO typography */}
            <div className="flex items-baseline justify-center gap-4">
              <span
                className="font-black"
                style={{
                  fontSize: 180,
                  color: "white",
                  lineHeight: 0.9,
                  letterSpacing: "-0.03em",
                }}
              >
                {discountParts.value}
              </span>
              <span
                className="font-bold"
                style={{
                  fontSize: 56,
                  color: "rgba(255,255,255,0.9)",
                }}
              >
                {discountParts.unit}
              </span>
            </div>
            {/* Subtext */}
            {discountSubtext && (
              <div
                className="text-center font-semibold"
                style={{
                  fontSize: 36,
                  color: "rgba(255,255,255,0.9)",
                  marginTop: 16,
                }}
              >
                {discountSubtext}
              </div>
            )}
          </div>
        )}

        {/* Contract name */}
        <h2
          className="font-bold mb-10"
          style={{
            fontSize: 64,
            color: "#0f172a",
            lineHeight: 1.15,
            opacity: nameSpring,
            transform: `translateY(${interpolate(nameSpring, [0, 1], [15, 0])}px)`,
          }}
        >
          {offer.name}
        </h2>

        {/* === DARK PRICING CARD === */}
        <div
          className="rounded-3xl overflow-hidden"
          style={{
            background: `linear-gradient(180deg, ${DARK_SLATE} 0%, ${DARK_SLATE_LIGHTER} 100%)`,
            boxShadow: "0 20px 50px rgba(0,0,0,0.25)",
            opacity: pricingSpring,
            transform: `translateY(${interpolate(pricingSpring, [0, 1], [30, 0])}px)`,
            padding: "40px 32px",
          }}
        >
          {/* Label */}
          <div
            className="text-center font-bold tracking-widest mb-2"
            style={{
              fontSize: 26,
              color: "#64748b",
            }}
          >
            VUOSIKUSTANNUS
          </div>

          {/* All three tiers in a row */}
          <div className="flex justify-between items-end mt-6">
            {/* Kerrostalo */}
            <div className="text-center flex-1">
              <div
                className="font-semibold"
                style={{ fontSize: 30, color: "#94a3b8" }}
              >
                Kerrostalo
              </div>
              <div
                className="font-medium"
                style={{ fontSize: 24, color: "#64748b", marginTop: 4 }}
              >
                2000 kWh
              </div>
              <div
                className="font-bold"
                style={{ fontSize: 56, color: "white", marginTop: 12 }}
              >
                {formatEur(offer.costs.apartment)}
              </div>
            </div>

            {/* Rivitalo - FEATURED */}
            <div className="text-center flex-1 mx-4">
              <div
                className="font-bold"
                style={{ fontSize: 34, color: CORAL }}
              >
                Rivitalo
              </div>
              <div
                className="font-semibold"
                style={{ fontSize: 26, color: CORAL, marginTop: 4, opacity: 0.8 }}
              >
                5000 kWh
              </div>
              <div
                className="font-black"
                style={{
                  fontSize: 96,
                  color: "white",
                  marginTop: 8,
                  lineHeight: 1,
                  letterSpacing: "-0.02em",
                }}
              >
                {formatEur(offer.costs.townhouse)}
              </div>
            </div>

            {/* Omakotitalo */}
            <div className="text-center flex-1">
              <div
                className="font-semibold"
                style={{ fontSize: 30, color: "#94a3b8" }}
              >
                Omakotitalo
              </div>
              <div
                className="font-medium"
                style={{ fontSize: 24, color: "#64748b", marginTop: 4 }}
              >
                10000 kWh
              </div>
              <div
                className="font-bold"
                style={{ fontSize: 56, color: "white", marginTop: 12 }}
              >
                {formatEur(offer.costs.house)}
              </div>
            </div>
          </div>
        </div>

        {/* Spacer to push savings badge down */}
        <div className="flex-1" />

        {/* === SAVINGS BADGE === */}
        {hasSavings && (
          <div
            className="rounded-2xl px-10 py-6 flex items-center justify-center"
            style={{
              background: GREEN,
              boxShadow: "0 8px 24px rgba(34, 197, 94, 0.35)",
              opacity: savingsSpring,
              transform: `scale(${interpolate(savingsSpring, [0, 0.7, 1], [0.9, 1.05, 1])})`,
              marginTop: 24,
            }}
          >
            <span
              className="font-black"
              style={{ fontSize: 44, color: "white" }}
            >
              SÄÄSTÄT {formatEur(featuredSavings)}/vuosi
            </span>
          </div>
        )}
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
