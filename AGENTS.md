# AGENTS.md

IMPORTANT: Reply using ASD-STE100 Simplified Technical English.

This file provides guidance to AI coding agents when working with code in this repository.

## Mandatory implementation principles

- **Use the simplest solution that satisfies all constraints and fully solves the problem.** Prefer direct, small changes over new abstractions, layers, services, feature flags, dependencies, or configuration.
- Do not over-engineer for hypothetical future needs. Add complexity only when a current requirement makes it necessary, and document the reason.
- Reuse an existing pattern when it is suitable. Do not create a parallel system for the same responsibility.

## Pi subagents

Project-local Pi agents live in `.pi/agents/`. When you call a trusted project-local Pi subagent, set `confirmProjectAgents: false` in the subagent tool call so Pi does not show the user a second permission prompt. This flag controls the subagent confirmation prompt; it does not replace Pi's project trust decision.

## Project Overview

Voltikka is a Finnish electricity contract comparison platform built with **Laravel 11 and Livewire 4**. The site helps consumers find and compare electricity contracts, view real-time spot prices, and calculate solar panel production estimates.

## Production site

- Production site URL https://voltikka.fi/
- Hosting: Railway with MySQL database

## Railway production operations

Voltikka runs on Railway in the **Breezily** workspace.

| Resource | Name | ID |
|----------|------|----|
| Workspace | Breezily | `3382ff24-215b-4936-9726-abb79ace7744` |
| Project | Voltikka | `6d8cae01-1006-409f-8108-1d51f1abc676` |
| Environment | production | `9245cef8-41d0-486e-862f-193726511dba` |
| App service | voltikka | `700d0624-fa96-4266-876c-e37640d220ea` |
| Database service | MySQL | `beb2ba12-4a7b-416b-b4b1-596434dc3215` |
| Backup bucket | voltikka-backups | `460e1b25-73fc-45e3-a43a-0473d2d2b86d` |

Use explicit Railway IDs instead of relying on whichever project is currently linked in the local shell. Prefer Railway MCP for read-only platform inspection when available, and use the Railway CLI for workflows such as `railway run`, SSH, or database shells that need local repository state.

### Production code deployment

The `voltikka` app service is connected to the GitHub repository and automatically deploys each push to `origin/main`. Use the Git-based deployment path for normal code releases:

1. Run the relevant tests and production asset build.
2. Check `git diff --check` and `git status --short`.
3. Stage only the intended files and commit them with a clear release message.
4. Push the commit with `git push origin main`. Do **not** use `railway up`, `railway redeploy`, or a dashboard redeploy for a normal code release. These paths bypass or duplicate the configured Git release flow.
5. Use Railway MCP to find the deployment whose commit hash matches the pushed commit.
6. Poll that exact deployment with `scripts/railway-poll-deployment.sh` and the explicit project, environment, service, and deployment IDs. A successful `git push` does not prove a successful production deployment.
7. After Railway reports `SUCCESS`, verify the relevant production page or asset. For a failure, read bounded build/deploy logs before taking further action.

A push to `origin/main` is a production mutation because it starts an automatic deployment. State the branch, command, target project/environment/service, and expected effect, and get explicit user confirmation before pushing.

Safe-operation rules for agents:

- **Never run destructive or production-mutating Railway commands without explicit user confirmation.** This includes Git pushes that trigger deploys, direct deploys, restarts, redeploys, rollbacks, service/domain changes, variable writes/deletes, database writes, migrations, queue restarts, SSH commands that mutate state, and any command that could affect production traffic or data.
- Before any production mutation, state the exact project, environment, service, command, and expected effect, then wait for an affirmative confirmation from the user.
- Read-only commands are allowed for investigation: listing projects/services, checking status, reading bounded logs, viewing variables metadata, checking domains, and inspecting deployment status.
- Do not paste or expose secrets from Railway variables or database connection strings in chat. If a secret must be changed, describe the variable name and action without revealing values.
- Prefer bounded log reads and targeted diagnostics over streaming or broad dumps.
- Use the `use-railway` skill when available. Prefix Railway CLI calls with `RAILWAY_CALLER=skill:use-railway@1.2.2` and reuse a stable `RAILWAY_AGENT_SESSION` for related calls in the same user request.
- For production Laravel commands via Railway, prefer explicit context flags such as `--project 6d8cae01-1006-409f-8108-1d51f1abc676 --environment 9245cef8-41d0-486e-862f-193726511dba --service 700d0624-fa96-4266-876c-e37640d220ea` where supported.
- Use `scripts/railway-poll-deployment.sh` to poll Railway deployments; it exits on success/failure instead of polling indefinitely.

### Local production database snapshot

Run `scripts/sync-production-database.sh` from the repository root to replace the ignored local SQLite database with current public application data. Add `--yes` only when no prompt is needed. The script uses the explicit Voltikka production project, production environment, and MySQL service IDs above. Every Railway call has the required caller and one stable agent session.

The workflow is production read-only. It isolates Laravel from cached config and inherited database URLs, proves the effective temporary SQLite target before migration, reads MySQL in one read-only consistent transaction, excludes authentication and runtime tables, and validates row counts, foreign keys, and SQLite integrity. Application-table drift fails in both directions, except that production can temporarily lack the local-derived `contract_source_observations` table. In that case, the fresh target reconstructs observations and current contract pointers with the existing backfill migration logic before final validation. If production has the table, it is copied normally. It stops if the local database or one of its SQLite sidecars is in use. `lsof` is advisory, so local Laravel, queue, and database processes must stay stopped for the full workflow. A failure leaves the active local file unchanged. After the fresh target passes validation, a success uses SQLite's backup mechanism for a consistent timestamped backup in `/tmp`, checkpoints both files, removes stale WAL/shared-memory/journal sidecars, makes the final possible use check immediately before an atomic same-filesystem replacement, and tells the operator to restart local processes.

Prerequisites: PHP `pdo_sqlite` and `pdo_mysql`, an authenticated Railway CLI, `sqlite3`, and `lsof`. Never use `env`, `printenv`, or Railway variable-list output in this workflow. Do not run the internal Artisan adapter against `laravel/database/database.sqlite`. See `laravel/app/Services/DevelopmentDatabase/AGENTS.md` for the detailed rules.

### Production backups and disaster recovery

Voltikka uses `spatie/laravel-backup` for database-only scheduled backups to a Railway S3-compatible bucket as a first DR layer. This is same-provider redundancy and does **not** replace Railway-native MySQL backups or a future off-provider backup copy.

- Bucket display name: `voltikka-backups`; bucket ID: `460e1b25-73fc-45e3-a43a-0473d2d2b86d`; region: `ams`.
- App service variables include `BACKUP_DISK=s3`, Railway object-storage S3 credentials, and `BACKUP_ARCHIVE_PASSWORD`; never print these values.
- Scheduled commands in `laravel/routes/console.php`: `backup:run --only-db` daily at 03:00, `backup:run --only-files` weekly on Sunday at 02:30 for `storage/app/public`, `backup:clean` daily at 03:30, and `backup:monitor` daily at 03:45 Europe/Helsinki.
- Backup archives are encrypted; a restore requires both the object-storage credentials and `BACKUP_ARCHIVE_PASSWORD` from Railway variables.
- Any restore, backup deletion, credential reset, or manual production backup run is production-mutating and requires explicit user confirmation.
- Future improvement: replicate backup archives to an off-Railway provider such as Cloudflare R2, AWS S3, or Backblaze B2 and schedule periodic restore drills.

## Browser based testing

You can use agent browser skill to access a browser and do browser-based testing or access websites.

## Form input processing

- A field in which a visitor enters a value must normally sync to Livewire only on blur. Use `wire:model.blur`; do not use live, input, or change processing while the visitor edits text, numbers, email, telephone, URL, dates, times, or a textarea.
- Search and autocomplete fields are the deliberate exception. Mark them with `data-search-input` and use `wire:model.live.debounce.Nms` so results update while the visitor types without one request per keystroke.
- Checkboxes, radio buttons, selects, range sliders, file inputs, preset buttons, and other complete discrete choices can process immediately. They do not have an unfinished typing state.
- If Enter must commit a typed value, make Enter blur the field so blur remains the one processing boundary.
- Numeric fields use a non-negative HTML `min` unless the domain is legitimately signed. HTML is not authoritative: normalize or reject invalid values in the Livewire component before calculation, and show an accessible notice when a value is corrected. Mark a genuinely signed field with `data-allow-negative` and document why.
- `laravel/tests/Unit/FormInputBlurPolicyTest.php` enforces the binding and numeric-minimum rules over all Laravel Blade templates. Update that test only when the interaction policy itself changes, not to allow one form to bypass it.

