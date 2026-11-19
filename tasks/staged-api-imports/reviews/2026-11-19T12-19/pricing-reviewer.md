# pricing-reviewer — staged-api-imports
Range: 3ed8b55f31b3f945c1c9211c5a63b7439942d921..cdc93650e77e1db73f7f9067fee987a73de59c62 (+ local changes: none) · Result: 2 findings

Read-only code review. No tests, live API calls, production access or Artisan commands were run. There are no earlier review rounds in this checkout. Tests mentioned below were inspected, not executed. Items outside pricing/data-meaning responsibility are marked N/A rather than independently certified.

## Spec checklist
| # | Spec item | Verdict | Notes |
|---|---|---|---|
| S1 | Raw, streamed gzip NDJSON | ✅ Pass | Shared reader/writer retain bodies; affected price parsers consume saved bodies. |
| S2 | No secrets in payloads | ➖ N/A | Shared redaction inspected; comprehensive privacy verdict belongs to Laravel review. |
| S3 | Atomic payload and SHA-256 | ✅ Pass | Temporary files and final checksum reference inspected. |
| S4 | Verify hash and completeness | ✅ Pass | Verification precedes store; source/command/options checked. |
| S5 | Preserve data-meaning times; real bookkeeping times | ⚠️ Partial | Fetch times propagate, including forecast vintages and contract observations, but out-of-order contract replay corrupts current chronology (PRC-001). |
| S6 | Retention and active-run protection | ➖ N/A | Cleanup/platform responsibility. |
| S7 | Three modes, one parsing/writing path | ✅ Pass | All 15 command implementations inspected. |
| S8 | Preserve rows, checkpoints and outcomes | ⚠️ Partial | Price arithmetic is retained; current-value publication can regress to older evidence (PRC-001, PRC-002). |
| S9 | Helsinki same-date refusal for contracts/EEX | ✅ Pass | All three date-bound stores check the saved effective date. Acceptance of older same-day input is not itself a defect. |
| S10 | Preserve partial-acquisition behavior | ✅ Pass | Available contract/Spot/EEX units retain their partial rules; FI proof cannot borrow old database rows. |
| S11 | Morning producer checkpoint lifecycle | ✅ Pass | Fetch starts running; stores establish ownership and final outcomes; store failures retain references. Source recency is a separate missing check (PRC-001). |
| S12 | Two activities, reference only | ✅ Pass | Fetch reference feeds store arguments; PHP stores perform no API acquisition. |
| S13 | Failed store never refetches | ✅ Pass | Separate activities and non-retryable CommandFailed policy inspected. |
| S14 | Interruptible fetch, draining store | ➖ N/A | Platform verdict; store is explicitly noninterruptible in inspected paths. |
| S15 | Contract fetch → store → completion | ✅ Pass | Existing completion/statistics/cache pipeline follows store. |
| S16 | Store-only replay with contract completion | ⚠️ Partial | Workflow/date behavior is present; same-day superseded data can be republished as current. |
| S17 | Busy completion lock defers | ➖ N/A | Explicitly excluded; shipped separately in PR #30. |
| S18 | Documentation | ✅ Pass | Time, payload, replay and source-specific policies documented in inspected contexts. |
| S19 | Read-only fetch planning | ✅ Pass | EEX discovery, outage revision planning and missing ECMWF point planning are preserved. |
| S20 | Closed JSON outcomes | ➖ N/A | Failure/output contract belongs to Laravel/platform review. |
| S21 | Transactional store; post-commit side effects | ✅ Pass | Primary import rows use shared/per-chunk transactions; contracts retain their existing separate import and completion stages. Spot averages/cache warming remain post-import work. |
| D1 | Per-command mode parity tests | ⚠️ Partial | Relevant price-command test implementations inspected; execution prohibited. |
| D2 | Rollback and HTTP-free replay tests | ⚠️ Partial | Price-command rollback/replay cases inspected; execution prohibited. |
| D3 | Secret-removal tests pass | ➖ N/A | Test/privacy review; no tests executed here. |
| D4 | Orchestration tests pass | ➖ N/A | Workflow code inspected; test/platform reviewers own execution and coverage. |
| D5 | Busy-lock deferral test | ➖ N/A | S17 explicitly excluded. |
| D6 | Safe SQL diagnostics tests | ➖ N/A | Laravel/test responsibility. |
| D7 | Production verification | ➖ N/A | Explicitly excluded; post-deploy check. |
| D8 | Documentation and mirrors | ⚠️ Partial | Pricing context inspected; exhaustive mirror check not performed. |

### Numerical paths verified by inspection

- EEX settlement extraction is unchanged. The store rebuilds the planned units and current-run prior-FI proof from the saved responses; month, quarter and year must share the latest prior trade date. An old database curve cannot make a quarter/year-only run ready. A real prior-FI write requests price-cache replacement after commit.
- Official Spot XML parsing, negative/zero values, historical VAT and quarter-to-hour arithmetic are unchanged. Request windows use Helsinki midnights, not fixed 48-hour UTC arithmetic across DST. Neighbour rows remain region-separated.
- FMI/Fingrid forecast stores receive payload fetch times. Open-Meteo records per-answer fetch times and saved model-run metadata; Previous Runs readiness still applies its fetch-time cut. Replay does not stamp these vintages with the store clock.
- No annual pricing method, VAT formula, calculated-cost schema identity or historical rebuild behavior changes in this range.

