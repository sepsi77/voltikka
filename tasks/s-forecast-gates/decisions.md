# Decisions

## 2026-11-20 – Plan (user confirmed)

- The user proposed replacing clock-based triggers with gates: fetches keep trying until they
  succeed, each one opens a gate, and the forecast runs when every gate is open. Manager view,
  confirmed by the user the same evening:
  - A gate opens on the readiness fact (data in the database), not on a successful fetch. On
    2026-11-17 `fingrid:fetch` succeeded at 14:00 but the 13:00 capacity row was not published yet.
  - Gates are parallel loops inside `SDailyForecast`, not Temporal signals from other workflows:
    the collectors' Schedules also serve the market review, and a signal can arrive before the S
    workflow exists. Re-reading the readiness check after a fetch gives the same result with less
    coupling.
  - Clocks stay as deadlines: the model freeze defines the morning view as of 11:30 and the
    Previous Runs vintages as fetched before 10:00/14:00.
  - Failed collector fetches retry until a deadline. Since the staged imports, a fetch only writes
    a payload file, so repeating it is safe. Stores keep the no-retry rule.
- Retries before this task (2026-11-20): `jobs.step_options()` makes `CommandFailed`
  non-retryable (only a lost attempt repeats); `s_forecast.FETCH_OPTIONS` the same; the HTTP
  clients retry about 3 times a few seconds apart. So a short Fingrid outage at 10:20 cost the day.
