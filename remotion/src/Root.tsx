import "./index.css";
import { Composition, Folder } from "remotion";
import { DailySpotPrice } from "./compositions/DailySpotPrice";
import { WeeklyOffers } from "./compositions/WeeklyOffers";
import type {
  DailySpotPriceProps,
  DailyVideoData,
  WeeklyOffersProps,
  WeeklyOffersVideoData,
} from "./types";

// API URL for fetching video data
const API_BASE_URL = process.env.VOLTIKKA_API_URL || "https://voltikka.fi";

// Placeholder for defaultProps - actual data is fetched via calculateMetadata
const placeholderData: DailyVideoData = {
  generated_at: "",
  date: { iso: "", weekday: "", day: 0, month: "", formatted: "" },
  current_price: null,
  statistics: {
    min: 0,
    max: 0,
    average: 0,
    median: 0,
    cheapest_hour: null,
    expensive_hour: null,
  },
  chart: { hours: [], prices: [], colors: [], min: 0, max: 0 },
  appliances: {
    sauna: null,
    laundry: null,
    dishwasher: null,
    water_heater: null,
  },
  ev_charging: null,
  comparison: {
    yesterday_average: null,
    change_from_yesterday_percent: null,
    rolling_30d_average: null,
    change_from_30d_percent: null,
    rolling_365d_average: null,
  },
  prices: { today: [], tomorrow: [] },
};

// Placeholder for Weekly Offers - actual data is fetched via calculateMetadata
const placeholderWeeklyOffersData: WeeklyOffersVideoData = {
  generated_at: "",
  week: { start: "", end: "", formatted: "" },
  offers_count: 0,
  offers: [],
};

// Fetch data from API for video rendering
const calculateDailyMetadata = async ({
  props,
  abortSignal,
}: {
  props: DailySpotPriceProps;
  abortSignal: AbortSignal;
}): Promise<{ props: DailySpotPriceProps }> => {
  const response = await fetch(`${API_BASE_URL}/api/video/daily`, {
    signal: abortSignal,
  });
  const json = await response.json();

  return {
    props: {
      ...props,
      data: json.data,
    },
  };
};

// Fetch Weekly Offers data from API
const calculateWeeklyOffersMetadata = async ({
  props,
  abortSignal,
}: {
  props: WeeklyOffersProps;
  abortSignal: AbortSignal;
}): Promise<{ props: WeeklyOffersProps }> => {
  const response = await fetch(`${API_BASE_URL}/api/video/weekly-offers`, {
    signal: abortSignal,
  });
  const json = await response.json();

  return {
    props: {
      ...props,
      data: json.data,
    },
  };
};

export const RemotionRoot: React.FC = () => {
  return (
    <>
      <Folder name="Daily">
        <Composition
          id="DailySpotPrice"
          component={DailySpotPrice}
          durationInFrames={16.5 * 30} // 16.5 seconds at 30fps
          fps={30}
          width={1080}
          height={1920}
          defaultProps={{
            data: placeholderData,
          } satisfies DailySpotPriceProps}
          calculateMetadata={calculateDailyMetadata}
        />
      </Folder>
      <Folder name="Weekly">
        <Composition
          id="WeeklyOffers"
          component={WeeklyOffers}
          durationInFrames={16.5 * 30} // 16.5 seconds at 30fps (495 frames)
          fps={30}
          width={1080}
          height={1920}
          defaultProps={{
            data: placeholderWeeklyOffersData,
          } satisfies WeeklyOffersProps}
          calculateMetadata={calculateWeeklyOffersMetadata}
        />
      </Folder>
    </>
  );
};