## Task tracking

Agents should use the task tracking system in `tasks/` when working on this code base. Read `tasks/AGENTS.md` before starting implementation work and keep the relevant task files updated as work progresses.

**A `tasks/` entry is not a reminder.** Nobody reads an old task folder. Anything that must happen at a future date belongs in the section below, and ideally in a scheduled command that surfaces itself. See `## Dated follow-ups`.

## Dated follow-ups

Time-triggered work that is not yet possible. Check this list when the date has passed. Each item says what unblocks it and where the detail lives. **Remove an item once it is done**, and do not add anything here that a scheduled command already surfaces on its own.

### After 2026-10-01 — calibrate the market-reset pricing coefficient

Live pricing currently annualises monthly/quarterly market-reset contracts with **one global pass-through coefficient, `beta = 1.0`** (`RESET_FORWARD_SHIFT_ENABLED`, enabled in production since 2026-07-25). That value is measured only for the **monthly** cadence: `retail-premiums:calibrate` on 2026-07-25 gives a gated monthly figure of **0.81** (VAT incl.) / **0.94** (VAT excl.) from the two companies with at least 3 pass-through pairs, which is within the 0.25 review threshold of the configured 1.0. The **27 quarterly lineages use it as an unverified prior** — quarterly products reprice four times a year, and the FI futures curve only exists from 2026-04-08, so each quarterly lineage had just one usable period.

The **1 October 2026** resets give every quarterly lineage a second period, so roughly 24 lineages contribute a pass-through step at once. At that point:

1. Run `php artisan retail-premiums:calibrate` (it is also scheduled monthly and logs to `storage/logs/retail-premium-calibration.log`).
2. Compare the measured per-company/per-cadence coefficient against the global 1.0.
3. If quarterly pass-through differs materially, either retune `RESET_FORWARD_SHIFT_BETA` or implement per-company parameters as specified in `laravel/app/Services/CanonicalPricing/MarketReset/AGENTS.md`.

This moves real published prices for named companies, so treat a retune as a reviewed change and not a config tweak. Full measurements, and several explicitly retracted earlier conclusions, are in `tasks/market-reset-annualised-pricing/decisions.md`.

## Repository Structure

```
voltikka/
├── laravel/                 # Main Laravel 11 application
│   ├── app/
│   │   ├── Livewire/        # Livewire components
│   │   ├── Models/          # Eloquent models
│   │   ├── Services/        # Business logic and external API integrations
│   │   └── Console/Commands/ # Artisan commands
│   ├── resources/views/     # Blade templates
│   ├── database/migrations/ # Database migrations
│   └── data-investigation/  # API structure documentation
├── legacy/                  # Deprecated code (not in active use)
│   ├── python/              # Old Python services
│   └── voltikka/            # Old SvelteKit frontend
└── tasks/                   # Long-running agent task state; see tasks/AGENTS.md
```

## Build and Run Commands

### Laravel Application
```bash
cd laravel
composer install
npm install && npm run build   # Vite build for CSS/JS
cp .env.example .env
php artisan key:generate
php artisan migrate
php artisan storage:link       # Expose storage/app/public logos and other public files
php artisan serve              # Runs on http://127.0.0.1:8000
```

### Key Artisan Commands
```bash
# Contract data
php artisan contracts:fetch                # Fetch contracts from Azure API and auto-link high-confidence replacements
php artisan contracts:detect-replacements  # Report likely replacements for inactive contracts
php artisan contracts:link-replacements    # Persist high-confidence replacement links
php artisan contracts:republish-gated-pricing  # Re-run the relational price publication gate over already-published interpretations and refill the days they lost (dry run without --apply)
php artisan contracts:backfill-historical-interpretations  # Plan isolated historical interpretation episodes; read-only unless an exact plan hash is applied

# Spot prices
php artisan spot:fetch               # Fetch current spot prices from ENTSO-E; retries transient server/connection timeouts
php artisan spot:check-freshness     # Read-only check that FI data covers the current Helsinki hour
php artisan spot:fetch-forecast      # Fetch third-party spot-price forecasts from nordpool-predict-fi
php artisan spot:backfill            # Backfill historical spot prices; retries transient server/connection timeouts per chunk
php artisan spot:averages            # Calculate spot price averages

# Electricity futures
php artisan futures:fetch-eex        # Fetch EEX futures EOD settlement prices for configured Nordic month/quarter/year instruments
php artisan futures:backfill-eex     # Fetch all EEX futures history available from the public API (~45 days)

# Fixed-term price forecasts
php artisan forecasting:run-fixed-contracts       # Calculate and persist fixed-term contract price forecasts
php artisan forecasting:evaluate-fixed-contracts  # Compare matured stored forecasts with realized retail prices

# Retail premium dataset (private; spread over wholesale, never called margin or profit)
php artisan retail-premiums:collect               # Collect per-contract retail premium observations
php artisan retail-premiums:cross-check           # Read-only: compare fixed-term premiums with stored EWMA forecasts
php artisan retail-premiums:calibrate             # Read-only: measure market-reset pass-through (beta) per company and cadence

# Utilities
php artisan logos:optimize           # Optimize company logos to WebP
php artisan sitemap:generate         # Generate sitemap.xml

# Testing
php artisan test
php artisan test --filter="ContractsFilterTest"
```

## Key Features

### 1. Contract Comparison (Main Feature)
- **Location**: `app/Livewire/ContractsList.php`, `SahkosopimusIndex.php`
- **Route**: `/`, `/sahkosopimus`
- Filters by pricing model, contract type, energy source, housing type
- Listings fail closed to proven national availability until a valid exact Finnish postcode is selected; then they add only contracts linked to that postcode. The browser preference is reused without making shared cached HTML visitor-specific
- **Pricing-type filter** `?hintatyyppi=porssisahko,kiintea` (multi-select include semantics over the four `PricingBucket` cases: `porssisahko` / `paivittyva` / `kulutusvaikutus` / `kiintea`). It filters in SQL through the shared `PricingCategoryResolver::scopeBucket()`, so the bucket that listed a contract always agrees with its card band. Legacy `?pricingModelFilter=Spot|FixedPrice|Hybrid` links are mapped onto it once at mount; see `laravel/app/Livewire/AGENTS.md`
- It renders as a row of four toggle pills (`resources/views/partials/pricing-bucket-pills.blade.php`) that is **always visible above the contract list on every listing page**, outside the collapsed "Rajaa hakua" accordion, because ranking makes page 1 spot-heavy and a hidden filter did not help a visitor who wants price certainty. A selected pill carries its category's card tint. A collapsed availability disclosure follows the pills; its trigger states the current availability (koko Suomi or the selected postcode) while the postcode form stays hidden until opened. The accordion keeps duration and energy source; its old "Hinnoittelumalli" section was removed
- Calculates annual costs based on user consumption
- SEO-optimized filter links with dual behavior (see SEO section)
- Low-prominence market-insight pills on comparison heroes reuse cached precomputed price statistics/forecasts; they are informational only and do not affect ranking
- **Contract cards state one of three pricing categories** (`Kiinteä hinta` / `Markkinahinta` / `Kulutusvaikutus`) in a single-purpose tinted band across the top of the card, followed by itemised receipt rows, the €/kk price stub, and a footer of coral warning pills plus quiet fact tags. An estimated 12-month total carries one `Arvio` popover that explains the estimate and links to `/tietoa#menetelma`. All of it is derived server-side by `laravel/app/Services/ContractCard/ContractCardPresenter`, shared by the normal and featured cards; see `laravel/app/Services/ContractCard/AGENTS.md`
- Individual contract detail meta descriptions are generated from Voltikka ranking/pricing data instead of provider marketing descriptions

### 1a. First-party seller-click analytics
- **Location**: `laravel/app/Services/Analytics/`, `laravel/resources/js/attribution.js`, `laravel/resources/js/first-party-analytics.js`
- Both seller CTAs on ContractDetail send one signed `contract_order_click` event to the stateless `POST /api/analytics/events` endpoint without delaying or replacing the direct seller link. The independent Plausible event remains
- Attribution uses `voltikka_attribution_v1` in `localStorage`, a strict 30-minute inactivity rule, first touch, and no visitor or session ID. Only source, medium, campaign, and landing pathname reach durable storage
- Server-signed facts use the displayed annual total and selected consumption plus the live rank, live universe size, and separate rank-consumption basis. Missing price and rank facts stay null
- Typed `contract_order_clicks` rows have indefinite retention at the initial release. There is no cleanup job. Rows do not contain IP addresses, user agents, full referrers, query strings, full URLs, visitor IDs, or session IDs
- Filament 5 provides a private read-only `/admin` resource. Existing users need `is_admin=true`; valid credentials alone are insufficient, and there is no public registration or deployment-time user creation
- Filament requires PHP `ext-intl`; the production Docker image installs `libicu-dev` and compiles `intl`. Keep this extension when changing the base image
- See `laravel/app/Services/Analytics/AGENTS.md` and `laravel/app/Filament/AGENTS.md`

