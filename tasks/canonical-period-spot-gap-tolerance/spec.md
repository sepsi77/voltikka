# Canonical period Spot gap tolerance

## Problem

Production contract-detail bill comparison fails a complete billing month for every Spot-dependent canonical contract when even one realized hourly Spot row is missing. Recent completed months contain small ENTSO-E history gaps, so the default bill-period presets can be unavailable even though almost all observations exist.

## Requirements

- Keep a completely absent realized Spot history unavailable with `no_spot_history`.
- Do not fail a period because a subset of required Spot hours is missing.
- Fill a missing hour deterministically from observed data without changing fixed-price period calculations.
- Prefer the observed mean for the same Helsinki calendar day. If that day has no observation, use the observed mean for the requested Spot period.
- Keep the existing canonical and legacy calculators separate.
- Record the gap-fill assumption in the typed period outcome.
- Add focused regression coverage and update nearby documentation.
- Do not mutate production data or deploy without explicit confirmation.
