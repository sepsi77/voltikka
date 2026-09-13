# Supplier premium coverage

## Result

Evidence collection is complete. No application code, production data, local database, or forecast settings changed. No supplier forecast was fitted or activated.

**Use `export-20260913T094138Z/`.** This is the complete export, taken on 2026-09-13 at 09:41:38 UTC. The earlier `export-20260913T093827Z/` is superseded: it selected only fixed-price snapshot segments. The final export also includes fixed-duration Hybrid snapshots, so the duration selection does not hide these variants. Do not combine the two exports.

The requested date interval is 2026-04-08 through 2026-09-13, inclusive. Each export uses its own single read-only consistent MySQL transaction, then rollback. Composer autoload supplies the connection helper; Laravel is not started. No local database is opened. The manifest retains exact count and export SQL, SQL hashes, expected and actual row counts, file hashes, byte counts, method selection, dates, limits, and Railway IDs. Exception details are suppressed.

## Export contents

| Evidence | Rows | Date coverage |
|---|---:|---|
| FI Base futures, all tenors | 2,239 | 2026-04-08–2026-09-11 |
| 6/12/24 energy unit statistics, null consumption | 477 | 2026-04-08–2026-09-13 |
| All stored fixed-contract forecasts by forecast date | 1,314 | 2026-04-18–2026-09-13 |
| FixedTerm 6/12/24 snapshots, selected nonannual fields | 25,620 | 2026-04-08–2026-09-13 |
| Current-pair FixedTerm term_strip premium rows | 1,949 | Periods that overlap the requested interval |
| Schema / version metadata rows | 145 / 89 | Export-time evidence |

Total exported data: 26,737,544 bytes. Each query first counts a bounded source selection with a 100,001-row sentinel. More than 100,000 rows fails rather than truncates. The total data limit is 100 MiB, query timeout is 20 seconds, and PHP timeout is 240 seconds. All final counts match.

Production metadata and the service constants select exactly `retail-premium-v2` and `retail-premium-history-v2`. Older method families are not in the premium export. Only `term_strip` is counted; month/quarter/year alternative candidates are not independent observations. Full JSON, quality, VAT, reference and provenance fields are retained.

The premium export includes all FixedTerm durations to retain missing or unexpected assignment evidence. Its 52 rows for 15/18/36 months are outside the coverage grid. All 1,897 requested-term rows have stored period duration metadata; none needs a current-attribute or dated-snapshot fallback. Historical duration metadata was originally built from the active-tip semantic template. It is retained period evidence, **not proof that a dated source explicitly disclosed the same duration**. Historical numeric prices come from relational components. This provenance limit remains in force.

## Supplier x term coverage

`coverage-by-supplier-term.csv` contains all 84 cells: 28 suppliers x 3 terms, including zero-coverage cells. It includes snapshot rows, distinct dates and contract IDs, basis/segment/metering counts, first/latest dates and age, premium lineage counts, component-period keys, method seams, chronology ambiguity, VAT/quality/flag counts, non-exclusive exclusion reasons, and separate included/excluded VAT descriptive medians.

| Term | Daily snapshot rows, including Hybrid | Premium rows | Distinct premium lineages | Component-period keys after seam removal | Compatible General premium rows |
|---|---:|---:|---:|---:|---:|
| 6 months | 3,446 | 320 | 89 | 311 | 31 |
| 12 months | 11,043 | 831 | 248 | 819 | 182 |
| 24 months | 11,131 | 746 | 246 | 738 | 198 |

Snapshot counts include 18,819 FixedPrice rows and 6,801 Hybrid rows. The CSV separates them. Hybrid base prices are not fixed-price premiums. Snapshot contract IDs are **not** replacement lineages. A supplier can have several tariff, campaign, green-energy, or customer variants on one day. Raw snapshot counts must not be compared as normalized supplier-observation counts. Missing snapshot dates are not filled.

### Suppliers with numerically compatible descriptive samples

These are row counts, not counts of independent repricings or proof of forecast readiness.

| Supplier | 6 months | 12 months | 24 months |
|---|---:|---:|---:|
| Cheap Energy Finland Oy | 0 | 0 | 12 |
| Fortum Markets Oy | 0 | 0 | 4 |
| Hehku Energia Oy | 0 | 14 | 6 |
| Imatran Seudun Sähkö Oy | 25 | 12 | 25 |
| Koillis-Satakunnan Sähkö Oy | 0 | 13 | 8 |
| Nurmijärven Sähkö Oy | 0 | 2 | 3 |
| Oomi Oy | 0 | 18 | 12 |
| Pohjois-Karjalan Sähkö Oy | 6 | 3 | 1 |
| Porvoon Energia Oy | 0 | 33 | 47 |
| Vattenfall Oy | 0 | 87 | 80 |

There are 411 compatible rows in 20 supplier-term cells from 10 suppliers. Vattenfall has the largest 12/24-month samples, followed by Porvoon Energia. Six-month coverage is concentrated in two suppliers. Eighteen suppliers have no compatible General premium sample under this rule. A zero is not proof that the supplier has no products or no commercial spread.

