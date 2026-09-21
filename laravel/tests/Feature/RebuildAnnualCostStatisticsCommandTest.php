<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\ContractPriceAnnualCost;
use App\Models\ContractPriceDailyStatistic;
use App\Models\ElectricityContract;
use App\Services\ContractStatistics\AnnualCostStatisticsWriter;
use App\Services\ContractStatistics\AsOfAnnualCostCalculator;
use App\Services\ContractStatistics\Enums\AnnualCostMethodVersion;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
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
        $this->assertSame(3, ContractPriceDailyStatistic::annualCostByMethod(AnnualCostMethodVersion::AsOfV2)->count());
        $this->assertSame(0, ContractPriceDailyStatistic::annualCostByMethod(AnnualCostMethodVersion::AsOf)->count());

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

    public function test_explicit_v3_preview_and_apply_keep_default_method_and_stored_v2(): void
    {
        $this->evidence('v3-command', 8.0);
        $active = ContractPriceDailyStatistic::activeAnnualMethodVersion();
        $this->artisan('contracts:rebuild-annual-cost-statistics', ['--date' => self::DATE, '--apply' => true])->assertSuccessful();
        $stored = DB::table('contract_price_annual_costs')->orderBy('id')->get()->toJson();
        $options = ['--date' => self::DATE, '--method' => AnnualCostMethodVersion::AsOfV3->value, '--baseline' => AnnualCostMethodVersion::AsOfV2->value];
        $this->artisan('contracts:rebuild-annual-cost-statistics', $options)
            ->expectsOutputToContain('V3 is not ready for release: full-history target-evidence and continuity review, dated current-producer parity')->assertSuccessful();
        $this->assertSame($stored, DB::table('contract_price_annual_costs')->orderBy('id')->get()->toJson());
        $this->artisan('contracts:rebuild-annual-cost-statistics', [...$options, '--apply' => true])->assertSuccessful();
        $this->assertSame($stored, DB::table('contract_price_annual_costs')->where('method_version', AnnualCostMethodVersion::AsOfV2->value)->orderBy('id')->get()->toJson());
        $this->assertSame(3, ContractPriceAnnualCost::query()->where('method_version', AnnualCostMethodVersion::AsOfV3->value)->count());
        $this->assertSame($active, ContractPriceDailyStatistic::activeAnnualMethodVersion());
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

    public function test_invalid_methods_and_v1_apply_fail_before_queries(): void
    {
        foreach ([['--method' => 'unknown'], ['--baseline' => 'unknown'], ['--method' => AnnualCostMethodVersion::Legacy->value], ['--method' => AnnualCostMethodVersion::AsOf->value, '--apply' => true]] as $options) {
            DB::enableQueryLog();
            DB::flushQueryLog();
            $this->artisan('contracts:rebuild-annual-cost-statistics', ['--date' => self::DATE, ...$options])->assertFailed();
            $this->assertSame([], DB::getQueryLog());
            DB::disableQueryLog();
        }
    }

    public function test_apply_compares_stored_v1_and_preserves_it_and_raw_evidence(): void
    {
        $this->evidence('contract-a', 8.0);
        app(AnnualCostStatisticsWriter::class)->write(self::DATE, app(AsOfAnnualCostCalculator::class)->calculate(self::DATE));
        $oldAnnual = DB::table('contract_price_annual_costs')->get()->toJson();
        $oldAggregates = DB::table('contract_price_daily_statistics')->get()->toJson();
        DB::table('price_components')->update(['price' => 9.0]);
        $raw = DB::table('contract_price_snapshots')->get()->toJson();
        config()->set('contract_statistics.annual_cost.active_method_version', AnnualCostMethodVersion::AsOf->value);

        $this->artisan('contracts:rebuild-annual-cost-statistics', ['--date' => self::DATE, '--apply' => true])
            ->expectsOutputToContain('against stored annual_cost_as_of_v1')
            ->expectsOutputToContain('contracts_matched=3')
            ->expectsOutputToContain('min=+20.00 EUR max=+180.00 EUR')
            ->assertSuccessful();
        $this->assertSame($oldAnnual, DB::table('contract_price_annual_costs')->where('method_version', AnnualCostMethodVersion::AsOf->value)->get()->toJson());
        $this->assertSame($oldAggregates, DB::table('contract_price_daily_statistics')->where('method_version', AnnualCostMethodVersion::AsOf->value)->get()->toJson());
        $this->assertSame($raw, DB::table('contract_price_snapshots')->get()->toJson());

        DB::table('price_components')->update(['price' => 10.0]);
        $this->artisan('contracts:rebuild-annual-cost-statistics', ['--date' => self::DATE, '--apply' => true, '--baseline' => AnnualCostMethodVersion::AsOfV2->value])
            ->expectsOutputToContain('min=+20.00 EUR max=+180.00 EUR')
            ->assertSuccessful();
    }

    public function test_partial_preview_uses_baseline_only_contract_in_the_same_sorted_universe(): void
    {
        $this->evidence('a-lost', 8.0);
        $this->evidence('b-retained', 10.0);
        app(AnnualCostStatisticsWriter::class)->write(self::DATE, app(AsOfAnnualCostCalculator::class)->calculate(self::DATE));
        DB::table('contract_price_snapshots')->where('contract_id', 'a-lost')->delete();
        DB::table('price_components')->where('electricity_contract_id', 'a-lost')->delete();
        $this->artisan('contracts:rebuild-annual-cost-statistics', ['--date' => self::DATE, '--baseline' => AnnualCostMethodVersion::AsOf->value, '--limit' => 1])
            ->expectsOutputToContain('aggregates_lost=3, aggregates_matched=0, aggregates_new=0, contracts_lost=3')
            ->expectsOutputToContain('evidence=0')
            ->assertSuccessful();
    }

    public function test_partial_matched_medians_do_not_use_the_whole_baseline_market(): void
    {
        $this->evidence('a-selected', 8.0);
        $this->evidence('z-other', 20.0);
        app(AnnualCostStatisticsWriter::class)->write(self::DATE, app(AsOfAnnualCostCalculator::class)->calculate(self::DATE));
        DB::table('price_components')->where('electricity_contract_id', 'a-selected')->update(['price' => 9.0]);
        $this->artisan('contracts:rebuild-annual-cost-statistics', ['--date' => self::DATE, '--baseline' => AnnualCostMethodVersion::AsOf->value, '--limit' => 1])
            ->expectsOutputToContain('min=+20.00 EUR max=+180.00 EUR')
            ->assertSuccessful();
    }

    public function test_missing_baseline_and_recalculated_v1_are_explicit_diagnostics(): void
    {
        $this->evidence('contract-a', 8.0);
        $status = Artisan::call('contracts:rebuild-annual-cost-statistics', ['--date' => self::DATE, '--method' => AnnualCostMethodVersion::AsOf->value, '--baseline' => AnnualCostMethodVersion::AsOf->value]);
        $output = Artisan::output();
        $this->assertSame(0, $status, $output);
        $this->assertStringContainsString('does not reproduce frozen original v1 mathematics', $output);
        $this->assertStringContainsString('NO BASELINE', $output);
        $this->assertSame(0, ContractPriceAnnualCost::count());
    }

    public function test_empty_apply_fails_and_baseline_only_date_reports_lost_coverage(): void
    {
        $this->artisan('contracts:rebuild-annual-cost-statistics', ['--date' => self::DATE, '--apply' => true])->assertFailed();
        $this->evidence('lost', 8.0);
        app(AnnualCostStatisticsWriter::class)->write(self::DATE, app(AsOfAnnualCostCalculator::class)->calculate(self::DATE));
        DB::table('contract_price_snapshots')->delete();
        DB::table('price_components')->delete();
        $this->artisan('contracts:rebuild-annual-cost-statistics', ['--date' => self::DATE, '--baseline' => AnnualCostMethodVersion::AsOf->value])
            ->expectsOutputToContain('contracts_lost=3')->assertSuccessful();
        $this->artisan('contracts:rebuild-annual-cost-statistics', ['--date' => self::DATE, '--baseline' => AnnualCostMethodVersion::AsOf->value, '--apply' => true])->assertFailed();
        $this->assertSame(3, ContractPriceAnnualCost::count());
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
