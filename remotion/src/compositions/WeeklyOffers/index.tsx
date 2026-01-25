import { AbsoluteFill, Sequence, useVideoConfig } from "remotion";
import type { WeeklyOffersProps } from "../../types";
import { TitleScene } from "./TitleScene";
import { OffersCarousel } from "./OffersCarousel";
import { PromoScene } from "./PromoScene";
import { SignOffScene } from "./SignOffScene";

// Brand colors
const BG_LIGHT = "#f8fafc"; // slate-50

/**
 * WeeklyOffers - Main composition for weekly electricity offers video
 *
 * Video Structure (19 seconds total at 30fps = 570 frames):
 * - TitleScene: 0-2.5s (frames 0-75)
 * - OffersCarousel: 2.5-14.5s (frames 75-435)
 * - PromoScene: 14.5-17s (frames 435-510)
 * - SignOffScene: 17-19s (frames 510-570)
 */
export const WeeklyOffers: React.FC<WeeklyOffersProps> = ({ data }) => {
  const { fps } = useVideoConfig();

  // Section timing (in seconds)
  const TITLE_END = 2.5;
  const CAROUSEL_END = 14.5;
  const PROMO_START = 14.5;
  const PROMO_END = 17;
  const SIGNOFF_START = 17;

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

      {/* === SECTION 1: Title (0-2.5s) === */}
      <Sequence durationInFrames={TITLE_END * fps}>
        <TitleScene
          weekFormatted={data.week.formatted}
          offersCount={data.offers_count}
        />
      </Sequence>

      {/* === SECTION 2: Offers Carousel (2.5-14.5s) === */}
      <Sequence
        from={TITLE_END * fps}
        durationInFrames={(CAROUSEL_END - TITLE_END) * fps}
      >
        <OffersCarousel offers={data.offers} />
      </Sequence>

      {/* === SECTION 3: Promo CTA (14.5-17s) === */}
      <Sequence
        from={PROMO_START * fps}
        durationInFrames={(PROMO_END - PROMO_START) * fps}
      >
        <PromoScene />
      </Sequence>

      {/* === SECTION 4: Sign-off (17-19s) === */}
      <Sequence from={SIGNOFF_START * fps}>
        <SignOffScene />
      </Sequence>
    </AbsoluteFill>
  );
};
