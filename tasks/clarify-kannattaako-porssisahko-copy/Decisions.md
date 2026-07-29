# Decisions

- Use plain Finnish for household readers and avoid overclaiming pörssisähkö savings.
- Keep statistical terms where needed, but explain them once in user language before using short labels.
- Replaced the p20 / edullisin viidennes explanation with "hinta, jonka alle 20 % tarjouksista jäi" so the chart is understandable without statistics vocabulary.
- Split spike-day and negative-price-day counts into separate volatility metrics because the combined `71 / 70` style looked like a fraction and was hard to parse.
- The hero, short answer, and summary use the current 5,000 kWh snapshot comparison. They name Spot or fixed 12-month contracts only when that type has the lower median annual cost. They give a neutral result when the medians are equal or unavailable.
- Removed the embedded `ContractTypeComparison` calculator. It compares selected individual contracts, so it can name a different winner and make the market-median answer look inconsistent.
- Added evergreen copy near the snapshot and in the summary. An individual contract can differ from the median for its contract type. The market median does not decide each contract pair.
- Kept the next action as a normal link to `/sahkosopimus`. No new interactive tool was added to the article.
- Put the current snapshot and short answer before the contents list, but kept the breadcrumb directly after the hero.
- Replaced the public canonical-method term with a plain sentence about current contract price data and one shared calculation method. The dynamic snapshot date remains the data date. The byline now calls 29.5.2026 the editorial review date.
- Added one exact takeaway and one native details table to every evidence chart. Tables use only each chart's prepared payload, include semantic headers and units, and show null values as a dash.
- Replaced the unnatural `sopimuspari` median caveat with plain Finnish. The short answer now says that prices vary and the market can include a fixed or Spot contract below its type median; the snapshot and summary use shorter supporting wording.
