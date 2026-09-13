# Fixed-term offer repricing frequency

## Conclusion

**The evidence does not show that 30 days is too short because suppliers rarely change their offers.** Exact contract IDs usually keep one energy price, but suppliers frequently appear with different IDs and different offered prices. Treating an ID as a durable product gives a misleading result.

Across 11,608 eligible General offer-days, there is only **one same-ID, same-basis energy-price change**. Yet supplier offered medians change on **473 adjacent supplier-term days**, and a restricted, dated stored-lineage sensitivity finds **112 adjacent-day changes**, all across different carrier IDs. In the lineage sensitivity, 82 fully observed change-to-change intervals have a median of **13.5 days** (p25 7, p75 20, p90 24.8). These are selected completed intervals, not an estimate of all suppliers' typical waiting time.

At 30 days, the supplier median has different endpoints in **3,915/5,496 pairs (71.2%)**. Within complete daily windows, it changes at least once in **3,812/5,238 windows (72.8%)**. The canonical-only subset has different endpoints in **659/891 pairs (74.0%)**. Slow market repricing is therefore not an adequate general explanation for the forecast's failure against the unchanged baseline.

## Evidence and scope

Analysis date: 2026-09-13. Study dates: **2026-04-08–2026-09-13**, 159 calendar dates. Only `../supplier-premium-coverage/export-20260913T094138Z/` is read. The script validates the complete manifest, manifest SHA-256, each file hash and byte count, exact/expected row counts, each selection/count SQL hash, limits, method pair, dates and explicit Railway resource IDs before analysis.

Verified counts: 25,620 snapshots; 1,949 current-method-pair `term_strip` premium rows; 2,239 futures; 477 statistics; 1,314 forecasts; 145 schema and 89 version-metadata rows. Futures and forecasts are verified but not used as repricing observations. No live access, production operation, database replacement, model fit, application change, commit or push was done.

Eligibility is the prior supplier pilot's General cohort: `FixedPrice`, matching `fixed_term_6/12/24`, General metering, no included Spot price, finite energy rate. All 11,608 accepted rows also meet the pilot's 0.005–50 c/kWh producer range; that range removes no additional rows here. Exclusions: 6,801 non-FixedPrice/term-segment rows (Hybrid), 7,205 Time/Season rows, and 6 missing/nonfinite-or-Spot rows. Multi-rate weighted snapshot averages are not complete component evidence and are not called individual repricings.

There are **780 distinct eligible IDs from 27 suppliers**. Counts by term:

| Contract duration | Offer-days | ID × basis series |
|---|---:|---:|
| 6 months | 1,989 | 173 |
| 12 months | 4,858 | 328 |
| 24 months | 4,761 | 350 |

Contract duration, offer repricing interval and forecast horizon are different quantities.

The snapshot producer admits Household/Both/legacy-null targets. That producer scope is trusted; the export cannot independently reconstruct every historical target classification or delivery restriction. Historical availability comes from exact-date component evidence; current canonical production uses active IDs. Current active flags are not historical availability records. Long-lived old IDs without continued evidence do not prove that a supplier waited months.

### Numeric and method rules

- A change requires an absolute finite numeric difference **greater than 0.0001** c/kWh; fee changes use the same tolerance in euros/month. Decimal arithmetic avoids floating-point threshold noise. Source fields store four decimal places.
- The main panel is supplier + term + exact ID + pricing basis. It compares no prices across a method boundary. Observed rows end July 26; canonical rows start July 27. All **71 same-ID basis transitions have unchanged numeric energy rates** and are excluded from change timing.
- General snapshots measure an offered/current signup unit rate, not a contract's average annual energy cost. Historical relational and canonical promotion handling can differ. No cross-basis continuity is needed here.
- Source key/signature changes are not numeric changes. Premium period rows are not independent events and are not expanded into invented daily observations.
- Dates are first observations, not exact seller decisions. Adjacent daily prices bound a detected transition between those observations. A multi-day difference gives only an interval bound; missing days never count as unchanged. Even complete daily evidence cannot exclude an intraday A→B→A change.
- All medians, probabilities and pooled counts are descriptive. Overlapping windows, related variants, common dates and unequal supplier coverage are dependent observations. No significance claim is made.

