# refactor-reviewer — staged-api-imports
Range: 3ed8b55f31b3f945c1c9211c5a63b7439942d921..cdc93650e77e1db73f7f9067fee987a73de59c62 (no local changes) · Result: 2 findings

S17 and D7 excluded as requested. No tests, live API calls, Artisan commands or production access were run. There are no earlier review rounds in this task folder.

Overlap: Laravel correctness owns the replay/output consequences below; these findings concern duplicated phase ownership and old failure paths left outside the shared trait, not a separate correctness/coverage audit.

## Findings

### REF-001 — Remove the second parsing gate from non-paginated fetches
- **Severity**: Medium
- **Likelihood**: Medium
- **Class**: normal
- **Spec rule**: S1 (a parser fault must be replayable), S7 (one parsing/writing path); root `AGENTS.md`, Mandatory implementation principles. `decisions.md`, “Raw bodies, parsed in the store step”.
- **Evidence**: `laravel/app/Console/Commands/FetchJaoMaxBorderFlows.php:113–119,128–135`; `laravel/app/Console/Commands/FetchNveReservoirs.php:89–95,102–109`.
- **What is wrong**: Both fetches run the data parser, discard its result, and persist a parser exception as an acquisition failure. Store runs the same parser again only for units that passed that first gate. Neither source needs parsing to plan its requests: JAO's windows are calculated beforehand and NVE always requests its two endpoints. The extra gate is therefore unnecessary and gives an old parser verdict permanent authority over a later, fixed parser. After a parser fix, the saved 200 response remains in `failed_units`, so replay skips it without trying the corrected parser. This is distinct from the necessary pagination/discovery parsing in other sources (S19).
- **User-impact scenario**: JAO or NVE changes a response envelope; the initial parser rejects it, but its complete raw response is saved. An operator fixes the parser and starts the approved same-day replay. No recovered rows are written: the payload's old failed-unit membership wins. The operator must fetch again despite the task's promised parser-fault recovery; NVE's reference is also withheld when the skipped unit is its weekly history.
- **Fix**: Fetch records answered bodies without parsing them. Store alone evaluates their data shape, preserving the existing partial-source rules and propagating database errors to the trait. For example:
  ```php
  // Fetch: request plan is already known.
  $payload->record($unit, $response);

  // Store: transport failures stay failures; parse answered bodies here.
  if (($request['status'] ?? 0) < 200 || ($request['status'] ?? 0) >= 300) {
      $failed++;
      continue;
  }
  try {
      $rows = JaoNordicPublicationService::parse(ImportPayloadReader::body($request));
  } catch (\RuntimeException $e) {
      $failed++;
      // Existing safe per-unit reporting; continue with the other units.
      continue;
  }
  // Import outside that parser catch; DB failures must roll back the store.
  ```
  Blast radius:
  - `FetchJaoMaxBorderFlows.php:113–145` — delete fetch-side parse/catch and derive unusable-body failures during store, not from immutable membership for answered units.
  - `FetchNveReservoirs.php:89–109,136–140` — same change for both datasets; keep reference-period proof and missing-history protection.
  - `laravel/tests/Feature/FetchJaoMaxBorderFlowsCommandTest.php:191–209`, `laravel/tests/Feature/FetchNveReservoirsCommandTest.php:205–222` — these currently pin a parser rejection into fetch `failed_units`. Replace that machinery assertion with the observable behavior: the invalid body is retained, a current parser rejects it without publishing its rows, and a corrected parser can consume the exact saved file without HTTP. Keep real transport-failure and partial-row assertions. Existing D1/D2 transaction/replay tests remain relevant.
  - `laravel/app/Services/Jao/AGENTS.md`, `laravel/app/Services/Nve/AGENTS.md` — document the single store-side parser owner. Their `CLAUDE.md` symlinks already mirror them.