### 1b. Company pages (`/sahkosopimus/sahkoyhtiot/{slug}`)
- **Location**: `app/Livewire/CompanyDetail.php`, `app/Services/CompanyStatistics/`
- The page is household-first. Household facts and the main cards use active `Household`, `Both`, and legacy null targets. A separate section at the bottom shows active `Company` and `Both` contracts. Thus, a `Both` contract appears in both audience sections.
- The HTML title is `{company}: sähkön hinta verrattuna markkinaan | Voltikka`. The H1 stays `{company}: sähkön hinta ja sähkösopimukset`. The page also answers three related clusters with generated sections: **`{yhtiö} tarjoukset`**, **`{yhtiö}: sähkön hinta`**, and **`{yhtiö} pörssisähkö`**. All three headings always render. Their empty states do not invent offers, prices, market metrics, or Spot products. These sections use household contracts only.
- The page shows the later of the newest `last_observed_at` from active contracts' pointed source-observation episodes and the newest stored price date among active legacy contracts with no source-observation pointer as `Päivitetty`. The company contact address is labelled as contact data, not as a delivery area. Company pages have no delivery-area or FAQ section. Their annual-consumption selector uses the same compact preset rail and direct-input pattern as the main comparison page.
- The comparison reads `contract_price_daily_statistics` plus method-compatible seller rows, never a live price calculation, and uses the `annual_cost` metric because `energy_price` prices spot at that day's spot average and is not comparable with fixed contracts. Legacy seller totals come from `contract_price_snapshots`; AsOf totals come only from `contract_price_annual_costs`, with snapshots joined for company identity and the historical observed-rate guard. It prefers an internally consistent current canonical market+company date; when none exists, it can show only the latest internally consistent observed date as an explicitly historical fallback, never as today's canonical price. See `laravel/app/Services/CompanyStatistics/AGENTS.md`

### 2. Contract Price Statistics
- **Location**: `app/Livewire/ContractPriceStatistics.php`, `app/Services/ContractStatistics/ContractPriceStatisticsService.php`
- **Route**: `/sahkosopimus/tilastot`
- Tracks daily contract-price trends from imported contract prices
- Versioned annual-cost persistence uses separate `contract_price_annual_costs` rows so legacy and as-of methods can coexist without rewriting observed unit-price evidence. Historical `annual_cost_as_of_v1` rebuilds use the union of exact-date snapshot and component identities, strict interpretation chronology, exact-target as-of Spot, and supplier-episode calculation. A component-only identity yields three typed `missing_historical_snapshot_identity` exclusions under `unclassified`; snapshot-only canonical evidence stays eligible. Stored rolling-365 Spot evidence can have small source gaps only when its `period_end` equals the target, it keeps the exact 365-date identity, has finite values and positive hours no greater than the DST-aware expectation, and has at least 98% coverage; an older stored level is never carried forward. Raw hourly reconstruction still requires every exact UTC hour. Current canonical daily collection instead reuses the exact canonical outcomes already calculated for public ranking and snapshots, then batch-loads current-pointer provenance without querying `price_components`. Both write through one AsOf writer, which rejects empty or incomplete apply identity sets before deletion and counts only available contributors in aggregate basis fields. The shared compatibility identity is method version + calculation basis + estimate method + estimate basis, without pricing basis. Public annual trends treat null as one legacy regime, null mixed periods, insert a gap at the first point after each compatibility transition, and require the same key for deltas. The current write stays inside the required date transaction; feature-off and historical observed runs stay legacy-only. Every public annual reader filters by the configured active method, which remains `annual_cost_legacy_v1`; production activation is not implemented
- Historical backfill infers availability from `price_components.price_date`
- Spot contract totals use stored spot-price history plus supplier margin
- Forward/current snapshots use typed canonical outcomes for annual totals, unit rates, fees, Spot facts, packages, and measured offer state without reading `price_components`; historical backfill remains observed seller evidence. Both snapshot and aggregate rows carry `pricing_basis`. Current consumers select the latest date for the basis expected by the canonical flag, while older observed rows remain historical evidence. One basis owns each newly calculated date, including stale canonical exclusions. Canonical monthly/quarterly/seasonal/other resets share the `market_reset` (`Päivittyvä hinta`) segment through the card bucket rule; observed history keeps its original text-based keys, including `quarterly`, with no row rewrite or backward projection. The public page/CSV explain the distinction
- Contract-type energy-price table, deep-dive spot chart, and top spot callout display spot as trailing-12-month realized daily average + typical margin, with p20–p80 daily variation where applicable. These are historical unit-price views. Current canonical annual-cost ranking uses the separate forward-looking Spot estimate described below
- Commands: `contracts:calculate-price-statistics`, `contracts:backfill-price-statistics`, `contracts:warm-price-statistics-cache`
- Daily import calculates these statistics before optional percentile badge recalculation so this page keeps updating even if badge metrics fail
- Page requests serve cached prepared view data per period + consumption; cache keys include the configured active annual method and auto-bust when active statistics/snapshot/source Spot fingerprints change. Shadow annual rows do not enter the public fingerprint
- Contract and spot-price update commands queue background warming for the default `/sahkosopimus/tilastot?kulutus=5000` page state so low-traffic first visitors do not pay the expensive cache-miss rebuild

### Automated Contract Interpretation
- **Location**: `laravel/app/Services/ContractInterpretation/`, `laravel/app/Jobs/AnalyzeContractSourceSnapshot.php`
- Every distinct upstream contract payload is stored during the authoritative `contracts:fetch` database transaction as immutable evidence. Separate `contract_source_observations` episodes preserve A→B→A chronology, and `electricity_contracts.current_source_observation_id` is the only source-currentness rule
- `app/Services/ContractImport/` owns that transaction and the typed post-import workflow. Partial postcode acquisition imports available contracts with `complete=false`, preserves active rows absent from the partial response, and skips replacement linking; required statistics or cache-invalidation failures make the command fail
- Production import-time interpretation is enabled: each observed pointed episode from `contracts:fetch` visits the fingerprint-idempotent post-commit dispatcher in its own failure boundary. An unchanged episode extends; a payload transition creates a point episode. When an earlier payload recurs, stored published/superseded output is revalidated at the recurrent episode date. Still-valid output rematerializes without an LLM job; date-sensitive invalid output gets one date-scoped fallback analysis
- Valid latest interpretations automatically publish compatible classifications and current canonical pricing JSON to `electricity_contracts`; invalid or stale results do not publish
- New contracts stay inactive until first validation; changed prices for interpreted contracts wait for the new version before relational publication
- Versioned interpretation JSON is the validated pricing history
- Relational price imports resolve duplicate null-UUID component-key collisions before upsert, so zero consumption-effect placeholders cannot overwrite a real energy price
- The safe-publication gate trusts the structured API data as the baseline and blocks only on a **named reason** to doubt it: a detected deception, `conflicting` structured pricing, or an issue code not classified as harmless (unknown codes block). It never blocks merely because no 12-month total is derivable. Conflating the two closed the gate permanently on all 49 Hybrid contracts on 2026-07-24 — a Hybrid's consumption effect is never quantified by the seller — and blanked the `hybrid`/Joustosähkö line on `/sahkosopimus/tilastot`
- `relational_pricing_published` is decided once at publication and read by every later import, so relaxing that gate reaches already-published contracts only through `php artisan contracts:republish-gated-pricing`
- Commands: `php artisan contracts:interpret`, `php artisan contracts:republish-gated-pricing`
- Historical reconstruction uses dedicated episode and interpretation tables. `contracts:backfill-historical-interpretations` is read-only by default. It streams deterministic 25-contract discovery chunks into a compact exact-manifest/action plan, verifies the plan hash before any apply write, and repeats discovery in one transaction to persist full audit payloads. Semantic LLM reuse still ignores storage row IDs, while a separate manifest fingerprint binds every target date, snapshot ID, component composite ID, and normalized economic digest. Backcast prose can recover stable classification/mechanism facts, but versioned deterministic validation binds each numeric billed/package fact to one cited exact structured component by canonical type, source unit, amount role, and scoped discount timing. It also requires null recurring-period dates and consumption-effect numbers and forbids `detected` backcast deception. Historical model calls use a 300-second, one-HTTP-attempt policy. One initial call plus two repair calls fits the 1,000-second historical job timeout. The shared Supervisor worker timeout is 1,020 seconds, and database queue `retry_after` has a code-enforced minimum of 1,050 seconds. A lower environment value cannot release the job while it still runs. The versioned AsOf resolver can use one exact-current-fingerprint validated result only when immutable source chronology has no covering observation; older dedicated analyses are ignored and all other source-path failures stay closed. Eligible pre-cutoff episodes can align semantic methods across the rollout, but component-only dates and real evidence/method transitions remain unavailable or chart gaps. The 2026-07-22 cutoff is deliberate because later dates use immutable source chronology. There is no schedule, and the public method remains `annual_cost_legacy_v1`

