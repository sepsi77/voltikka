<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLE = 'contract_price_annual_costs';

    private const INDEXES = [
        'contract_annual_costs_method_basis_date_idx' => ['method_version', 'pricing_basis', 'snapshot_date'],
        'contract_annual_costs_method_basis_updated_idx' => ['method_version', 'pricing_basis', 'updated_at'],
    ];

    public function up(): void
    {
        $this->changeIndexes(add: true);
    }

    public function down(): void
    {
        $this->changeIndexes(add: false);
    }

    private function changeIndexes(bool $add): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            throw new RuntimeException('Required table contract_price_annual_costs is missing.');
        }

        $indexes = array_filter(
            self::INDEXES,
            fn (string $name): bool => Schema::hasIndex(self::TABLE, $name) !== $add,
            ARRAY_FILTER_USE_KEY,
        );

        if ($indexes === []) {
            return;
        }

        $connection = DB::connection();
        if ($connection->getDriverName() === 'mysql') {
            $clauses = [];
            foreach ($indexes as $name => $columns) {
                $clauses[] = $add
                    ? 'ADD INDEX `'.$name.'` (`'.implode('`, `', $columns).'`)'
                    : 'DROP INDEX `'.$name.'`';
            }

            $timeout = (int) $connection->selectOne('SELECT @@SESSION.lock_wait_timeout AS timeout')->timeout;
            try {
                $connection->statement('SET SESSION lock_wait_timeout = 5');
                // One online build; unsupported online DDL must fail, not copy the table.
                $connection->statement(
                    'ALTER TABLE `'.self::TABLE.'` '.implode(', ', $clauses).', ALGORITHM=INPLACE, LOCK=NONE'
                );
            } finally {
                $connection->statement('SET SESSION lock_wait_timeout = '.$timeout);
            }

            return;
        }

        Schema::table(self::TABLE, function (Blueprint $table) use ($indexes, $add): void {
            foreach ($indexes as $name => $columns) {
                if ($add) {
                    $table->index($columns, $name);
                } else {
                    $table->dropIndex($name);
                }
            }
        });
    }
};
