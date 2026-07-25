# Decisions

- Treat existing untracked files outside this task folder as user work and do not change them.
- Start with the repository-standard `php artisan test` command from `laravel/`.
- The default 128 MB PHP limit is too small for the 1,148-test single-process suite. Set a test-only 512 MB limit in `phpunit.xml`.
- Force the isolated PHPUnit database, cache, queue, session, mail, logging, and Sentry settings. Local `.env` Sentry tracing and profiling were active during tests and made the memory failure worse.
- Keep current application behavior. Update stale UI/schema assertions and create required municipality data in the pagination test.
- Isolate sitemap command output from `public/sitemap.xml` and restore libxml process state in XML tests.
- Keep the narrow PDO deprecation handler because Laravel 11 loads deprecated vendor database defaults on PHP 8.5. Chain all unrelated deprecations to PHPUnit so they remain visible.
- Final verification: `cd laravel && php artisan test` passed 1,148 tests with 3,475 assertions in 65.24 seconds. Peak resident memory was about 160 MB, which confirms that the former 128 MB limit was not sufficient.
