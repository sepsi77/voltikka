<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SyncProductionDatabaseCommandTest extends TestCase
{
    /** @var list<string> */
    private array $temporaryFiles = [];

    protected function tearDown(): void
    {
        DB::purge();
        putenv('VOLTIKKA_LOCAL_DATABASE_SYNC');

        foreach ($this->temporaryFiles as $file) {
            @unlink($file);
        }

        parent::tearDown();
    }

    public function test_it_requires_the_explicit_environment_guard(): void
    {
        putenv('VOLTIKKA_LOCAL_DATABASE_SYNC');

        $this->artisan('development:sync-production-database', ['--target' => __FILE__])
            ->expectsOutput('Set VOLTIKKA_LOCAL_DATABASE_SYNC=1 to run this command.')
            ->assertExitCode(1);
    }

    public function test_it_requires_an_explicit_target(): void
    {
        putenv('VOLTIKKA_LOCAL_DATABASE_SYNC=1');

        $this->artisan('development:sync-production-database')
            ->expectsOutput('The --target option is required.')
            ->assertExitCode(1);
    }

    public function test_it_rejects_a_missing_target_before_it_connects_to_production(): void
    {
        putenv('VOLTIKKA_LOCAL_DATABASE_SYNC=1');

        $this->artisan('development:sync-production-database', [
            '--target' => storage_path('missing-production-sync.sqlite'),
        ])
            ->expectsOutput('The --target file must exist and must be writable.')
            ->assertExitCode(1);
    }

    public function test_it_rejects_the_active_local_database_as_a_target(): void
    {
        putenv('VOLTIKKA_LOCAL_DATABASE_SYNC=1');

        $this->artisan('development:sync-production-database', [
            '--target' => database_path('database.sqlite'),
        ])
            ->expectsOutput('The --target file must not be the active local database.')
            ->assertExitCode(1);
    }

    public function test_it_rejects_an_existing_target_outside_the_database_directory(): void
    {
        putenv('VOLTIKKA_LOCAL_DATABASE_SYNC=1');
        $target = storage_path('.production-sync-outside');
        touch($target);
        $this->temporaryFiles[] = $target;

        $this->artisan('development:sync-production-database', ['--target' => $target])
            ->expectsOutput('The --target file must be a .production-sync-* temporary file in the database directory.')
            ->assertExitCode(1);
    }

    public function test_it_rejects_a_target_without_the_wrapper_prefix(): void
    {
        putenv('VOLTIKKA_LOCAL_DATABASE_SYNC=1');
        $target = database_path('unsafe-sync-target.sqlite');
        touch($target);
        $this->temporaryFiles[] = $target;

        $this->artisan('development:sync-production-database', ['--target' => $target])
            ->expectsOutput('The --target file must be a .production-sync-* temporary file in the database directory.')
            ->assertExitCode(1);
    }

    public function test_it_rejects_a_hard_link_to_the_active_database(): void
    {
        putenv('VOLTIKKA_LOCAL_DATABASE_SYNC=1');
        $target = $this->temporaryTarget(create: false);
        $this->assertTrue(link(database_path('database.sqlite'), $target));

        $this->artisan('development:sync-production-database', ['--target' => $target])
            ->expectsOutput('The --target file must not be the active local database.')
            ->assertExitCode(1);
    }

    public function test_verification_proves_the_effective_sqlite_target_without_copying_data(): void
    {
        putenv('VOLTIKKA_LOCAL_DATABASE_SYNC=1');
        $target = $this->temporaryTarget();
        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.url', null);
        config()->set('database.connections.sqlite.database', $target);

        $this->artisan('development:sync-production-database', [
            '--target' => $target,
            '--verify-target' => true,
        ])
            ->expectsOutput('The effective Laravel database target is the explicit temporary SQLite file.')
            ->assertExitCode(0);
    }

    public function test_verification_fails_when_the_effective_path_is_not_the_explicit_target(): void
    {
        putenv('VOLTIKKA_LOCAL_DATABASE_SYNC=1');
        $target = $this->temporaryTarget();
        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.url', null);
        config()->set('database.connections.sqlite.database', ':memory:');

        $this->artisan('development:sync-production-database', [
            '--target' => $target,
            '--verify-target' => true,
        ])
            ->expectsOutput('The effective Laravel database path is not the explicit --target file.')
            ->assertExitCode(1);
    }

    private function temporaryTarget(bool $create = true): string
    {
        $target = database_path('.production-sync-test-'.bin2hex(random_bytes(6)));
        $this->temporaryFiles[] = $target;

        if ($create) {
            touch($target);
        }

        return $target;
    }
}
