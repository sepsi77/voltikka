# Contract price cache refresh

## Goal
Serve the last successful cached prices until a successful import refresh replaces them. Prevent public detail requests from doing unnecessary full-market price calculations.

## Evidence
The reported Sentry detail request reached the 30-second limit after full-market caches for 2,000 and 10,000 kWh were written; the 18,000 kWh cache was missing. The static cost table currently uses the market read-through cache for each tier.

## First unit
For non-selected consumption, ContractDetail must use its existing single-contract canonical or legacy calculation. Keep the selected-consumption cache lookup, ranking, request memoization, canonical exclusions, and complete initial HTML table unchanged. Add regression tests and update the nearest context.

## Approved second unit
Replace import-time global cache clearing and public prewarm bumps with a private build/verify/activate generation. Keep all eight shared annual presets and default company/5,000 usable across midnight and beyond 48 hours until refresh. Required statistics must succeed and the import must be complete before replacement starts. Immediate interpretation/EEX/manual invalidations remain safety boundaries and reject concurrent candidates. Keep current availability and pointed/published source safety checks at the shared-cache and prepared-page boundaries. Retire only tracked prior-generation payloads with an in-flight grace period. Do not claim a site-wide database snapshot.

## Review corrections
Canonical cache eligibility uses current source/publication identity, not `relational_pricing_published`. Corrected canonical hidden-price increases remain available when their typed outcome is listed, even if relational writes are blocked. Genuinely excluded canonical outcomes remain excluded.

Retired payloads and manifests remain until explicit physical cleanup after one hour of reader grace. An owned five-minute scheduled command deletes only due tracked keys, with bounded work, active-generation checks, and durable retry state. A TTL alone is not sufficient to reclaim MySQL rows. Retained phase prices and offer facts can remain from yesterday until explicit refresh; no date-driven market rebuild is added.

## Detail date result
`heroVerdictNote()` now uses `ContractListCacheService::calculatedAt(rankConsumption())` in Europe/Helsinki. Missing timestamps omit the date. Regression tests cover retained yesterday prices, today's default cache, a snapped custom consumption, UTC conversion, and missing evidence. Other hero copy and pricing data stay unchanged.

## Limits
The second unit may update caching/import/service and root architecture context. Do not edit ContractDetail, its presenter tests, or Livewire context in this unit. No unrelated task edits, commit, push, deployment, or production changes.