### Canonical phase-aware pricing (deceptive-pricing fix)
- **Canonical pricing is the intended source of truth for every price Voltikka publishes.** The raw Azure API structured price is a seller-controlled input and is subject to manipulative presentation — a promo rate in the priced fields with the increase disclosed only in prose is the recurring case. The interpretation pipeline exists to detect exactly that, so where canonical pricing and the relational components disagree, canonical wins. A surface that silently falls back to relational rows, or drops a contract because it has none, is a bug: it re-exposes the manipulation the pipeline caught. Standard/featured cards and ContractDetail current receipt, title/meta price text, and Product JSON-LD are canonical-only when the flag is on; missing values are omitted, excluded outcomes have no unit offer, and historical charts/timelines remain relational observed evidence. The company-directory counts, averages, lowest prices, and price rankings accept only listed canonical metrics; canonical-only contracts work and excluded or missing outcomes cannot become zero. Company offer sections, the SEO offer listing/JSON-LD, and weekly-offers generated data/API/prompt use only canonical measured benefits and exact typed offer terms in canonical mode; controlled Finnish copy comes from component type, actual/normal amount, and resolved timing, never seller text or raw phase labels. Short fixed terms show the real-term benefit, while packages, excluded outcomes, unsafe integrity states, zero-benefit rows, and offers without a supported typed term are absent. Weekly offers rank by the measured 5,000 kWh customer benefit, then the canonical total, and batch all three consumption evaluations without loading relational rows. The public contract list/show API omits relational component rows in canonical mode and returns typed `current_pricing` plus the canonical `calculated_cost` when consumption is requested; feature-off responses retain the legacy component resources. Canonical batch pricing now stays in `ContractPricingViewData` / `CanonicalContractMetric` through card, company, ranking, weekly-offer, and API preparation, with arrays limited to Laravel cache, Eloquent presentation, and HTTP transport boundaries. All three bill-comparison surfaces also use one typed canonical period outcome with realized hourly Spot history, phase/mechanism timing, package allowances, and fail-closed unavailable reasons; feature-off keeps legacy component period pricing. The editorial contract-type comparison uses one memoized canonical annual outcome per candidate for its chart, displayed rates, winner, savings, package facts, and estimate disclosures; an unavailable side stops the comparison instead of becoming zero. Broader consumer migration remains in `tasks/canonical-pricing-source-of-truth-completion/`
- **Location**: `laravel/app/Services/CanonicalPricing/`
- `PricingMode` is one immutable request/command snapshot for canonical state, reset-shift state, expected statistics basis, and cache markers. `CalculatedCostPayloadSchema::VERSION` is the one calculated-cost cache schema; list, company, ranking, and prepared-page caches keep separate outer versions but all include this shared dependency
- Consumes the validated `canonical_pricing`/`canonical_source_consistency`/`canonical_calculation` JSON to calculate accurate 12-month prices across pricing phases, so a cheap promotional price that later increases (disclosed only in the description) no longer flatters a contract in rankings
- Monthly included-energy packages are phase-level canonical pricing: one monthly fee includes one monthly kWh allowance, unused allowance does not carry over, and only that calendar month's excess uses the c/kWh rate. Package terms are not promotions. Package phases contain no duplicate billed fee/rate components, and malformed or unsupported package data fails closed
- Active structured `UntilDate` and first-N-month discounts must survive interpretation as exact scoped discounted phases plus their known normal-price continuation. Deterministic validation rejects missing, wrong-scope, wrong-amount, or unsafe discount timelines; expired and `has_discount=false` metadata do not create phases. Spot margin discounts map to `spot_margin` from every source tariff slot
- Assigns a deterministic comparability verdict deciding list inclusion and sort key: open-ended promos with an undisclosed later price and broken/ambiguous pricing are hidden from listings (still reachable on the detail page with a warning); short fixed terms are annualized and labelled; Hybrids rank base-only with a disclosure
- Adds a tiered deterministic deceptive-pricing label: a soft card pill ("Hinta nousee 1.8.2026") and a detailed detail-page notice with both prices, the change date, and the first-year € impact. Only `misleading_first_12_months = detected` contracts get a label; UI copy is generated from typed fields, never the raw LLM summary
- **Gated behind `CANONICAL_PRICING_ENABLED` (default off)**; when off, the legacy `ContractPriceCalculator` behavior is unchanged. Staged with `php artisan contracts:compare-canonical-pricing` (diffs legacy vs canonical totals and lists exclusions/labels)
- **Market-reset annualised pricing** (`laravel/app/Services/CanonicalPricing/MarketReset/`): market-reset products (monthly/quarterly/seasonal/other repricing, e.g. Kokkolan Tyyni, Helen Markkinahintasähkö, kvartaalisähkö, Lumme Perussähkö) used to be annualised by holding the **current period's seasonal price** flat for twelve months, which understated them badly in summer and overstated them in winter across roughly 32 lineages. They are now annualised with an FI forward-curve shift, `P_m = P_current + beta * (F_m - F_reference)`: the current period stays exact and only the tail is repriced. Cadence `other` stays an estimate and uses the quarterly calendar and reference proxy because the seller does not publish exact reset boundaries. **Two vintages on purpose** — `F_m` reads today's curve because the coming year's level is what the customer pays, while `F_reference` reads the **pricing** vintage (latest `trade_date` before the current period started) because that is the forward the seller priced from; reading it at today's vintage inflates the implied spread by pure front-month convergence (measured 1.58 c/kWh, about +79 €/yr at 5000 kWh). Ladder: forward curve → multi-year spot seasonal index (lower confidence) → hold flat. **Gated behind its own `RESET_FORWARD_SHIFT_ENABLED` (default off)** because `CANONICAL_PRICING_ENABLED` is already true in production; the flag varies the list/ranking/page cache keys. Staged with `php artisan contracts:compare-canonical-pricing --resets`. Cards and detail pages show the known current-period price and the estimated 12-month equivalent as two separate figures; there is deliberately **no** deceptive-pricing label, because a published reset mechanism is not hidden promotional text
- **Supplier-adjusted open-ended annual pricing** (`laravel/app/Services/CanonicalPricing/SupplierAdjusted/`): a separate path annualises exact ordinary `OpenEnded` + `FixedPrice` contracts with `General`, `Time`, or `Season` metering, one current phase, and no recurring reset, promotion, package, Spot margin, Hybrid effect, or future mechanism. The current calendar-month remainder stays at the published rate. Later months use an FI forward shift anchored to the start of the current observed seller-price episode, then fall back to a Spot seasonal index and hold-current. Time and Season tariffs apply the same additive monthly shift to each exact current rate and use the statistics snapshot weights only for episode matching and the displayed 12-month equivalent. All rungs remain typed estimates in `supplier_adjusted_estimate`; exact bill-period pricing stays factual. Multiple monthly-fee variants use the calculator's existing conservative maximum rule; this covers both Sulaketariffi's five identical €4.20 components and fuse-size fee variants without changing the exact current fee used for ranking. Cards and ContractDetail keep the Kiinteä hinta category but separate the published current rates from the estimated 12-month equivalent, show the shared Arvio popover for every fallback rung, and never claim a reset cadence
- **Forward-looking Spot annual pricing** (`laravel/app/Services/CanonicalPricing/SpotForward/`): canonical household and `Both` Spot rankings use the VAT-inclusive FI Base forward curve for every month touched by the coming 12-month window. The in-delivery month uses its last pre-delivery curve; later months use the latest curve before the comparison date. The rolling-365 overall/day/night evidence supplies only an additive intraday load-shape adjustment. The exact contract margin, fees, phases, and measured discounts are then applied. Missing, stale, or incomplete curve evidence falls back as one typed `rolling_365_fallback`; it never mixes forward and historical months. `spot_estimate` records both curve vintages, monthly prices, shape evidence, confidence, and fallback flags. Exact-period bill comparison still uses realized hourly Spot prices and never the annual projection
- **TO BE IMPLEMENTED IN THE FUTURE**: per-company calibration of that estimate (the reference period each seller prices from, and the pass-through coefficient). Blocked on data that cannot be bought back — the FI futures curve only exists from 2026-04-08 and EEX serves an approximately 45-day rolling window, so earlier vintages are permanently gone. Becomes possible for quarterly products after the **1 October 2026** resets. The first rollout therefore uses one global coefficient (`beta = 1.0`)
- See `laravel/app/Services/CanonicalPricing/AGENTS.md` and `laravel/app/Services/CanonicalPricing/MarketReset/AGENTS.md`

