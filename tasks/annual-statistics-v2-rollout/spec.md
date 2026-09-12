# Production annual-statistics v2 rollout

## Goal
After the approved code deployment, preview and prepare the historical correction and public v2 activation. Follow `../annual-statistics-correction/rollout.md`.

## Approval boundary
The user confirmed the production code release and confirmed that a full database backup is verified. This is operator confirmation, not agent verification of an archive. The user then requested the historical rollout after deployment. Exact apply ranges, commands, expected changes, and the public method switch still require explicit production-operation approval after the read-only review.

## Target
Railway Breezily / Voltikka / production / voltikka:
- Project: `6d8cae01-1006-409f-8108-1d51f1abc676`
- Environment: `9245cef8-41d0-486e-862f-193726511dba`
- Service: `700d0624-fa96-4266-876c-e37640d220ea`

Preserve v1 annual results and observed seller/market evidence. Do not bypass the historical command's past-date limit or overwrite the last v1 snapshot date without the required reviewed decision.
