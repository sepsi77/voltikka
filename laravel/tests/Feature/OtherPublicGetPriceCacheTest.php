<?php

namespace Tests\Feature;

use App\Livewire\ContractTypeComparison;
use App\Models\ActiveContract;
use App\Models\Company;
use App\Models\ElectricityContract;
use App\Models\PriceComponent;
use App\Services\Caching\PublicPriceCalculationPolicy;
use App\Services\CanonicalPricing\CanonicalContractPricingService;
use App\Services\CanonicalPricing\Enums\BoundaryKind;
use App\Services\CanonicalPricing\Enums\CalculationStatus;
use App\Services\CanonicalPricing\Enums\ComponentType;
use App\Services\CanonicalPricing\Enums\ComponentUnit;
use App\Services\CanonicalPricing\Enums\PhaseKind;
use App\Services\CanonicalPricing\Enums\PriceRole;
use App\Services\CompanyListCacheService;
use App\Services\ContractListCacheService;
use App\Services\ContractPriceCalculator;
use App\Services\ContractPricing\ContractMetricSet;
use App\Services\WeeklyOffersVideoService;
use Database\Factories\Support\CanonicalPricingFixture as Fixture;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class OtherPublicGetPriceCacheTest extends TestCase
{
    use RefreshDatabase;

    private function seedPrices(bool $canonical): ElectricityContract
    {
        config(['canonical_pricing.enabled' => $canonical]);
        app()->forgetScopedInstances();
        Cache::flush();
        Company::create(['name' => 'Cached Energy', 'name_slug' => 'cached-energy']);
        $contract = ElectricityContract::create([
            'id' => 'cached-offer', 'name' => 'Cached offer', 'name_slug' => 'cached-offer',
            'company_name' => 'Cached Energy', 'contract_type' => 'FixedTerm',
            'fixed_time_range' => 'Fixed12', 'pricing_model' => 'FixedPrice',
            'metering' => 'General', 'target_group' => 'Household', 'availability_is_national' => true,
        ]);
        if ($canonical) {
            $contract->update(Fixture::attributes(phases: [Fixture::phase(
                label: 'Current', kind: PhaseKind::CurrentStructured,
                starts: Fixture::boundary(BoundaryKind::ContractStart),
                ends: Fixture::boundary(BoundaryKind::AfterMonths, '12'),
                components: [Fixture::component(ComponentType::EnergyGeneral, 6.0, ComponentUnit::CentsPerKwh, PriceRole::Current, 8.0)],
            )], calculationStatus: CalculationStatus::Exact));
        }
        ActiveContract::create(['id' => $contract->id]);
        PriceComponent::create([
            'id' => 'cached-general', 'electricity_contract_id' => $contract->id,
            'price_component_type' => 'General', 'price_date' => now()->toDateString(),
            'price' => 8, 'payment_unit' => 'c/kWh', 'has_discount' => true,
            'discount_value' => 2, 'discount_is_percentage' => false,
        ]);
        app(ContractListCacheService::class)->refresh(app(CompanyListCacheService::class));

        return $contract;
    }

    private function forbidCalculation(bool $enabled): void
    {
        $this->partialMock(ContractPriceCalculator::class)->shouldNotReceive('calculate');
        $canonical = $this->partialMock(CanonicalContractPricingService::class);
        $canonical->shouldReceive('enabled')->andReturn($enabled);
        $canonical->shouldNotReceive('evaluate');
        $canonical->shouldNotReceive('metricsForContracts');
    }

    public static function modes(): array
    {
        return ['canonical' => [true], 'legacy' => [false]];
    }

    #[DataProvider('modes')]
    public function test_api_get_and_head_use_only_cached_pricing(bool $canonical): void
    {
        $this->seedPrices($canonical);
        $expected = app(ContractListCacheService::class)->getCachedMetrics(5000)->metric('cached-offer')->pricing()->total();
        $this->forbidCalculation($canonical);
        foreach (['/api/contracts', '/api/contracts/cached-offer'] as $url) {
            foreach (ContractListCacheService::PRESET_CONSUMPTIONS as $consumption) {
                $total = app(ContractListCacheService::class)->getCachedMetrics($consumption)->metric('cached-offer')->pricing()->total();
                $presetPath = $url === '/api/contracts' ? 'data.0' : 'data';
                $this->getJson($url.'?consumption='.$consumption)->assertOk()
                    ->assertJsonPath($presetPath.'.calculated_cost.total_cost', fn ($value) => (float) $value === $total);
            }
            $path = $url === '/api/contracts' ? 'data.0' : 'data';
            $this->getJson($url.'?consumption=5000&sort=cost')->assertOk()->assertJsonPath($path.'.calculated_cost.total_cost', fn ($total) => (float) $total === $expected);
            $this->getJson($url)->assertOk()->assertJsonMissingPath($path.'.calculated_cost');
            $this->getJson($url.'?consumption=5432&sort=cost')->assertOk()->assertJsonPath($path.'.calculated_cost.availability', 'unavailable')->assertJsonPath($path.'.calculated_cost.total_cost', null);
            $this->call('HEAD', $url.'?consumption=5000')->assertOk();
            $this->call('HEAD', $url.'?consumption=5432')->assertOk();
        }
        if ($canonical) {
            $this->getJson('/api/contracts/cached-offer')->assertJsonPath('data.current_pricing.general_kwh_price', 6);
        }
        $newContract = ElectricityContract::find('cached-offer')->replicate(['api_id']);
        $newContract->id = 'new-uncached';
        $newContract->name_slug = 'new-uncached';
        $newContract->save();
        ActiveContract::create(['id' => 'new-uncached']);
        $response = $this->getJson('/api/contracts/new-uncached?consumption=5000')->assertOk()->assertJsonPath('data.calculated_cost.total_cost', null);
        if ($canonical) {
            $response->assertJsonPath('data.current_pricing.availability', 'unavailable');
        }
        ActiveContract::where('id', 'cached-offer')->delete();
        $response = $this->getJson('/api/contracts/cached-offer?consumption=5000')->assertOk()->assertJsonPath('data.calculated_cost.total_cost', null);
        if ($canonical) {
            $response->assertJsonPath('data.current_pricing.availability', 'unavailable');
        }
    }

    #[DataProvider('modes')]
    public function test_missing_generation_is_503_without_calculation(bool $canonical): void
    {
        $this->seedPrices($canonical);
        Cache::forget(app(ContractListCacheService::class)->getCacheKey(5000));
        app(ContractListCacheService::class)->resetCalculationState();
        $this->forbidCalculation($canonical);
        $this->getJson('/api/contracts?consumption=5000')->assertStatus(503);
        $this->getJson('/api/video/weekly-offers')->assertStatus(503)->assertHeader('Cache-Control', 'no-store, private');
    }

    #[DataProvider('modes')]
    public function test_weekly_offer_profiles_read_cached_totals(bool $canonical): void
    {
        $this->seedPrices($canonical);
        $this->forbidCalculation($canonical);
        $response = $this->getJson('/api/video/weekly-offers')->assertOk()->assertJsonPath('data.offers_count', 1);
        foreach (['apartment' => 2000, 'townhouse' => 5000, 'house' => 10000] as $profile => $consumption) {
            $pricing = app(ContractListCacheService::class)->getCachedMetrics($consumption)->metric('cached-offer')->pricing();
            $path = $canonical ? 'data.offers.0.consumptions.'.$profile.'.total_cost' : 'data.offers.0.costs.'.$profile;
            $response->assertJsonPath($path, fn ($total) => (float) $total === ($canonical ? round($pricing->total(), 2) : round($pricing->total())));
        }
    }

    #[DataProvider('modes')]
    public function test_initial_widget_uses_cached_selection_chart_and_display(bool $canonical): void
    {
        $this->seedPrices($canonical);
        $expected = app(ContractListCacheService::class)->getCachedMetrics(5000)->metric('cached-offer')->pricing();
        $this->forbidCalculation($canonical);
        $this->get('/test-comparison')->assertOk()->assertSee('Cached offer');
        app()->instance('request', Request::create('/test-comparison', 'GET'));
        $widget = new ContractTypeComparison;
        $this->assertSame('cached-offer', $widget->contractB->id);
        $this->assertSame(array_map(fn ($value) => round($value, 2), $expected->monthlyCosts()), $widget->projectedCostsB['monthly']);
        $this->assertSame($expected->total(), $widget->getDisplayPrice($widget->contractB)['annualCost']);
        $this->assertFalse($widget->comparisonResult['hasResult']);
    }

    #[DataProvider('modes')]
    public function test_api_reads_a_custom_profile_prepared_by_an_explicit_action(bool $canonical): void
    {
        $this->seedPrices($canonical);
        app()->instance('request', Request::create('/livewire/update', 'POST'));
        app(PublicPriceCalculationPolicy::class)->allowUserAction();
        $prepared = app(ContractListCacheService::class)->prepareComparisonForConsumption(5432);
        $expected = $prepared->metric('cached-offer')->pricing()->total();
        $this->forbidCalculation($canonical);
        foreach (['/api/contracts', '/api/contracts/cached-offer'] as $url) {
            $path = $url === '/api/contracts' ? 'data.0' : 'data';
            $this->getJson($url.'?consumption=5432')->assertOk()
                ->assertJsonPath($path.'.calculated_cost.total_cost', fn ($value) => (float) $value === $expected);
            $this->call('HEAD', $url.'?consumption=5432')->assertOk();
        }
    }

    #[DataProvider('modes')]
    public function test_cli_weekly_consumer_reads_prepared_profiles_without_duplicate_calculation(bool $canonical): void
    {
        $this->seedPrices($canonical);
        app()->instance('request', new Request);
        $this->assertTrue(app(PublicPriceCalculationPolicy::class)->allowsCalculation());
        $this->forbidCalculation($canonical);
        $data = app(WeeklyOffersVideoService::class)->getWeeklyOffersData();
        $this->assertSame(1, $data['offers_count']);
        $this->assertSame('cached-offer', $data['offers'][0]['id']);
    }

    public function test_weekly_retries_promotion_once_without_mixing_profiles(): void
    {
        $this->seedPrices(false);
        $cache = app(ContractListCacheService::class);
        $sets = [];
        foreach ([2000, 5000, 10000] as $consumption) {
            $sets[$consumption] = $cache->getCachedMetrics($consumption);
        }
        $old = $sets[2000]->toArray();
        $old['contracts']['cached-offer']['calculated_cost']['total_cost'] = 9999.0;
        $old['contracts']['cached-offer']['total_cost'] = 9999.0;
        $this->forbidCalculation(false);
        $reader = $this->partialMock(ContractListCacheService::class);
        $reader->shouldReceive('getGeneration')->times(6)->andReturn('old', 'new', 'new', 'new', 'new', 'new');
        $reader->shouldReceive('getCachedMetrics')->with(2000)->twice()->andReturn(ContractMetricSet::fromArray($old), $sets[2000]);
        $reader->shouldReceive('getCachedMetrics')->with(5000)->once()->andReturn($sets[5000]);
        $reader->shouldReceive('getCachedMetrics')->with(10000)->once()->andReturn($sets[10000]);
        $this->getJson('/api/video/weekly-offers')->assertOk()->assertJsonPath('data.offers.0.costs.apartment', 120);
    }

    public function test_weekly_two_promotions_return_unavailable(): void
    {
        $this->seedPrices(false);
        $set = app(ContractListCacheService::class)->getCachedMetrics(2000);
        $this->forbidCalculation(false);
        $reader = $this->partialMock(ContractListCacheService::class);
        $reader->shouldReceive('getGeneration')->times(4)->andReturn('old', 'new', 'new', 'newer');
        $reader->shouldReceive('getCachedMetrics')->with(2000)->twice()->andReturn($set);
        $this->getJson('/api/video/weekly-offers')->assertStatus(503);
    }

    #[DataProvider('modes')]
    public function test_missing_weekly_contract_profile_is_ineligible(bool $canonical): void
    {
        $this->seedPrices($canonical);
        $cache = app(ContractListCacheService::class);
        $empty = $cache->getCachedMetrics(10000)->toArray();
        $empty['contracts'] = [];
        $empty['sorted_ids'] = [];
        $empty['excluded_ids'] = [];
        Cache::forever($cache->getCacheKey(10000), $empty);
        $cache->resetCalculationState();
        $this->forbidCalculation($canonical);
        $this->getJson('/api/video/weekly-offers')->assertOk()->assertJsonPath('data.offers_count', 0);
    }

    public function test_widget_automatic_post_initialization_has_no_calculation_permission(): void
    {
        $this->seedPrices(true);
        $this->forbidCalculation(true);
        app()->instance('request', Request::create('/livewire/update', 'POST'));
        $widget = new ContractTypeComparison;
        $this->assertSame('cached-offer', $widget->contractB->id);
        $this->assertSame(300.0, $widget->projectedCostsB['total']);
        $this->assertFalse($widget->comparisonResult['hasResult']);
    }

    #[DataProvider('modes')]
    public function test_explicit_post_and_widget_custom_action_keep_exact_prices(bool $canonical): void
    {
        $this->seedPrices($canonical);
        $this->postJson('/api/calculate-price', ['contract_id' => 'cached-offer', 'consumption' => 5432])
            ->assertOk()->assertJsonPath('data.total_cost', fn ($total) => abs($total - 325.92) < 0.001);
        // Legacy explicit actions keep seasonal monthly distribution and integer-kWh truncation.
        $expectedChartTotal = $canonical ? 325.92 : 325.62;
        Livewire::test(ContractTypeComparison::class)->call('setConsumption', 5432)
            ->assertViewHas('projectedCostsB', fn ($costs) => $costs['available'] && $costs['total'] === $expectedChartTotal);
    }
}
