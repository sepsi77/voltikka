<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\ElectricityContract;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ContractAnnualCostFingerprintIndexesMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const TABLE = 'contract_price_annual_costs';

    private const INDEXES = [
        'contract_annual_costs_method_basis_date_idx' => ['method_version', 'pricing_basis', 'snapshot_date'],
        'contract_annual_costs_method_basis_updated_idx' => ['method_version', 'pricing_basis', 'updated_at'],
    ];

    public function test_up_and_down_are_repeat_safe_and_preserve_full_rows_and_existing_keys(): void
    {
        $migration = $this->migration();
        $migration->down();
        $keys = Schema::getIndexes(self::TABLE);
        $foreignKeys = Schema::getForeignKeys(self::TABLE);
        $company = Company::create(['name' => 'Fingerprint test company']);
        $contract = ElectricityContract::factory()->forCompany($company)->create();

        foreach (['annual_cost_as_of_v1', 'annual_cost_as_of_v2', 'annual_cost_as_of_v3'] as $version) {
            foreach (['canonical_calculation', 'observed_seller_data'] as $offset => $basis) {
                DB::table(self::TABLE)->insert([
                    'snapshot_date' => '2026-10-0'.($offset + 1),
                    'contract_id' => $contract->id,
                    'segment_key' => 'fixed_term_12',
                    'pricing_basis' => $basis,
                    'consumption_kwh' => 5000,
                    'annual_cost' => '1234.5678',
                    'method_version' => $version,
                    'calculation_basis' => 'as_of',
                    'estimate_method' => 'fixture_method',
                    'estimate_basis' => 'fixture_basis',
                    'compatibility_key' => 'fixture_compatibility',
                    'source_observation_id' => 11,
                    'source_snapshot_id' => 12,
                    'source_interpretation_id' => 13,
                    'price_episode_started_at' => '2026-09-01 01:02:03',
                    'provenance' => '{"snapshot_id":12,"retrospective":true,"amount":"1234.5678"}',
                    'historical_episode_id' => 14,
                    'historical_interpretation_id' => 15,
                    'historical_evidence_grade' => 'exact_source',
                    'created_at' => '2026-09-02 03:04:05',
                    'updated_at' => '2026-10-03 06:07:08',
                ]);
            }
        }
        $rows = DB::table(self::TABLE)->orderBy('id')->get()->toJson();

        $migration->up();
        $migration->up();
        $this->assertFingerprintIndexes();
        $this->assertSame($rows, DB::table(self::TABLE)->orderBy('id')->get()->toJson());
        $this->assertSame($keys, $this->existingKeys());
        $this->assertSame($foreignKeys, Schema::getForeignKeys(self::TABLE));

        $migration->down();
        $migration->down();
        $this->assertSame($keys, Schema::getIndexes(self::TABLE));
        $this->assertSame($foreignKeys, Schema::getForeignKeys(self::TABLE));
        $this->assertSame($rows, DB::table(self::TABLE)->orderBy('id')->get()->toJson());
    }

    #[DataProvider('indexNames')]
    public function test_up_completes_a_partial_index_build(string $existing): void
    {
        $migration = $this->migration();
        $migration->down();
        $keys = Schema::getIndexes(self::TABLE);
        Schema::table(self::TABLE, function (Blueprint $table) use ($existing): void {
            $table->index(self::INDEXES[$existing], $existing);
        });

        $migration->up();
        $migration->up();

        $this->assertFingerprintIndexes();
        $this->assertSame($keys, $this->existingKeys());
    }

    #[DataProvider('indexNames')]
    public function test_down_tolerates_one_missing_index(string $missing): void
    {
        $migration = $this->migration();
        $migration->up();
        $keys = $this->existingKeys();
        Schema::table(self::TABLE, fn (Blueprint $table) => $table->dropIndex($missing));

        $migration->down();
        $migration->down();

        $this->assertSame($keys, Schema::getIndexes(self::TABLE));
    }

    public function test_missing_required_table_fails_instead_of_skipping(): void
    {
        Schema::drop(self::TABLE);

        foreach (['up', 'down'] as $direction) {
            try {
                $this->migration()->{$direction}();
                $this->fail('A missing required table must fail.');
            } catch (\RuntimeException $exception) {
                $this->assertSame('Required table contract_price_annual_costs is missing.', $exception->getMessage());
            }
        }
    }

    public static function indexNames(): array
    {
        return array_map(fn (string $name): array => [$name], array_keys(self::INDEXES));
    }

    private function migration(): Migration
    {
        return require database_path('migrations/2026_10_03_000001_add_fingerprint_indexes_to_contract_price_annual_costs.php');
    }

    private function assertFingerprintIndexes(): void
    {
        $indexes = array_column(Schema::getIndexes(self::TABLE), null, 'name');
        foreach (self::INDEXES as $name => $columns) {
            $this->assertSame($columns, $indexes[$name]['columns']);
            $this->assertFalse($indexes[$name]['unique']);
            $this->assertFalse($indexes[$name]['primary']);
        }
    }

    private function existingKeys(): array
    {
        return array_values(array_filter(
            Schema::getIndexes(self::TABLE),
            fn (array $index): bool => ! array_key_exists($index['name'], self::INDEXES),
        ));
    }
}