### Retail premium dataset (private, no public UI)
- **Location**: `laravel/app/Services/RetailPremium/`, `retail_premium_observations`
- Records per contract-lineage price period how far a retail price sits above the wholesale price the seller could have hedged at, against every candidate futures reference at the vintage the price was set. Call it **retail premium** or **spread over wholesale**, never margin or profit — it also pays for hedging, load shape, imbalance, credit risk, acquisition, billing, and service
- Immediate purpose is calibrating the market-reset estimate above; longer term it supports pass-through asymmetry analysis and seller value profiling
- Rows are immutable by default and `method_version` is part of row identity, so a method change inserts new rows beside the old ones. **Any analysis must filter to the current `method_version` pair**
- Command `retail-premiums:collect` (daily at 07:15 Europe/Helsinki); the scheduled form uses `--require-freshness` and defers unless the same-day full contract and EEX checkpoints, exactly one pointed source-observation episode and current publication for every active contract, current-run prior-date FI Base proof, and recent FI Base database data are ready. Historical/manual collection remains opt-in and compatible. Read-only diagnostic: `retail-premiums:cross-check`
- See `laravel/app/Services/RetailPremium/AGENTS.md`

### 3. Fixed-term Price Forecasting
- **Location**: `app/Services/PriceForecasting/`, `app/Models/FixedContractPriceForecast.php`, `app/Livewire/FixedContractPriceForecast.php`
- **Route**: `/sahkosopimus/sahkon-hintaennuste`
- **Commands**: `forecasting:run-fixed-contracts`, `forecasting:evaluate-fixed-contracts`
- **Schedule**: daily forecast run at 07:30 and evaluation at 07:45 Europe/Helsinki. The scheduled generation command uses `--require-freshness`; it defers on missing same-day full import checkpoints, incomplete active pointed-episode/publication coverage, no current fixed-term 6/12/24 statistic in the expected pricing basis, missing current-run prior-date FI proof, or stale FI Base database data. If the only failure is that statistics started before a required publication, the non-dry command overwrites that date's statistics from all current active contracts and runs the full gate again against the new calculation start before forecasting
- Model v2 forecasts fixed-term 6/12/24 month market p20/median/p80 energy-price indices
- In canonical mode, the current retail input must be a `canonical_calculation` statistic; observed seller statistics remain separate historical EWMA evidence and matured actuals
- Uses FI EEX futures-implied hedge costs plus EWMA retail premium / gap closure
- Persists current and historical input provenance in `source_metadata`; old model or missing/wrong-basis rows are not shown as current forecasts
- The public "Mediaanihinta viime kuukausina" section is not forecast-run history. It reads the complete fixed-term `energy_price` median timeline from `contract_price_daily_statistics`: older `observed_seller_data` evidence followed by canonical daily calculations after that rollout. Current forecast rows still require the configured model and current-input basis
- Persists forecasts and later fills actual prices/errors so forecast quality can be tracked over time
- See `laravel/app/Services/PriceForecasting/AGENTS.md` before changing model semantics

### 4. Spot Price Display
- **Location**: `app/Livewire/SpotPrice.php`, `HeaderSpotPrice.php`
- **Route**: `/spot-price`
- **Data source**: ENTSO-E API via `EntsoeService` for official actual prices; optional third-party forecast feed from `vividfog/nordpool-predict-fi` for hours after official prices end
- **Import safety**: ENTSO-E requests default to a 5-second connection timeout and 30-second total timeout. The hourly Europe/Helsinki import runs on one server and expires its overlap mutex after 60 minutes. An independent read-only freshness check runs at minute 10 without an overlap mutex. It writes a Laravel error log when the latest official FI row does not cover the current Helsinki hour.
- Features:
  - Hourly and 15-minute price granularity
  - Third-party forecast section clearly separated from official prices with source citation
  - Real-time current price in the header, loaded by one shared retrying/60-second refresh loop; zero and negative values are valid prices
  - Household appliance cost calculators (sauna, laundry, dishwasher, water heater)
  - Historical comparisons (daily, weekly, monthly, year-over-year)
  - Price charts with signed zero baselines so negative hourly and 15-minute prices extend in the opposite direction from positive prices
  - CSV export

### Daily spot social publication
- `spot:fetch` has no social side effects. It only persists Spot data, calculates averages, and warms caches.
- `social:publish-daily-spot` runs independently each hour at minute 15 and waits for complete hourly data for the Helsinki content date and next date.
- External posting defaults off through `SPOT_SOCIAL_PUBLISHING_ENABLED=false`. A unique Helsinki `content_date` ledger prevents repeated normal publication.
- Failed or active processing attempts never retry automatically. An operator can use `--retry --date=YYYY-MM-DD` only after inspecting PostFast; provider timeouts can be uncertain because external posts can exist even when Voltikka records failure.
- See `laravel/app/Services/SpotSocial/AGENTS.md` for claim, retry, and partial-success rules.

### 5. Solar Panel Calculator
- **Location**: `app/Livewire/SolarCalculator.php`
- **Route**: `/aurinkopaneelit/laskuri`
- **Services**:
  - `DigitransitGeocodingService` - Finnish address geocoding
  - `PvgisService` - EU PVGIS API for solar production estimates
  - `SolarCalculatorService` - Orchestration layer
- Features:
  - Address autocomplete with geocoding
  - System size and shading configuration
  - Monthly production estimates
  - Savings calculation based on self-consumption

### 6. Electricity Consumption Calculator
- **Location**: `app/Livewire/ConsumptionCalculator.php`
- **Route**: `/sahkosopimus/laskuri`
- Estimates annual consumption based on housing type and heating

### 7. Bill Comparison ("Maksatko liikaa?")
- **Location**: `app/Livewire/BillComparison.php`, `app/Services/BillComparison/BillComparisonService.php`
- **Route**: `/maksatko-liikaa`
- Visitor enters one electricity bill's date range, consumption (kWh) and total paid (energy-contract portion, excl. siirto); the tool computes what each active market contract would have cost for the same period+consumption and ranks the user's bill alongside them
- The bill total is the anchor — the user's pricing model / day-night split / margin are never modelled. Optional energy-price/base-fee inputs are explanatory only
- Leads with monthly + annualized savings (seasonal-adjusted via a heating toggle); spot contracts use actual historical hourly spot prices for the period
- No new models / DB writes; pure compute, ephemeral. See `laravel/app/Services/BillComparison/AGENTS.md`
- The same comparison is also available **in-listing** on the contract comparison pages (`/sahkosopimus` and all household SEO listing pages + cheapest): an optional, collapsed bill entry reranks the listed contracts by their exact billing-period cost and shows EUR savings vs the user's current contract directly on the cards, without leaving the listing. Period basis only (facts); gated behind `ContractsList::$showBillComparison` (on by default for `SeoContractsList`/`SahkosopimusIndex`, off on the homepage and on business pages). Listing cards deep-link the visitor's consumption to the contract detail page via `?kulutus=` (clean self-canonical keeps it non-indexable). Comparison pages use a compact layout (slim hero, consumption chip row, collapsed bill/calculator disclosures) so contracts sit high on the page. See `laravel/app/Livewire/AGENTS.md`
- A third surface is the **contract detail page** module "Vertaa nykyiseen sähkölaskuusi" (`ContractDetail`, collapsed, after Hintatiedot): the same bill priced against that one contract through `BillComparisonService::periodRowsForContracts()`, with the save/pay-more delta and honest unavailability states. Period basis only, per-user compute, never cached. The two period-basis surfaces share their inputs (`app/Livewire/Concerns/BillComparisonInputs.php`) and their form (`resources/views/partials/bill-comparison-form.blade.php`) so they cannot drift

## Laravel Architecture

### Key Models (`app/Models/`)

