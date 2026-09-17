# Decisions

## 2026-09-15 — User-approved target, documentation only

The user supplied these decisions after a pricing review. They govern future estimator and ranking work where older context conflicts. This task does not implement them, change financial results, or authorize a release or production mutation. The completed `annual-pricing-estimate-consistency` task remains a record of older implementation.

### Source-evidence audit and user decisions

The read-only production audit in the preceding discussion covered 382 active contracts on 2026-09-15. It found 50 complete introductory fee promotions, no confirmed instance of the original one-month 4 c/kWh hold case, and two ambiguities that overlap the 50. A research subagent performed that audit; the user then decided how to treat the ambiguous cases. This documentation task did not repeat the audit.

Dated regression examples from that source-evidence review:

- **Hehku JATKUVA**, `xam9ev-hehku-energia-oy-hehku-jatkuva`: the user requires exclusion from price calculations because campaign energy-price information is incomplete. This is an intended outcome, not a claim that the current application already excludes it.
- **Cheap Porssisahko**, `wqazsh-cheap-energy-finland-oy-cheap-porssisahko-superdiili-cheap-porssisahkon-marginaali-vain-032-sntkwh-neljan-kuukauden-perusmaksut-0-kk`: the user permits inclusion. The margin is not stated to be temporary, and the fee promotion is complete.

Use the structured prices and seller prose together. Distinguish a parser omission from missing seller disclosure. These examples are evidence-based regression cases dated above, not hardcoded contract-ID exceptions or permanent bans. Use a neutral insufficient-promotion-terms reason; dishonest intent need not be proved. No raw source payload was copied or newly fetched in this documentation task.

### Known future implementation work

- `test_2_open_ended_promo_with_unknown_later_price_is_an_explicit_estimate` deliberately extends a one-month 4 c/kWh price to EUR 200 at 5,000 kWh. It must change when the approved promotion rule is implemented. Do not modify it in this task.
- `ContractPriceHistory/ContractHistoryPresenter.php` follows the predecessor CTE. `CanonicalPricing/SupplierAdjusted/CurrentPriceEpisodeResolver.php` currently queries current IDs only and includes fees in episode matching. Future consumers must share one resolver based on the existing trusted `replaced_by_contract_id` chain/matcher. No new heuristic matching or production relinking is approved.
- The existing retail-premium dataset currently excludes ordinary non-reset open-ended `FixedPrice` contracts. Check applicability before reuse; do not assume it already supplies the required estimates.
- Premium estimation can use available comparable evidence now. It does not need to wait for future coefficient-calibration data. Exact fitting thresholds and coefficients were not approved.
- Six-month contracts always use annualized six-month costs, including disclosed in-term changes. Continuation after the term and Hybrid classification must not change that horizon. Ordinary fully known 12/24-month first-year handling is not redesigned.
- Monthly projections for unknown quarterly-reset tails and discretionary open-ended changes remain accepted estimates. A future quarterly-fixed step model or seller repricing calendar is not required.

### Verification plan

Completed: `git diff --check` passed. Final diff review confirmed additive policy/status notes only; older implementation text remains unchanged. A Python check passed for exactly four changed context files, three new task files, all four `CLAUDE.md -> AGENTS.md` symlinks with equal content, and valid task JSON. `git status --short` confirmed the intended scope.

Read-only source inspection confirmed the test name, current-ID and fee-based episode matching, predecessor CTE, and the retail-premium eligibility gap.

No PHP tests or asset builds were run because the task changes documentation only. No code, configuration, data, pricing behavior, deployment, commit, or push changed. No new market audit was performed.