## 1. Same-ID changes and censoring

The 851 ID × basis series include **821 with at least two dates**. Of those, **820 have no observed energy change**. There are 10,757 adjacent-day comparisons and just one change:

- **Hehku Energia, 6 months**, `lecan2-hehku-energia-oy-hehku-kiintea-6-kk`: 9.6900 on July 20 → 10.9900 c/kWh on July 21. Both rows have `has_discount=true`. The evidence does not distinguish a promotion expiry from a new seller decision; do not call it proven ordinary repricing.

Removing every discount-marked snapshot leaves 9,174 offer-days, 654 multi-date series and **zero energy changes in 8,497 adjacent comparisons**. This is a broad sensitivity: `has_discount` can describe a fee-only offer, so it is not an energy-promotion classifier.

Monthly fees are separate: **6 numeric same-ID fee changes**, all Vattenfall 12/24-month rows on July 24/26. No adjacent `has_discount` flag change occurs in the main ID panel. Exact promotion/phase attribution needs dated components/source episodes; the export does not provide that for every daily row.

The 852 observed constant fragments have a median length of **8 observed days**, not a median repricing interval. There are **851 left-censored and 851 right-censored fragments** (counts overlap). The one witnessed fresh price spell ends before another observed change. Thus **there are zero complete same-ID change-to-change intervals**: no valid median or percentile waiting time can be reported.

Initial prices have unknown prior ages. An ID's last appearance, missing day, eligibility loss, basis boundary or study end censors observation; it never becomes a price change. `constant-spells.csv` records both censor flags and exact bounds. All constant fragments sum to the underlying observed-date totals.

## 2. New IDs and stored-lineage sensitivity

Code inspection is important: `ContractImporter` looks up the upstream API `Id` through `api_id`, preserves the existing local ID, and creates a new random-prefix local ID only for a new API identity. The local ID is **not a hash of price**. However, this export has no complete dated upstream identity/active-state ledger or seller decision log. New local appearances show offer turnover, not proven deliberate product launches.

Of 780 eligible IDs, **700 first appear after the study start** and **716 last appear before the end**. These are observation appearances/disappearances, not verified launch/withdrawal counts. Boundary counts include basis-independent ID history. No missing period is treated as continued availability.

### Mapping policy

For each eligible snapshot, use only a premium row whose stored period covers that date, whose supplier/term and carrier ID agree, and whose General energy price numerically matches. Historical rows use `period_carrier_ids`; current rows use `contract_id`. Do not map the full current `lineage_contract_ids` DAG over historical dates. Exclude bridged unreadable/outage dates, conflicting/incomplete quality, noncurrent/introductory/normal phases, nonzero phase index and detectable energy discounts. Fee-only discount evidence can remain. VAT may be unknown: no premium level is inferred, and matching requires an unchanged numeric scale.

This accepts **4,648 snapshot-days (40.0%)**, leaves **6,738 unmapped**, and rejects **222 ambiguous snapshot-days across 136 IDs**. There are no conflicting numeric lineage-day states after these checks. Historical duration and VAT semantics still originate from the active-tip template. The lineage graph itself was stored later and can merge roots; it is not proof of a contemporaneous direct replacement edge. This panel is therefore explicitly a **dated stored-lineage sensitivity**, not a fully identified historical product panel.

Results:

- 141 lineage × basis series; 118 have two or more dates, of which 38 show no observed change.
- **275 disjoint-carrier switches**: 118 on adjacent observed days and 157 across gaps.
- Of the adjacent switches, **112 have changed energy prices**, 6 do not.
- Of the gap switches, **147 have changed prices**, 10 do not. The gaps do not reveal the number or dates of intermediate seller changes.
- All 112 adjacent numeric lineage changes are ID switches, not edits to the same ID.
- A verified count of deliberate seller replacement/launch decisions is **unavailable**, not zero. The dated stored lineage supports candidate carrier succession; a direct historical edge and seller intent remain unverified. The 700 appearances cannot all be assigned to these events.