| Model | Description |
|-------|-------------|
| `ElectricityContract` | Contract with pricing_model, target_group, metering |
| `Company` | Electricity provider (primary key: name) |
| `PriceComponent` | Pricing components (Monthly, General, DayTime, NightTime, SeasonalWinter, SeasonalOther) |
| `ElectricitySource` | Energy mix (renewable, fossil, nuclear percentages) |
| `SpotPriceHour` | Hourly spot prices |
| `SpotPriceQuarter` | 15-minute spot prices |
| `SpotPriceAverage` | Calculated averages (daily, monthly, yearly, rolling) |
| `SpotPriceForecast` | Third-party hourly spot-price forecasts, stored separately from official actual prices |
| `FixedContractPriceForecast` | Stored fixed-term price forecasts plus realized actual/error metrics |
| `ContractOrderClick` | Durable typed seller-CTA event with signed price/rank facts and indefinite retention |
| `Postcode` | Finnish postcodes with municipality data |

### Key Livewire Components (`app/Livewire/`)

| Component | Description |
|-----------|-------------|
| `ContractsList` | Main contracts listing with filters |
| `SahkosopimusIndex` | SEO landing page for /sahkosopimus |
| `ContractDetail` | Single contract view |
| `CompanyDetail` | Company profile with their contracts, offers, market comparison and FAQ |
| `CompanyList` | List of all electricity companies |
| `SpotPrice` | Spot price page with analytics |
| `HeaderSpotPrice` | Compact spot price in header |
| `SolarCalculator` | Solar panel production calculator |
| `SeoContractsList` | SEO-optimized filtered contract lists |
| `CheapestContracts` | Cheapest contracts ranking |
| `LocationsList` | Browse by municipality |

### Key Services (`app/Services/`)

| Service | Description |
|---------|-------------|
| `ContractPriceCalculator` | Calculates annual contract costs |
| `ContractCard/ContractCardPresenter` | One server-side view model for both contract-card templates: pricing category, type-band copy, receipt rows, estimate explanation, footer warnings/facts |
| `EntsoeService` | Fetches official spot prices from ENTSO-E API |
| `SpotForecasts/NordpoolPredictFiService` | Fetches the public nordpool-predict-fi forecast feed for display as an attributed third-party forecast |
| `SpotPriceAverageService` | Calculates spot price statistics |
| `PvgisService` | Fetches solar production data from EU PVGIS |
| `DigitransitGeocodingService` | Finnish address geocoding |
| `SolarCalculatorService` | Solar calculator orchestration |
| `CompanyLogoService` | Handles company logo URLs with WebP optimization |
| `AzureConsumerApiClient` | Fetches contracts from Azure API |
| `PriceForecasting/FixedTermPriceForecastService` | Builds fixed-term price forecasts from retail stats and futures hedge costs |

### Important Fields

#### ElectricityContract
| Field | Values | Description |
|-------|--------|-------------|
| `pricing_model` | Spot, FixedPrice, Hybrid | **Use this for filtering spot contracts** |
| `target_group` | Household, Company, Both | Consumer vs business |
| `contract_type` | OpenEnded, FixedTerm | Contract duration type |
| `metering` | General, Time, Season | Tariff structure |
| `fixed_time_range` | Below6, Fixed6, Between711, Fixed12, Between1323, Fixed24, Over24, Other | Fixed-term duration bucket |
| `replaced_by_contract_id` | contract id or null | Forward link to detected replacement contract |

#### PriceComponent Types
`price_component_type` is written verbatim from the upstream API payload, so this
list is descriptive, not a closed enum. Code that maps types must fall back for
unknown values instead of dropping the component.

| Type | Description |
|------|-------------|
| Monthly | Fixed monthly fee (€/month) |
| General | Single rate (c/kWh); on a `Spot` contract this is the **margin**, not the energy price |
| DayTime | Day rate 07-22 (c/kWh) |
| NightTime | Night rate 22-07 (c/kWh) |
| SeasonalWinterDay | Winter rate Nov-Mar (c/kWh). Upstream has also used `SeasonalWinter`; handle both |
| SeasonalOther | Other seasons (c/kWh) |
| Spot | Spot margin as its own component (c/kWh); rare, currently one inactive Hybrid contract |

## Routes

### Main Routes
| Route | Component | Description |
|-------|-----------|-------------|
| `/` | ContractsList | Homepage with contracts |
| `/sahkosopimus` | SahkosopimusIndex | Main comparison landing page |
| `/sahkosopimus/sopimus/{id}` | ContractDetail | Contract detail |
| `/sahkosopimus/sahkoyhtiot` | CompanyList | All companies |
| `/sahkosopimus/sahkoyhtiot/{slug}` | CompanyDetail | Company profile |
| `/sahkosopimus/laskuri` | ConsumptionCalculator | Consumption calculator |
| `/maksatko-liikaa` | BillComparison | "Am I paying too much?" bill comparison |
| `/sahkosopimus/halvin-sahkosopimus` | CheapestContracts | Cheapest contracts |
| `/sahkosopimus/tilastot` | ContractPriceStatistics | Contract price trend statistics |
| `/sahkosopimus/yritykselle` | SeoContractsList | Business contracts |
| `/spot-price` | SpotPrice | Spot price analytics |
| `/aurinkopaneelit/laskuri` | SolarCalculator | Solar calculator |

### SEO Routes
| Route Pattern | Type |
|---------------|------|
| `/sahkosopimus/paikkakunnat/{location}` | City-specific (e.g., /sahkosopimus/paikkakunnat/helsinki) |
| `/sahkosopimus/omakotitalo`, `/kerrostalo`, `/rivitalo` | Housing type |
| `/sahkosopimus/porssisahko`, `/kiintea-hinta`, `/kvartaalisahko`, `/aikasahko`, `/kausisahko`, `/joustosahko`, `/yleissahko`, `/kulutusvaikutus` | Pricing type |
| `/sahkosopimus/tuulisahko`, `/aurinkosahko`, `/vihrea-sahko` | Energy source |
| `/sahkosopimus/sahkotarjous` | Promotions/offers |
| `/sahkosopimus/yritykselle` | Business contracts |

All SEO listing pages use `SeoContractsList` component. Location pages must match a real `municipalities.slug`; unknown `/sahkosopimus/paikkakunnat/{location}` slugs return 404 instead of rendering fabricated city pages. See "Creating SEO Contract Listing Pages" section for how to add new ones.

## External APIs

### Azure Consumer API (Contract Data)
- Endpoint: `https://ev-shv-prod-app-wa-consumerapi1.azurewebsites.net/api/productlist/{postcode}`
- Returns full contract details
- Structure documented in `laravel/data-investigation/contract-structure.md`

### ENTSO-E API (Spot Prices)
- Fetches Finnish day-ahead electricity prices
- Service: `EntsoeService`
- Config: `services.entsoe.api_key`, `services.entsoe.finland_eic`
- Supports both hourly and 15-minute resolution

### EEX API (Electricity Futures)
- Fetches electricity futures end-of-day settlement prices from `https://api.eex-group.com/pub/market-data/chart/eod`
- Service: `App\Services\ElectricityFutures\EexFuturesService`
- Command: `php artisan futures:fetch-eex`
- Config: `config/eex_futures.php`
- Requires `Referer: https://www.eex.com/`; public chart history is limited to roughly 45 days
- Maturity/delivery selection uses EEX `YYYYMM` maturity parameters, matching the web UI delivery dropdown values; the collector dynamically probes `price-ticker` once per tenor because out-of-bounds maturities return empty HTTP 200 payloads, then reuses the discovered maturity values across markets
- EEX requests are throttled by default with roughly 15 seconds between public API calls (`EEX_FUTURES_REQUEST_DELAY_SECONDS`, jitter configurable)
- Default instruments are EEX Nordic System Price and Nordic zonal Base Month, Quarter, and Year futures; Baltic instruments must be added only after verified EEX short codes exist

### EU PVGIS API (Solar Production)
- Endpoint: `https://re.jrc.ec.europa.eu/api/v5_2/PVcalc`
- Service: `PvgisService`
- Returns monthly and annual solar production estimates

### Digitransit Geocoding API (Address Search)
- Finnish address geocoding
- Service: `DigitransitGeocodingService`
- Used by solar calculator for address lookup

## Domain Knowledge

- **VAT rate**: 25.5% (changed from 24% in September 2024)
- **Night hours**: 22:00-07:00 Finnish time (8 hours = 33% of day)
- **Winter months**: January, February, March, November, December
- **Spot contracts**: Identified by `pricing_model = 'Spot'`, NOT by name matching
- **Company logos**: Stored in Google Cloud Storage, optimized to WebP format

## Contract Replacement Handling

Inactive contracts are kept in the database for history and SEO continuity. They are not deleted.

