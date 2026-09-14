# Production results — 2026-09-14

## Result and evidence source

The final correction deployment, automatic full-import readiness, and initial forecast publication are verified complete. This record contains facts verified by the manager. No application or production calls, commit, or push were made for this documentation-only update. The final documentation remains local.

## Deployment

| Item | Verified value |
|---|---|
| Correction commit | `5414c045615da2748904f5c86b54e7b01a8494a2` |
| Railway deployment | `fcf726f3-76ee-4680-8324-80c43ac3c7d7` |
| Deployment status | `SUCCESS` |
| Project | `6d8cae01-1006-409f-8108-1d51f1abc676` |
| Production environment | `9245cef8-41d0-486e-862f-193726511dba` |
| App service | `700d0624-fa96-4266-876c-e37640d220ea` |

## Approved full import and automatic completion

The approved September 14 `contracts:fetch` succeeded with completion status `DEFERRED`:

- Run UUID: `9d9ac804-fffa-4459-b51d-7a65a7a88590`.
- 405 contracts, 34 companies, 805 price components, and 383 active contracts.
- Read-only monitoring saw the 15 pending/processing targets finish. The final target counts were 401 published and four failed but settled.
- The checkpoint then became `READY` automatically. No manual checkpoint edits were made.
- Monitoring took place after 05:50 UTC. The exact ready time is unknown.
- Two optional company-logo failures remain: Seinäjoen Energia and Vimpelin Voima. They did not block completion.

A settled failed target is not a publication. Old failed production runs were not rewritten. The earlier failed import and its `publication_missing` diagnosis remain recorded in `decisions.md`.

## Initial forecast publication

The September 14 forecast dry run used the freshness gate and a 30-day horizon. All nine forecasts passed. The same command without dry-run mode then saved nine forecasts, with zero skipped, under the user's standing deployment/recovery approval.

- Model: `fixed_term_futures_adjusted_v1`.
- Target date: October 14, 2026.
- Full pairs: 175; futures pairs: 91; current-basis pairs: 19.

| Fixed term | Median price (c/kWh) | Predicted move (c/kWh) |
|---|---:|---:|
| 6 months | 15.6563 | +1.1269 |
| 12 months | 12.3472 | +0.3563 |
| 24 months | 10.7377 | +0.1777 |

## Public checks and limits

Actual HTTP responses were `200` for the Lahti page and the forecast page. The forecast page showed median prices `15,66`, `12,35`, and `10,74`, target date `14.10.2026`, and one uncertainty notice.

These checks do not prove that all Sentry Issues are resolved. Retail-premium checks were not tested, and no retail-premium pass is claimed. The remaining optional logo failures are not part of the completion claim.