## Findings

### PRC-001 — An older same-day contract payload can roll current pricing and source chronology backwards
- **Severity**: High
- **Likelihood**: Medium
- **Class**: blocking
- **Spec rule**: S5, S8; root `AGENTS.md` facts/evidence rules. `decisions.md`, “Implementation (manager)” approves a new replay checkpoint owner, not replacement of newer seller evidence by an older fetch. S9 permits same-day replay but does not establish source recency.
- **Evidence**: `laravel/app/Console/Commands/FetchContracts.php:183-202`, `:226-232`, `:265`; `laravel/app/Services/ContractImport/ContractImporter.php:62-68`, `:284-314`, `:332-339`; `laravel/app/Services/ContractInterpretation/ContractInterpretationDispatcher.php:161-180`; `laravel/app/Services/ContractImport/ContractImportCompletion.php:312-337`.
- **What is wrong**: The only age check is equality of the Helsinki date. Replay then takes a new checkpoint UUID and unconditionally imports the old payload as the latest source. The importer unconditionally sets snapshot/episode `last_observed_at` to the older fetch time or creates a new pointed episode at that old time. It also replaces same-day components and full-import availability. UUID ownership protects command completion order, not seller-evidence order.

  Worked example (all times Helsinki, same day):
  1. 06:00 payload A offers a fully known 12-month contract at **8 c/kWh**, zero fees; save/store it.
  2. 10:00 payload B changes it to **12 c/kWh**; store and publish B.
  3. At 12:00 replay A after a fix or as the replay trial.

  Expected: retain B as current, **€600/year at 5,000 kWh**; refuse A as superseded (or process it without changing current state). Actual: the pointer moves back to A with a new episode dated 06:00. A's reusable validated interpretation can publish synchronously through `dispatchExisting()`, and completion rebuilds statistics and price caches from **8 c/kWh = €400/year**. This is not a real A→B→A seller recurrence. Even unchanged-source replay can move an episode's last-observed time backwards; if its first stored observation was later, it can produce `last_observed_at < first_observed_at`.
- **User-impact scenario**: A recovery/replay overlaps a later successful full or scoped import. Comparison cards, detail/API pricing and rankings can advertise the superseded €400 price; today's statistics and replacement generation persist it. A full replay can also restore products absent from the newer full acquisition. Existing pointer/currentness guards accept the stale data because replay itself made that pointer current.
- **Fix**: Before *any* contract, availability, observation or checkpoint takeover mutation, reject superseded evidence atomically against accepted source times, using the existing import transaction/locking boundary. Cover full-market membership as well as each imported contract; merely taking `max()` for timestamps would still let the old price win. Target shape:

  ```php
  // Within the serialized import boundary, before processContracts()/active-set writes.
  if ($latestAcceptedObservationAt?->gt($payloadFetchedAt)) {
      throw SupersededImportPayload::forCurrentState(); // closed refusal; no writes
  }
  // Existing import with observedAt: $payloadFetchedAt, never now().
  ```

  Apply to default split stores and manual/Temporal replay, full and scoped imports. Keep replay of the latest payload idempotent. Add one real-chain A→B→replay-A regression proving B's pointer, prices, availability, observations, statistics and active cache generation remain unchanged. No annual-method/schema change is required. If this has already occurred, affected source episodes, same-day price rows/statistics and activated cache generations need separately approved inspection/repair; a cache flush alone cannot repair provenance.

### PRC-002 — Spot-forecast replay replaces newer displayed values and can mislabel a mixed-vintage curve
- **Severity**: High
- **Likelihood**: Medium
- **Class**: blocking
- **Spec rule**: S8; root `AGENTS.md:39` (“Facts stay exact”). S5/S9 require retaining the old fetch time and accepting same-day input, not promoting that input over newer current values. The implementation assumption is explicit in `laravel/tests/Feature/FetchSpotForecastCommandTest.php:253-279`; this is concrete stale public output despite that assumption. No explicit user ruling requires current-display rollback.
- **Evidence**: `laravel/app/Console/Commands/FetchSpotForecast.php:100-118`; `laravel/tests/Feature/FetchSpotForecastCommandTest.php:259-279`; `laravel/app/Livewire/SpotPrice.php:759-796`; `laravel/app/Http/Controllers/SpotPriceCsvController.php:76-94`.
- **What is wrong**: The append-only archive correctly retains each feed and its original time, but the separate public display table unconditionally upserts `price_with_tax`, `fetched_at` and metadata by source/region/hour. An older replay therefore overwrites newer predictions. The existing test expressly expects a newer **11 c/kWh** value to regress to **8 c/kWh**.

  Worked example, same UTC day: 04:00 feed A predicts future hour H at **8 c/kWh**. At 10:00 feed B predicts H at **11**, and adds H+1 at **12**. Replay A at noon. Expected public curve: **[11, 12]**, from the latest acquired evidence. Actual: **[8, 12]**. H has been rolled back to A while H+1 remains from B. The page chooses its displayed update time independently with `orderByDesc('fetched_at')`, so it labels this mixed curve **10:00**, although the displayed 8 was superseded by 11 at that time.
