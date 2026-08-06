<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\ContractPriceAnnualCost;
use App\Models\ContractPriceDailyStatistic;
use App\Models\ElectricityContract;
use App\Services\ContractStatistics\AsOfAnnualCostCalculator;
use App\Services\ContractStatistics\Enums\AnnualCostMethodVersion;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class RebuildAnnualCostStatisticsCommandTest extends TestCase
{
    use RefreshDatabase;

    private const DATE = '2026-06-01';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-06-05 09:00:00 Europe/Helsinki');
        Company::create(['name' => 'Rebuild Energy Oy', 'name_slug' => 'rebuild-energy-oy']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_dry_run_writes_nothing_and_apply_is_idempotent(): void
    {
        foreach (['contract-c', 'contract-a', 'contract-b'] as $id) {
            $this->evidence($id, 8.0);
        }

        $this->artisan('contracts:rebuild-annual-cost-statistics', ['--date' => self::DATE])
            ->expectsOutputToContain('Dry run:')
            ->assertSuccessful();
        $this->assertSame(0, ContractPriceAnnualCost::count());
        $this->assertSame(0, ContractPriceDailyStatistic::annualCostByMethod(AnnualCostMethodVersion::AsOf)->count());

        $this->artisan('contracts:rebuild-annual-cost-statistics', ['--date' => self::DATE, '--apply' => true])
            ->expectsOutputToContain('persisted=9')
            ->assertSuccessful();
        $this->assertSame(9, ContractPriceAnnualCost::count());
        $this->assertSame(3, ContractPriceDailyStatistic::annualCostByMethod(AnnualCostMethodVersion::AsOf)->count());

        $first = ContractPriceAnnualCost::query()->orderBy('contract_id')->orderBy('consumption_kwh')->get()->map->only([
            'contract_id', 'consumption_kwh', 'annual_cost', 'compatibility_key',
        ])->all();
        $this->artisan('contracts:rebuild-annual-cost-statistics', ['--date' => self::DATE, '--apply' => true])
            ->assertSuccessful();
        $second = ContractPriceAnnualCost::query()->orderBy('contract_id')->orderBy('consumption_kwh')->get()->map->only([
            'contract_id', 'consumption_kwh', 'annual_cost', 'compatibility_key',
        ])->all();
        $this->assertSame($first, $second);
    }

    public function test_component_only_date_is_selected_from_the_union_and_previewed_as_three_exclusions(): void
    {
        $contract = ElectricityContract::factory()->forCompany('Rebuild Energy Oy')->legacy()->create([
            'id' => 'component-only-date',
        ]);
        DB::table('price_components')->insert([
            'id' => 'component-only-date-general',
            'price_date' => self::DATE,
            'price_component_type' => 'General',
            'electricity_contract_id' => $contract->id,
            'has_discount' => false,
            'price' => 8.0,
            'payment_unit' => 'CentPerKiloWattHour',
        ]);

        $this->artisan('contracts:rebuild-annual-cost-statistics', ['--date' => self::DATE])
            ->expectsOutputToContain('evidence=3 available=0 unavailable=3')
            ->expectsOutputToContain('missing_historical_snapshot_identity=3')
            ->assertSuccessful();
    }

    public function test_contract_filter_and_limit_are_deterministic_in_dry_run(): void
    {
        foreach (['contract-c', 'contract-a', 'contract-b'] as $id) {
            $this->evidence($id, 8.0);
        }

        $this->artisan('contracts:rebuild-annual-cost-statistics', [
            '--date' => self::DATE,
            '--contract' => ['contract-c', 'contract-b'],
            '--limit' => 1,
        ])
            ->expectsOutputToContain('evidence=3')
            ->assertSuccessful();
        $this->assertSame(0, ContractPriceAnnualCost::count());
    }

    public function test_apply_rejects_contract_and_limit_filters(): void
    {
        $this->evidence('contract-a', 8.0);

        foreach ([
            ['--contract' => ['contract-a']],
            ['--limit' => 1],
        ] as $options) {
            $this->artisan('contracts:rebuild-annual-cost-statistics', [
                '--date' => self::DATE,
                '--apply' => true,
                ...$options,
            ])
                ->expectsOutputToContain('dry-run diagnostics')
                ->assertFailed();
        }

        $this->assertSame(0, ContractPriceAnnualCost::count());
    }

    public function test_apply_filters_are_rejected_before_an_empty_date_selection_can_return_success(): void
    {
        foreach ([
            ['--contract' => ['missing-contract']],
            ['--limit' => 1],
        ] as $options) {
            $this->artisan('contracts:rebuild-annual-cost-statistics', [
                '--date' => self::DATE,
                '--apply' => true,
                ...$options,
            ])
                ->expectsOutputToContain('dry-run diagnostics')
                ->assertFailed();
        }

        $this->assertSame(0, ContractPriceAnnualCost::count());
        $this->assertSame(0, ContractPriceDailyStatistic::count());
    }

    public function test_apply_rejects_today(): void
    {
        $this->artisan('contracts:rebuild-annual-cost-statistics', [
            '--date' => '2026-06-05',
            '--apply' => true,
        ])
            ->expectsOutputToContain('only dates before today')
            ->assertFailed();
    }

    public function test_failed_date_returns_failure_and_writes_nothing(): void
    {
        $this->evidence('contract-failure', 8.0);
        $this->mock(AsOfAnnualCostCalculator::class, function ($mock): void {
            $mock->shouldReceive('calculate')->once()->andThrow(new RuntimeException('test date failure'));
        });

        $this->artisan('contracts:rebuild-annual-cost-statistics', ['--date' => self::DATE, '--apply' => true])
            ->expectsOutputToContain('failed')
            ->assertFailed();
        $this->assertSame(0, ContractPriceAnnualCost::count());
        $this->assertSame(0, ContractPriceDailyStatistic::annualCostByMethod(AnnualCostMethodVersion::AsOf)->count());
    }

    private function evidence(string $id, float $price): ElectricityContract
    {
        $contract = ElectricityContract::factory()->forCompany('Rebuild Energy Oy')->legacy()->create([
            'id' => $id,
            'name' => $id,
        ]);
        DB::table('contract_price_snapshots')->insert([
            'snapshot_date' => self::DATE,
            'contract_id' => $id,
            'company_name' => $contract->company_name,
            'contract_name' => $contract->name,
            'pricing_model' => 'FixedPrice',
            'contract_type' => 'OpenEnded',
            'metering' => 'General',
            'segment_key' => 'open_ended',
            'pricing_basis' => 'observed_seller_data',
            'energy_price_cents_per_kwh' => $price,
            'annual_cost_2000_kwh' => 1.0,
            'annual_cost_5000_kwh' => 1.0,
            'annual_cost_18000_kwh' => 1.0,
            'has_discount' => false,
            'includes_spot_price' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('price_components')->insert([
            'id' => $id.'-general',
            'price_date' => self::DATE,
            'price_component_type' => 'General',
            'electricity_contract_id' => $id,
            'has_discount' => false,
            'price' => $price,
            'payment_unit' => 'CentPerKiloWattHour',
        ]);

        return $contract;
    }
}
