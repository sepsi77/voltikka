# Canonical pricing source-of-truth rollout

Status: **planned only**. No production action in this task.

Pre-deploy blocker status (2026-07-27): the `/sahkosopimus/kannattaako-porssisahko` 128 MB failure is locally addressed with bounded, selective article-widget reads and passes a local 128 MB production-shape route check. It is **not cleared in production** until the fix is deployed and the public URL returns HTTP 200 under bounded log review. The mixed working tree remains a separate deploy blocker.

## Production target and safety

Use only this Railway target:

- Project `Voltikka`: `6d8cae01-1006-409f-8108-1d51f1abc676`
- Environment `production`: `9245cef8-41d0-486e-862f-193726511dba`
- App service `voltikka`: `700d0624-fa96-4266-876c-e37640d220ea`
- MySQL service: `beb2ba12-4a7b-416b-b4b1-596434dc3215`

Each production mutation needs a new, explicit user confirmation before execution. This includes the deploy, its automatic migration, manual backup runs, interpretation dispatch, cache writes, statistics calculation, and forecast runs. Use `RAILWAY_CALLER=skill:use-railway@1.2.2` and one stable `RAILWAY_AGENT_SESSION` for an approved execution session. Do not print variables, connection strings, object-storage credentials, or `BACKUP_ARCHIVE_PASSWORD`.

Pin one rollout date and one comparison start date before capture. Use the same `--start-date=<YYYY-MM-DD>` in every before/after command.

## 1. Read-only pre-deploy baseline

No deploy or database write is permitted in this stage.

1. Confirm the Railway target IDs, current deployment ID/status, health, queue-worker status, scheduler status, and bounded application/worker logs.
2. Confirm that the latest scheduled encrypted database backup and Railway-native MySQL backup are healthy and recent enough for the rollout. If a new manual backup is required, stop and request confirmation before `backup:run --only-db`.
3. Record, without secret values:
   - active household contract and company counts;
   - published interpretation counts by schema, prompt, validator, and status;
   - counts of active contracts without a published interpretation;
   - `contract_price_snapshots` and `contract_price_daily_statistics` latest dates and basis counts;
   - queue and failed-job counts;
   - fixed forecast counts by `model_version` and current-input basis;
   - current `CANONICAL_PRICING_ENABLED`, `RESET_FORWARD_SHIFT_ENABLED`, and `PRICE_FORECASTING_MODEL_VERSION` names/state. The expected target is canonical on, reset shift on, and `fixed_term_ewma_gap_v2`. Do not print other variables.
4. Save read-only HTTP/API baselines for `/`, `/sahkosopimus`, `/sahkosopimus/sahkoyhtiot`, `/sahkosopimus/tilastot`, `/maksatko-liikaa`, one company page, one contract page, `GET /api/contracts?consumption=5000`, and `GET /api/video/weekly-offers`.
5. Run the deployed comparison code against production data at 2,000, 5,000, 10,000, and 18,000 kWh. Capture output locally, not in production storage:

```bash
php artisan contracts:compare-canonical-pricing --consumption=2000 --start-date=<YYYY-MM-DD> --fail-on-parse-errors
php artisan contracts:compare-canonical-pricing --consumption=5000 --start-date=<YYYY-MM-DD> --fail-on-parse-errors
php artisan contracts:compare-canonical-pricing --consumption=10000 --start-date=<YYYY-MM-DD> --fail-on-parse-errors
php artisan contracts:compare-canonical-pricing --consumption=18000 --start-date=<YYYY-MM-DD> --fail-on-parse-errors
php artisan contracts:compare-canonical-pricing --resets --consumption=5000 --start-date=<YYYY-MM-DD>
```

For machine comparison, run the same command through an approved local Railway environment wrapper and write `--json` to the local checkout as `before-schema-v4-<kwh>.json`. The command reads production data, but the JSON file must be local. Do not write it into the production container.

Stop if the baseline has parse errors, failed critical interpretations, unhealthy backups, an active queue incident, or unexplained missing canonical output.

## 2. Deploy and migration stage

Request explicit confirmation for one deploy to the exact project, environment, and app service above. State that the container entrypoint will run `php artisan migrate --force` and warm list/company caches.

The additive migration must add indexed `pricing_basis` columns to:

- `contract_price_snapshots`;
- `contract_price_daily_statistics`.

Existing rows default to `observed_seller_data`. The deploy must not reinterpret contracts automatically unless a new source snapshot arrives through the normal import.

After deploy, inspect migration status, both columns and defaults, deployment health, worker health, bounded logs, and failed jobs. Do not run a second manual migration if the entrypoint migration succeeded.

