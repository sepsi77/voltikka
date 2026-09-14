# Decisions

- Frozen before metrics. Use global per-term presence seam for the chronological history. No future canonical price enters a feature or fit. Before that seam, observed history is unchanged.
- Each duration can be eligible independently. No all-term intersection and no retail lag/vector requirement. Cohort matching is within feature definition, not across definitions.
- Use production saved-delta and saved-price rounding for all numerical comparators, not prior-study raw-prediction MAE. Reuse prior ridge convention only.
- Keep basket provenance once per issue/term/valuation and pair identities once per horizon. Fits reference these IDs. No duplicated CSV and JSON tables.
- No full-coverage baseline table is needed: unrestricted training mean is retained on the primary matched tests.
- Completed two runs and independent checks without changing executable sources after the first results. Artifacts are byte-identical and pinned original inputs are unchanged.
- Empty direction groups are absent in machine metrics; they are explicitly reported as N=0 in the report and verification document. No zero error is imputed.
- Primary gains concentrate in six-month contracts. Five primary falling targets are all observed 12-month cases; no fitted model calls them correctly. Recommendation: no adoption and no falling-accuracy claim. The secondary horizon and synthetic sensitivity do not replace these missing falling observations.
