import { AbsoluteFill, Sequence } from "remotion";
import { SceneFade } from "./SceneFade";
import type { ContractOffer } from "../../types";
import { OfferCard } from "./OfferCard";

// Brand colors
const BG_DARK = "#0f172a"; // slate-900

type OffersCarouselProps = {
  offers: ContractOffer[];
  totalFrames: number;
  overlapFrames: number;
};

/**
 * OffersCarousel - Displays offer cards sequentially with smooth transitions
 *
 * Art Direction:
 * - Each card gets equal time based on total count
 * - The production maximum of 3 offers gives each card 6 seconds
 * - Incoming cards fade over retained outgoing cards
 * - Progress dots show current position
 */
export const OffersCarousel: React.FC<OffersCarouselProps> = ({ offers, totalFrames, overlapFrames }) => {
  // Calculate timing per card without dropping any input offers.
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
          durationInFrames={(index === offers.length - 1 ? totalFrames - index * framesPerCard : framesPerCard) + overlapFrames}
          premountFor={overlapFrames}
        >
          <SceneFade frames={overlapFrames} enabled={index > 0}>
            <OfferCard
              offer={offer}
              cardIndex={index}
              totalCards={offers.length}
            />
          </SceneFade>
        </Sequence>
      ))}
    </AbsoluteFill>
  );
};