### Expected temporary unavailable states

These states are expected only between deploy and the related rebuild:

- Current statistics consumers can show unavailable data because old rows default to `observed_seller_data`, while canonical mode requires `canonical_calculation`.
- The statistics page, Consumption Calculator price table, homepage trend, spot article snapshot, and market-insight pills can be empty until a canonical statistics run owns the current date. The company market comparison can instead show its latest internally consistent observed snapshot, but it must be visibly dated and labelled as historical, not as the current canonical price.
- Model-v2 forecasts can be unavailable until canonical-basis statistics exist and `forecasting:run-fixed-contracts` creates v2 rows.
- Vaasan Sähkö package products keep their old published interpretation until schema-v4 reinterpretation. Surffari keeps its old phase output until prompt-v19/validator-v16 reinterpretation. Do not treat these temporary old outputs as reviewed new prices.

Temporary unavailability is safer than a relational fallback. Stop if any unavailable state becomes EUR 0 or exposes relational current rates in canonical mode.

## 3. Controlled reinterpretation

Interpretation writes production data and can make public price changes. Request confirmation before each stage.

1. Use read-only queries to resolve current contract IDs for the critical set:
   - Vaasan Sähkö Kuukausipaketti XS/S/M/L;
   - Surffari kesäkampanja;
   - both Vattenfall monthly-fee offer shapes;
   - representative Hybrids, including a reset-plus-consumption-effect product;
   - representative six-month and other short fixed terms.
2. Dispatch one critical contract at a time:

```bash
php artisan contracts:interpret --contract=<contract-id>
```

3. After each dispatch, wait for completion. Check the newest interpretation row, fingerprint versions (`schema-v4`, `prompt-v19`, `validator-v16`), attempt count, correction errors, published fields, published pointer, queue depth, failed jobs, and bounded worker logs. Do not use `--retry-failed` until the failure is reviewed.
4. After the critical set passes, process the remaining active latest snapshots in batches of at most 25 contract IDs. Use the same `--contract=` command per ID. Wait for the queue to drain between batches.
5. Pause a batch if any critical interpretation fails, if final validation failures exceed 5% of the batch, if failed jobs appear, if queue age grows without progress, or if correction calls repeatedly reach the two-repair limit.

Each successful publication bumps the shared contract-list data version. Company-list and ranking keys also include that version, so they cannot keep pre-publication values. Warm caches after a completed reviewed batch, not after each contract.

## 4. Comparison and review gates

After the critical set, and again after all approved batches, run the same comparison commands and consumptions as the baseline. Save local `after-schema-v4-<kwh>.json` files.

The command output is the legacy-vs-canonical review. Compare old canonical to new canonical by contract ID with the local before/after JSON files:

```bash
python3 - <<'PY'
import json
before = {r['id']: r for r in json.load(open('before-schema-v4-5000.json'))}
after = {r['id']: r for r in json.load(open('after-schema-v4-5000.json'))}
for contract_id in sorted(before.keys() | after.keys()):
    old = before.get(contract_id, {})
    new = after.get(contract_id, {})
    if (old.get('canonical_total') != new.get('canonical_total') or old.get('comparability') != new.get('comparability')):
        print(contract_id, old.get('canonical_total'), new.get('canonical_total'), old.get('comparability'), new.get('comparability'))
PY
```

Also rerun:

```bash
php artisan contracts:compare-canonical-pricing --consumption=5000 --start-date=<YYYY-MM-DD> --fail-on-parse-errors
php artisan contracts:compare-canonical-pricing --resets --consumption=5000 --start-date=<YYYY-MM-DD>
```

Require human review before the next batch when any contract changes by more than EUR 25/year or 5%, changes listing comparability, enters/leaves the top 20, or changes the cheapest company/contract.

Review these named gates:

