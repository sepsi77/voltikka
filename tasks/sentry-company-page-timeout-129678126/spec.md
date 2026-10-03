# Company-page timeout 129678126

## Goal
Determine whether the 2026-10-03 04:02:31 UTC production GET timeout on `/sahkosopimus/sahkoyhtiot/turku-energia-oy` is caused by annual-price calculation or stored-statistics queries.

## Scope
The original investigation was read-only. The user then requested a tracked migration, not direct SSH index creation. Add only two nonunique annual-cost fingerprint indexes on exact method/basis plus snapshot date or update time. Preserve all rows, existing keys, queries, financial history, flags and caches. Use named retry guards; fail if the required table is missing. MySQL must use one online ALTER for missing indexes, with ALGORITHM=INPLACE, LOCK=NONE and a five-second session metadata lock timeout restored in finally. SQLite uses Schema operations.

Implementation and tests are local only. Do not change local database.sqlite. The user has now authorised commit, push and deployment, but the executor must not perform them. Release status: awaiting manager gates and release actions. Verify the actual deployment, named index definitions and production EXPLAIN after release. Do not run a historical rebuild or cache flush.
