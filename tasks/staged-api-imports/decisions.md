# Decisions

## 2026-11-19 – Task created

User decisions (2026-11-19):

- Every import from an external API saves the response as JSON first and writes the database from that file.
- The two steps are separate Temporal activities.
- Past failed imports are not recovered, and a payload is not replayed for a past date.

## 2026-11-19 – Incident record: the morning imports of 11-17 and 11-19 (Helsinki times)

**2026-11-17.**
- The 06:00 `contracts:fetch` import succeeded, but its completion step failed at 06:10:23 with `price_cache_refresh` (`LockTimeoutException`).
- Price-cache maintenance (`contracts:warm-cache --pending`) held the shared producer lock from 06:10:00 to 06:10:47. The completion step waits 10 s for that lock, and a lock timeout is a terminal failure.
- Retail premiums and the forecast waited until noon and did not run.
- The 11-18 retail run collected the 7 price periods first seen on 11-17. The 11-17 forecast is missing. A dry run of `forecasting:run-fixed-contracts --as-of=2026-11-17` produces it, and the same dry run for 11-18 matched the 9 stored 11-18 forecasts exactly.
- On 11-18 the same collision happened at 06:05 and 06:10, but the maintenance job lost both times.

**2026-11-19.**
- `contracts:fetch` failed at 06:00:27 with a `QueryException`. The safe failure report kept only the class, and MySQL keeps no error record (no deadlock, `performance_schema` off).
- The recovery chain (`morning-chain-recovery-2026-11-19`, the old chain terminated with approval) failed the same way at 07:57.
- A diagnostic run of `ContractImporter::import()` inside an outer transaction that was always rolled back gave `SQLSTATE 22001 / 1406 Data too long for column 'product_link'`. Helen sent three 455-character product links; the column was `varchar(255)`.
- PR #28 changed `order_link` and `product_link` to `text`. It was merged without the CodeRabbit round (user decision) and deployed as `deploy-000029-e9172189e356` to the web role, whose entrypoint ran the migration.
- Price-cache maintenance was paused during the completion step, so the 11-17 collision could not repeat. A standalone `ContractImport` workflow completed at 08:20, and maintenance was resumed.
- The recovery chain's EEX step ended at 09:39. Retail (117 rows) and the forecast (9 rows) completed at 09:39.
- The 07:57 payload is saved in `storage/app/contract-payloads/` on the VPS volume.

## Raw bodies, parsed in the store step

The payload keeps each response body verbatim (XML and CSV as strings) inside the JSON envelope. The alternative was to save the parsed rows. Raw bodies make a parser fault replayable as well as a database fault, and they show exactly what the source sent.

## Both containers share the payload directory

On the VPS, the web and worker containers both mount the `voltikka-storage` volume on `/app/storage` (checked 2026-11-19 with `docker inspect`). A fetch activity and its store activity run in the same worker, and the web role can read the files for diagnosis.

## Open decisions (manager recommendation, user to confirm)

1. **Retention.** Recommendation: 14 days on the volume, no GCS copy. The files serve same-day replay and diagnosis only (no past-date recovery). The database backups hold the stored result. Measured size today: the 11-19 contract payload is 2.6 MB uncompressed. The other sources are smaller per run, except the Open-Meteo ensemble and seasonal sources, which must be measured in the first implementation step.
2. **Contract interpretation (OpenRouter).** Recommendation: out of scope. Its input is the stored, immutable source snapshot, so a failed write costs one more model call, not data. The queue retries the job (`--tries=3`).
