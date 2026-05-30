# Decisions

- User explicitly confirmed production mutations for creating a Railway bucket and other backup setup changes.
- Start with Railway Object Storage despite same-provider risk; add off-provider redundancy later.
- Created Railway Object Storage bucket `voltikka-backups` in `ams` with ID `460e1b25-73fc-45e3-a43a-0473d2d2b86d`.
- Set app service backup/S3 variables with `--skip-deploys` so production code is not restarted before code changes are deployed.
- Scheduled database-only backups at 03:00 Europe/Helsinki, before existing overnight imports.
- Added `default-mysql-client` to the production Docker image because Spatie requires `mysqldump` for MySQL backups.
- Local S3 reachability check succeeded via `php artisan backup:list` with Railway bucket credentials; it reports unhealthy only because no backups exist yet.
- Full `php artisan test` was run before commit; unrelated pre-existing feature test failures remain in CompanyDetail, ConsumptionCalculator, SEO city count, and SpotPrice UI assertions. Backup-specific config/schedule validation passed.
- First Railway build failed because `spatie/laravel-backup` requires PHP `ext-zip`; fixed Dockerfile by installing `libzip-dev` and enabling `zip`.
- Added `scripts/railway-poll-deployment.sh` so future Railway deployment polling exits on terminal status instead of polling forever.
- Root AGENTS.md now points agents to `scripts/railway-poll-deployment.sh` for bounded deployment polling.
- Manual production `backup:run --only-db` over Railway SSH reached the container but failed because `mysqldump` rejected Railway MySQL TLS chain; configured Spatie dump `skip_ssl`.
- Railway image uses a mysqldump variant that rejected `ssl-mode=DISABLED`; pinned Spatie `ssl_flag` to legacy `skip-ssl`.
