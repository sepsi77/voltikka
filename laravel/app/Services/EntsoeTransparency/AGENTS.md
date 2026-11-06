# ENTSO-E Transparency datasets (market review)

Collects three ENTSO-E Transparency Platform datasets for the monthly market review. Nothing public
reads these rows yet. Task context: `../../../../market-review/` (inventory G8, G11,
G23). Day-ahead prices are not here: they stay in `../EntsoeService.php` and `../SpotPriceImport/`.

## Files

- `EntsoeTransparencyClient.php` – read-only HTTP client. Key, base URL and connect timeout come from
  `services.entsoe`; other limits from `config/entsoe_transparency.php`. Unzips ZIP answers, treats
  an acknowledgement with reason 999 as "no data", pages unavailability queries with `offset`.
- `EntsoeCurve.php` – reads Period points (A01 and A03) as slots or as constant segments.
- `OutageImporter.php` – A77/A78/A80 → `entsoe_outages` (insert-only versions).
- `GenerationImporter.php` – A75 → `entsoe_generation_hourly` (hourly mean MW, upsert).
- `ReservoirImporter.php` – A72 → `entsoe_reservoir_weeks` (MWh per week, upsert).
- `../../Console/Commands/FetchEntsoeTransparencyData.php` – `php artisan entsoe:fetch`.

## API facts (checked live 2026-11-01)

- **Curve type A03** everywhere (outages, generation, reservoirs): a point is omitted when the value
  does not change; it lasts until the next position, and the last point until the end of the
  interval. `EntsoeCurve` expands it. Example: DE-LU B09 sends one point for a whole day.
- **Unavailability (A77, A78, A80)** answers are ZIP files, one XML document per outage. A plain
  period query returns only the **latest version** of each document, **including cancelled ones**
  (`<docStatus><value>A09</value></docStatus>`; no docStatus element = active). Withdrawn (A13)
  documents are not returned and are not collected.
- At most **200 documents per answer**; `offset` pages (max 4800). SE1 A80 for 2025 has exactly 200.
- A period longer than one year is rejected (HTTP 400). With `periodStartUpdate`/`periodEndUpdate`
  (update window, max one year) the period itself may be longer (3 years worked).
- The update window filters on the version's `createdDateTime`; it is the reliable way to get new
  versions daily. A query with `mRID` returns **every version** whose outage period overlaps the
  query period. A version whose period moved outside that one-year window is missed.
- A78 is directional: `out_Domain` is the exporting zone, `in_Domain` the importing zone. The two
  directions are different documents (FI→SE1 5, SE1→FI 19 in 2026). A78 TimeSeries can hold several
  `Asset_RegisteredResource` (Estlink1 + Estlink2). The nominal power is not given for A78.
- A80 has the generation unit (`powerSystemResources` mRID/name, e.g. "Olkiluoto 3 B3") under the
  production unit; A77 has only the production unit. Both carry `nominalP`. Curves are PT1M.
  Collect both: Swedish nuclear is mostly in A77 (SE3 2026: 41 A77 nuclear documents for Forsmark,
  Ringhals, Oskarshamn, 9 A80 for generator units). In FI, Olkiluoto is in A80 and Loviisa in A77
  (5 documents in 2026). One event can appear in
  both types; a reader must not add A77 and A80 capacity of the same plant together.
- A75 TimeSeries with `outBiddingZone_Domain` is consumption (DE-LU B10 pumped-storage pumping); with
  `inBiddingZone_Domain` it is generation. Resolution is PT15M for all six configured zones.
- A72 is P7D. Weeks start Monday 00:00 market time (CET/CEST), so UTC starts move between 22:00 and
  23:00 across DST. `EntsoeCurve` steps day/week resolutions in Europe/Brussels.

## Rules

- `entsoe_outages` is **insert-only** on (document_mrid, revision_number, time_series_mrid). A version
  is evidence of what was known when it was published; do not update or delete it. `is_cancelled`
  flags A09. `zone` is the bidding zone key (FI, SE1…) for A77/A80 and the border FROM_TO key
  (SE1_FI, FI_EE…, the JAO convention) for A78. `available_periods` is a JSON list of
  `{start, end, mw}` segments (UTC, adjacent equal values merged), never one row per minute.
  `min_available_mw` is the lowest segment value. `reasons` lists TimeSeries reasons, then document
  reasons.
- Version history: after each query, a document with revision > 1 whose previous revision is not
  stored gets one `mRID` query (one year around its start). This fills skipped versions. Revision
  numbers can have gaps upstream; then the query repeats while the document is in the window.
- Generation and reservoir rows are **upserted** (ENTSO-E revises). A frozen review figure must be
  copied when the review is published. Generation stores the mean of the slots in each UTC hour and
  `sample_count` (4 for a full PT15M hour). When two TimeSeries of one type and direction give the
  same slot, the later one wins; they are not summed.
- `utc_datetime`, `week_start_utc`, `start_utc` etc. are UTC text without casts (the app timezone can
  be Europe/Helsinki); `timestamp` columns are unix seconds.
- Retries: three attempts on every failed response and connection error (ENTSO-E sometimes answers
  a stalled request with a non-5xx error). The client waits `request_delay_seconds` (0.5 s) between
  requests; ENTSO-E allows 400 per minute.
- Exception messages and URLs contain the `securityToken`. Log only the dataset, zone, document type
  and exception class. A failed unit (dataset + zone/border + document type) does not stop others;
  the run then exits 1.

## Commands

```bash
php artisan entsoe:fetch                                   # daily window, all datasets
php artisan entsoe:fetch --dataset=outages --zone=FI       # one dataset, one zone or border key
php artisan entsoe:fetch --dataset=outages --from=2025-01-01           # backfill to today + 365 days
php artisan entsoe:fetch --dataset=generation --from=2025-10-01 --to=2026-10-31  # 7-day pieces
```

Daily windows: outages updated in the last 3 days with an outage period from today − 365 to
today + 730 days; generation the last 4 days; reservoirs the last 56 days. Generation and reservoir
windows end at the start of the current UTC hour. Backfill pieces: outages 365 days, generation 7,
reservoirs 364.