Example: Cheap Energy's 24-month fee-promotion offer changes from carrier `o0st2m-…` at **8.09** on May 7 to `02t8fp-…` at **8.35 c/kWh** on May 8, within the same stored lineage. Full carrier IDs and premium mapping rows are in the audit CSVs.

### Observed interval distribution

There are 410 constant lineage fragments: 298 left-censored and 298 right-censored. Only **82** are bounded by witnessed adjacent changes at both ends and have complete daily evidence between them:

| Scope | Complete intervals | Median days |
|---|---:|---:|
| All mapped lineages | 82 | 13.5 |
| 6-month contracts | 16 | 12.5 |
| 12-month contracts | 21 | 8 |
| 24-month contracts | 45 | 14 |

All-term p25/p75/p90: **7 / 20 / 24.8 days**. Linear-interpolated descriptive percentiles are used. These results select frequent, fully observed changes; they do not hide the 38 multi-date series with no change and must not be generalized as a population median.

Supplier examples in the older observed regime:

| Supplier/term | Adjacent changes | Complete intervals | Median interval |
|---|---:|---:|---:|
| Vattenfall 12 months, 2 mapped series | 10 | 8 | 18.5 days |
| Vattenfall 24 months, 2 mapped series | 15 | 13 | 15 days |
| Cheap Energy 24 months, 1 mapped series | 6 | 4 | 16 days |
| Hehku 24 months, 2 mapped series | 9 | 6 | 16 days |

These are small, variant-dependent samples, not calibrated supplier response lags.

To retain censoring information, `fresh-change-followup.csv` follows all **112 witnessed new lineage-price spells**. By 30 days, 76 have another observed change, 12 are observed without another change through day 30, and **24 are censored before day 30**. At 60 days: 81 change, 3 remain observed without change, 28 censor. No censor is counted as unchanged. A population survival curve is not estimated: prevalent spells are left-censored, entry/exit and mapping loss can be informative, and the panel is selected.

## 3. Supplier offered medians

Use the exact same 11,608 eligible offers, equal weight per ID within supplier/date/term/basis. This matches the prior pilot's cohort, not the broader public statistic's Time/Season population. Fees stay outside the median. Campaign, energy-source, customer and other variants remain; no invented historical national/variant filter is applied.

There are 9,094 supplier-term-basis dates in 126 series. There are **473 changes in 8,913 adjacent comparisons (5.3%)**, plus 54 differences across gaps with uncertain timing. Among the 473 adjacent changes:

- **472** coincide with a changed ID set;
- **430** have entirely disjoint before/after ID sets;
- only **1** occurs with the identical ID set.

Thus supplier median movements largely track changed offers/variant membership, not within-ID price editing. Entry/exit can change a median without any existing product being repriced. The median cannot separate pure mix effects from comparable successor pricing by itself.

There are **362 complete median-change intervals**, with median **7 days**, p25 7, p75 15, p90 28.9. Another 181 left-censored and 181 right-censored fragments are retained. Ten of 125 multi-date supplier-basis series have no observed change. Like the lineage distribution, complete intervals overrepresent frequent changers.

Canonical 30-day examples: Helen 6-month medians differ in **17/17** available pairs; Helen 12-month in **16/17**; Vattenfall 12-month in **11/11**, 24-month in **17/17**; Oomi 12-month in **14/19**. These overlapping small samples show observed movement, not forecast skill.

### Calendar timing