### Current behavior
- Active contract detail pages render normally.
- Inactive contracts with a high-confidence replacement chain redirect with **301** to the latest active replacement.
- Inactive contracts without a trusted replacement still render their normal contract detail page for historical reference, but with a `noindex` robots meta tag. Their history timeline begins with an explicit “no longer on sale” status node and labels the latest `price_components.price_date` only as the last date Voltikka observed the contract on sale, not as an exact expiry date.
- Inactive contract detail pages without a trusted replacement are excluded from the sitemap.

### Replacement matching rules
The matcher is intentionally conservative and only auto-links when confidence is high.

Hard requirements:
- same provider
- same `contract_type`
- same `metering`
- same `pricing_model`
- same `target_group`
- if fixed-term, same `fixed_time_range`

Name matching then scores candidates by:
- normalized base-name similarity
- identity token overlap
- profile/variant token overlap
- full-string similarity
- tolerance for promotion text changes like `0€ perusmaksu`, `ensimmäiset 3 kk`, etc.

### Main implementation files
- `app/Services/ContractReplacementMatcher.php`
- `app/Services/ContractReplacementLinker.php`
- `app/Console/Commands/DetectReplacementContracts.php`
- `app/Console/Commands/LinkReplacementContracts.php`
- `app/Livewire/ContractDetail.php`
- `app/Models/ElectricityContract.php`

See `laravel/AGENTS.md` for detailed implementation and chain-querying guidance.

## Code Patterns

### Filtering Contracts by Type
```php
// Spot contracts (pörssisähkö)
ElectricityContract::where('pricing_model', 'Spot')->get();

// Fixed price contracts
ElectricityContract::where('pricing_model', 'FixedPrice')->get();

// Household contracts only
ElectricityContract::where('target_group', 'Household')->get();
```

### Filtering by Energy Source
```php
// 100% renewable
ElectricityContract::whereHas('electricitySource', fn($q) =>
    $q->where('renewable_total', 100)
)->get();

// Fossil-free
ElectricityContract::whereHas('electricitySource', fn($q) =>
    $q->where('fossil_total', 0)
)->get();

// Has wind power
ElectricityContract::whereHas('electricitySource', fn($q) =>
    $q->where('renewable_wind', '>', 0)
)->get();
```

### Price Calculation 
```php
use App\Services\ContractPriceCalculator;
use App\Services\DTO\EnergyUsage;

$calculator = app(ContractPriceCalculator::class);
$usage = new EnergyUsage(total: 5000, basicLiving: 5000);
$result = $calculator->calculate($priceComponents, $contractData, $usage);
```

### Discount-aware pricing behavior
- `ContractPriceCalculator` now supports structured price-component discounts for first-year estimates
- calculation inputs should include latest price components with discount metadata, not only raw `price`
- contract list/cache code must avoid eager-loading full `priceComponents` history for all active contracts; use `ElectricityContract::getLatestPriceComponentsForCalculation()` so list metric rebuilds stay under memory limits
- discounted totals are component-scoped:
  - monthly fee promos apply only to `Monthly`
  - energy promos apply only to the matching energy component type
- result payloads can include both:
  - discounted totals (`total_cost`, `monthly_costs`)
  - base totals before promotions (`base_total_cost`, `base_monthly_costs`)
  - promo savings (`discount_savings_total`, `monthly_discount_savings`)
- use `ElectricityContract::getLatestPriceComponentsForCalculation()` when preparing calculator input so promo metadata is preserved

### Spot Price Access
```php
use App\Models\SpotPriceHour;
use App\Models\SpotPriceAverage;

// Current Finnish prices
$prices = SpotPriceHour::forRegion('FI')
    ->whereBetween('utc_datetime', [$start, $end])
    ->get();

// Rolling averages
$avg365 = SpotPriceAverage::latestRolling365Days('FI');
$avg30 = SpotPriceAverage::latestRolling30Days('FI');
```

## SEO Architecture

### Schema.org Structured Data
Pages include JSON-LD structured data for rich search results:
- `ContractsList` - ItemList of electricity contracts
- `ContractDetail` - Product schema for individual contracts
- `CompanyDetail` - Organization schema for companies
- `SolarCalculator` - WebApplication schema

Implementation: Each Livewire component has a `getJsonLdSchema()` method that returns the schema data, rendered via `<x-schema-markup>` component.

### Filter Links (Dual Behavior)
The visible pricing-type pills (`resources/views/partials/pricing-bucket-pills.blade.php`) have dual behavior for SEO optimization:

1. **When NO filters are selected**: the three buckets that own a canonical SEO page render as `<a href="...">` links to that page (`/sahkosopimus/porssisahko`, `/sahkosopimus/kiintea-hinta`, `/sahkosopimus/kulutusvaikutus`), so search engines crawl the real landing page instead of a query-string variant. `wire:click.prevent` keeps a human click filtering in place.

2. **When ANY filter IS selected**: every pill becomes a Livewire toggle button (`wire:click`). This prevents infinite URL combinations.

The `ContractsList::$showSeoFilterLinks` property controls this behavior — enabled only on `/sahkosopimus` (`SahkosopimusIndex`), not on the SEO listing pages or the cheapest page, where a pill link would drop the context that page ranks for. The `paivittyva` bucket owns no canonical page and stays a toggle in every state. See `laravel/app/Livewire/AGENTS.md` ("Pricing-type filter").

### Pagination SEO
- URLs use query string `?page=N` for unique, crawlable URLs
- Page titles include "– Sivu N" suffix for pages > 1
- `rel="canonical"`, `rel="prev"`, and `rel="next"` link tags are added
- Changing filters or consumption resets pagination to page 1

### Sitemap
- Sitemap URLs are generated by `app/Services/SitemapService.php` and served at `/sitemap.xml` through a cached route.
- Main indexable pages include `/sahkosopimus/tilastot`; keep `tests/Feature/SitemapTest.php` updated when adding canonical SEO pages.
- The route cache key lives in `SitemapService::CACHE_KEY`; bump or clear it when stale production sitemap content must be forced to refresh.

### Creating SEO Contract Listing Pages (Step-by-Step)

All SEO contract listing pages use the **`SeoContractsList`** Livewire component (`app/Livewire/SeoContractsList.php`) and the shared Blade template (`resources/views/livewire/seo-contracts-list.blade.php`). New pages are added by configuring the existing component, NOT by creating new components.

#### Page Categories

There are several types of SEO listing pages, each differentiated by a route parameter:

| Category | Route Parameter | Example Slug | Example URL |
|----------|----------------|-------------|-------------|
| Pricing type | `pricingType` | `porssisahko` | `/sahkosopimus/porssisahko` |
| Housing type | `housingType` | `omakotitalo` | `/sahkosopimus/omakotitalo` |
| Energy source | `energySource` | `tuulisahko` | `/sahkosopimus/tuulisahko` |
| City/Location | `location` | `helsinki` | `/sahkosopimus/paikkakunnat/helsinki` |
| Target group | `targetGroup` | `Company` | `/sahkosopimus/yritykselle` |
| Offer type | `offerType` | `promotion` | `/sahkosopimus/sahkotarjous` |

#### Checklist: Adding a New SEO Pricing Type Page

When adding a new pricing type page (e.g., `/sahkosopimus/yleissahko`), update these files in order:

**1. Route — `routes/web.php`**
Add a new route in the "SEO Pricing Type Routes" section (BEFORE the city catch-all route at the bottom):
```php
Route::get('/sahkosopimus/yleissahko', SeoContractsList::class)
    ->name('seo.pricing.yleissahko')
    ->defaults('pricingType', 'PricingTypeKey');
```
The `pricingType` default value must match a key used in the component's filtering logic.

**2. Component — `app/Livewire/SeoContractsList.php`**
Update these arrays/methods in the component:

- **`$pricingTypeNames`** — Add the Finnish display name:
  ```php
  'PricingTypeKey' => 'Display Name',
  ```
- **`generateSeoTitle()`** — Add a `match` case for the SEO page title (used in `<title>` tag)
- **`generateMetaDescription()`** — Add a meta description (used in `<meta name="description">`)
- **`generateCanonicalUrl()`** — Add the slug mapping in `$slugMap`:
  ```php
  'PricingTypeKey' => 'url-slug',
  ```
- **`getPageHeadingProperty()`** — The H1 heading is auto-generated from `$pricingTypeNames` as `"{Name}sopimukset"`. Override in the method if a custom heading is needed.
- **`getPricingTypeIntroText()`** — Add a descriptive intro paragraph (2-3 sentences explaining the contract type, shown below the H1)
- **`getContractsProperty()`** — If the new pricing type needs custom filtering logic (e.g., matching by name/description patterns instead of `pricing_model` field), add an `elseif` block in the pricing filter section. Standard `pricing_model` values (Spot, FixedPrice, Hybrid) are handled automatically.

