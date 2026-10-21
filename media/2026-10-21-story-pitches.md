# Media story pitches from Voltikka data

Date: 2026-10-21

Source of figures: local copy of the production database, synchronized on 2026-10-16. Check each figure against production before you send a pitch.

Background: a review of Finnish electricity-price coverage from October 2024 to October 2026. It found about 16 recurring story types. The daily spot-price alert from STT is the most frequent. The topic numbers below (#1–#16) refer to that review.

## Data that other sources do not have

Voltikka's strongest asset is the **contract market**. Media write about the spot price every day. Nobody publishes a daily record of what sellers actually charge.

- **Daily contract price statistics since 2026-01-21.** 266 days, by segment (fixed 6/12/24 months, open-ended, spot, Hybrid, quarterly), at 2,000, 5,000 and 18,000 kWh. Table `contract_price_daily_statistics`.
- **Promotion prices with an increase that only the text discloses.** The interpretation pipeline marks 24 current contracts with `misleading_first_12_months = detected`.
- **Retail premium dataset.** 8,033 observations, 35 companies. Each observation compares a retail price with the futures price at the date the seller set the price. Table `retail_premium_observations`.
- **Market data.** FI futures since 2026-04-08, spot hours since 2022, 15-minute prices since 2025-09-30.

## Pitches, strongest first

### 1. "Fixed prices rose 44 % while spot electricity was record cheap"

Topics #8 and #9. Timing: now.

- The median 12-month fixed energy price was 8.23 c/kWh in January 2026 and 11.84 c/kWh in October 2026.
- The 24-month median went from 7.88 c/kWh to 10.63 c/kWh.
- In the same period, spot was 2.2 c/kWh in March and 1.9 c/kWh in July.
- Message: people who wait for a crisis before they lock a fixed price pay more. This is the usual "should I lock a fixed price now?" story, but with real offer prices, not one seller's press release.
- Extra hook: a 24-month contract is now cheaper than a 12-month contract. At 5,000 kWh the medians are 591 € and 648 € a year.

Figures are monthly averages of daily medians. Each segment has 43–58 contracts.

### 2. "One year after the bait-pricing decision, low starting prices are still sold"

Topic #11. Timing: the KKV Aalto Energia decision was on 2025-10-31, so the anniversary is 2026-10-31.

- Our data has 24 current contracts where the shown price is a promotion and the later increase is only in the description text.
- This story has the highest value and the highest risk. Check each case manually. Start with the aggregate count. If you name companies, give each company time to reply before publication.

### 3. "Same electricity, more than double the price: open-ended customers pay the most"

Topics #9 and #10.

- On 2026-10-21, open-ended general-tariff energy prices were from 8.69 c/kWh (regional contract) to 20.23 c/kWh. The cheapest national contract was 11.69 c/kWh. The median of all open-ended contracts was 13.73 c/kWh.
- Correction: an earlier version of this pitch said that open-ended prices rose from June to October. That was wrong. The median changed because the set of contracts changed, and because the annual cost is an estimate. The same 15 comparable contracts had the same energy price in January and October.
- Human-interest version: a reader enters a real bill in `/maksatko-liikaa`. We show the cheaper alternatives for the same period.

### 4. Monthly contract-market scorecard

Topic #4. Timing: first working day of each month.

- Media already write a monthly spot scorecard. We can supply the same for contracts: fixed medians, spot margin (median 0.5 c/kWh, maximum 1.6 c/kWh), open-ended prices and the seller-set energy-price index.
- A regular release gives a data desk (Yle, MT, Verkkouutiset) a recurring reason to cite Voltikka.

### 5. "Do sellers pass on price falls as fast as price rises?"

Topics #11 and #14. Status: needs analysis before a pitch.

- This is the "rockets and feathers" question. The retail premium dataset against FI futures can answer it.
- It is the most original story, but it is not ready.
- Always say "retail premium" or "spread over wholesale". Never say "margin" or "profit".

### 6. "One year of 15-minute prices: what changed"

Topic #16. Timing: follow-up to the 2025-10-01 switch.

- We have one full year of 15-minute data. We can show how far the most expensive quarter-hour is from the hourly average, and how negative prices became less frequent and shallower.
- Lower value: price-tracker sites can make the same story.

## Do not pitch

- **Our own price forecasts.** The evaluated directions were mostly wrong; many "falling" forecasts were followed by rises. If we use futures, a story about how wrong last winter's futures were is safer than a new forecast.
- **The daily spot alert (#1, #2).** STT owns this template. We add nothing.
- **Data centres, grid fees, nuclear outages, cables (#5, #6, #12, #13).** We have no own data.
- **Seller-click analytics.** The data is private and the sample is small (1,414 clicks).
- **Energiavirasto.** Do not mention it in pitch text.

## Practical points

- Give each pitch one chart, one figure for each household size (apartment 2,000 kWh, house 5,000 kWh, electric heating 18,000 kWh), and a link to the method page `/tietoa#menetelma`.
- The CSV on `/sahkosopimus/tilastot` lets journalists check the figures.
- Pitches 1 and 2 fit the next two weeks: the OL3 return is delayed to 2026-11-07, and the KKV anniversary is on 2026-10-31.
