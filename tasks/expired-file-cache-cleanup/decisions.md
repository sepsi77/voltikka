# Decisions

- This change does not restore broad import cache flushing. Tracked generation cleanup stays unchanged.
- Default command is a read-only full scan; --apply enables truncation. --scheduled scans one first-level hash shard per epoch minute (256-minute cycle). Rotate the second-level starting shard each cycle to avoid a repeatedly busy leaf blocking later leaves. Manual full scans provide catch-up. A runtime limit reports incomplete work as failure.
- Schedule runs on each instance, not onOneServer: file storage is local. File locks make concurrent scans safe.
- Read only the ten-byte expiration and bounded serialization type header. This can reject malformed headers, not validate arbitrary serialized bodies without reading them. Do not deserialize data.
- Keep the inode and zero-byte marker. Unlink can strand a waiting Laravel put/add writer on an unlinked inode and lose its new value. No directory or marker removal.
- Command: `cache:reclaim-expired-files [--apply] [--scheduled] [--max-seconds=30]`. Runtime budget accepts 1–3600 seconds. The scheduler uses `--apply --scheduled`, every minute, withoutOverlapping(2), no one-server mutex. Counts use logical file sizes, not allocated disk blocks.
- Safety tests cover a held exclusive lock, an already-open writer retaining its inode across cleanup, and actual FileStore put/add after truncation. The test models the waiting-writer ordering without a fork or a timing-dependent process test.
- Verification: `php artisan test --filter=ExpiredFileCacheCleanupTest` passed initially: 6 tests, 47 assertions. After safe-error coverage, `php artisan test --filter='ExpiredFileCacheCleanupTest|ContractPriceCacheLifecycleTest'` passed: 22 tests, 134 assertions. Targeted `vendor/bin/pint --test` passed for both new PHP classes and the new test.
- Remaining limitation for review: the requested header-only read cannot prove an arbitrary serialized body is valid. Malformed expiration/type headers are preserved; a corrupt body with an otherwise valid array/object header can qualify. Full serialized-body validation would require changing that read constraint. Custom `C:` serialized objects are conservatively retained.
- Final `git diff --check` passed. Reviewed the final schedule diff and working-tree status; only the assigned implementation, tests, context, and task files changed. Context CLAUDE.md files are existing symlinks to AGENTS.md.
- The implementation phase ran no production operations, commits, or pushes.

## Approved local release preparation

- The user subsequently approved commit and deployment preparation. This unit is limited to local checks and one local commit. The manager owns exact push confirmation and deployment; no push or production operation is permitted here.
- Initial branch was `main`; only the intended cleanup files were changed. Created `chore/expired-file-cache-cleanup` before the local commit, as required by the agent rules.
- Release check: `cd laravel && php artisan test --filter='ExpiredFileCacheCleanupTest|ContractPriceCacheLifecycleTest'` passed: 22 tests, 134 assertions.
- Release check: `cd laravel && npm run build` passed (Vite 6.4.1, 60 modules). Browserslist reported that caniuse-lite data is nine months old; no dependency was changed. Generated `public/build` assets are ignored and are not staged.
- Release checks: `git diff --check` passed. `cd laravel && vendor/bin/pint --test app/Console/Commands/CleanupExpiredFileCache.php app/Services/Caching/ExpiredFileCacheCleanup.php tests/Feature/ExpiredFileCacheCleanupTest.php` passed.
- Reviewed the intended code and context diff. The local commit contains only the specified cleanup code, tests, schedule, context, and task files. Production release and manual production apply remain outside this local unit.
