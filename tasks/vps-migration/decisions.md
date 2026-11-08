# VPS migration: decisions

## 2026-11-09 – Task started; phase 2 Temporal goes live only on the VPS

- User decision: hosting moves from Railway to a VPS. The Temporal phase 2 orchestration
  (merged in PR #9) is deployed only to the VPS, never to Railway. Reason: the VPS gives a way to
  test the Temporal setup without affecting production.
- Consequence on `main`: the collector entries return to `laravel/routes/console.php`, so every
  scheduled job keeps running on Railway until its VPS cut-over, and a Railway deploy of `main`
  stays safe (`tasks/temporal-orchestration/decisions.md`, 2026-11-09).
- Two risks to settle in phase 0 (agent note): a VPS test that shares the production database
  while Railway still runs the scheduler would double-write; a VPS worker on the same Temporal
  namespace and task queue as Railway `voltikka-worker` would take production tasks.

## 2026-11-09 – PR #10 review, round 1

Head 99a6787. CodeRabbit review 5818910412 (Greptile not requested: user decision for this small
PR). Findings, all valid and fixed:

| Finding | Kind | Action |
|---|---|---|
| `WebSchedulerTest` checks `backup:run` by name, so the weekly files backup hides a missing daily database backup | inline 4507148843 (👍) | exact command strings |
| The same name-only check hides a lost `weather:fetch` / `fmi:fetch` `--only` variant | nitpick | every collector variant listed as an exact string |
| The "Fingrid 245 wind power forecast vintages" comment sat above the `ecmwf_00z` block (old misplacement restored from `543c342`) | additional comment | moved above `fingrid:fetch-forecast-vintages` |

Codification: none, because no spec review ran for this small PR; the fixes are local to one test
and one comment.
