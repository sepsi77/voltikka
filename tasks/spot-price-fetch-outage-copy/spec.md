# Investigate stale Spot prices and correct unavailable copy

Investigate why the production header and Spot price page do not show the current official FI Spot price.

## Requirements

- Inspect the scheduled production import and freshness diagnostics without changing production data.
- Identify the newest official FI hour and the upstream failure class.
- Change `Spot-hintaa ei ole saatavilla` to the correct Finnish text `Spot-hintoja ei ole saatavilla` in all header states.
- Update focused tests and documentation if needed.
- Do not run a manual production import, change variables, or deploy without explicit user confirmation.

## Status

Completed on 2026-09-01. The header copy and focused tests now use `Spot-hintoja ei ole saatavilla`. Display behavior and pricing logic did not change.
