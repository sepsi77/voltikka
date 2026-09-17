// Section starts stay fixed; outgoing scenes remain for the incoming fade.
export const WEEKLY_OFFERS_TIMING = {
  title: 2.5,
  carousel: 18,
  promo: 2.5,
  signoff: 2,
  overlap: 0.5,
} as const;

export const WEEKLY_OFFERS_DURATION_SECONDS =
  WEEKLY_OFFERS_TIMING.title + WEEKLY_OFFERS_TIMING.carousel +
  WEEKLY_OFFERS_TIMING.promo + WEEKLY_OFFERS_TIMING.signoff;
