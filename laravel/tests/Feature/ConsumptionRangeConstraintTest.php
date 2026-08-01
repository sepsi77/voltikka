<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\ElectricityContract;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ConsumptionRangeConstraintTest extends TestCase
{
    use RefreshDatabase;

    public function test_invalid_consumption_ranges_cannot_be_inserted(): void
    {
        $valid = $this->contract('insert-template');

        foreach ([
            'negative-minimum' => [-1, null],
            'negative-maximum' => [null, -1],
            'inverted-range' => [5001, 5000],
        ] as $id => [$minimum, $maximum]) {
            $attributes = (array) DB::table('electricity_contracts')->where('id', $valid->id)->first();
            $attributes['id'] = $id;
            $attributes['api_id'] = (string) Str::uuid();
            $attributes['name'] = $id;
            $attributes['name_slug'] = $id;
            $attributes['consumption_limitation_min_x_kwh_per_y'] = $minimum;
            $attributes['consumption_limitation_max_x_kwh_per_y'] = $maximum;

            $this->assertConstraintFailure(fn () => DB::table('electricity_contracts')->insert($attributes));
            $this->assertDatabaseMissing('electricity_contracts', ['id' => $id]);
        }
    }

    public function test_invalid_consumption_ranges_cannot_be_created_by_updates(): void
    {
        $negativeMinimum = $this->contract('update-negative-minimum');
        $negativeMaximum = $this->contract('update-negative-maximum');
        $inverted = $this->contract('update-inverted', 1000, 5000);

        $this->assertConstraintFailure(fn () => DB::table('electricity_contracts')
            ->where('id', $negativeMinimum->id)
            ->update(['consumption_limitation_min_x_kwh_per_y' => -1]));
        $this->assertConstraintFailure(fn () => DB::table('electricity_contracts')
            ->where('id', $negativeMaximum->id)
            ->update(['consumption_limitation_max_x_kwh_per_y' => -1]));
        $this->assertConstraintFailure(fn () => DB::table('electricity_contracts')
            ->where('id', $inverted->id)
            ->update(['consumption_limitation_min_x_kwh_per_y' => 5001]));

        $this->assertNull($negativeMinimum->fresh()->consumption_limitation_min_x_kwh_per_y);
        $this->assertNull($negativeMaximum->fresh()->consumption_limitation_max_x_kwh_per_y);
        $this->assertSame(1000.0, $inverted->fresh()->consumption_limitation_min_x_kwh_per_y);
    }

    public function test_null_zero_equal_and_ordered_consumption_ranges_are_valid_for_inserts_and_updates(): void
    {
        $unbounded = $this->contract('valid-null');
        $zero = $this->contract('valid-zero', 0, 0);
        $equal = $this->contract('valid-equal', 5000, 5000);
        $ordered = $this->contract('valid-ordered', 1000, 5000);

        $this->assertNull($unbounded->consumption_limitation_min_x_kwh_per_y);
        $this->assertSame(0.0, $zero->consumption_limitation_min_x_kwh_per_y);
        $this->assertSame(5000.0, $equal->consumption_limitation_max_x_kwh_per_y);
        $this->assertSame(1000.0, $ordered->consumption_limitation_min_x_kwh_per_y);

        $unbounded->update([
            'consumption_limitation_min_x_kwh_per_y' => 0,
            'consumption_limitation_max_x_kwh_per_y' => null,
        ]);
        $zero->update([
            'consumption_limitation_min_x_kwh_per_y' => null,
            'consumption_limitation_max_x_kwh_per_y' => 0,
        ]);
        $equal->update([
            'consumption_limitation_min_x_kwh_per_y' => 1000,
            'consumption_limitation_max_x_kwh_per_y' => 5000,
        ]);
        $ordered->update([
            'consumption_limitation_min_x_kwh_per_y' => 2500,
            'consumption_limitation_max_x_kwh_per_y' => 2500,
        ]);

        $this->assertSame(0.0, $unbounded->fresh()->consumption_limitation_min_x_kwh_per_y);
        $this->assertSame(0.0, $zero->fresh()->consumption_limitation_max_x_kwh_per_y);
        $this->assertSame(5000.0, $equal->fresh()->consumption_limitation_max_x_kwh_per_y);
        $this->assertSame(2500.0, $ordered->fresh()->consumption_limitation_min_x_kwh_per_y);
    }

    public function test_migration_preflight_rejects_existing_invalid_ranges_before_ddl(): void
    {
        $migration = $this->migration();
        $migration->down();
        $contract = $this->contract('invalid-before-ddl');
        DB::table('electricity_contracts')->where('id', $contract->id)->update([
            'consumption_limitation_min_x_kwh_per_y' => -1,
        ]);

        try {
            $migration->up();
            $this->fail('The migration must reject an existing invalid range.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('invalid-before-ddl', $exception->getMessage());
            $this->assertStringContainsString('negative or inverted range', $exception->getMessage());
        }

        $this->assertSame(0, DB::table('sqlite_master')
            ->where('type', 'trigger')
            ->whereIn('name', [
                'electricity_contracts_consumption_range_insert',
                'electricity_contracts_consumption_range_update',
            ])
            ->count());
    }

    private function contract(string $id, float|int|null $minimum = null, float|int|null $maximum = null): ElectricityContract
    {
        $company = Company::firstOrCreate(
            ['name' => 'Constraint Energy'],
            ['name_slug' => 'constraint-energy'],
        );

        return ElectricityContract::factory()
            ->forCompany($company)
            ->create([
                'id' => $id,
                'api_id' => $id.'-api',
                'name' => $id,
                'consumption_limitation_min_x_kwh_per_y' => $minimum,
                'consumption_limitation_max_x_kwh_per_y' => $maximum,
            ]);
    }

    private function assertConstraintFailure(callable $operation): void
    {
        try {
            $operation();
            $this->fail('The database must reject the invalid consumption range.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('consumption range is invalid', $exception->getMessage());
        }
    }

    private function migration(): object
    {
        return require database_path('migrations/2026_07_31_000001_enforce_electricity_contract_consumption_ranges.php');
    }
}
