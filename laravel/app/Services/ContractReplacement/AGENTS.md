# AGENTS.md

Context for services under `laravel/app/Services`.

Use this file as a shortcut to find relevant service classes and understand the important decisions behind them. It does **not** replace reading the code.

See also:
- `../AGENTS.md` for service-level subtree guidance
- `../../AGENTS.md` for Laravel-level guidance
- `../../../AGENTS.md` for project-level guidance

## Contract replacement detection and linking

Directory:
- `app/Services/ContractReplacement/`

Primary files:
- `ContractReplacementMatcher.php` — finds the best replacement candidate for an inactive contract
- `ContractReplacementLinker.php` — persists high-confidence links onto inactive contracts

Related files outside this directory:
- `../Models/ElectricityContract.php` — replacement relations and chain traversal helpers
- `../Livewire/ContractDetail.php` — 301 redirect to latest active replacement, otherwise 410
- `../Console/Commands/DetectReplacementContracts.php` — reporting/debug command
- `../Console/Commands/LinkReplacementContracts.php` — manual linking command
- `../Console/Commands/FetchContracts.php` — import flow that auto-links high-confidence matches

### What the matcher does

`ContractReplacementMatcher` tries to map an **inactive** contract to the most likely replacement contract.

High-level method guide:
- `findBestReplacement($inactive)`
  - returns the best candidate match plus score, signals, metrics, and confidence
- `findMatchesForInactiveContracts()`
  - runs matcher across all inactive contracts for reporting/review
- `getCandidatesFor($inactive)`
  - narrows candidates with hard structural filters before any fuzzy name matching
- `scoreCandidate($inactive, $candidate)`
  - computes match score from structural and name-based signals
- `classifyConfidence(...)`
  - converts score/signals into `high`, `medium`, or `low`

### Hard requirements for candidate matching

A candidate must match the inactive contract on:
- provider (`company_name`)
- `contract_type`
- `metering`
- `pricing_model`
- `target_group`
- `fixed_time_range` for fixed-term contracts

## Important decision: do not loosen these structural filters casually

These constraints exist because providers often have multiple similarly named products at the same time.

Examples of real failure modes we want to avoid:
- matching a fixed contract to a spot contract from the same provider
- matching a household product to a business product
- matching a 12-month product to a 24-month product
- matching a general tariff contract to a time/season tariff contract

If future sessions change these filters, they must also re-evaluate SEO redirect safety. A wrong 301 is worse than a 410.

## Name matching strategy

After structural filtering, the matcher scores by normalized-name similarity.

Key ideas:
- remove promo/noise text before comparing base names
- compare product identity words separately from campaign wording
- give extra attention to variant/flavor words that may indicate a materially different product

Internal concepts used by the matcher:
- **noise tokens**
  - marketing/promotional words like `ensimmäiset`, `perusmaksu`, `-50%`, etc.
  - these should not block a match when the base product stayed the same
- **identity tokens**
  - core product labels like `duo`, `varma`, `joustosahko`, `vire`, `verraton`
  - help distinguish sibling products from the same provider
- **profile tokens**
  - meaningful variant words like `tuuli`, `aurinko`, `vesi`, `fossiilivapaa`, `yrityksille`
  - used to avoid collapsing materially different variants together

## Important decision: tolerate campaign changes, avoid product drift

The matcher is intentionally tolerant of changes such as:
- `0 € perusmaksu`
- `ensimmäiset 3 kk`
- `ensimmäinen kuukausi ilmaiseksi`
- similar sales wording

But it must remain conservative when the underlying product seems different.

Examples of risky drift that should usually **not** auto-link:
- `Aurinkovoima` → `Tuulivoima`
- `CO2-vapaa` / `fossiilivapaa` variant → plain product
- clearly different product family names under same provider

Reason:
- for SEO and UX, safe redirects matter more than redirect coverage
- uncertain cases should remain `410 Gone` until manually reviewed or confidently linked later

## Confidence policy

Current intended meaning:
- `high`
  - safe enough to persist automatically during import
- `medium`
  - plausible; keep for reporting and manual review, but do not auto-link
- `low`
  - not safe; do not link

## Important decision: only auto-link high-confidence matches

`ContractReplacementLinker` only persists `high` confidence matches.

Reason:
- redirect quality is more important than maximizing redirect count
- `410` is the safe fallback when we do not know the successor with enough confidence

## Linking behavior and chain preservation

`ContractReplacementLinker` only links:
- inactive contracts
- with no existing `replaced_by_contract_id`
- where the best match is `high` confidence

It also avoids cycles.

## Important decision: do not overwrite existing links during import

Reason:
- we want chains to accumulate naturally over time
- example:
  - month 1: `A -> B`
  - month 3: `B -> C`
- if imports rewrote historical links aggressively, we would flatten chain history and lose sequence information

Current desired behavior is to preserve prior links so future history can walk:
- backward from current contract to predecessors
- forward from older contracts to latest successor

## If you work on price history features

Relevant starting points:
- `../Models/ElectricityContract.php`
  - `replacedBy()`
  - `replacements()`
  - `getReplacementChainForward()`
  - `getReplacementChainBackward()`
  - `resolveLatestReplacement()`
- `ContractReplacementLinker.php`
- `ContractReplacementMatcher.php`

Recommended approach:
- start from the current/live contract
- walk backward across known predecessors
- aggregate `PriceComponent` history across the chain

## Command shortcuts

Useful commands when modifying this area:
```bash
cd laravel
php artisan contracts:detect-replacements --min-score=70 --limit=100
php artisan contracts:detect-replacements --confidence=medium --limit=100
php artisan contracts:detect-replacements --json=storage/app/replacement-matches.json
php artisan contracts:link-replacements
php artisan contracts:fetch --skip-logos
```

## Documentation rule for this subtree

If you change matching semantics, auto-link thresholds, or redirect behavior:
- update this file with the new decision and the reason
- update `../../AGENTS.md` if the behavior changes at Laravel-app level
- update root `../../../AGENTS.md` only with the high-level summary
