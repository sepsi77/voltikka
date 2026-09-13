# Supplier premium coverage

Collect read-only production evidence for 2026-04-08 through 2026-09-13. Export FI Base futures, 6/12/24-month energy unit statistics, all stored fixed-contract forecasts by forecast date, selected nonannual fixed-term snapshot fields, and current-pair fixed-term term_strip premium evidence. Preserve full premium metadata, VAT and quality fields.

Use Composer autoload only and ProductionMySqlConnection, one read-only consistent transaction, rollback, count-first queries, 100000-row sentinels, 100 MiB total data limit and query timeouts. Never print credentials or exception details. No local database replacement, application changes, model implementation, or deployment.

Report supplier x term dated snapshot coverage separately from semantic price-period evidence. Use period metadata or dated snapshots for duration, never current contract attributes. Exclude method seams from independent periods. State VAT, quality, chronology, variant and validation limits. Keep exact SQL, counts and hashes for later validation.
