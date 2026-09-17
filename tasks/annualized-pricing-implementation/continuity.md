# Progress: shared lineage foundation complete

## Interface for the next stage

`ElectricityContract::getReplacementLineageIdsByContractIds(array $contractIds): array`

Supply string contract IDs. Each requested ID maps to an `Illuminate\Support\Collection<int, string>` with the existing root and every trusted predecessor. Missing roots map to empty collections. Duplicate requests collapse; numeric string keys use PHP array rules. Order is unspecified. Empty input makes no query. Non-empty input makes one parameterized recursive UNION query over root/id pairs. No cache or graph write was added.

The single-root wrapper, backward model chains (excluding self), raw lineage components and history presenter now share this resolver. Retail-premium identity still uses its unchanged single-root caller. Forward resolution and redirects are unchanged.

## Verification

- Initial lineage/history run: 9 tests, 103 assertions passed.
- Retail-premium collection/inferred/backfill run: 35 tests, 205 assertions passed.
- Final combined run after explicit identity assertions: 44 tests, 310 assertions passed.
- `git diff --check` passed. Both edited context directories retain `CLAUDE.md -> AGENTS.md` symlinks.

The former presenter depth-cutoff test now expects all 28 versions. Cycles terminate without duplicate history; backward models exclude self. Tests cover isolated IDs, chains, converging branches, mixed/duplicate/missing roots, query count and parameter binding. No MySQL execution or production access was performed; local tests use SQLite.

## Remaining work

The other three task stages remain pending in this unit's tracking. The manager owns root and CanonicalPricing context integration. Existing uncommitted policy files remain unchanged by this unit. A new untracked `CanonicalPricing/Enums/ComparisonPolicy.php` appeared during final review; this is concurrent work and was not edited or verified here.

No production release, data writer, migration, dependency, commit or push was added.
