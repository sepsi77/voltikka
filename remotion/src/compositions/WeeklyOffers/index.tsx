import { AbsoluteFill, Sequence, useVideoConfig } from "remotion";
import type { WeeklyOffersProps } from "../../types";
import { TitleScene } from "./TitleScene";
import { OffersCarousel } from "./OffersCarousel";
import { SignOffScene } from "./SignOffScene";

// Brand colors
const BG_LIGHT = "#f8fafc"; // slate-50

/**
 * WeeklyOffers - Main composition for weekly electricity offers video
 *
 * Video Structure (16.5 seconds total at 30fps = 495 frames):
 * - TitleScene: 0-2.5s (frames 0-75)
 * - OffersCarousel: 2.5-14.5s (frames 75-435)
 * - SignOffScene: 14.5-16.5s (frames 435-495)
 */
export const WeeklyOffers: React.FC<WeeklyOffersProps> = ({ data }) => {
  const { fps } = useVideoConfig();

  // Section timing (in seconds)
  const TITLE_END = 2.5;
  const CAROUSEL_END = 14.5;
  const SIGNOFF_START = 14.5;

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

      {/* === SECTION 3: Sign-off (14.5-16.5s) === */}
      <Sequence from={SIGNOFF_START * fps}>
        <SignOffScene />
      </Sequence>
    </AbsoluteFill>
  );
};
