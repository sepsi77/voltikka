<?php

namespace Tests\Feature;

use App\Services\DevelopmentDatabase\DevelopmentDatabaseSynchronizer;
use Illuminate\Support\Facades\DB;
use PDO;
use Tests\TestCase;

class ProductionSchemaLagDatabaseSyncTest extends TestCase
{
    private string $originalDefaultConnection;

    private ?string $targetPath = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalDefaultConnection = (string) config('database.default');
    }

    protected function tearDown(): void
    {
        DB::purge('production_sync_test');
        config()->set('database.default', $this->originalDefaultConnection);

        if ($this->targetPath !== null) {
            @unlink($this->targetPath);
        }

        parent::tearDown();
    }

    public function test_it_rebuilds_observations_when_only_the_derived_production_table_is_missing(): void
    {
        $source = $this->sqlite('sqlite::memory:');
        $target = $this->temporaryTarget();

        $source->exec('CREATE TABLE electricity_contracts (id TEXT PRIMARY KEY)');
        $source->exec('CREATE TABLE contract_source_snapshots (
            id INTEGER PRIMARY KEY,
            contract_id TEXT NOT NULL,
            first_observed_at TEXT NOT NULL,
            last_observed_at TEXT NOT NULL
        )');
        $source->exec("INSERT INTO electricity_contracts (id) VALUES ('contract-1')");
        $source->exec("INSERT INTO contract_source_snapshots VALUES
            (10, 'contract-1', '2026-07-01 00:00:00', '2026-07-03 00:00:00'),
            (11, 'contract-1', '2026-07-02 00:00:00', '2026-07-04 00:00:00')");

        $target->exec('CREATE TABLE migrations (id INTEGER PRIMARY KEY, migration TEXT NOT NULL)');
        $target->exec('CREATE TABLE electricity_contracts (
            id TEXT PRIMARY KEY,
            current_source_observation_id INTEGER NULL
        )');
        $target->exec('CREATE TABLE contract_source_snapshots (
            id INTEGER PRIMARY KEY,
            contract_id TEXT NOT NULL,
            first_observed_at TEXT NOT NULL,
            last_observed_at TEXT NOT NULL,
            FOREIGN KEY (contract_id) REFERENCES electricity_contracts(id) ON DELETE CASCADE
        )');
        $target->exec('CREATE TABLE contract_source_observations (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            contract_id TEXT NOT NULL,
            source_snapshot_id INTEGER NOT NULL,
            first_observed_at TEXT NOT NULL,
            last_observed_at TEXT NOT NULL,
            FOREIGN KEY (contract_id) REFERENCES electricity_contracts(id) ON DELETE CASCADE,
            FOREIGN KEY (source_snapshot_id) REFERENCES contract_source_snapshots(id) ON DELETE CASCADE
        )');

        $counts = (new DevelopmentDatabaseSynchronizer)->copy($source, $target);

        $this->assertSame([
            'contract_source_observations' => 4,
            'contract_source_snapshots' => 2,
            'electricity_contracts' => 1,
        ], $counts);
        $this->assertSame([
            ['source_snapshot_id' => 10, 'first_observed_at' => '2026-07-01 00:00:00', 'last_observed_at' => '2026-07-01 00:00:00'],
            ['source_snapshot_id' => 11, 'first_observed_at' => '2026-07-02 00:00:00', 'last_observed_at' => '2026-07-02 00:00:00'],
            ['source_snapshot_id' => 10, 'first_observed_at' => '2026-07-03 00:00:00', 'last_observed_at' => '2026-07-03 00:00:00'],
            ['source_snapshot_id' => 11, 'first_observed_at' => '2026-07-04 00:00:00', 'last_observed_at' => '2026-07-04 00:00:00'],
        ], $target->query(
            'SELECT source_snapshot_id, first_observed_at, last_observed_at
             FROM contract_source_observations
             ORDER BY first_observed_at, source_snapshot_id'
        )->fetchAll());
        $this->assertSame('11', (string) $target->query(
            'SELECT observation.source_snapshot_id
             FROM electricity_contracts AS contract
             JOIN contract_source_observations AS observation
               ON observation.id = contract.current_source_observation_id
             WHERE contract.id = \'contract-1\''
        )->fetchColumn());
        $this->assertSame([], $target->query('PRAGMA foreign_key_check')->fetchAll());
        $this->assertSame('ok', $target->query('PRAGMA integrity_check')->fetchColumn());
    }

    private function temporaryTarget(): PDO
    {
        $this->targetPath = database_path('.production-sync-schema-lag-test-'.bin2hex(random_bytes(6)));
        touch($this->targetPath);

        config()->set('database.connections.production_sync_test', [
            'driver' => 'sqlite',
            'url' => null,
            'database' => $this->targetPath,
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
        config()->set('database.default', 'production_sync_test');
        DB::purge('production_sync_test');

        return $this->sqlite('sqlite:'.$this->targetPath);
    }

    private function sqlite(string $dsn): PDO
    {
        return new PDO($dsn, options: [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }
}
