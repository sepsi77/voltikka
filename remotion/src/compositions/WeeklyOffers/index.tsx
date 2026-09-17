import { AbsoluteFill, Sequence, useVideoConfig } from "remotion";
import type { WeeklyOffersProps } from "../../types";
import { TitleScene } from "./TitleScene";
import { OffersCarousel } from "./OffersCarousel";
import { PromoScene } from "./PromoScene";
import { SignOffScene } from "./SignOffScene";
import { SceneFade } from "./SceneFade";
import { WEEKLY_OFFERS_TIMING } from "./timing";
export { WEEKLY_OFFERS_DURATION_SECONDS } from "./timing";

// 25 seconds: title 2.5, offers 18, promo 2.5, sign-off 2.
export const WeeklyOffers: React.FC<WeeklyOffersProps> = ({ data }) => {
  const { fps } = useVideoConfig();
  const titleFrames = WEEKLY_OFFERS_TIMING.title * fps;
  const carouselFrames = WEEKLY_OFFERS_TIMING.carousel * fps;
  const promoFrames = WEEKLY_OFFERS_TIMING.promo * fps;
  const overlapFrames = WEEKLY_OFFERS_TIMING.overlap * fps;
  const promoStart = titleFrames + carouselFrames;

  return (
    <AbsoluteFill style={{ backgroundColor: "#f8fafc", fontFamily: "var(--font-primary)" }}>
      <div className="absolute inset-0" style={{
        backgroundImage: `
          radial-gradient(circle at 20% 20%, rgba(249, 115, 22, 0.03) 0%, transparent 50%),
          radial-gradient(circle at 80% 80%, rgba(249, 115, 22, 0.02) 0%, transparent 40%)
        `,
      }} />
      <Sequence durationInFrames={titleFrames + overlapFrames}>
        <TitleScene weekFormatted={data.week.formatted} offersCount={data.offers_count} />
      </Sequence>
      <Sequence from={titleFrames} durationInFrames={carouselFrames + overlapFrames} premountFor={overlapFrames}>
        <SceneFade frames={overlapFrames}>
          <OffersCarousel offers={data.offers} totalFrames={carouselFrames} overlapFrames={overlapFrames} />
        </SceneFade>
      </Sequence>
      <Sequence from={promoStart} durationInFrames={promoFrames + overlapFrames} premountFor={overlapFrames}>
        <SceneFade frames={overlapFrames}><PromoScene /></SceneFade>
      </Sequence>
      <Sequence from={promoStart + promoFrames} premountFor={overlapFrames}>
        <SceneFade frames={overlapFrames}><SignOffScene /></SceneFade>
      </Sequence>
    </AbsoluteFill>
  );
};