### REF-002 — Finish routing validation failures through the shared result owner
- **Severity**: Medium
- **Likelihood**: Medium
- **Class**: normal
- **Spec rule**: S20; `design.md` section 3, shared last-stdout JSON contract; root `AGENTS.md`, reuse existing patterns.
- **Evidence**: `laravel/app/Console/Commands/FetchFmiData.php:63–74,89–92`; `FetchGasStorage.php:69–91`; `FetchJaoMaxBorderFlows.php:58–70`; `FetchNveReservoirs.php:49–52`; `FetchSykeHydrology.php:53–80`. Shared owner: `laravel/app/Console/Concerns/StagedImport.php:122–134`. Consumer: `orchestration/src/voltikka_orchestration/activities.py:141`.
- **What is wrong**: Twelve old validation branches in these five converted commands still print prose and return `Command::FAILURE` directly. Their siblings already use `stagedFailure('fetch', 'invalid_options')`. This leaves two output/failure mechanisms for the same command contract and bypasses both the closed JSON result and safe aggregate log in exactly those branches. Adding another wrapper or reporter is unnecessary; the trait already provides the required boundary.
- **User-impact scenario**: A scheduled source option becomes invalid after a configuration change (for example a removed FMI part or Syke series), or an operator supplies a malformed replay option. The process exits 1 without the required JSON result. Temporal consequently reports `no_json_error` rather than `invalid_options`, recreating the diagnostic ambiguity S20 explicitly fixes.
- **Fix**: Keep the existing human-readable explanation, but replace each direct return with the existing helper:
  ```php
  $this->error('Invalid date. Use YYYY-MM-DD.');
  return $this->stagedFailure('fetch', 'invalid_options');
  ```
  Blast radius:
  - `FetchFmiData.php:67,74,92` — unknown parts, forecast backfill and invalid dates.
  - `FetchGasStorage.php:72,85,91` — unknown areas, invalid dates and inverted range.
  - `FetchJaoMaxBorderFlows.php:61,70` — invalid dates and empty/inverted range.
  - `FetchNveReservoirs.php:52` — invalid year.
  - `FetchSykeHydrology.php:56,71,80` — unknown series, invalid dates and inverted range.
  - Existing validation tests in `laravel/tests/Feature/FetchFmiDataCommandTest.php:396`, `FetchGasStorageCommandTest.php:168`, `FetchJaoMaxBorderFlowsCommandTest.php:120`, `FetchSykeHydrologyCommandTest.php:134`, and the NVE command tests — retain exit/no-HTTP assertions and assert the shared observable JSON result. No tests need to retain the direct-return implementation. No trait or Temporal change is needed.

## Files inspected

- Task: `tasks/staged-api-imports/{spec.md,design.md,decisions.md,tasks.json}`, `tasks/AGENTS.md`, review-directory inventory.
- Context: root `AGENTS.md` (mandatory principles, documentation maintenance and supplied project context), `laravel/AGENTS.md`, `laravel/app/Services/AGENTS.md`, `laravel/app/Support/AGENTS.md`, `orchestration/AGENTS.md`; local context in `ContractImport`, `ElectricityFutures`, `EntsoeTransparency`, `Fingrid`, `Fmi`, `GasStorage`, `ImportPayload`, `Jao`, `Nve`, `OpenMeteo`, `SpotForecasts`, `SpotPriceImport`, `Syke`. Verified every changed context file has its sibling `CLAUDE.md` symlink, including the new ImportPayload directory.
- All 15 converted command files under `laravel/app/Console/Commands/`: `FetchContracts.php`, `FetchEexFutures.php`, `FetchEexEuropeFutures.php`, `FetchSpot.php`, `FetchNeighbourSpot.php`, `FetchSpotForecast.php`, `FetchEntsoeTransparencyData.php`, `FetchFingridData.php`, `FetchFingridForecastVintages.php`, `FetchFmiData.php`, `FetchSykeHydrology.php`, `FetchWeather.php`, `FetchGasStorage.php`, `FetchJaoMaxBorderFlows.php`, `FetchNveReservoirs.php`; plus `CleanupImportPayloads.php`.
- Shared payload code: `laravel/app/Console/Concerns/StagedImport.php`; `laravel/app/Services/ImportPayload/{ImportPayloadWriter.php,ImportPayloadReader.php,ImportPayloadReference.php,ImportPayloadCleanup.php,ImportStoreResult.php}`; `laravel/config/import_payloads.php`.
- Client/service code: full reads of `Fingrid/FingridOpenDataService.php`, `Fmi/FmiOpenDataService.php`, `Jao/JaoNordicPublicationService.php`, `Nve/NveReservoirService.php`, `EntsoeTransparency/EntsoeTransparencyClient.php`, `Syke/SykeHydrologyService.php`; diffs of `AzureConsumerApiClient.php`, `OpenMeteo/OpenMeteoService.php`, `GasStorage/GieAgsiService.php`, `Support/DataFetchFailureReporter.php`. Searched removed client method names for residual callers.
- Orchestration: `orchestration/src/voltikka_orchestration/{jobs.py,staged.py}`, diffs of `s_forecast.py`, `schedules.py`, worker registration; result-consumer search in `activities.py`; `laravel/routes/console.php` diff.
- Tests inspected/search-reviewed (not executed): affected sections of `laravel/tests/Feature/Fetch{JaoMaxBorderFlows,NveReservoirs,GasStorage,SykeHydrology,FmiData,FingridData,EntsoeTransparencyData}CommandTest.php`, `Feature/ImportPayload/StagedImportTest.php`, `Unit/ImportPayload/ImportPayloadWriterReaderTest.php`; staged/patch/drain assertions in `orchestration/tests/{test_jobs.py,test_s_forecast.py}`.

The shared payload/reference/transaction mechanism is otherwise appropriately bounded by the written contract; no additional framework, flag or configuration layer is recommended.
