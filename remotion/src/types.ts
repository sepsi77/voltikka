// Types for the Voltikka Video API responses

export type DailyVideoData = {
  generated_at: string;
  date: {
    iso: string;
    weekday: string;
    day: number;
    month: string;
    formatted: string;
  };
  current_price: {
    price_with_tax: number;
    price_without_tax: number;
    is_quarter: boolean;
    time_label: string;
  } | null;
  statistics: {
    min: number | null;
    max: number | null;
    average: number | null;
    median: number | null;
    cheapest_hour: {
      hour: number;
      price: number;
      label: string;
    } | null;
    expensive_hour: {
      hour: number;
      price: number;
      label: string;
    } | null;
  };
  chart: {
    hours: string[];
    prices: number[];
    colors: string[];
    min: number;
    max: number;
  };
  appliances: {
    sauna: ApplianceRecommendation | null;
    laundry: ConsecutiveApplianceRecommendation | null;
    dishwasher: ConsecutiveApplianceRecommendation | null;
    water_heater: ApplianceRecommendation | null;
  };
  ev_charging: EvChargingRecommendation | null;
  comparison: {
    yesterday_average: number | null;
    change_from_yesterday_percent: number | null;
    rolling_30d_average: number | null;
    change_from_30d_percent: number | null;
    rolling_365d_average: number | null;
  };
  prices: {
    today: HourlyPrice[];
    tomorrow: HourlyPrice[];
  };
};

export type ApplianceRecommendation = {
  best_hour: number;
  best_hour_label: string;
  best_price: number;
  worst_hour?: number;
  worst_price?: number;
  cost_cents: number;
  cost_euros: number;
  savings_cents: number;
  savings_euros: number;
  power_kw: number;
  duration_hours: number;
  time_window: string;
};

export type ConsecutiveApplianceRecommendation = {
  start_hour: number;
  end_hour: number;
  time_label: string;
  average_price: number;
  cost_cents: number;
  cost_euros: number;
  savings_cents: number;
  savings_euros: number;
  power_kw: number;
  duration_hours: number;
  time_window: string;
};

export type EvChargingRecommendation = {
  start_hour: number;
  end_hour: number;
  time_label: string;
  average_price: number;
  cost_cents: number;
  cost_euros: number;
  savings_cents: number;
  savings_euros: number;
  power_kw: number;
  duration_hours: number;
  kwh_added: number;
  range_km_estimate: number;
};

export type HourlyPrice = {
  hour: number;
  label: string;
  price: number;
  price_with_vat: number;
};

export type WeeklyVideoData = {
  generated_at: string;
  period: {
    start: string;
    end: string;
  };
  days: {
    date: string;
    weekday: string;
    weekday_short: string;
    average: number;
    min: number;
    max: number;
  }[];
  summary: {
    average: number | null;
    best_day: {
      date: string;
      weekday: string;
      average: number;
    } | null;
    worst_day: {
      date: string;
      weekday: string;
      average: number;
    } | null;
  };
  context: {
    rolling_30d_average: number | null;
    rolling_365d_average: number | null;
  };
};

// Props for DailySpotPrice composition
export type DailySpotPriceProps = {
  data: DailyVideoData;
};
