<?php

namespace Tests\Feature;

use App\Models\ActiveContract;
use App\Models\Company;
use App\Models\ContractPriceSnapshot;
use App\Models\ElectricityContract;
use App\Models\PriceComponent;
use App\Services\CompanyListCacheService;
use App\Services\ContractCard\Enums\PricingBucket;
use App\Services\ContractListCacheService;
use App\Services\ContractRankingService;
use App\Services\ContractStatistics\ContractPriceStatisticsService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class AnnualConsumerConsistencyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-08-31 23:59:00', 'Europe/Helsinki'));
        Cache::flush();
    }

    public function test_partial_breakdowns_keep_the_full_total_in_both_pricing_modes(): void
    {
        $this->contract('fixed');
        foreach ([false, true] as $canonical) {
            config(['canonical_pricing.enabled' => $canonical]);
            app()->forgetScopedInstances();
            foreach ([
                ['total' => 5000],
                ['total' => 5000, 'basic_living' => 1000],
                ['total' => 5000, 'basic_living' => 1000, 'room_heating' => 4000],
                ['total' => 5000, 'cooling' => 0.5],
                ['total' => 5000, 'room_heating' => 4000, 'heating_electricity_use_by_month' => array_fill(0, 12, 1)],
                ['total' => 5000, 'room_heating' => 0, 'heating_electricity_use_by_month' => array_fill(0, 12, 0)],
            ] as $usage) {
                $response = $this->postJson('/api/calculate-price', ['contract_id' => 'fixed', 'energy_usage' => $usage]);
                $response->assertOk();
                $this->assertEqualsWithDelta(560, $response->json('data.total_cost'), 0.01);
            }
            $exact = $this->postJson('/api/calculate-price', [
                'contract_id' => 'fixed',
                'energy_usage' => ['total' => 5000, 'basic_living' => 3800, 'room_heating' => 1200,
                    'heating_electricity_use_by_month' => [1200, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0]],
            ])->assertOk()->json('data');
            $weighted = $this->postJson('/api/calculate-price', [
                'contract_id' => 'fixed',
                'energy_usage' => ['total' => 5000, 'room_heating' => 1200,
                    'heating_electricity_use_by_month' => [1, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0]],
            ])->assertOk()->json('data');
            $this->assertSame($exact, $weighted);
        }
    }

    public function test_invalid_breakdowns_return_clear_validation_errors(): void
    {
        $this->contract('fixed');
        $this->postJson('/api/calculate-price', [
            'contract_id' => 'fixed', 'energy_usage' => ['total' => 5000, 'basic_living' => 1000, 'room_heating' => 4001],
        ])->assertUnprocessable()->assertJsonValidationErrors('energy_usage.total')
            ->assertJsonFragment(['The consumption breakdown must not exceed energy_usage.total.']);

        foreach ([[], ['basic_living' => 1000]] as $usage) {
            $this->postJson('/api/calculate-price', ['contract_id' => 'fixed', 'energy_usage' => $usage])->assertUnprocessable();
        }
        $this->postJson('/api/calculate-price', [
            'contract_id' => 'fixed', 'consumption' => 5000, 'energy_usage' => [],
        ])->assertUnprocessable()->assertJsonValidationErrors('energy_usage.total');
        foreach ([
            array_fill(1, 12, 1),
            array_replace(array_fill(0, 12, 1), [0 => -1]),
            array_replace(array_fill(0, 12, 1), [0 => 'NaN']),
            array_replace(array_fill(0, 12, 1), [0 => '1e309']),
            array_fill(0, 12, 0),
        ] as $weights) {
            $this->postJson('/api/calculate-price', [
                'contract_id' => 'fixed',
                'energy_usage' => ['total' => 5000, 'room_heating' => 4000, 'heating_electricity_use_by_month' => $weights],
            ])->assertUnprocessable();
        }
        $this->postJson('/api/calculate-price', [
            'contract_id' => 'fixed',
            'energy_usage' => ['total' => 5000, 'heating_electricity_use_by_month' => array_fill(0, 12, 1)],
        ])->assertUnprocessable()->assertJsonValidationErrors('energy_usage.room_heating');
    }

    public function test_same_service_instances_refresh_annual_results_at_helsinki_midnight(): void
    {
        config(['canonical_pricing.enabled' => true]);
        app()->forgetScopedInstances();
        $promo = $this->contract('promo');
        $pricing = $promo->canonical_pricing;
        $intro = $pricing['phases'][0];
        $intro['phase_kind'] = 'introductory';
        $intro['ends'] = ['kind' => 'date', 'value' => '2026-08-31'];
        $intro['components'][0]['amount'] = 0;
        $normal = $pricing['phases'][0];
        $normal['phase_kind'] = 'normal';
        $normal['starts'] = ['kind' => 'date', 'value' => '2026-09-01'];
        $pricing['phases'] = [$intro, $normal];
        $promo->update(['canonical_pricing' => $pricing]);
        $this->contract('competitor', 9.99);

        $list = app(ContractListCacheService::class);
        $companies = app(CompanyListCacheService::class);
        $ranking = app(ContractRankingService::class);
        $statistics = app(ContractPriceStatisticsService::class);
        $statistics->calculateForDate('2026-08-31', ['promo', 'competitor'], useCanonical: true);
        $statsBefore = ContractPriceSnapshot::where('contract_id', 'promo')->whereDate('snapshot_date', '2026-08-31')->value('annual_cost_5000_kwh');
        $before = $list->getCachedMetrics(5000);
        $companyBefore = $companies->getCachedCompanies()->keyBy('company.name')['promo']['lowestPrice'];
        $bucketBefore = $ranking->getBucketCostSummary('competitor', 5000, PricingBucket::Fixed);
        $this->assertSame(1, $ranking->getContractRank('promo'));
        $this->assertSame(1, $ranking->getRankForConsumption('promo', 5000));
        $apiBefore = $this->postJson('/api/calculate-price', ['contract_id' => 'promo', 'consumption' => 5001])->assertOk()->json('data.total_cost');

        // UTC is still 31 August. Only the Helsinki calculation date has changed.
        $this->travelTo(CarbonImmutable::parse('2026-08-31 21:01:00', 'UTC'));
        $after = $list->getCachedMetrics(5000);
        $this->assertNotSame($before, $after);
        $this->assertGreaterThan($before->metric('promo')->pricing()->total(), $after->metric('promo')->pricing()->total());
        $this->assertEqualsWithDelta(560, $after->metric('promo')->pricing()->total(), 0.01);
        $this->assertGreaterThan($companyBefore, $companies->getCachedCompanies()->keyBy('company.name')['promo']['lowestPrice']);
        $this->assertSame(2, $ranking->getContractRank('promo'));
        $this->assertSame(2, $ranking->getRankForConsumption('promo', 5000));
        $this->assertNotEquals($bucketBefore, $ranking->getBucketCostSummary('competitor', 5000, PricingBucket::Fixed));
        $apiAfter = $this->postJson('/api/calculate-price', ['contract_id' => 'promo', 'consumption' => 5001])->assertOk()->json('data.total_cost');
        $this->assertGreaterThan($apiBefore, $apiAfter);
        $this->assertEqualsWithDelta(560.1, $apiAfter, 0.01);
        $this->assertNull($list->getCachedMetrics(5001));
        $statistics->calculateForDate('2026-09-01', ['promo', 'competitor'], useCanonical: true);
        $statsAfter = ContractPriceSnapshot::where('contract_id', 'promo')->whereDate('snapshot_date', '2026-09-01')->value('annual_cost_5000_kwh');
        $this->assertGreaterThan($statsBefore, $statsAfter);
        $this->assertEqualsWithDelta(560, $statsAfter, 0.01);
    }

    private function contract(string $id, float $rate = 10): ElectricityContract
    {
        Company::create(['name' => $id, 'name_slug' => $id]);
        $components = [];
        foreach ([['energy_general', $rate, 'cents_per_kwh'], ['monthly_fee', 5, 'eur_per_month']] as [$type, $amount, $unit]) {
            $components[] = [
                'component_type' => $type, 'amount' => $amount, 'normal_amount' => null,
                'unit' => $unit, 'vat_status' => 'included', 'price_role' => 'current', 'source_kind' => 'both', 'evidence' => [],
            ];
        }
        $contract = ElectricityContract::create([
            'id' => $id, 'company_name' => $id, 'name' => $id, 'contract_type' => 'FixedTerm', 'fixed_time_range' => 12,
            'pricing_model' => 'FixedPrice', 'metering' => 'General', 'target_group' => 'Household', 'availability_is_national' => true,
            'canonical_pricing' => [
                'recurring_schedule' => ['present' => false, 'cadence' => 'none'],
                'consumption_effect' => ['present' => false, 'applies_to' => 'unknown'],
                'phases' => [[
                    'label' => 'current', 'phase_kind' => 'current_structured',
                    'starts' => ['kind' => 'contract_start', 'value' => null], 'ends' => ['kind' => 'none', 'value' => null],
                    'components' => $components, 'evidence' => [],
                ]]],
            'canonical_calculation' => ['status' => 'exact', 'missing_facts' => [], 'required_assumptions' => []],
            'canonical_source_consistency' => ['misleading_first_12_months' => 'not_detected', 'structured_pricing_status' => 'complete', 'issue_codes' => []],
        ]);
        ActiveContract::create(['id' => $id]);
        foreach (['General' => $rate, 'Monthly' => 5] as $type => $price) {
            PriceComponent::create([
                'id' => $id.$type, 'electricity_contract_id' => $id, 'price_component_type' => $type,
                'price_date' => '2026-08-31', 'price' => $price, 'payment_unit' => $type === 'General' ? 'c/kWh' : 'EUR/month',
            ]);
        }

        return $contract;
    }
}
