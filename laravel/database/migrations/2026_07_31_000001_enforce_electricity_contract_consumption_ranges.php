<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const CHECK_NAME = 'electricity_contracts_consumption_range_check';

    private const INSERT_TRIGGER_NAME = 'electricity_contracts_consumption_range_insert';

    private const UPDATE_TRIGGER_NAME = 'electricity_contracts_consumption_range_update';

    private const MIN_COLUMN = 'consumption_limitation_min_x_kwh_per_y';

    private const MAX_COLUMN = 'consumption_limitation_max_x_kwh_per_y';

    public function up(): void
    {
        $driver = DB::connection()->getDriverName();

        if (! in_array($driver, ['mysql', 'sqlite'], true)) {
            throw new RuntimeException("Consumption range constraints do not support the [{$driver}] database driver.");
        }

        $this->assertExistingRangesAreValid();

        if ($driver === 'mysql') {
            DB::statement(sprintf(
                'ALTER TABLE `electricity_contracts` ADD CONSTRAINT `%s` CHECK ((`%s` IS NULL OR `%s` >= 0) AND (`%s` IS NULL OR `%s` >= 0) AND (`%s` IS NULL OR `%s` IS NULL OR `%s` <= `%s`))',
                self::CHECK_NAME,
                self::MIN_COLUMN,
                self::MIN_COLUMN,
                self::MAX_COLUMN,
                self::MAX_COLUMN,
                self::MIN_COLUMN,
                self::MAX_COLUMN,
                self::MIN_COLUMN,
                self::MAX_COLUMN,
            ));

            return;
        }

        $invalidCondition = sprintf(
            'NEW.%s < 0 OR NEW.%s < 0 OR NEW.%s > NEW.%s',
            self::MIN_COLUMN,
            self::MAX_COLUMN,
            self::MIN_COLUMN,
            self::MAX_COLUMN,
        );

        DB::statement(sprintf(
            "CREATE TRIGGER %s BEFORE INSERT ON electricity_contracts WHEN %s BEGIN SELECT RAISE(ABORT, 'Electricity contract consumption range is invalid.'); END",
            self::INSERT_TRIGGER_NAME,
            $invalidCondition,
        ));
        DB::statement(sprintf(
            "CREATE TRIGGER %s BEFORE UPDATE ON electricity_contracts WHEN %s BEGIN SELECT RAISE(ABORT, 'Electricity contract consumption range is invalid.'); END",
            self::UPDATE_TRIGGER_NAME,
            $invalidCondition,
        ));
    }

    public function down(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'mysql') {
            DB::statement(sprintf(
                'ALTER TABLE `electricity_contracts` DROP CHECK `%s`',
                self::CHECK_NAME,
            ));

            return;
        }

        if ($driver === 'sqlite') {
            DB::statement('DROP TRIGGER IF EXISTS '.self::INSERT_TRIGGER_NAME);
            DB::statement('DROP TRIGGER IF EXISTS '.self::UPDATE_TRIGGER_NAME);

            return;
        }

        throw new RuntimeException("Consumption range constraints do not support the [{$driver}] database driver.");
    }

    private function assertExistingRangesAreValid(): void
    {
        $invalidContractId = DB::table('electricity_contracts')
            ->where(function ($query): void {
                $query->where(self::MIN_COLUMN, '<', 0)
                    ->orWhere(self::MAX_COLUMN, '<', 0)
                    ->orWhere(function ($query): void {
                        $query->whereNotNull(self::MIN_COLUMN)
                            ->whereNotNull(self::MAX_COLUMN)
                            ->whereColumn(self::MIN_COLUMN, '>', self::MAX_COLUMN);
                    });
            })
            ->orderBy('id')
            ->value('id');

        if ($invalidContractId !== null) {
            throw new RuntimeException("Cannot enforce electricity contract consumption ranges: contract [{$invalidContractId}] has a negative or inverted range.");
        }
    }
};
