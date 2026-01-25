import { AbsoluteFill, Sequence, useVideoConfig } from "remotion";
import type { ContractOffer } from "../../types";
import { OfferCard } from "./OfferCard";

// Brand colors
const BG_DARK = "#0f172a"; // slate-900

// Carousel timing constants
const CAROUSEL_DURATION_SECONDS = 12; // Total duration for all cards (14.5s - 2.5s title)

type OffersCarouselProps = {
  offers: ContractOffer[];
};

/**
 * OffersCarousel - Displays offer cards sequentially with smooth transitions
 *
 * Art Direction:
 * - Each card gets equal time based on total count
 * - 1 card = 12s, 5 cards = 2.4s each
 * - Cards animate in/out with spring physics
 * - Progress dots show current position
 */
export const OffersCarousel: React.FC<OffersCarouselProps> = ({ offers }) => {
  const { fps } = useVideoConfig();

  // Calculate timing per card
  const totalFrames = CAROUSEL_DURATION_SECONDS * fps;
  const cardCount = Math.max(1, offers.length);
  const framesPerCard = Math.floor(totalFrames / cardCount);

  // Handle empty state
  if (offers.length === 0) {
    return (
      <AbsoluteFill
        style={{
          backgroundColor: BG_DARK,
          fontFamily: "var(--font-primary)",
        }}
      >
        <div className="absolute inset-0 flex flex-col items-center justify-center px-16">
          <div
            className="text-6xl font-bold mb-6"
            style={{ color: "white" }}
          >
            Ei tarjouksia
          </div>
          <div
            className="text-3xl text-center"
            style={{ color: "#94a3b8" }}
          >
            Tällä viikolla ei ole aktiivisia sähkötarjouksia.
            <br />
            Tarkista tilanne myöhemmin uudelleen.
          </div>
        </div>
      </AbsoluteFill>
    );
  }

  return (
    <AbsoluteFill>
      {offers.map((offer, index) => (
        <Sequence
          key={offer.id}
          from={index * framesPerCard}
          durationInFrames={framesPerCard}
        >
          <OfferCard
            offer={offer}
            cardIndex={index}
            totalCards={offers.length}
          />
        </Sequence>
      ))}
    </AbsoluteFill>
  );
};