## Usability and exclusions

A descriptive energy-premium row must have a known included/excluded energy VAT basis, a non-null energy premium and matched reference, an exact/inferred quality, General metering and `energy_general`, a strict prior reference trade date, and no missing delivery months. It must not be a post-term continuation, method seam, unresolved discount, or incomplete/conflicting source-consistency row. VAT bases stay separate. Monthly-fee-inclusive premiums are retained in raw data but are not used in these baseline counts or medians.

Requested-term VAT counts are **1,249 unknown, 494 included, and 154 excluded**. The dataset is **not all unknown VAT**. Reconstructed history alone has 319 unknown, 76 included, and 28 excluded rows. Its VAT uses the documented canonical-role mapping, not independent historical VAT disclosure. Never use target group to invent VAT.

Non-exclusive exclusions in the requested terms:

- 1,249 unknown/mixed VAT rows; these same rows have null energy premiums and null VAT-matched references.
- 830 multi-rate component rows, not a General price aggregate.
- 233 unresolved-discount rows.
- 29 method-seam rows.
- 15 incomplete and 2 conflicting source-consistency rows.

Reasons overlap and must not be added as distinct excluded observations. `coverage-period-audit.csv` preserves the classification for every exported premium row, including out-of-term rows. The 411 accepted rows include 350 canonical-family and 61 historical-family rows. Calendar-period-unknown flags remain visible; they do not prevent a description of a stored spread, but they weaken timing claims. Unknown-VAT wholesale evidence remains usable for a separately specified analysis of differences, not for a known-VAT premium level.

## Genuine periods versus storage rows

Premium rows are stored component/phase price-period evidence, not daily snapshot rows. Even after removing the 29 flagged seams, **1,868 keys are not 1,868 independent retail repricings**. All seam target keys resolve inside this export. Seam rows are dropped from independent-period counts, not silently merged into another key.

`coverage-transition-audit.csv` records the exact before/after row IDs for consecutive same-lineage, same-method, same-component, same-phase, same-VAT comparisons. It does not bridge method families. There are:

- 805 non-overlapping component pairs with an energy or monthly-fee change;
- 108 pairs with an unchanged price signature but a new observation key;
- 41 overlapping period pairs with ambiguous chronology;
- 25 signature changes without an energy or fee change.

Only 179 of the 805 changed component pairs have both rows numerically compatible under the descriptive filter. **This still is not an independent supplier training sample.** Multi-rate components, related variants, shared calendar shocks, observation gaps, source-only episodes, and unresolved chronology can create dependent evidence. Same-signature rows are reported, not invented as repricings or merged across recurrence. Stored signature counts are descriptive only; the method families use different signature construction. The report does not claim an exact unified economic-period count where the evidence is ambiguous.

## Freshness and later validation

The latest premium observation date is 2026-09-12, one day behind the requested endpoint. Compatible rows span 2026-04-09–2026-09-12. Most suppliers with premium evidence reach September 12; Lumme Energia ends on August 17. Snapshot and premium freshness are separate CSV fields. The Friday September 11 futures endpoint is consistent with the Sunday September 13 export; this is a date fact, not a full import-health certification.

The export contains 909 `fixed_term_ewma_gap_v1` and 405 `fixed_term_ewma_gap_v2` forecast rows. There are no v3 rows. Their complete saved metadata and evaluation fields remain intact. Forecast selection is by forecast date; target dates can be later than September 13. The 477 unit statistics contain 330 observed-seller and 147 canonical rows. Later comparisons must use forecast-vintage-safe futures, exact-date matured actuals, saved model/basis provenance, and method-compatible retail evidence. Do not treat stored cross-basis evaluations as automatically valid. The separate forecast correction task is not deployed.

Some cells permit a **VAT-separated description of the available sample**. No cell has a validated supplier forecast from this work. Sparse cells such as Nurmijärvi 12/24 months or Pohjois-Karjala 24 months cannot support a reliable supplier-specific claim from these rows alone. Larger cells also need chronology review, variant normalization, a time-separated holdout and a fair unchanged-price/market baseline comparison. No arbitrary row threshold is used as statistical proof.

These values are retail premiums, not supplier profit or margin. Disclosed Spot fees are a different measure and are not in this export. Fixed-term coverage does not establish quarterly market-reset beta readiness.

## Verification

- `php -l tasks/supplier-premium-coverage/export-production.php`: passed before both read-only runs.
- Both explicit-ID Railway export commands completed with exit code 0. The final run exported the counts above, with rollback in `finally`.
- `python3 tasks/supplier-premium-coverage/coverage-analysis.py tasks/supplier-premium-coverage/export-20260913T094138Z`: passed all manifest, SQL hash, file hash, byte-count, row-count, and term checks; generated the CSV files and summary.
- No Laravel tests or asset build are required: no application behavior, CSS, or JS changed.