- **Vaasan packages:** XS/S/M/L have monthly allowances 75/150/250/350 kWh, 16.60 c/kWh only on monthly excess, no promotion, and no duplicate L fee. At 5,000 kWh with the standard profile, expected totals are about EUR 806.60, 783.20, 752.00, and 720.80.
- **Surffari:** the active campaign phase is 0.20 c/kWh through 31 August 2026 and the known continuation is 0.60 c/kWh from 1 September. It must not silently show only the continuation.
- **Vattenfall:** discounted totals stay unchanged. The measured 5,000 kWh benefit is about EUR 28.44 or EUR 35.76 for the two offer shapes.
- **Hybrids:** billed base-component offers apply, the unknown consumption effect does not, and base-only/reset disclosures remain visible.
- **Short terms:** ranking uses annualized comparison totals, while public offer copy uses the real-term total and benefit.
- **Rankings and CompanyList:** no excluded/missing contract becomes zero; canonical-only contracts count; cheapest order, counts, averages, fees, margins, and company ranks are reviewed.
- **Offers:** company sections, SEO offer page/JSON-LD, weekly-offers API/prompt/Remotion data use positive measured canonical benefits. Packages, unsafe states, and relational-only discounts do not enter.
- **Bill comparison:** standalone, listing, and detail surfaces agree for the same exact period. Missing Spot history, excluded pricing, consumption caps, and missing pricing show typed unavailable reasons.
- **Statistics:** current rows are `canonical_calculation`, historical rows remain `observed_seller_data`, one basis owns the rebuilt date, CSV includes basis, and excluded stale snapshots are gone.
- **APIs:** canonical contract list/show omit `price_components`, return typed `current_pricing`, preserve null/unavailable values, and return canonical `calculated_cost` only when requested. Calculation API does not query relational components.
- **Forecasts:** only model v2 with canonical current-input provenance is public in canonical mode. Historical EWMA and matured actuals remain observed seller evidence.

## 5. Statistics, forecasts, and cache rebuild

These are production writes. Request confirmation before execution.

After the approved interpretation set:

```bash
php artisan contracts:warm-cache
php artisan contracts:calculate-price-statistics --date=<YYYY-MM-DD> --overwrite
php artisan contracts:warm-price-statistics-cache --period=weekly --period=monthly --period=daily --consumption=2000 --consumption=5000 --consumption=18000 --sync
php artisan forecasting:run-fixed-contracts --as-of=<YYYY-MM-DD> --horizon=30
```

`contracts:calculate-price-statistics` already queues the default warmer. The explicit synchronous warm fills all public statistics states after review. Do not run a historical backfill with canonical pricing; historical backfill must remain `observed_seller_data`.

Check that the target date has one basis, canonical snapshots and aggregates exist, Hybrid and package segments did not vanish, forecast v2 rows carry canonical current-input metadata, and no v1/wrong-basis row is public.

## 6. Smoke tests

Run after deploy, after the critical set, and after the final rebuild:

- `/`, `/sahkosopimus`, cheapest, fixed, Spot, consumption-effect, company list, and one company detail page;
- one normal fixed contract, Vaasan package, Surffari, Vattenfall offer, Hybrid, short term, reset, canonical-only, and excluded contract detail page;
- all three bill-comparison surfaces with one fixed period and one Spot period;
- `/sahkosopimus/tilastot` at 2,000/5,000/18,000 kWh and CSV;
- `/sahkosopimus/laskuri` and `/sahkosopimus/sahkon-hintaennuste`;
- `GET /api/contracts` with and without consumption, one excluded contract, and one canonical-only contract;
- `POST /api/calculate-price`;
- `GET /api/video/weekly-offers` and a Remotion data/type render check.

Check HTTP health, no new Sentry exceptions, bounded logs, queue depth, failed jobs, response null states, and no `price_components` field in canonical API output.

## 7. Stop and rollback criteria

Stop immediately for:

- migration or health-check failure;
- any parse error from `--fail-on-parse-errors`;
- a critical interpretation that fails or publishes an unsupported shape;
- an unreviewed material price/rank change;
- Vaasan duplicate fees/package promotions, missing Surffari campaign phase, changed Vattenfall discounted totals, or offer savings caused by wholesale movement;
- a canonical-excluded/missing result shown as zero or filled from relational data;
- API schema leakage of relational components in canonical mode;
- loss of a statistics segment, mixed ownership on the rebuilt date, or missing canonical current rows after rebuild;
- failed jobs, a stalled queue, or a material error-rate increase.

Rollback must preserve interpretation history:

1. Stop new interpretation batches and cache/statistics/forecast writes.
2. Prefer a corrective code deploy and targeted reinterpretation under a new reviewed version.
3. For an urgent application rollback, deploy the prior code but leave the additive provenance columns and all `contract_interpretations` rows in place. Old code can fail closed on schema-v4 package output; temporary unavailability is acceptable.
4. Do not delete interpretation rows, reset history, bulk overwrite canonical JSON, or drop provenance columns.
5. If selected published pointers must return to prior validated outputs, first require a verified database backup and a separate reviewed, targeted republish procedure that copies the chosen prior interpretation output and pointer without deleting either version. This action needs explicit confirmation.
6. Use a full database restore only for a database-level incident, from a verified backup, with separate confirmation. A restore is not the normal price rollback.
