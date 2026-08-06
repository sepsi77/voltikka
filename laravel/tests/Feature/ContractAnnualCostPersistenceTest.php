<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\ContractPriceAnnualCost;
use App\Models\ContractPriceDailyStatistic;
use App\Models\ElectricityContract;
use App\Services\ContractStatistics\Enums\AnnualCostCalculationBasis;
use App\Services\ContractStatistics\Enums\AnnualCostMethodVersion;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class ContractAnnualCostPersistenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_migration_backfills_daily_statistic_method_versions(): void
    {
        $migration = require database_path(
            'migrations/2026_08_06_000001_version_contract_annual_cost_statistics.php'
        );
        $migration->down();

        $now = now();
        DB::table('contract_price_daily_statistics')->insert([
            [
                ...$this->dailyStatisticAttributes('annual_cost', 5000),
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                ...$this->dailyStatisticAttributes('energy_price', null),
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);

        $migration->up();

        $this->assertDatabaseHas('contract_price_daily_statistics', [
            'metric_key' => 'annual_cost',
            'method_version' => AnnualCostMethodVersion::Legacy->value,
        ]);
        $this->assertDatabaseHas('contract_price_daily_statistics', [
            'metric_key' => 'energy_price',
            'method_version' => ContractPriceDailyStatistic::UNIT_STATISTICS_METHOD_VERSION,
        ]);

        $methodColumn = collect(DB::select("PRAGMA table_info('contract_price_daily_statistics')"))
            ->firstWhere('name', 'method_version');
        $this->assertSame(0, $methodColumn->notnull);

        $migration->up();
        $this->assertSame(2, DB::table('contract_price_daily_statistics')->count());
    }

    public function test_migration_reports_nullable_consumption_identity_duplicates_before_replacing_the_key(): void
    {
        $migration = require database_path(
            'migrations/2026_08_06_000001_version_contract_annual_cost_statistics.php'
        );
        $migration->down();
        $now = now();
        $duplicate = [
            ...$this->dailyStatisticAttributes('energy_price', null),
            'created_at' => $now,
            'updated_at' => $now,
        ];
        DB::table('contract_price_daily_statistics')->insert([$duplicate, $duplicate]);

        try {
            $migration->up();
            $this->fail('The migration accepted duplicate nullable daily-statistic identities.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Duplicate legacy contract price daily statistic identities', $exception->getMessage());
            $this->assertStringContainsString('"consumption_kwh":null', $exception->getMessage());
        }

        DB::table('contract_price_daily_statistics')->delete();
        $migration->up();
    }

    public function test_method_key_still_has_the_cross_database_nullable_consumption_limitation(): void
    {
        $now = now();
        $row = [
            ...$this->dailyStatisticAttributes('energy_price', null),
            'method_version' => ContractPriceDailyStatistic::UNIT_STATISTICS_METHOD_VERSION,
            'created_at' => $now,
            'updated_at' => $now,
        ];

        DB::table('contract_price_daily_statistics')->insert([$row, $row]);

        $this->assertSame(2, DB::table('contract_price_daily_statistics')->count());
    }

    public function test_daily_statistics_keep_method_versions_separate_and_cast_basis_counts(): void
    {
        $attributes = $this->dailyStatisticAttributes('annual_cost', 5000);

        $legacy = ContractPriceDailyStatistic::create([
            ...$attributes,
            'method_version' => AnnualCostMethodVersion::Legacy->value,
            'basis_counts' => ['observed_relational_components' => 4],
        ]);
        ContractPriceDailyStatistic::create([
            ...$attributes,
            'method_version' => AnnualCostMethodVersion::AsOf->value,
            'basis_counts' => ['canonical_outcome' => 3],
        ]);
        $unit = ContractPriceDailyStatistic::create([
            ...$this->dailyStatisticAttributes('energy_price', null),
            'basis_counts' => ['observed_seller_data' => 5],
        ]);

        $this->assertSame(
            ['observed_relational_components' => 4],
            $legacy->fresh()->basis_counts,
        );
        $this->assertSame(
            ['observed_seller_data' => 5],
            $unit->fresh()->basis_counts,
        );
        $this->assertSame(
            ContractPriceDailyStatistic::UNIT_STATISTICS_METHOD_VERSION,
            $unit->method_version,
        );
        $this->assertSame(
            1,
            ContractPriceDailyStatistic::annualCostByMethod(AnnualCostMethodVersion::AsOf)->count(),
        );

        config()->set(
            'contract_statistics.annual_cost.active_method_version',
            AnnualCostMethodVersion::Legacy->value,
        );
        $this->assertSame(1, ContractPriceDailyStatistic::activeAnnualMethod()->count());
        $this->assertSame(1, ContractPriceDailyStatistic::unitStatistics()->count());
        $this->assertSame(2, ContractPriceDailyStatistic::activeMetricMethods()->count());

        config()->set(
            'contract_statistics.annual_cost.active_method_version',
            AnnualCostMethodVersion::AsOf->value,
        );
        $this->assertSame(1, ContractPriceDailyStatistic::activeAnnualMethod()->count());
        $this->assertSame(2, ContractPriceDailyStatistic::activeMetricMethods()->count());

        $unit->update(['method_version' => null]);
        $this->assertSame(
            ContractPriceDailyStatistic::UNIT_STATISTICS_METHOD_VERSION,
            $unit->fresh()->method_version,
        );

        try {
            ContractPriceDailyStatistic::create([
                ...$attributes,
                'method_version' => AnnualCostMethodVersion::AsOf->value,
            ]);
            $this->fail('The versioned daily statistic unique key accepted a duplicate row.');
        } catch (QueryException) {
            $this->assertTrue(true);
        }
    }

    public function test_annual_cost_model_casts_values_and_enforces_versioned_uniqueness(): void
    {
        $company = Company::create([
            'name' => 'Persistence Energy Oy',
            'name_slug' => 'persistence-energy-oy',
        ]);
        $contract = ElectricityContract::factory()->forCompany($company)->create([
            'id' => 'persistence-contract',
        ]);

        $attributes = [
            'snapshot_date' => '2026-08-05',
            'contract_id' => $contract->id,
            'segment_key' => 'open_ended',
            'pricing_basis' => 'observed_seller_data',
            'consumption_kwh' => 5000,
            'annual_cost' => 612.3456,
            'calculation_basis' => AnnualCostCalculationBasis::ObservedRelationalComponents,
            'compatibility_key' => 'open_ended:5000:observed',
            'source_observation_id' => 11,
            'source_snapshot_id' => 12,
            'source_interpretation_id' => 13,
            'price_episode_started_at' => '2026-07-01 06:30:00',
            'provenance' => ['price_dates' => ['2026-08-05']],
        ];

        $legacy = ContractPriceAnnualCost::create([
            ...$attributes,
            'method_version' => AnnualCostMethodVersion::Legacy,
        ]);
        ContractPriceAnnualCost::create([
            ...$attributes,
            'method_version' => AnnualCostMethodVersion::AsOf,
            'calculation_basis' => AnnualCostCalculationBasis::CanonicalOutcome,
        ]);

        $legacy = $legacy->fresh();
        $this->assertSame('2026-08-05', $legacy->snapshot_date->toDateString());
        $this->assertSame(612.3456, $legacy->annual_cost);
        $this->assertSame(5000, $legacy->consumption_kwh);
        $this->assertSame(AnnualCostMethodVersion::Legacy, $legacy->method_version);
        $this->assertSame(
            AnnualCostCalculationBasis::ObservedRelationalComponents,
            $legacy->calculation_basis,
        );
        $this->assertSame(['price_dates' => ['2026-08-05']], $legacy->provenance);
        $this->assertSame('2026-07-01 06:30:00', $legacy->price_episode_started_at->format('Y-m-d H:i:s'));
        $this->assertTrue($legacy->contract->is($contract));
        $this->assertSame(2, ContractPriceAnnualCost::count());

        try {
            ContractPriceAnnualCost::create([
                ...$attributes,
                'method_version' => AnnualCostMethodVersion::Legacy,
            ]);
            $this->fail('The annual cost unique key accepted a duplicate method row.');
        } catch (QueryException) {
            $this->assertTrue(true);
        }
    }

    /** @return array<string, mixed> */
    private function dailyStatisticAttributes(string $metricKey, ?int $consumption): array
    {
        return [
            'stat_date' => '2026-08-05',
            'segment_key' => 'open_ended',
            'metric_key' => $metricKey,
            'pricing_basis' => 'observed_seller_data',
            'consumption_kwh' => $consumption,
            'min_value' => 500.0,
            'p20_value' => 520.0,
            'avg_value' => 550.0,
            'median_value' => 545.0,
            'p80_value' => 580.0,
            'max_value' => 600.0,
            'contract_count' => 5,
        ];
    }
}
