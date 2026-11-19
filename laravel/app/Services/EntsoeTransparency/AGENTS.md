# ENTSO-E Transparency datasets (market review)

Collects three ENTSO-E Transparency Platform datasets for the monthly market review. Nothing public
reads these rows yet. Task context: `../../../../market-review/` (inventory G8, G11,
G23). Day-ahead prices are not here: they stay in `../EntsoeService.php` and `../SpotPriceImport/`.

## Files

- `EntsoeTransparencyClient.php` – read-only HTTP client. Key, base URL and connect timeout come from
  `services.entsoe`; other limits from `config/entsoe_transparency.php`. `get()` returns the raw
  answer of one request; `documents()` unzips ZIP answers and treats an acknowledgement with reason
  999 as "no data". The command pages unavailability queries with `offset`.
- `EntsoeCurve.php` – reads Period points (A01 and A03) as slots or as constant segments.
- `OutageImporter.php` – A77/A78/A80 → `entsoe_outages` (insert-only versions). `versions()` and
  `missingPreviousRevisions()` (read-only) plan the version-history queries in the fetch step.
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
- Version history: after a unit's main queries, a document with revision > 1 whose previous
  revision is neither stored nor in the fetched pages gets one `mRID` query (one year around its
  start). This fills skipped versions. Revision
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

## Staged import (fetch, then store)

`entsoe:fetch` follows `../ImportPayload/AGENTS.md` (source directory `entsoe-transparency`):

- **Fetch** records every request: unit `{dataset, zone, document_type, query, kind, offset}`
  (`kind` is `main` or `history`; `query` numbers the queries of one unit). Outage ZIP answers are
  `body_base64`. The fetch parses each page to decide whether the next offset page exists and to
  read the outage versions. After a unit's main queries it reads `entsoe_outages` to find the
  missing previous revisions (S19) and fetches their history. Header facts: `window_minute` (outage
  windows), `current_hour` (generation and reservoir window end) and `history` (the planned mRID
  queries per zone and document type). The fetch never writes rows.
- **Store** parses every recorded body and imports query by query with the header `fetched_at`
  (S5), so a replay keeps the fetch time that `SForecastReadiness` and the S model compare with
  their cuts. A query with a failed page is left out, as before. Each importer has a pure
  `records()` (parse, no database) and `write()`; `import()` is both. The store parses a whole
  query before it writes, so a saved body that cannot be parsed (truncated XML with HTTP 200, an
  unreadable ZIP, an unexpected acknowledgement; any `Exception` from parsing) fails only that
  query: one log line `ENTSO-E transparency store skipped an unreadable query` (dataset, zone,
  document type, query, class), the other queries are stored, and the run exits 1 with
  `fetch_incomplete`. Database errors propagate and roll back (review PR #31; before, one bad body
  failed the whole store). Generation and reservoir bodies reach the store unparsed (the fetch
  step only parses outage pages), so this is their only parse boundary. Daily run: one transaction for
  all datasets. Backfill (`--from`): one transaction per query window, because a multi-year
  backfill is too large for one transaction; a fault keeps the earlier windows.
- A failed unit (request failed after retries, or an answer the client cannot read) is logged
  with the class only, listed in `failed_units`, and the store exits 1 with `fetch_incomplete`
  after committing the other units (S10). A missing key fails the whole fetch (`fetch_failed`).
  When no query of any unit was answered, the fetch throws `ImportFetchFailed` (`all_failed`):
  step `fetch`, exit 1, the payload with the error bodies kept and named in the JSON line. Before
  staging, the command also exited 1 when every unit failed.
- `--dataset` and `--zone` are deduplicated before planning (review PR #31): a repeated dataset
  planned its units twice under the same query keys, so a failed second copy hid the answered
  first one.
- Not date-bound (S9), but a `--from-payload` store of an older payload is refused
  (`payload_invalid`, `superseded`, no write) while a newer usable payload of the same options
  exists (shared rule, `../ImportPayload/AGENTS.md`). Without a newer file, outages add only
  missing versions; generation and reservoirs take the payload's values and fetch time.
- The S forecast workflow form `entsoe:fetch --dataset=outages --zone=FI --zone=SE3 ...` works in
  all three modes: the repeated options are stored in the header `options` and must be given
  again with `--from-payload`.
- The `securityToken` travels in the query string; the payload writer removes it from `url` and
  `query` (test `test_the_security_token_never_reaches_the_payload...`).

## Commands

```bash
php artisan entsoe:fetch                                   # daily window, all datasets
php artisan entsoe:fetch --dataset=outages --zone=FI       # one dataset, one zone or border key
php artisan entsoe:fetch --dataset=outages --from=2025-01-01           # backfill to today + 365 days
php artisan entsoe:fetch --dataset=generation --from=2025-10-01 --to=2026-10-31  # 7-day pieces
php artisan entsoe:fetch --zone=FI --fetch-only            # payload only, prints its reference
php artisan entsoe:fetch --zone=FI --from-payload=<path>   # store a saved payload, no API call
```

Daily windows: outages updated in the last 3 days with an outage period from today − 365 to
today + 730 days; generation the last 4 days; reservoirs the last 56 days. Generation and reservoir
windows end at the start of the current UTC hour. Backfill pieces: outages 365 days, generation 7,
reservoirs 364.
