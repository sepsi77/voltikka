<?php

namespace Tests\Unit;

use App\Services\DevelopmentDatabase\ContractSourceObservationRebuilder;
use App\Services\DevelopmentDatabase\DatabaseSyncException;
use App\Services\DevelopmentDatabase\DevelopmentDatabaseSynchronizer;
use PDO;
use PHPUnit\Framework\TestCase;

class DevelopmentDatabaseSynchronizerTest extends TestCase
{
    public function test_it_excludes_all_local_or_operational_tables(): void
    {
        $this->assertSame([
            'users',
            'password_reset_tokens',
            'sessions',
            'cache',
            'cache_locks',
            'jobs',
            'job_batches',
            'failed_jobs',
            'spot_social_publications',
            'data_freshness_checkpoints',
        ], DevelopmentDatabaseSynchronizer::EXCLUDED_TABLES);
    }

    public function test_it_copies_shared_rows_and_keeps_excluded_and_migration_rows(): void
    {
        $source = $this->sqlite();
        $target = $this->sqlite();

        $source->exec('CREATE TABLE products (id INTEGER PRIMARY KEY, name TEXT NOT NULL, source_only TEXT)');
        $source->exec("INSERT INTO products VALUES (1, 'First', 'old'), (2, 'Second', 'old')");
        $source->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, email TEXT NOT NULL)');
        $source->exec("INSERT INTO users VALUES (10, 'production@example.test')");

