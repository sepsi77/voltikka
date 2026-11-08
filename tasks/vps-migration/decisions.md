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