There are too few same-ID changes for any calendar claim. For supplier median changes, first-observed weekday counts are Monday 26, Tuesday 99, Wednesday 115, Thursday 74, Friday 103, Saturday 56, Sunday 0. Comparable supplier-term exposures are respectively 1,210 / 1,241 / 1,238 / 1,307 / 1,296 / 1,304 / 1,317. Only **12/473** changes first appear on the first day of a month. The selected completed-interval median of seven days and these clusters are consistent with weekly offer updates, not a universal monthly reset. Import/publication delays can shift a seller's actual decision day. No day-of-week causal rule is inferred.

## 4. Exact horizon windows

Each start requires an exact endpoint in the **same basis**. No interpolation or carry-forward is used. Endpoint equality can miss A→B→A. A second denominator requires every calendar date in the window; its no-change count tests every adjacent daily pair. Counts are empirical conditional frequencies, not independent probability trials.

### Same ID

| Horizon | Starts with target inside study | Exact pairs | Unchanged endpoints | Series with pairs |
|---|---:|---:|---:|---:|
| 7 days | 11,179 | 6,632 | 6,629 | 429 |
| 14 days | 10,735 | 4,234 | 4,234 | 245 |
| 30 days | 9,711 | 1,641 | 1,641 | 85 |
| 45 days | 8,730 | 707 | 707 | 39 |
| 60 days | 7,669 | 252 | 252 | 20 |
| 90 days | 5,436 | 20 | 20 | 3 |

All exact same-ID windows happen to have complete daily coverage. **Only 16.9% of possible 30-day starts have a same-ID endpoint.** The 100% unchanged result is a survival/availability selection result, not proof that suppliers wait longer than 30 days. At 90 days only three ID-basis series survive the selection. The 7-day no-change rate is 99.955%; the other listed rates are 100% in their selected samples.

### Dated lineage sensitivity

| Horizon | Equal endpoints / exact pairs | Endpoint equal | No daily change / complete windows | No daily change |
|---|---:|---:|---:|---:|
| 7 | 2,589 / 3,812 | 67.9% | 2,567 / 3,281 | 78.2% |
| 14 | 1,644 / 3,345 | 49.1% | 1,592 / 2,654 | 60.0% |
| 30 | 605 / 2,223 | 27.2% | 556 / 1,753 | 31.7% |
| 45 | 238 / 1,341 | 17.7% | 203 / 1,230 | 16.5% |
| 60 | 87 / 871 | 10.0% | 84 / 847 | 9.9% |
| 90 | 3 / 210 | 1.4% | 3 / 186 | 1.6% |

### Supplier offered median

| Horizon | Equal endpoints / exact pairs | Endpoint equal | No daily change / complete windows | No daily change |
|---|---:|---:|---:|---:|
| 7 | 5,269 / 8,186 | 64.4% | 5,251 / 8,014 | 65.5% |
| 14 | 3,536 / 7,341 | 48.2% | 3,447 / 7,118 | 48.4% |
| 30 | 1,581 / 5,496 | 28.8% | 1,426 / 5,238 | 27.2% |
| 45 | 702 / 3,823 | 18.4% | 618 / 3,645 | 17.0% |
| 60 | 302 / 2,747 | 11.0% | 243 / 2,658 | 9.1% |
| 90 | 79 / 1,063 | 7.4% | 44 / 1,016 | 4.3% |

Change counts are the denominator minus no-change counts; both counts are explicit in `horizon-summary.csv`. At 30 days there are **146 complete supplier windows that change and return to the starting median**, compared with 19 in the lineage panel. This explains why endpoint equality is not a no-event measure. Complete and endpoint panels also select different starts; percentages should not be subtracted across denominators.

### Selection and method dependence

The horizon populations are not fixed. For example, supplier exact-pair availability falls from 5,496/7,580 possible 30-day starts to 1,063/4,288 at 90 days. Lineage availability falls from 2,223/3,296 to 210/1,388. Missing endpoints include offer absence, missing mapping, basis boundaries and study limits, not only censoring by price change.

**There are no canonical 60- or 90-day pairs.** Canonical data spans only July 27–September 13. Those long horizons use older observed evidence exclusively. At 30 days:

