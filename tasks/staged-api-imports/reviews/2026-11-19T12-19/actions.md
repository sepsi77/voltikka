# Review actions — 2026-11-19T12-19
Verdict after fixes: ACCEPT

## Fixed
| Finding(s) | Commit or local change | Change (one line) |
|---|---|---|
| LAR-001 | 743f1c1 | Spot averages run inside the store transaction; only cache warming after commit. |
| LAR-002 | 743f1c1 | A database error in the EEX final checkpoint write rolls the run back and reports SQL facts. |
| LAR-003 | 743f1c1 | An EEX replay takes over once; failure paths never start a second owner. |
| LAR-004 | 743f1c1 | `ImportFetchFailed` keeps the payload of an all-failed fetch; contracts use it. |
| LAR-005 | 743f1c1 | Store-only `contracts:fetch` never downloads logos. |
| LAR-006 (JAO, NVE), REF-001 | 743f1c1 | JAO and NVE parse only in the store, so a parser fix can replay the saved body. |
| LAR-007, REF-002 | 743f1c1 | Validation failures of five collectors print the JSON result line. |
| PRC-001 | 743f1c1, a906fac | Contract store refuses a superseded payload; check and write serialized under `contract_import_store_v1`. |
| PRC-002 | 743f1c1, a906fac | Older forecast replay archives its vintage and keeps the newer display; serialized under a source/region lock. |
| PLT-001 | 743f1c1 | S forecast input activities do not retry `CommandFailed`. |
| PLT-002 | 743f1c1 | S forecast and `ImportReplay` Issues carry step and payload reference. |
| PLT-003, TST-005 | 743f1c1 | Stores have a one-day schedule-to-close and are never scheduled on a payload older than retention (S6). |
| TST-001, TST-101 | 743f1c1, a906fac | Removed pinned key order and progress prose. |
| TST-002 | 743f1c1 | EC46 and SEAS5 D1/D2/D6 cases. |
| TST-003 | 743f1c1 | EEX rollback tests fail a later write after earlier upserts succeeded. |
| TST-004 | 743f1c1 | Spot staged tests use the real averages service. |
| TST-102 | a906fac | EEX newer-owner test injects the failure at the real cache write. |
| TST-103 | a906fac | Deterministic lock-order tests for the contract and forecast recency checks. |
| LAR-201 | fix commit after a906fac | Store-lock leases 65 min, above the 60-min Temporal store timeout. |
| LAR-202 | fix commit after a906fac | Fetch owner UUID in the payload; a split store failing before takeover finalizes only that still-running owner. |

## Not done
| Finding(s) | Sev/Lik | Disposition | Reason | Decided by |
|---|---|---|---|---|
| LAR-006 (GIE, Syke, Fingrid, ENTSO-E) | Medium/Low | defer | Paging needs the parsed page to plan the next request; a page that fails to parse leaves the run genuinely incomplete, and a replay cannot invent the missing pages. | agent under delegated authority |
| LAR-202 (reader-level refusals) | Low/Low | defer | `sha256_mismatch`, `incomplete`, `missing`, `wrong_source`, `options_mismatch` mean a corrupted or foreign file; the fetch's `running` marker then stays until the next run, and the Temporal Issue names the refusal. | agent under delegated authority |
| LAR-201 (database mutex shape) | High/Low | ignore | The 65-minute lease above the Temporal kill bound gives the same guarantee for scheduled writers without moving the check inside the importer transaction. | agent under delegated authority |

## Still owed
| Item | Owner / next step |
|---|---|
| External PR review (CodeRabbit, Greptile) | Manager, `external-pr-review` |
| Deploy (both roles; worker after the PHP commands support the modes, same image) | User approval |
| Re-apply Schedules: `schedules.py --apply --group collectors`, `--group spot`, `--group maintenance` | User approval (production change) |
| D7: first payload per job (daily/hourly 24 h, weekly 7 days), SEAS5 manual `--fetch-only`, one approved `ImportReplay` trial | User approval, after deploy |
| Measure payload sizes and free space on `voltikka-storage` | Manager, after deploy (read-only) |

## Codification
- Written in `laravel/app/Services/ImportPayload/AGENTS.md` and root `AGENTS.md`: every new external import follows the staged pattern; stores are one transaction; data-meaning times come from the header.
- Added to the `laravel-reviewer` checklist, item 3 (missed by the first implementation, caught here): a recency or freshness check that guards a later write must run under the same serialization as that write, and the serialization must outlast the writer's allowed life (PRC-001/PRC-002 residuals, LAR-201).
- Added after the external review (2026-11-19), to the `laravel-reviewer` checklist, item 2: a rewritten command keeps its behavior from `main` in every mode (exit code per scope, failed-unit isolation, option normalization, output lines). Cause: missed by the spec review, caught by CodeRabbit in PR #31 (ENTSO-E unit isolation, scoped `contracts:fetch` exit code, `--dataset` dedupe).
