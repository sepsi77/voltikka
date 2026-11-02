# JAO Nordic flow-based capacity

Collects the day-ahead `maxBorderFlow` of the Finnish borders from the JAO Nordic flow-based
publication tool for the monthly market review. Nothing public reads these rows yet. Task context:
`../../../../tasks/monthly-market-review/` (data inventory G7).

## Why JAO

Since the Nordic flow-based market coupling (first delivery day 2024-10-30), Fingrid's official
day-ahead capacity datasets (24–27, 112, 115) hold only zeros or nothing (checked 2026-11-01). The
real day-ahead limit per border direction is JAO `maxBorderFlow`: the largest flow the flow-based
domain permits on that border, given the other borders. It is not an NTC, and the sum of borders is
not a guaranteed simultaneous import.

## Files

- `JaoNordicPublicationService.php` – read-only client for
  `GET https://publicationtool.jao.eu/nordic/api/data/maxBorderFlow?FromUtc=…&ToUtc=…`
  (ISO UTC with `.000Z`). No key. Response `{data: [...], rejected, messages}`; one row per MTU
  with `dateTimeUtc`, `lastModifiedOn` and one `border_<FROM>_<TO>` field per Nordic border.
  The range is half-open and at most 2 days: a longer range is HTTP 400 "The requested range
  exceeds 2 day(s)". One request per `jao.request_delay_seconds` (2 s); retries 429, 5xx and
  connection errors.
- `JaoMaxBorderFlowImporter.php` – hourly mean (`capacity_mw`) and minimum (`min_capacity_mw`) per
  border, upserted into `jao_max_border_flows` on (border, timestamp).
- `../../Console/Commands/FetchJaoMaxBorderFlows.php` – `php artisan jao:fetch`.
- `../../../config/jao.php` – borders and limits.

## Rules

- `border` is `<from>_<to>`: `SE1_FI`, `SE3_FI`, `EE_FI`, `NO4_FI` are import into Finland;
  `FI_SE1`, `FI_SE3`, `FI_EE`, `FI_NO4` are export. NO4–FI is the small AC link (about 50 MW).
- History starts at 2024-10-29T23:00Z. MTU is 60 min until 2025-09-30T22:00Z and 15 min after it,
  so `sample_count` is 1 or 4. A lower count after that date is an incomplete hour.
- `utc_datetime` is UTC text without a cast; `timestamp` is the UTC hour start in unix seconds.
  `last_modified_on` is JAO's text as given (no zone; it looks like UTC) and the latest in the hour.
- The default run covers UTC days today − 2 to tomorrow. D+1 is published at about 10–11 CET, so
  run it after 12:00 Helsinki. Future windows that JAO has not published return an empty list; that
  is not a failure.
- Each 48-hour window is one unit: a failed window does not stop the others; the run then exits 1.
  Logs contain the window and exception class only.
- Live check 2026-10-30 (CET day): SE1→FI 1,799–1,884 MW (mean 1,809), SE3→FI 1,200, EE→FI 1,016,
  NO4→FI 48–57; FI→SE1 1,698–1,773.

## Terms of use (open)

JAO's "General Terms and Conditions for the Use of the Information Systems"
(https://new-prod.jao.eu/terms-conditions, fetched 2026-11-01) permit "Legitimate Use": research, and internal information,
monitoring, analysis and reporting. They forbid dissemination, reproduction and commercial use of
the Content without approval (contact@jao.eu), and ask that the source is named. They do not name
the Nordic publication tool. Use the data for analysis; before a public review shows JAO figures
or charts, get the user's decision (and if needed JAO's written permission). Credit "JAO Nordic
publication tool".

User decision (2026-11-02): when Voltikka explains its own forecast results (for example in the
monthly review or the own-model reports), it may mention a JAO capacity value, or its change
from earlier periods, to the degree that it helps explain the forecast. Use single values or
changes in text, not data tables, series downloads or charts of JAO data, and credit
"JAO Nordic publication tool". This is the user's decision; JAO's terms still name approval for
dissemination (contact@jao.eu), so wider reuse (tables, charts, downloads) still needs JAO's
written permission.

## Commands

```bash
php artisan jao:fetch                                   # today - 2 .. tomorrow
php artisan jao:fetch --from=2024-10-29 --to=2026-10-31  # backfill, 2-day requests (~370 calls, ~13 min)
```