- Canonical supplier medians: **232 equal / 891 exact pairs**, and **207 no-change / 711 complete windows**.
- Observed supplier medians: **1,349 equal / 4,605 exact pairs**, and **1,219 no-change / 4,527 complete windows**.
- Canonical lineage mapping is much more incomplete: **162 equal / 602 endpoint pairs**, but **136 no-change / only 156 complete windows**. The high complete-window no-change fraction is a severe selected-survivor result, not a contradiction of changed endpoints.

Use `horizons-by-basis.csv` before making any contemporary long-horizon claim. Longer-horizon no-change fractions from different available starts do not identify an optimal horizon or a retail reaction lag.

## Continuation

The requested fixed 14/30/45/60-day forecast tests and seven-day change/lag tests are now in [horizon-lag-report.md](horizon-lag-report.md). The canonical all-model cohorts do not beat unchanged price; 45-day momentum and all 60-day canonical tests lack same-basis evidence. Lag associations are sensitive to basket-roll removal and common-date selection. No longer horizon or universal response lag is recommended. The cadence measurements below and their artifacts are unchanged.

## Forecast implications and next experiment

1. **Do not lengthen the forecast merely because exact IDs stay constant.** Changed offers and supplier medians often move within 30 days. The strict-ID panel misses most economically relevant turnover.
2. This analysis measures update timing, not futures predictiveness, procurement costs, direction accuracy or the time between a wholesale shock and a seller response. A new price can move in an unpredictable direction. Frequent change is compatible with an unchanged-price forecast winning on error.
3. A separate accuracy comparison at 7/14/30/45/60/90 days is appropriate. Use exact matured actuals, vintage-safe curves and historical inputs, unchanged-price and market baselines, and the same issue-date/supplier/term pairs within each comparison. Report basis-specific results and a common-start sensitivity where possible. Preserve chronology in a time-separated holdout; do not treat overlapping targets as independent tests.
4. **Do not reuse 30-day lambda 0.30 as a calibrated 45/60/90-day model.** Longer-horizon parameter choices need pre-specified or training-only estimation and a separate holdout. This research neither fits those models nor changes alpha 0.25 or lambda 0.30.
5. The corrected local forecast draft remains **undeployed**. Nothing here changes its release state. No deployment recommendation follows from cadence alone.

## Reproduce and audit

From the repository root:

```sh
python3 tasks/fixed-term-repricing-frequency/analyze.py
python3 tasks/fixed-term-repricing-frequency/verify.py
```

Both passed. The second script independently rebuilds daily prices/medians from raw evidence, validates accepted dated numeric carrier mappings, recomputes every horizon window, checks constant-fragment coverage and censor boundaries, and checks count identities. The source export remains unchanged.

Main artifacts:

- `summary.json`: verified input identity, totals, horizons, exclusions and mapping counts.
- `supplier-term-frequency.csv`: all observed supplier/term/basis cells, zero-change series, fees, spells and complete-interval percentiles. Missing cells mean no eligible evidence, not no supplier activity.
- `horizon-summary.csv`, `horizons-by-basis.csv`, `windows-by-series.csv`: exact denominators, counts, conditional rates and return-to-start counts.
- `series.csv`, `constant-spells.csv`, `fresh-change-followup.csv`: exposure and censoring, including never-changers.
- `transitions.csv`: numeric before/after prices, fee/discount flags, carrier identities and observation bounds.
- `dated-mapping.csv`, `premium-mapping-audit.csv`, `lineage-carrier-switches.csv`: row-level stored-lineage provenance and limitations.
- `id-appearances.csv`, `excluded-basis-seams.csv`: appearances and deliberately excluded method transitions.
- `supplier-daily-medians.csv`, `date-clusters.csv`, `supplier-lineage-date-clusters.csv`: exact variant sets, daily medians and calendar exposure.

No `lineage-conflicts.csv` is generated because there are zero conflicting mapped lineage days. All analysis output is confined to this new task folder.
