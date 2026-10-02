<?php

namespace Tests\Feature;

use App\Livewire\ConsumptionCalculator;
use App\Models\ActiveContract;
use App\Models\Company;
use App\Models\ElectricityContract;
use App\Models\PriceComponent;
use App\Services\BillComparison\BillComparisonService;
use App\Services\Caching\ContractPriceCacheUnavailable;
use App\Services\Caching\PublicPriceCalculationPolicy;
use App\Services\CanonicalPricing\CanonicalContractPricingService;
use App\Services\CompanyListCacheService;
use App\Services\ContractListCacheService;
use App\Services\ContractPriceCalculator;
use App\Services\ContractPricing\ContractMetricSet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Tests\TestCase;

class CalculatorExplicitPriceActionTest extends TestCase
{
    use RefreshDatabase;

    private function seedContract(): void
    {
        Cache::flush();
        Company::create(['name' => 'seller', 'name_slug' => 'seller']);
        ElectricityContract::create([
            'id' => 'exact', 'name' => 'Exact fixed', 'company_name' => 'seller',
            'availability_is_national' => true, 'contract_type' => 'OpenEnded',
            'pricing_model' => 'FixedPrice', 'metering' => 'General', 'target_group' => 'Household',
            'canonical_calculation' => ['status' => 'exact', 'missing_facts' => [], 'required_assumptions' => []],
            'canonical_source_consistency' => ['misleading_first_12_months' => 'not_detected', 'structured_pricing_status' => 'complete', 'issue_codes' => []],
            'canonical_pricing' => [
                'recurring_schedule' => ['present' => false, 'cadence' => 'none'],
                'consumption_effect' => ['present' => false, 'applies_to' => 'unknown'],
                'phases' => [[
                    'label' => 'current', 'phase_kind' => 'current_structured',
                    'starts' => ['kind' => 'contract_start', 'value' => null],
                    'ends' => ['kind' => 'none', 'value' => null],
                    'components' => [[
                        'component_type' => 'energy_general', 'amount' => 6, 'normal_amount' => null,
                        'unit' => 'cents_per_kwh', 'vat_status' => 'included', 'price_role' => 'current',
                        'source_kind' => 'both', 'evidence' => [],
                    ]],
                    'evidence' => [],
                ]],
            ],
        ]);
        ActiveContract::create(['id' => 'exact']);
        PriceComponent::create([
            'id' => 'energy', 'electricity_contract_id' => 'exact',
            'price_component_type' => 'General', 'price_date' => now()->toDateString(),
            'price' => 6, 'payment_unit' => 'c/kWh',
        ]);
        app(ContractListCacheService::class)->refresh(app(CompanyListCacheService::class));
    }

    private function forbidAnnualCalculation(): void
    {
        $this->partialMock(ContractPriceCalculator::class)->shouldNotReceive('calculate');
        $enabled = app(CanonicalContractPricingService::class)->enabled();
        $canonical = $this->partialMock(CanonicalContractPricingService::class);
        $canonical->shouldReceive('enabled')->andReturn($enabled);
        $canonical->shouldNotReceive('metricsForContracts');
        $canonical->shouldNotReceive('evaluate');
        app()->forgetInstance(ContractListCacheService::class);
    }

    public function test_exact_calculator_action_prepares_destination_and_get_reuses_it(): void
    {
        $this->checkPreparedDestination(false);
    }

    public function test_canonical_calculator_action_prepares_destination_and_get_reuses_it(): void
    {
        $this->checkPreparedDestination(true);
    }

