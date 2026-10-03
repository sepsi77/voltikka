<?php

namespace Tests\Unit;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class ContractAnnualCostFingerprintMysqlMigrationTest extends TestCase
{
    #[DataProvider('ddlCases')]
    public function test_mysql_uses_one_online_alter_and_restores_session_timeout(
        bool $add,
        bool $dateExists,
        bool $updatedExists,
        string $clauses,
        bool $fail,
    ): void {
        Schema::shouldReceive('hasTable')->once()->with('contract_price_annual_costs')->andReturn(true);
        Schema::shouldReceive('hasIndex')->once()
            ->with('contract_price_annual_costs', 'contract_annual_costs_method_basis_date_idx')->andReturn($dateExists);
        Schema::shouldReceive('hasIndex')->once()
            ->with('contract_price_annual_costs', 'contract_annual_costs_method_basis_updated_idx')->andReturn($updatedExists);
        $connection = Mockery::mock(Connection::class);
        DB::shouldReceive('connection')->once()->andReturn($connection);
        $connection->shouldReceive('getDriverName')->once()->andReturn('mysql');
        $connection->shouldReceive('selectOne')->once()->ordered()
            ->with('SELECT @@SESSION.lock_wait_timeout AS timeout')->andReturn((object) ['timeout' => 37]);
        $connection->shouldReceive('statement')->once()->ordered()->with('SET SESSION lock_wait_timeout = 5')->andReturn(true);
        $ddl = $connection->shouldReceive('statement')->once()->ordered()
            ->with('ALTER TABLE `contract_price_annual_costs` '.$clauses.', ALGORITHM=INPLACE, LOCK=NONE');
        if ($fail) {
            $ddl->andThrow(new RuntimeException('Online DDL failed.'));
        } else {
            $ddl->andReturn(true);
        }
        $connection->shouldReceive('statement')->once()->ordered()->with('SET SESSION lock_wait_timeout = 37')->andReturn(true);

        $migration = require database_path('migrations/2026_10_03_000001_add_fingerprint_indexes_to_contract_price_annual_costs.php');
        if ($fail) {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('Online DDL failed.');
        }
        $add ? $migration->up() : $migration->down();
        if (! $fail) {
            $this->addToAssertionCount(1);
        }
    }

    public static function ddlCases(): array
    {
        $date = 'ADD INDEX `contract_annual_costs_method_basis_date_idx` (`method_version`, `pricing_basis`, `snapshot_date`)';
        $updated = 'ADD INDEX `contract_annual_costs_method_basis_updated_idx` (`method_version`, `pricing_basis`, `updated_at`)';
        $dropDate = 'DROP INDEX `contract_annual_costs_method_basis_date_idx`';
        $dropUpdated = 'DROP INDEX `contract_annual_costs_method_basis_updated_idx`';

        return [
            'add both' => [true, false, false, $date.', '.$updated, false],
            'add missing updated' => [true, true, false, $updated, false],
            'add missing date' => [true, false, true, $date, false],
            'drop both' => [false, true, true, $dropDate.', '.$dropUpdated, false],
            'drop remaining date' => [false, true, false, $dropDate, false],
            'drop remaining updated' => [false, false, true, $dropUpdated, false],
            'failed up restores timeout' => [true, false, false, $date.', '.$updated, true],
            'failed down restores timeout' => [false, true, true, $dropDate.', '.$dropUpdated, true],
        ];
    }
}