- **User-impact scenario**: An operator replays a saved failed/previous feed after a later scheduled feed succeeded. `/spot-price` immediately loses the latest prediction for overlapping hours, its update label can still claim the newer time, and the CSV exports the stale 8 instead of 11. Cache invalidation makes the regression visible immediately; this is not an archive-only change. Official actuals and averages are unaffected.
- **Fix**: Keep storing the old feed in `spot_price_forecast_runs`, but make the display projection monotonic by payload fetch time, atomically within the store transaction:

  ```php
  // Existing display row locked/read inside the store transaction.
  if ($current === null || $current->fetched_at->lte($payloadFetchedAt)) {
      // Insert/update this hour's display price and its matching provenance together.
  }
  // Always retain the append-only vintage independently.
  ```

  Reuse a database-safe conditional update/locking pattern rather than a racy precheck. Update the existing older-payload test to require the newer display value to survive; include a newer-feed-only trailing hour and verify page/CSV values and update provenance. Preserve original fetched times (do not fix this by stamping replay with now). Blast radius: `FetchSpotForecast`, its current-table projection, the five-minute `spot_price:forecast:nordpool_predict_fi:v1` cache, page and CSV consumers. Archive rows need no rewrite. Already regressed display rows require reconstruction from the newest saved feed/vintage plus cache invalidation, not deletion of the older archive.

## Files inspected

- Task/context: `tasks/staged-api-imports/spec.md`, `design.md`, `decisions.md`, `tasks.json`, review-directory listing; `.agents/skills/spec-review/SKILL.md`; root and Laravel `AGENTS.md`; nearest pricing, statistics, caching, forecasting, retail-premium, card, import, futures, Spot, FMI, Fingrid, Open-Meteo, interpretation and readiness contexts.
- Shared payload path: `laravel/app/Console/Concerns/StagedImport.php`; `laravel/app/Services/ImportPayload/ImportPayloadWriter.php`, `ImportPayloadReader.php`.
- Commands: `laravel/app/Console/Commands/FetchContracts.php`, `FetchEexFutures.php`, `FetchEexEuropeFutures.php`, `FetchSpot.php`, `FetchNeighbourSpot.php`, `FetchSpotForecast.php`, `FetchEntsoeTransparencyData.php`, `FetchFingridData.php`, `FetchFingridForecastVintages.php`, `FetchFmiData.php`, `FetchSykeHydrology.php`, `FetchWeather.php`, `FetchGasStorage.php`, `FetchJaoMaxBorderFlows.php`, `FetchNveReservoirs.php`.
- Import/calculation consumers: `laravel/app/Services/ContractImport/ContractImporter.php`, `ContractPostImportCoordinator.php`, `ContractImportCompletion.php` (relevant stages); `laravel/app/Services/ContractInterpretation/ContractInterpretationDispatcher.php`; `laravel/app/Services/AzureConsumerApiClient.php`; `laravel/app/Services/ElectricityFutures/EexFuturesService.php`; `laravel/app/Services/EntsoeService.php`; `laravel/app/Services/SpotPriceAverageService.php`; `laravel/app/Services/SpotForecasts/NordpoolPredictFiService.php`; `laravel/app/Livewire/SpotPrice.php` (forecast readers); `laravel/app/Http/Controllers/SpotPriceCsvController.php`.
- Collector/parser diffs and vintage writers: `laravel/app/Services/Fingrid/FingridOpenDataService.php`, `FingridForecastVintageImporter.php`; `laravel/app/Services/Fmi/FmiForecastImporter.php`; `laravel/app/Services/OpenMeteo/OpenMeteoService.php`, `OpenMeteoWeatherImporter.php`; `laravel/app/Services/EntsoeTransparency/OutageImporter.php`; `laravel/app/Services/GasStorage/GieAgsiService.php`; `laravel/app/Services/Jao/JaoNordicPublicationService.php`; `laravel/app/Services/Nve/NveReservoirService.php`; `laravel/app/Services/Syke/SykeHydrologyService.php`; `laravel/app/Services/Orchestration/SForecastReadiness.php`.
- Tests inspected: `laravel/tests/Feature/FetchContractsCommandTest.php` (staged/time/replay cases), `FetchSpotForecastCommandTest.php`, `FetchEexFuturesCommandTest.php` (staged/replay cases), `FetchWeatherStagedCommandTest.php` (time/replay cases).
- Orchestration: `orchestration/AGENTS.md`; `orchestration/src/voltikka_orchestration/staged.py`, `jobs.py`, `s_forecast.py` (changed fetch paths).