        $target->exec('CREATE TABLE migrations (id INTEGER PRIMARY KEY, migration TEXT NOT NULL)');
        $target->exec("INSERT INTO migrations VALUES (1, 'create_products')");
        $target->exec("CREATE TABLE products (id INTEGER PRIMARY KEY, name TEXT NOT NULL, local_note TEXT DEFAULT 'local')");
        $target->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, email TEXT NOT NULL)');
        $target->exec("INSERT INTO users VALUES (20, 'local@example.test')");

        $counts = (new DevelopmentDatabaseSynchronizer)->copy($source, $target);

        $this->assertSame(['products' => 2], $counts);
        $this->assertSame([
            ['id' => 1, 'name' => 'First', 'local_note' => 'local'],
            ['id' => 2, 'name' => 'Second', 'local_note' => 'local'],
        ], $target->query('SELECT id, name, local_note FROM products ORDER BY id')->fetchAll());
        $this->assertSame('local@example.test', $target->query('SELECT email FROM users')->fetchColumn());
        $this->assertSame('create_products', $target->query('SELECT migration FROM migrations')->fetchColumn());
    }

    public function test_it_copies_production_observations_normally_when_the_table_exists(): void
    {
        $source = $this->sqlite();
        $target = $this->sqlite();
        $source->exec('CREATE TABLE contract_source_observations (id INTEGER PRIMARY KEY, contract_id TEXT NOT NULL)');
        $source->exec("INSERT INTO contract_source_observations VALUES (1, 'contract-1')");
        $target->exec('CREATE TABLE migrations (id INTEGER PRIMARY KEY, migration TEXT NOT NULL)');
        $target->exec('CREATE TABLE contract_source_observations (id INTEGER PRIMARY KEY, contract_id TEXT NOT NULL)');

        $rebuilder = $this->createMock(ContractSourceObservationRebuilder::class);
        $rebuilder->expects($this->never())->method('rebuild');

        $this->assertSame(
            ['contract_source_observations' => 1],
            (new DevelopmentDatabaseSynchronizer($rebuilder))->copy($source, $target),
        );
        $this->assertSame('contract-1', $target->query(
            'SELECT contract_id FROM contract_source_observations WHERE id = 1'
        )->fetchColumn());
    }

    public function test_it_fails_when_a_production_application_table_is_absent_from_target(): void
    {
        $source = $this->sqlite();
        $target = $this->sqlite();
        $source->exec('CREATE TABLE products (id INTEGER PRIMARY KEY, name TEXT NOT NULL)');
        $source->exec('CREATE TABLE production_only (id INTEGER PRIMARY KEY)');
        $target->exec('CREATE TABLE migrations (id INTEGER PRIMARY KEY, migration TEXT NOT NULL)');
        $target->exec('CREATE TABLE products (id INTEGER PRIMARY KEY, name TEXT NOT NULL)');

        $this->expectException(DatabaseSyncException::class);
        $this->expectExceptionMessage('does not contain production application tables: production_only');

        (new DevelopmentDatabaseSynchronizer)->copy($source, $target);
    }

    public function test_it_ignores_an_excluded_production_table_that_is_absent_from_target(): void
    {
        $source = $this->sqlite();
        $target = $this->sqlite();
        $source->exec('CREATE TABLE products (id INTEGER PRIMARY KEY, name TEXT NOT NULL)');
        $source->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, email TEXT NOT NULL)');
        $target->exec('CREATE TABLE migrations (id INTEGER PRIMARY KEY, migration TEXT NOT NULL)');
        $target->exec('CREATE TABLE products (id INTEGER PRIMARY KEY, name TEXT NOT NULL)');

        $this->assertSame(['products' => 0], (new DevelopmentDatabaseSynchronizer)->copy($source, $target));
    }

    public function test_it_fails_when_a_non_derived_target_table_is_absent_from_source(): void
    {
        $source = $this->sqlite();
        $target = $this->sqlite();
        $target->exec('CREATE TABLE migrations (id INTEGER PRIMARY KEY, migration TEXT NOT NULL)');
        $target->exec('CREATE TABLE contract_source_observations (id INTEGER PRIMARY KEY)');
        $target->exec('CREATE TABLE products (id INTEGER PRIMARY KEY, name TEXT NOT NULL)');

        $this->expectException(DatabaseSyncException::class);
        $this->expectExceptionMessage('does not contain target table [products]');

        (new DevelopmentDatabaseSynchronizer)->copy($source, $target);
    }

    public function test_it_fails_when_source_lacks_a_required_target_column(): void
    {
        $source = $this->sqlite();
        $target = $this->sqlite();
        $source->exec('CREATE TABLE products (id INTEGER PRIMARY KEY)');
        $target->exec('CREATE TABLE migrations (id INTEGER PRIMARY KEY, migration TEXT NOT NULL)');
        $target->exec('CREATE TABLE products (id INTEGER PRIMARY KEY, name TEXT NOT NULL)');

        $this->expectException(DatabaseSyncException::class);
        $this->expectExceptionMessage('required target columns: name');

        (new DevelopmentDatabaseSynchronizer)->copy($source, $target);
    }

    public function test_it_requires_a_migrated_target_schema(): void
    {
        $source = $this->sqlite();
        $target = $this->sqlite();

        $this->expectException(DatabaseSyncException::class);
        $this->expectExceptionMessage('target database is not migrated');

        (new DevelopmentDatabaseSynchronizer)->copy($source, $target);
    }

    public function test_it_detects_a_row_count_change(): void
    {
        $source = $this->sqlite();
        $target = $this->sqlite();
        $source->exec('CREATE TABLE products (id INTEGER PRIMARY KEY, name TEXT NOT NULL)');
        $source->exec("INSERT INTO products VALUES (1, 'First'), (2, 'Second')");
        $target->exec('CREATE TABLE migrations (id INTEGER PRIMARY KEY, migration TEXT NOT NULL)');
        $target->exec('CREATE TABLE products (id INTEGER PRIMARY KEY, name TEXT NOT NULL)');

        $synchronizer = new DevelopmentDatabaseSynchronizer;
        $counts = $synchronizer->copy($source, $target);
        $target->exec('DELETE FROM products WHERE id = 2');

        $this->expectException(DatabaseSyncException::class);
        $this->expectExceptionMessage('source 2, target 1');

        $synchronizer->validateTarget($target, $counts);
    }

    public function test_it_detects_a_foreign_key_error_after_copy(): void
    {
        $source = $this->sqlite();
        $target = $this->sqlite();
        $source->exec('CREATE TABLE parents (id INTEGER PRIMARY KEY)');
        $source->exec('CREATE TABLE children (id INTEGER PRIMARY KEY, parent_id INTEGER NOT NULL)');
        $source->exec('INSERT INTO children VALUES (1, 999)');
        $target->exec('CREATE TABLE migrations (id INTEGER PRIMARY KEY, migration TEXT NOT NULL)');
        $target->exec('CREATE TABLE parents (id INTEGER PRIMARY KEY)');
        $target->exec('CREATE TABLE children (id INTEGER PRIMARY KEY, parent_id INTEGER NOT NULL, FOREIGN KEY (parent_id) REFERENCES parents(id))');

        $this->expectException(DatabaseSyncException::class);
        $this->expectExceptionMessage('Foreign key validation failed for [children]');

        (new DevelopmentDatabaseSynchronizer)->copy($source, $target);
    }

    private function sqlite(): PDO
    {
        return new PDO('sqlite::memory:', options: [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }
}
