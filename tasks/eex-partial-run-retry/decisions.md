# Decisions

## Root cause (2026-10-16, production read-only log)
- 7.10: the month-tenor `price-ticker` discovery probe (representative NP FBBM) threw
  `ConnectionException` after the HTTP client's own retries. Discovery is once per tenor, so no
  month request was built for any of the 13 markets (468 of 793 requests). One SE4 quarter EOD
  request also failed. Counted failures: 1 discovery + 1 acquisition = 2.
- 9.10: one NO3 year EOD request failed with `ConnectionException`. Counted failures: 1.
- Both are short transient connection failures; the next day's run was clean.

## In-run retry design (2026-10-16)
- Failure unit = one tenor discovery or one market/maturity EOD request. Units fail without an
  Issue entry during the passes; only units still failed after the last pass go to
  `DataFetchFailureReporter` (stages `discovery` / `acquisition`). Recovered retries do not alert,
  as `app/Support/AGENTS.md` requires.
- After the main pass: pause `retry_pause_seconds` (60, `Illuminate\Support\Sleep`, faked in tests),
  then retry the failed units through the same throttled service methods. At most `retry_passes`
  (2). A recovered discovery builds that tenor's requests for all markets in the same pass; if
  some of them fail, the next pass retries them.
- Retry follows the main pass, not the discovery step, as the spec asks. Cost on a 7.10-type day:
  about 26 probes + 325 month requests at about 15 s, about 90 minutes, so the run ends about
  08:30-09:00 Helsinki, before the 12:00 consumer deadline.
- Outage bound: more than `retry_max_failed_units` (25) failed units means no retry. A full outage
  already takes many hours in the main pass (793 units x connect timeouts + throttle); retrying it
  could run into the next day's 04:00 run.
- All `RequestException|ConnectionException` units are retried, including 4xx; the cap and the
  pass limit bound the cost. The HTTP client's own 3-attempt retry for 5xx/connection is unchanged.
- Config keys are plain values in `config/eex_futures.php` (no new environment variables).
- Retries also apply to targeted runs and `futures:backfill-eex` (it calls the fetch command);
  they never write the global checkpoint, so only the exit code and console output change.
- No new scheduler entry: the single 04:00 run has enough time for the bounded passes.

## Complete current-run FI proof (2026-10-16)
- The command records `current_run_latest_prior_fi_trade_dates_by_tenor` (month/quarter/year).
  Ready needs all three equal to `current_run_latest_prior_fi_trade_date` (the run's latest prior
  FI trade date), i.e. one complete FI Base vintage from this run. This matches the
  `EexMarketReferenceCurveProvider::availableTradeDates()` complete-vintage rule. "Any month row"
  was rejected: the 45-day window re-fetches old month rows, so it would not prove the newest day.
- `MorningJobFreshnessService::hasCurrentRunPriorFiVintage()` (the only reader of this proof;
  used by both `checkFixedTermForecast` and `checkRetailPremium`) checks the same rule. A ready row
  without the map (written before this change) fails closed with `eex_metadata`.
- Shared tenor list: `MorningJobFreshnessService::EEX_PROOF_TENORS`.
- Release timing: a deploy on a day whose consumers are not yet completed makes that day's
  pre-change ready row fail the gate until noon. Deploy after both morning consumers completed
  (or before 04:00), and never during the 04:00-09:00 EEX run (a deploy stopped the 2.10 run).

## Verification (local)
- Focused: FetchEexFuturesCommandTest, MorningJobFreshness*, MorningConsumerExecution*,
  DataFetchFailureReporter*, DeferredContractImportCompletionTest, FuturesAdjustedForecastTest,
  EexMarketReferenceCurveProviderTest: 121 passed.
- Full suite: 3047 passed, 1 failed (`LayoutAssetLoadingTest`, local `public/hot` file only).
- `pint --test --dirty` passed; `git diff --check` clean.