**3. Sitemap — `app/Services/SitemapService.php`**
Add the URL slug to the `$pricingTypes` array:
```php
protected array $pricingTypes = [
    'porssisahko',
    'kiintea-hinta',
    // ...
    'yleissahko',  // Add here
];
```

**4. Internal Links — `resources/views/livewire/seo-contracts-list.blade.php`**
Add the new page to the "Katso myös" (See also) section's "Hinnoittelumalli" list:
```html
<li>
    <a href="/sahkosopimus/yleissahko" class="hover:text-coral-600">Display Name</a>
</li>
```

**5. Navigation (optional) — `resources/views/layouts/app.blade.php`**
If the page should appear in the site navigation or footer, add links in:
- Desktop dropdown menu (search for "kvartaalisahko" to find the section)
- Mobile collapsible menu
- Footer links section

#### SEO Content Elements per Page

Each SEO listing page automatically includes:

| Element | Source Method | Description |
|---------|-------------|-------------|
| `<title>` tag | `generateSeoTitle()` | Format: "Vertaa {type}sopimuksia (N sopimusta) \| Voltikka" |
| Meta description | `generateMetaDescription()` | 150-160 char description for search results |
| Canonical URL | `generateCanonicalUrl()` | Self-referencing canonical with slug |
| H1 heading | `getPageHeadingProperty()` | Usually "{Type}sopimukset" |
| Intro text | `getSeoIntroTextProperty()` | 2-3 sentence description below H1 |
| JSON-LD | `generateJsonLd()` | ItemList schema with Service items |
| Breadcrumbs | Blade template | Etusivu > Sähkösopimukset > {Page heading} |
| Internal links | Blade template | "Katso myös" section with cross-links |

#### Contract Filtering Logic

For pricing type pages, the `getContractsProperty()` method determines which contracts to show:

- **`Spot` / `Hybrid`**: filter by `pricing_model` column directly
- **`FixedPrice` (fully-fixed / `kiintea-hinta`) and `GeneralElectricity` (`yleissahko`)**: `pricing_model = FixedPrice` is **NOT** sufficient. Kvartaalisähkö and monthly market-price (`markkinahintasähkö`) products are `FixedPrice` in the source enum but reset from the market (canonical `periodic_market_reset` / `recurring_schedule.present`) and are costed as estimates. A genuinely fully-fixed contract — energy price known and unchanging for the whole first year, **no consumption effect** — is marked `canonical_calculation.status = 'exact'` (spot and resets are always `estimate_required`, hybrids `unsupported`). So these pages filter `pricing_model = FixedPrice` **AND** `canonical_calculation->status = 'exact'`. Do not revert to a plain `pricing_model` filter — it puts quarterly/monthly market resets on the "fully fixed, full certainty" page. The page copy promises the energy price never changes and there is no consumption effect, so the filter must match that promise.
- **`ConsumptionEffect` (kulutusvaikutus / `kulutusvaikutus`)**: contracts with a fixed base energy price plus a mandatory consumption-profile adjustment (the "mini-spot" ± effect). Filter by the **mechanism**, not the enum: `canonical_pricing->consumption_effect->present = true` AND `applies_to = 'base_contract'` (these are the Hybrids; `applies_to = 'optional_fixing'` Spot contracts are excluded because their effect only applies if the customer fixes the price). The effect's numeric ± bounds are often null because sources rarely disclose them — that is expected, so the page ranks by base price + monthly fee and explains the effect rather than quantifying it.
- **Special types** (`Quarterly`, `TimeOfUse`, `Seasonal`): shared query rules live in `laravel/app/Services/ContractListing/ContractListingPipeline.php`. Quarterly phrase rules exist only there and are also reused by statistics classification. Do not add component-local phrase lists
- New types should use whichever approach matches the data: direct field filtering is preferred when possible

## Performance Optimizations

- **Vite build**: CSS/JS bundled and minified (`npm run build`)
- **WebP logos**: Company logos optimized to WebP format via `logos:optimize` command
- **Async fonts**: Google Fonts loaded asynchronously to avoid render-blocking
- **Resource preloading**: Critical CSS/JS preloaded in `<head>`

## Analytics and Observability

- **First-party seller-click analytics**: Signed, typed, rate-limited `contract_order_click` events with data-minimal browser attribution; see `laravel/app/Services/Analytics/AGENTS.md`
- **Plausible Analytics**: Privacy-friendly analytics script in `layouts/app.blade.php`; it stays independent from first-party event delivery
- **Sentry**: Laravel exception capture, optional Sentry log forwarding, and tracing/profiling configuration are configured in `laravel/bootstrap/app.php`, `laravel/config/sentry.php`, and `laravel/config/logging.php`. Performance spans/profiles are disabled by default to preserve span quota; see `laravel/AGENTS.md` for env variables and verification commands.
- **Scheduled workflow failures**: Central Laravel scheduler lifecycle listeners log non-zero exits, thrown exceptions, and `withoutOverlapping()` skips through `Log::error`, so the production `single,sentry_logs` stack forwards them to Sentry. The safe context contains the task display summary, cron expression, timezone, and relevant exit/runtime or exception-class facts only.

## Navigation Structure

The main navigation uses dropdown menus (desktop) and collapsible sections (mobile):

- **Sähkösopimukset** (dropdown)
  - Vertaa sopimuksia
  - Halvimmat sopimukset
  - Pörssisähkö
  - Määräaikainen
  - Toistaiseksi voimassa
  - Yrityksille
  - Sähköyhtiöt
- **Sähkölaskuri**
- **Aurinkopaneelit**

The header also displays the current spot price via `HeaderSpotPrice` component.

## Documentation Maintenance

`AGENTS.md` files define documentation and context-file CRUD rules for this repository.

### `CLAUDE.md` must mirror `AGENTS.md` (non-negotiable)

`AGENTS.md` is the canonical context file in every directory. `CLAUDE.md` exists only so Claude Code loads the same content.

- **`CLAUDE.md` should normally be a symlink to the sibling `AGENTS.md`** in the same directory (`ln -s AGENTS.md CLAUDE.md`). When it is a symlink, editing either name edits the one underlying file, so they can never drift.
- **When you create a new context file, create `AGENTS.md` and symlink `CLAUDE.md` to it.** Do not author two real files.
- **If a directory has `CLAUDE.md` and `AGENTS.md` as two real files (the symlink is missing), they MUST be kept byte-identical.** Whenever you edit one, apply the exact same edit to the other in the same change. Never commit a change that touches only one of the pair.
- **Treat `AGENTS.md` as the source of truth.** If the two real files have already drifted, reconcile onto `AGENTS.md` (it is canonical), then restore the symlink or re-sync `CLAUDE.md` from it.
- This applies to every `CLAUDE.md`/`AGENTS.md` pair in the repo (root and every subtree), not just this one.

### Principles for context files
- Context files are **shortcuts and pointers** for coding agents.
- They should help agents find the right code and concepts **without broad codebase searching first**.
- They are **not substitutes for reading code**.
- They may document important classes, files, and methods with short purpose descriptions to speed up navigation.
- They should document **important decisions**, **constraints**, and **reasons** behind the implementation.
- If there is logic that future sessions **must not change casually**, it **must** be documented in the nearest relevant context file together with the reason.

### Where documentation should live
- Document functionality and decisions **near the code that implements them**.
- Keep root `AGENTS.md` high-level, architectural, and cross-cutting.
- Keep local `AGENTS.md` files scoped to their subtree and rich in implementation detail.
- Prefer several small, local context files over one oversized root-only document.

### CRUD rules for `AGENTS.md` files
After any meaningful domain, data model, import, routing, SEO, matching, or behavioral change:
- update this root `AGENTS.md` if the change affects project-level behavior or architecture
- update the closest existing `AGENTS.md` with implementation details
- if no nearby context file exists, create a new `AGENTS.md` in the nearest sensible directory
- add pointers from broader context files to the more specific one when useful
- keep outdated context files synchronized; if a local file becomes misleading, fix it as part of the same change

### Rule for adding new `AGENTS.md` files
When working in an area that has non-trivial domain logic, import behavior, matching rules, SEO behavior, data model semantics, or other implementation-specific decisions:
- create an `AGENTS.md` in the nearest sensible directory if one does not already exist
- keep it scoped to that subtree
- use parent/root `AGENTS.md` files for broader context and link downward to more specific files
- document notable classes/files and what they are for, as navigation shortcuts
- document important decisions and the reasons behind them
- organize code and context files by **logical feature or domain grouping**, not necessarily one subfolder per service/class
