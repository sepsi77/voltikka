<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\ContractPriceAnnualCost;
use App\Models\ContractPriceDailyStatistic;
use App\Models\ElectricityContract;
use App\Models\PriceComponent;
use App\Services\ContractStatistics\ContractPriceBasis;
use App\Services\ContractStatistics\ContractPriceStatisticsService;
use App\Services\ContractStatistics\Enums\AnnualCostCalculationBasis;
use App\Services\ContractStatistics\Enums\AnnualCostMethodVersion;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContractStatisticsMethodIsolationTest extends TestCase
{
    use RefreshDatabase;

    private const DATE = '2026-06-01';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(self::DATE.' 09:00:00 Europe/Helsinki');
        Company::create(['name' => 'Isolation Energy Oy', 'name_slug' => 'isolation-energy-oy']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_current_and_backfill_calculations_preserve_as_of_daily_and_annual_rows(): void
    {
        $contract = ElectricityContract::factory()->forCompany('Isolation Energy Oy')->active()->legacy()->create([
            'id' => 'isolation-contract',
        ]);
        PriceComponent::create([
            'id' => 'isolation-component',
            'electricity_contract_id' => $contract->id,
            'price_component_type' => 'General',
            'price_date' => self::DATE,
            'price' => 8.0,
            'payment_unit' => 'c/kWh',
        ]);
        $asOf = $this->asOfStatistic();
        $annual = ContractPriceAnnualCost::create([
            'snapshot_date' => self::DATE,
            'contract_id' => $contract->id,
            'segment_key' => 'open_ended',
            'pricing_basis' => ContractPriceBasis::ObservedSellerData->value,
            'consumption_kwh' => 5000,
            'annual_cost' => 400.0,
            'method_version' => AnnualCostMethodVersion::AsOf,
            'calculation_basis' => AnnualCostCalculationBasis::ObservedRelationalComponents,
            'estimate_method' => 'none',
            'estimate_basis' => 'exact_date_components_held_flat',
            'compatibility_key' => 'as-of-existing',
            'provenance' => ['flags' => ['existing']],
        ])->fresh();

        $service = app(ContractPriceStatisticsService::class);
        $service->calculateForDate(self::DATE, [$contract->id], overwrite: true, useCanonical: false);
        $this->artisan('contracts:backfill-price-statistics', [
            '--from' => self::DATE,
            '--to' => self::DATE,
            '--overwrite' => true,
        ])->assertSuccessful();

        $this->assertSame($asOf->getRawOriginal(), $asOf->fresh()->getRawOriginal());
        $this->assertSame($annual->getRawOriginal(), $annual->fresh()->getRawOriginal());
        $this->assertTrue(ContractPriceDailyStatistic::query()
            ->whereDate('stat_date', self::DATE)
            ->where('method_version', AnnualCostMethodVersion::Legacy->value)
            ->exists());
        $this->assertTrue(ContractPriceDailyStatistic::query()
            ->whereDate('stat_date', self::DATE)
            ->where('method_version', ContractPriceDailyStatistic::UNIT_STATISTICS_METHOD_VERSION)
            ->exists());
    }

    public function test_current_command_rejects_a_past_explicit_date_with_rebuild_guidance(): void
    {
        $this->artisan('contracts:calculate-price-statistics', ['--date' => '2026-05-31'])
            ->expectsOutputToContain('contracts:rebuild-annual-cost-statistics')
            ->assertFailed();
    }

    public function test_current_command_rejects_a_future_date(): void
    {
        $this->artisan('contracts:calculate-price-statistics', ['--date' => '2026-06-02'])
            ->expectsOutputToContain('accepts only today')
            ->assertFailed();
    }

    private function asOfStatistic(): ContractPriceDailyStatistic
    {
        return ContractPriceDailyStatistic::create([
            'stat_date' => self::DATE,
            'segment_key' => 'open_ended',
            'metric_key' => 'annual_cost',
            'pricing_basis' => ContractPriceBasis::ObservedSellerData->value,
            'method_version' => AnnualCostMethodVersion::AsOf,
            'calculation_basis' => AnnualCostCalculationBasis::ObservedRelationalComponents->value,
            'estimate_basis' => 'exact_date_components_held_flat',
            'compatibility_key' => 'as-of-existing',
            'basis_counts' => ['estimate_method' => ['none' => 1]],
            'consumption_kwh' => 5000,
            'min_value' => 400.0,
            'p20_value' => 400.0,
            'avg_value' => 400.0,
            'median_value' => 400.0,
            'p80_value' => 400.0,
            'max_value' => 400.0,
            'contract_count' => 1,
        ])->fresh();
    }
}
