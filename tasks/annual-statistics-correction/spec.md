# Versioned annual statistics correction

## Goal
Prepare corrected current and historical annual-cost statistics for the revised pricing calculations. Preserve observed seller prices, actual Spot inputs, and prior annual-method results. Historical estimates must use information available on the target date, not later market data.

## Scope
Add an explicit corrected annual method using the existing versioned annual tables and readers. Correct historical recurring-reset estimation and date-bound seasonal fallbacks. Preview against the public active method with coverage and difference diagnostics. Keep apply explicit and date-atomic. Prepare a reviewed rollout procedure, without running production changes.

## Constraints
Preserve all existing uncommitted pricing and methodology changes. No commit/push/deployment, production mutation, data refresh, historical apply, or active production method switch in this task. Useful estimates must remain available with honest fallbacks; no new certainty requirements. Prefer existing types, services and commands over a parallel calculation system.

## Acceptance
Old annual rows and observed evidence stay unchanged when corrected rows are written. Historical market inputs are strictly date-bounded, including seasonal fallbacks. Corrected historical/reset/Spot/short-term/Hybrid outcomes are covered by tests. Preview compares the intended methods and reports unavailable/new/lost coverage. Readers and chart compatibility distinguish the corrected method. Tests, build, diff checks and rollout documentation are complete.
