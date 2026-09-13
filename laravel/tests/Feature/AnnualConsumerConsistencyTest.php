<?php

namespace Tests\Feature;

use App\Models\ActiveContract;
use App\Models\Company;
use App\Models\ContractInterpretation;
use App\Models\ContractPriceSnapshot;
use App\Models\ContractSourceObservation;
use App\Models\ContractSourceSnapshot;
use App\Models\ElectricityContract;
use App\Models\PriceComponent;
use App\Services\Caching\ContractPageCacheVersion;
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

    public function test_shared_annual_prices_survive_midnight_until_an_explicit_successful_refresh(): void
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
        $this->assertSame($before, $after);
        $this->assertEquals($companyBefore, $companies->getCachedCompanies()->keyBy('company.name')['promo']['lowestPrice']);
        $this->assertSame(1, $ranking->getContractRank('promo'));
        $this->assertSame(1, $ranking->getRankForConsumption('promo', 5000));
        $this->assertEquals($bucketBefore, $ranking->getBucketCostSummary('competitor', 5000, PricingBucket::Fixed));
        $apiAfter = $this->postJson('/api/calculate-price', ['contract_id' => 'promo', 'consumption' => 5001])->assertOk()->json('data.total_cost');
        $this->assertGreaterThan($apiBefore, $apiAfter);
        $this->assertEqualsWithDelta(560.1, $apiAfter, 0.01);
        $this->assertNull($list->getCachedMetrics(5001));
        $statistics->calculateForDate('2026-09-01', ['promo', 'competitor'], useCanonical: true);
        $statsAfter = ContractPriceSnapshot::where('contract_id', 'promo')->whereDate('snapshot_date', '2026-09-01')->value('annual_cost_5000_kwh');
        $this->assertGreaterThan($statsBefore, $statsAfter);
        $this->assertEqualsWithDelta(560, $statsAfter, 0.01);

        $this->travel(3)->days();
        app()->forgetScopedInstances();
        $freshList = app(ContractListCacheService::class);
        $this->assertEquals($before->toArray(), $freshList->getCachedMetrics(5000)->toArray());
        $this->assertEquals($companyBefore, app(CompanyListCacheService::class)->getCachedCompanies()->keyBy('company.name')['promo']['lowestPrice']);
        $this->assertSame(1, app(ContractRankingService::class)->getContractRank('promo'));
        $freshList->refresh(app(CompanyListCacheService::class));
        $this->assertEqualsWithDelta(560, $list->getCachedMetrics(5000)->metric('promo')->pricing()->total(), 0.01);
        $this->assertSame(2, $ranking->getContractRank('promo'));
        $this->assertGreaterThan($companyBefore, $companies->getCachedCompanies()->keyBy('company.name')['promo']['lowestPrice']);
    }

    public function test_corrected_canonical_prices_do_not_depend_on_relational_publication_but_require_current_evidence(): void
    {
        config(['canonical_pricing.enabled' => true]);
        app()->forgetScopedInstances();
        $contract = $this->contract('source-guard');
        $pricing = $contract->canonical_pricing;
        $intro = $pricing['phases'][0];
        $intro['phase_kind'] = 'introductory';
        $intro['ends'] = ['kind' => 'after_months', 'value' => '1'];
        $intro['components'][0]['amount'] = 3;
        $normal = $pricing['phases'][0];
        $normal['phase_kind'] = 'normal';
        $normal['starts'] = ['kind' => 'after_months', 'value' => '1'];
        $normal['components'][0]['amount'] = 15;
        $pricing['phases'] = [$intro, $normal];
        $contract->update([
            'canonical_pricing' => $pricing,
            'canonical_source_consistency' => [
                'misleading_first_12_months' => 'detected', 'structured_pricing_status' => 'complete',
                'issue_codes' => ['structured_matches_intro_only', 'future_price_omitted'],
            ],
        ]);
        $snapshot = ContractSourceSnapshot::create([
            'contract_id' => $contract->id, 'source_fingerprint' => str_repeat('a', 64),
            'source_payload' => [], 'first_observed_at' => now(), 'last_observed_at' => now(),
        ]);
        $observation = ContractSourceObservation::create([
            'contract_id' => $contract->id, 'source_snapshot_id' => $snapshot->id,
            'first_observed_at' => now(), 'last_observed_at' => now(),
        ]);
        $interpretation = ContractInterpretation::create([
            'contract_id' => $contract->id, 'source_snapshot_id' => $snapshot->id,
            'analysis_fingerprint' => str_repeat('b', 64), 'status' => 'published',
            'schema_version' => 'test', 'prompt_version' => 'test', 'validator_version' => 'test',
            'provider' => 'test', 'model' => 'test', 'relational_pricing_published' => false,
        ]);
        $contract->update(['current_source_observation_id' => $observation->id, 'published_interpretation_id' => $interpretation->id]);
        $list = app(ContractListCacheService::class);
        $companies = app(CompanyListCacheService::class);
        $ranking = app(ContractRankingService::class);
        $page = app(ContractPageCacheVersion::class);
        $metric = $list->getCachedMetrics(5000)->metric($contract->id);
        $this->assertTrue($metric->isListed());
        $this->assertGreaterThan(700, $metric->pricing()->total());
        $this->assertSame('canonical', $metric->pricing()->pricingBasis());
        $this->assertCount(1, $companies->getCachedCompanies());
        $this->assertSame(1, $ranking->getContractRank($contract->id));
        $fingerprint = $page->hash();
        $interpretation->update(['relational_pricing_published' => true]);
        $this->assertSame($fingerprint, $page->hash());
        $interpretation->update(['relational_pricing_published' => false]);
        $this->assertSame($fingerprint, $page->hash());
        $this->assertSame($metric->pricing()->total(), $list->getCachedMetrics(5000)->metric($contract->id)->pricing()->total());
        $changed = $snapshot->replicate();
        $changed->source_fingerprint = str_repeat('c', 64);
        $changed->save();
        $next = $observation->replicate();
        $next->source_snapshot_id = $changed->id;
        $next->save();
        $contract->update(['current_source_observation_id' => $next->id]);
        $this->assertNotSame($fingerprint, $page->hash());
        $this->assertNull($ranking->getContractRank($contract->id));
        $this->assertNull($list->getCachedMetrics(5000)->metric($contract->id)->pricing()->total());
        // Even a successful cache rebuild must not turn an old publication into current facts.
        $list->refresh($companies);
        $this->assertNull($list->getCachedMetrics(5000)->metric($contract->id)->pricing()->total());
        $this->assertCount(0, $companies->getCachedCompanies());

        // A current publication with genuinely incomplete canonical prices is still excluded.
        $currentPublication = $interpretation->replicate();
        $currentPublication->source_snapshot_id = $changed->id;
        $currentPublication->analysis_fingerprint = str_repeat('d', 64);
        $currentPublication->save();
        $pricing['phases'] = [];
        $contract->update(['published_interpretation_id' => $currentPublication->id, 'canonical_pricing' => $pricing]);
        $list->bumpVersion();
        $list->refresh($companies);
        $this->assertNull($list->getCachedMetrics(5000)->metric($contract->id)->pricing()->total());
        $this->assertCount(0, $companies->getCachedCompanies());
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
