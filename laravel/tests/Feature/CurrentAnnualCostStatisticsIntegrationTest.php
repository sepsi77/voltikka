<?php

namespace Tests\Feature;

use App\Livewire\ContractPriceStatistics;
use App\Models\Company;
use App\Models\ContractPriceAnnualCost;
use App\Models\ContractPriceDailyStatistic;
use App\Models\ContractPriceSnapshot;
use App\Models\ElectricityContract;
use App\Models\PriceComponent;
use App\Services\CanonicalPricing\CanonicalContractPricingService;
use App\Services\CanonicalPricing\DTO\CanonicalPricingOutcome;
use App\Services\CanonicalPricing\Enums\ContractComparability;
use App\Services\CanonicalPricing\Enums\EstimateMethod;
use App\Services\ContractStatistics\AnnualCostStatisticsWriter;
use App\Services\ContractStatistics\AsOfAnnualCostCalculator;
use App\Services\ContractStatistics\ContractPriceBasis;
use App\Services\ContractStatistics\ContractPriceStatisticsService;
use App\Services\ContractStatistics\CurrentCanonicalAnnualCostResultFactory;
use App\Services\ContractStatistics\Enums\AnnualCostCalculationBasis;
use App\Services\ContractStatistics\Enums\AnnualCostMethodVersion;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class CurrentAnnualCostStatisticsIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private const DATE = '2026-06-01';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(self::DATE.' 09:00:00 Europe/Helsinki');
        Company::create(['name' => 'Current Statistics Oy', 'name_slug' => 'current-statistics-oy']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_canonical_current_calculation_writes_legacy_and_as_of_annual_aggregates_and_three_contract_rows(): void
    {
        $contract = $this->contract('current-canonical');
        $this->mockCanonicalOutcomes($contract);
        $this->mock(AsOfAnnualCostCalculator::class, function ($mock): void {
            $mock->shouldNotReceive('calculate');
        });

        $result = app(ContractPriceStatisticsService::class)->calculateForDate(
            self::DATE,
            [$contract->id],
            overwrite: true,
            useCanonical: true,
        );

        $this->assertSame(['snapshots' => 1, 'statistics' => 5], $result);
        $this->assertSame(3, ContractPriceAnnualCost::query()
            ->where('method_version', AnnualCostMethodVersion::AsOf->value)
            ->where('contract_id', $contract->id)
            ->count());
        $this->assertSame(
            [2000, 5000, 18000],
            ContractPriceAnnualCost::query()->orderBy('consumption_kwh')->pluck('consumption_kwh')->all(),
        );
        $this->assertSame(
            [220.0, 520.0, 1820.0],
            ContractPriceAnnualCost::query()->orderBy('consumption_kwh')->pluck('annual_cost')->all(),
        );
        $this->assertSame(3, ContractPriceDailyStatistic::annualCostByMethod(AnnualCostMethodVersion::Legacy)->count());
        $this->assertSame(3, ContractPriceDailyStatistic::annualCostByMethod(AnnualCostMethodVersion::AsOf)->count());
    }

    public function test_canonical_rerun_does_not_create_nullable_unit_identity_duplicates(): void
    {
        $contract = $this->contract('current-rerun');
        $this->mockCanonicalOutcomes($contract, times: 2);
        $service = app(ContractPriceStatisticsService::class);

        $service->calculateForDate(self::DATE, [$contract->id], overwrite: true, useCanonical: true);
        $service->calculateForDate(self::DATE, [$contract->id], overwrite: true, useCanonical: true);

        $duplicateIdentities = ContractPriceDailyStatistic::query()
            ->select(['stat_date', 'segment_key', 'metric_key', 'consumption_kwh', 'method_version'])
            ->groupBy(['stat_date', 'segment_key', 'metric_key', 'consumption_kwh', 'method_version'])
            ->havingRaw('COUNT(*) > 1')
            ->get()
            ->count();
        $this->assertSame(0, $duplicateIdentities);
        $this->assertSame(3, ContractPriceAnnualCost::query()->where('contract_id', $contract->id)->count());
    }

    public function test_feature_off_calculation_writes_no_as_of_rows(): void
    {
        $contract = ElectricityContract::factory()->forCompany('Current Statistics Oy')->legacy()->create([
            'id' => 'feature-off',
        ]);
        PriceComponent::create([
            'id' => 'feature-off-general',
            'electricity_contract_id' => $contract->id,
            'price_component_type' => 'General',
            'price_date' => self::DATE,
            'price' => 8.0,
            'payment_unit' => 'c/kWh',
        ]);
        $this->mock(AsOfAnnualCostCalculator::class, function ($mock): void {
            $mock->shouldNotReceive('calculate');
        });
        $existing = ContractPriceAnnualCost::create([
            ...$this->storedAnnualAttributes($contract, 5000),
            'method_version' => AnnualCostMethodVersion::AsOf,
        ])->fresh();

        app(ContractPriceStatisticsService::class)->calculateForDate(
            self::DATE,
            [$contract->id],
            overwrite: true,
            useCanonical: false,
        );

        $this->assertSame($existing->getRawOriginal(), $existing->fresh()->getRawOriginal());
        $this->assertSame(0, ContractPriceDailyStatistic::annualCostByMethod(AnnualCostMethodVersion::AsOf)->count());
    }

    public function test_feature_off_recalculation_does_not_expose_the_stale_canonical_as_of_endpoint(): void
    {
        $contract = $this->contract('feature-cutover');
        PriceComponent::create([
            'id' => 'feature-cutover-general',
            'electricity_contract_id' => $contract->id,
            'price_component_type' => 'General',
            'price_date' => self::DATE,
            'price' => 8.0,
            'payment_unit' => 'c/kWh',
        ]);
        $this->mockCanonicalOutcomes($contract);
        $service = app(ContractPriceStatisticsService::class);

        config()->set('canonical_pricing.enabled', true);
        $service->calculateForDate(self::DATE, [$contract->id], overwrite: true, useCanonical: true);
        $this->assertTrue(ContractPriceDailyStatistic::annualCostByMethod(AnnualCostMethodVersion::AsOf)
            ->where('pricing_basis', ContractPriceBasis::CanonicalCalculation->value)
            ->exists());

        config()->set('canonical_pricing.enabled', false);
        config()->set('contract_statistics.annual_cost.active_method_version', AnnualCostMethodVersion::AsOf->value);
        app()->forgetScopedInstances();
        $service->calculateForDate(self::DATE, [$contract->id], overwrite: true, useCanonical: false);

        $this->assertTrue(ContractPriceDailyStatistic::annualCostByMethod(AnnualCostMethodVersion::AsOf)
            ->where('pricing_basis', ContractPriceBasis::CanonicalCalculation->value)
            ->exists(), 'The shadow row remains for audit after the feature-off calculation.');
        $this->assertSame([], app(ContractPriceStatistics::class)->leadChartPayload['x']);
    }

    public function test_canonical_rerun_replaces_stale_as_of_contract_rows(): void
    {
        $current = $this->contract('current-contract');
        $stale = $this->contract('stale-contract');
        $this->mockCanonicalOutcomes($current);
        foreach ([2000, 5000, 18000] as $consumption) {
            ContractPriceAnnualCost::create([
                ...$this->storedAnnualAttributes($stale, $consumption),
                'method_version' => AnnualCostMethodVersion::AsOf,
            ]);
        }

        app(ContractPriceStatisticsService::class)->calculateForDate(
            self::DATE,
            [$current->id],
            overwrite: true,
            useCanonical: true,
        );

        $this->assertFalse(ContractPriceAnnualCost::query()->where('contract_id', $stale->id)->exists());
        $this->assertSame(3, ContractPriceAnnualCost::query()->where('contract_id', $current->id)->count());
    }

    public function test_current_outcome_adapter_failure_rolls_back_snapshot_unit_and_legacy_changes(): void
    {
        $contract = $this->contract('adapter-failure');
        $this->mockCanonicalOutcomes($contract);
        $this->seedPreviousSnapshotAndStatistics($contract);
        $this->mock(CurrentCanonicalAnnualCostResultFactory::class, function ($mock): void {
            $mock->shouldReceive('create')->once()->andThrow(new RuntimeException('forced adapter failure'));
        });

        $this->assertCanonicalFailureRollsBack($contract);
    }

    public function test_out_of_range_result_is_unavailable_and_removes_its_stale_row(): void
    {
        $contract = $this->contract('limited-consumption');
        $contract->update(['consumption_limitation_min_x_kwh_per_y' => 3000]);
        $this->mockCanonicalOutcomes($contract);
        ContractPriceAnnualCost::create([
            ...$this->storedAnnualAttributes($contract, 2000),
            'method_version' => AnnualCostMethodVersion::AsOf,
        ]);

        app(ContractPriceStatisticsService::class)->calculateForDate(
            self::DATE,
            [$contract->id],
            overwrite: true,
            useCanonical: true,
        );

        $this->assertSame(
            [5000, 18000],
            ContractPriceAnnualCost::query()->orderBy('consumption_kwh')->pluck('consumption_kwh')->all(),
        );
        $this->assertFalse(ContractPriceAnnualCost::query()
            ->where('contract_id', $contract->id)
            ->where('consumption_kwh', 2000)
            ->exists());
    }

    public function test_as_of_writer_failure_rolls_back_snapshot_unit_and_legacy_changes(): void
    {
        $contract = $this->contract('writer-failure');
        $this->mockCanonicalOutcomes($contract);
        $this->seedPreviousSnapshotAndStatistics($contract);
        $this->mock(AnnualCostStatisticsWriter::class, function ($mock): void {
            $mock->shouldReceive('write')->once()->andThrow(new RuntimeException('forced writer failure'));
        });

        $this->assertCanonicalFailureRollsBack($contract);
    }

    private function assertCanonicalFailureRollsBack(ElectricityContract $contract): void
    {
        $snapshot = ContractPriceSnapshot::query()->where('contract_id', $contract->id)->sole()->fresh();
        $unit = ContractPriceDailyStatistic::unitStatistics()->sole()->fresh();
        $legacy = ContractPriceDailyStatistic::annualCostByMethod(AnnualCostMethodVersion::Legacy)->sole()->fresh();

        try {
            app(ContractPriceStatisticsService::class)->calculateForDate(
                self::DATE,
                [$contract->id],
                overwrite: true,
                useCanonical: true,
            );
            $this->fail('The forced AsOf failure did not leave the statistics service.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('forced', $exception->getMessage());
        }

        $this->assertSame($snapshot->getRawOriginal(), $snapshot->fresh()->getRawOriginal());
        $this->assertSame($unit->getRawOriginal(), $unit->fresh()->getRawOriginal());
        $this->assertSame($legacy->getRawOriginal(), $legacy->fresh()->getRawOriginal());
        $this->assertSame(0, ContractPriceAnnualCost::count());
    }

    private function contract(string $id): ElectricityContract
    {
        return ElectricityContract::factory()->forCompany('Current Statistics Oy')->create(['id' => $id]);
    }

    private function mockCanonicalOutcomes(ElectricityContract $contract, int $times = 1): void
    {
        $outcomes = [];
        foreach ([2000 => 220.0, 5000 => 520.0, 18000 => 1820.0] as $consumption => $total) {
            $outcomes[$contract->id][$consumption] = $this->canonicalOutcome($total);
        }

        $this->mock(CanonicalContractPricingService::class, function ($mock) use ($outcomes, $times): void {
            $mock->shouldReceive('outcomesForContractsAtConsumptions')->times($times)->andReturn($outcomes);
        });
    }

    private function canonicalOutcome(float $total): CanonicalPricingOutcome
    {
        return new CanonicalPricingOutcome(
            comparability: ContractComparability::ComparableExact,
            estimateMethod: EstimateMethod::None,
            totalCost: $total,
            monthlyCosts: array_fill(0, 12, $total / 12),
            baseTotalCost: $total,
            baseMonthlyCosts: array_fill(0, 12, $total / 12),
            measuredDiscountSavingsTotal: 0.0,
            monthlyDiscountSavings: array_fill(0, 12, 0.0),
            structuredOnlyTotal: $total,
            isSpotContract: false,
            monthlyFixedFee: 1.0,
            generalKwhPrice: 8.0,
        );
    }

    private function seedPreviousSnapshotAndStatistics(ElectricityContract $contract): void
    {
        ContractPriceSnapshot::create([
            'snapshot_date' => self::DATE,
            'contract_id' => $contract->id,
            'company_name' => $contract->company_name,
            'contract_name' => $contract->name,
            'pricing_model' => 'FixedPrice',
            'contract_type' => 'OpenEnded',
            'metering' => 'General',
            'segment_key' => 'open_ended',
            'pricing_basis' => ContractPriceBasis::ObservedSellerData->value,
            'energy_price_cents_per_kwh' => 9.0,
            'annual_cost_5000_kwh' => 450.0,
        ]);
        ContractPriceDailyStatistic::create([
            ...$this->dailyAttributes('energy_price', null),
            'method_version' => ContractPriceDailyStatistic::UNIT_STATISTICS_METHOD_VERSION,
        ]);
        ContractPriceDailyStatistic::create([
            ...$this->dailyAttributes('annual_cost', 5000),
            'method_version' => AnnualCostMethodVersion::Legacy,
        ]);
    }

    /** @return array<string, mixed> */
    private function dailyAttributes(string $metric, ?int $consumption): array
    {
        return [
            'stat_date' => self::DATE,
            'segment_key' => 'open_ended',
            'metric_key' => $metric,
            'pricing_basis' => ContractPriceBasis::ObservedSellerData->value,
            'consumption_kwh' => $consumption,
            'min_value' => 450.0,
            'p20_value' => 450.0,
            'avg_value' => 450.0,
            'median_value' => 450.0,
            'p80_value' => 450.0,
            'max_value' => 450.0,
            'contract_count' => 1,
        ];
    }

    /** @return array<string, mixed> */
    private function storedAnnualAttributes(ElectricityContract $contract, int $consumption): array
    {
        return [
            'snapshot_date' => self::DATE,
            'contract_id' => $contract->id,
            'segment_key' => 'open_ended',
            'pricing_basis' => ContractPriceBasis::CanonicalCalculation,
            'consumption_kwh' => $consumption,
            'annual_cost' => 999.0,
            'calculation_basis' => AnnualCostCalculationBasis::CanonicalOutcome,
            'estimate_method' => EstimateMethod::None->value,
            'estimate_basis' => 'stale',
            'compatibility_key' => 'stale-row',
            'provenance' => ['flags' => ['stale']],
        ];
    }
}