    private function checkPreparedDestination(bool $canonical): void
    {
        config(['canonical_pricing.enabled' => $canonical]);
        app()->forgetScopedInstances();
        $this->seedContract();
        $component = Livewire::test('consumption-calculator')
            ->set('livingArea', 20)
            ->set('electricVehicleKmsPerMonth', 1238);
        $this->assertSame(7312, $component->get('calculationResult')['total']);
        $key = app(ContractListCacheService::class)->getCacheKey(7312);
        $this->assertNull(Cache::get($key));

        $component->call('compareContracts')
            ->assertDispatched('track', eventName: 'Energy Compare Clicked')
            ->assertRedirect('/sahkosopimus?consumption=7312');
        $payload = Cache::get($key);
        $this->assertNotNull($payload);
        $set = app(ContractListCacheService::class)->getCachedMetrics(7312);
        $this->assertSame(7312, $set->consumption());
        $this->assertEqualsWithDelta(438.72, $set->metric('exact')->pricing()->total(), .001);

        $this->forbidAnnualCalculation();
        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            $queries[] = $query->sql;
        });
        $this->get('/sahkosopimus?consumption=7312')->assertOk()->assertSee('Exact fixed');
        $this->get('/sahkosopimus?consumption=7312')->assertOk();
        $this->assertSame($payload, Cache::get($key));
        $this->assertSame($payload, app(ContractListCacheService::class)->getCachedMetrics(7312)->toArray());
        $this->assertFalse(app(PublicPriceCalculationPolicy::class)->allowsCalculation());
        foreach ($queries as $sql) {
            $this->assertDoesNotMatchRegularExpression('/premium_observations|electricity_futures|contract_source_observations/i', $sql);
        }
    }

    public function test_calculator_get_and_unprepared_custom_get_never_prepare_prices(): void
    {
        config(['canonical_pricing.enabled' => false]);
        app()->forgetScopedInstances();
        $this->seedContract();
        $this->forbidAnnualCalculation();
        $service = app(ContractListCacheService::class);
        $this->instance(ContractListCacheService::class, \Mockery::mock($service)->makePartial()
            ->shouldNotReceive('prepareComparisonForConsumption')->getMock());
        $this->get('/sahkosopimus/laskuri')->assertOk();
        $this->get('/sahkosopimus/laskuri?livingArea=20&electricVehicleKmsPerMonth=1238')->assertOk();
        $this->get('/sahkosopimus?consumption=7312')->assertStatus(503);
        $this->assertNull(Cache::get(app(ContractListCacheService::class)->getCacheKey(7312)));
    }

    public function test_client_cannot_replace_or_change_computed_results(): void
    {
        foreach (['calculationResult', 'calculationResult.total'] as $property) {
            app()->forgetScopedInstances();
            $this->forbidAnnualCalculation();
            $this->mock(ContractListCacheService::class)->shouldNotReceive('prepareComparisonForConsumption');
            $this->mock(PublicPriceCalculationPolicy::class)->shouldNotReceive('allowUserAction');
            $component = Livewire::test('consumption-calculator');
            $before = $component->get('calculationResult');
            $cache = Cache::getFacadeRoot();
            $cacheSpy = Cache::spy();
            try {
                $component->set($property, $property === 'calculationResult' ? ['total' => 7312] : 7312);
                $this->fail('Client result updates must be rejected.');
            } catch (CannotUpdateLockedPropertyException) {
                $this->assertSame($before, $component->get('calculationResult'));
                $cacheSpy->shouldNotHaveReceived('put');
                $cacheSpy->shouldNotHaveReceived('forever');
                $cacheSpy->shouldNotHaveReceived('add');
            } finally {
                Cache::swap($cache);
            }
        }
    }

    public function test_invalid_server_result_is_rejected_before_permission_or_preparation(): void
    {
        $this->forbidAnnualCalculation();
        $this->mock(ContractListCacheService::class)->shouldNotReceive('prepareComparisonForConsumption');
        $this->mock(PublicPriceCalculationPolicy::class)->shouldNotReceive('allowUserAction');
        foreach ([[], ['total' => null], ['total' => 0], ['total' => -1], ['total' => 7312.5], ['total' => 'invalid'], ['total' => ContractListCacheService::MAX_COMPARISON_CONSUMPTION + 1]] as $result) {
            $component = new ConsumptionCalculator;
            // Server assignment tests invalid computed state, not client hydration.
            $component->calculationResult = $result;
            try {
                $component->compareContracts();
                $this->fail('Invalid consumption must be rejected.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('comparisonConsumption', $exception->errors());
            }
        }
    }

    public function test_upper_bound_is_exact_and_inclusive(): void
    {
        $this->mock(ContractListCacheService::class)->shouldReceive('prepareComparisonForConsumption')
            ->once()->with(ContractListCacheService::MAX_COMPARISON_CONSUMPTION)
            ->andReturn(ContractMetricSet::fromArray([
                'contracts' => [], 'sorted_ids' => [], 'excluded_ids' => [],
                'consumption' => ContractListCacheService::MAX_COMPARISON_CONSUMPTION,
            ]));
        $this->mock(PublicPriceCalculationPolicy::class)->shouldReceive('allowUserAction')->once();
        $component = new ConsumptionCalculator;
        $component->calculationResult = ['total' => ContractListCacheService::MAX_COMPARISON_CONSUMPTION];
        $component->compareContracts();
        $this->assertSame(ContractListCacheService::MAX_COMPARISON_CONSUMPTION, $component->calculationResult['total']);
    }

    public function test_out_of_domain_result_shows_accessible_notice_without_event_or_redirect(): void
    {
        $this->forbidAnnualCalculation();
        $this->mock(ContractListCacheService::class)->shouldNotReceive('prepareComparisonForConsumption');
        $this->mock(PublicPriceCalculationPolicy::class)->shouldNotReceive('allowUserAction');
        Livewire::test('consumption-calculator')->set('livingArea', 10000)
            ->call('compareContracts')->assertHasErrors(['comparisonConsumption' => 'max'])
            ->assertSee('Sopimusvertailu on saatavilla vuosikulutukselle 1–150 000 kWh.')
            ->assertSeeHtml('role="alert"')->assertNotDispatched('track')->assertNoRedirect();
    }

    public function test_unavailable_preparation_shows_accessible_retry_notice(): void
    {
        $this->forbidAnnualCalculation();
        $this->mock(ContractListCacheService::class)->shouldReceive('prepareComparisonForConsumption')
            ->once()->with(7312)->andThrow(new ContractPriceCacheUnavailable);
        Livewire::test('consumption-calculator')->set('livingArea', 20)->set('electricVehicleKmsPerMonth', 1238)
            ->call('compareContracts')->assertHasErrors('comparisonConsumption')
            ->assertSee('Sopimusvertailu ei ole juuri nyt saatavilla tällä kulutuksella. Yritä myöhemmin uudelleen.')
            ->assertSeeHtml('role="alert"')->assertNotDispatched('track')->assertNoRedirect();
    }

    public function test_unexpected_preparation_failure_is_not_hidden(): void
    {
        $this->mock(ContractListCacheService::class)->shouldReceive('prepareComparisonForConsumption')
            ->once()->with(7312)->andThrow(new \RuntimeException('Storage failure'));
        $component = new ConsumptionCalculator;
        $component->calculationResult = ['total' => 7312];
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Storage failure');
        $component->compareContracts();
    }

    public function test_bill_get_and_head_do_not_compare_sample_inputs(): void
    {
        $this->mock(BillComparisonService::class)->shouldNotReceive('compare');
        $this->get('/maksatko-liikaa')->assertOk()
            ->assertSee('Muokkaa yllä olevia tietoja sähkölaskusi mukaan');
        $this->head('/maksatko-liikaa')->assertOk();
        Livewire::test('bill-comparison')->assertViewHas('resultArray', null)
            ->call('$refresh')->assertViewHas('resultArray', null);
    }

    public function test_bill_actions_keep_exact_period_vat_and_protected_results(): void
    {
        $this->seedContract();
        $component = Livewire::test('bill-comparison')->assertViewHas('resultArray', null)
            ->set('periodPreset', 'custom')
            ->set('startDate', '2026-08-01')
            ->set('endDate', '2026-08-31')
            ->set('kwh', 731.2)
            ->set('totalEur', 60)
            ->set('includesVat', false)
            ->set('annualKwh', 7312)
            ->call('calculate');
        $result = $component->viewData('resultArray');
        $market = collect($result['rows'])->firstWhere('contract_id', 'exact');
        $user = collect($result['rows'])->firstWhere('is_user', true);
        $this->assertSame(43.87, $market['period_cost_eur']);
        $this->assertSame(75.3, $user['period_cost_eur']);
        $this->assertSame(7312.0, $result['annual_kwh']);
        $component->set('kwh', 800);
        $updated = $component->viewData('resultArray');
        $this->assertSame(48.0, collect($updated['rows'])->firstWhere('contract_id', 'exact')['period_cost_eur']);
        foreach (['resultArray', 'calculated', 'errorMessage'] as $property) {
            $this->assertArrayNotHasKey($property, $component->snapshot['data']);
        }
        $this->assertLessThan(5000, strlen(json_encode($component->snapshot)));
    }
}
