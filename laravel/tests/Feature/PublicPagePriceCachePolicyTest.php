<?php

namespace Tests\Feature;

use App\Livewire\CompanyDetail;
use App\Livewire\ContractDetail;
use App\Models\ActiveContract;
use App\Models\Company;
use App\Models\ElectricityContract;
use App\Models\Municipality;
use App\Models\Postcode;
use App\Models\PriceComponent;
use App\Services\Caching\ContractPriceCacheUnavailable;
use App\Services\Caching\PublicPriceCalculationPolicy;
use App\Services\CanonicalPricing\CanonicalContractPricingService;
use App\Services\CanonicalPricing\Enums\BoundaryKind;
use App\Services\CanonicalPricing\Enums\CalculationStatus;
use App\Services\CanonicalPricing\Enums\ComponentType;
use App\Services\CanonicalPricing\Enums\ComponentUnit;
use App\Services\CanonicalPricing\Enums\PhaseKind;
use App\Services\CompanyListCacheService;
use App\Services\ContractListCacheService;
use App\Services\ContractListing\ContractListingPipeline;
use App\Services\ContractPriceCalculator;
use App\Services\DTO\EnergyUsage;
use App\Services\LocalContractsService;
use Database\Factories\Support\CanonicalPricingFixture;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PublicPagePriceCachePolicyTest extends TestCase
{
    use RefreshDatabase;

    private function seedPrices(bool $canonical = false): ElectricityContract
    {
        config(['canonical_pricing.enabled' => $canonical]);
        app()->forgetScopedInstances();
        Cache::flush();
        Company::create(['name' => 'Test Energia', 'name_slug' => 'test-energia']);
        $contract = ElectricityContract::create([
            'id' => 'public-price', 'name' => 'Public price', 'name_slug' => 'public-price',
            'company_name' => 'Test Energia', 'contract_type' => 'FixedTerm',
            'fixed_time_range' => 'Fixed12', 'pricing_model' => 'FixedPrice',
            'metering' => 'General', 'target_group' => 'Household',
            'availability_is_national' => true,
            'order_link' => 'https://seller.example/order',
        ]);
        if ($canonical) {
            $contract->update(CanonicalPricingFixture::attributes(
                phases: [CanonicalPricingFixture::phase(
                    label: 'Current',
                    kind: PhaseKind::CurrentStructured,
                    starts: CanonicalPricingFixture::boundary(BoundaryKind::ContractStart),
                    ends: CanonicalPricingFixture::boundary(BoundaryKind::AfterMonths, '12'),
                    components: [CanonicalPricingFixture::component(
                        ComponentType::EnergyGeneral,
                        6.0,
                        ComponentUnit::CentsPerKwh,
                    )],
                )],
                calculationStatus: CalculationStatus::Exact,
            ));
        }
        ActiveContract::create(['id' => $contract->id]);
        PriceComponent::create([
            'id' => 'public-general', 'electricity_contract_id' => $contract->id,
            'price_component_type' => 'General', 'price_date' => now()->toDateString(),
            'price' => 6, 'payment_unit' => 'c/kWh',
        ]);
        // Producer work occurs before any calculator expectation.
        app(ContractListCacheService::class)->refresh(app(CompanyListCacheService::class));

        return $contract;
    }

    private function forbidCalculation(bool $enabled = false): void
    {
        $this->partialMock(ContractPriceCalculator::class)->shouldNotReceive('calculate');
        $canonical = $this->partialMock(CanonicalContractPricingService::class);
        $canonical->shouldReceive('enabled')->andReturn($enabled);
        $canonical->shouldNotReceive('evaluate');
        $canonical->shouldNotReceive('metricsForContracts');
    }

    public static function pricingModes(): array
    {
        return ['legacy' => [false], 'canonical' => [true]];
    }

    #[DataProvider('pricingModes')]
    public function test_warm_get_detail_reads_every_reference_tier_and_company_and_local_cards(bool $canonical): void
    {
        $contract = $this->seedPrices($canonical);
        $this->forbidCalculation($canonical);
        $this->app->instance('request', Request::create('/public', 'GET'));
        $detail = new class extends ContractDetail
        {
            public function price(int $consumption): ?float
            {
                return $this->pricingViewDataFor($consumption)?->total();
            }
        };
        $detail->contractId = $contract->id;
        foreach ([2000, 5000, 10000, 18000] as $consumption) {
            $this->assertEqualsWithDelta($consumption * 0.06, $detail->price($consumption), 0.000001);
        }
        $this->assertNull($detail->price(7654));
        if ($canonical) {
            $this->assertNotNull($detail->getPricingIntegrityProperty());
            $this->assertNotNull($detail->getPricingComparabilityProperty());
        }
        $this->get('/sahkosopimus/sopimus/'.$contract->id)->assertOk();
        $this->get('/sahkosopimus/sopimus/'.$contract->id.'?kulutus=7654')->assertOk()
            ->assertSee('Vuosihinta ei ole vielä saatavilla tällä kulutuksella')
            ->assertSeeHtml('wire:model.blur="directConsumption"')
            ->assertDontSeeHtml('data-analytics-placement="sticky"')
            ->assertDontSee('0,00 €/kk');
        $this->get('/sahkosopimus/sahkoyhtiot/test-energia')->assertOk();
        $this->get('/sahkosopimus/sahkoyhtiot/test-energia?consumption=7654')->assertOk()
            ->assertSee('Vuosihinnat eivät ole vielä saatavilla tällä kulutuksella');
        $this->get('/sahkosopimus?consumption=7654')->assertStatus(503)
            ->assertSee('Hintatiedot ovat tilapäisesti poissa käytöstä.');
        $detail->contractId = 'absent';
        $absent = new ContractDetail;
        $absent->contractId = 'absent';
        $this->assertSame([], $absent->getCalculatedCostProperty());

        $company = new CompanyDetail;
        $company->mount('test-energia');
        $this->assertEquals(300, $company->getContractsProperty()->first()->calculated_cost['total_cost']);
        $company->consumption = 7654;
        $customCompany = new CompanyDetail;
        $customCompany->mount('test-energia');
        $customCompany->consumption = 7654;
        $this->assertNull($customCompany->getContractsProperty()->first()->calculated_cost);
        $this->assertNull($customCompany->getCompanyStatsProperty()['min_price']);

        $missing = new ElectricityContract(['id' => 'not-cached']);
        $cards = app(ContractListingPipeline::class)->enrichAndSortAnnual(new Collection([$contract, $missing]), 5000, true);
        $this->assertSame([$contract->id], $cards->pluck('id')->all());
        $this->assertSame(! $canonical, $cards->first()->relationLoaded('priceComponents'));
        Company::where('name', 'Test Energia')->update(['postal_name' => 'Test City']);
        $municipality = Municipality::create([
            'code' => '999', 'slug' => 'test-city', 'name' => 'Test City',
            'name_locative' => 'Test City', 'name_genitive' => 'Test City',
        ]);
        $local = app(LocalContractsService::class)->getLocalContracts($municipality, 5000);
        $this->assertSame([$contract->id], $local['local_companies']->pluck('id')->all());
        $this->expectException(ContractPriceCacheUnavailable::class);
        app(ContractListingPipeline::class)->enrichAndSortAnnual(new Collection([$contract]), 7654, true);
    }

    #[DataProvider('pricingModes')]
    public function test_get_and_head_city_tiers_new_missing_contract_and_inactive_detail_are_cache_only(bool $canonical): void
    {
        $contract = $this->seedPrices($canonical);
        Company::where('name', 'Test Energia')->update(['postal_name' => 'Test City']);
        Company::create(['name' => 'Regional Energia', 'name_slug' => 'regional-energia', 'postal_name' => 'Other City']);
        $municipality = Municipality::create([
            'code' => '999', 'slug' => 'test-city', 'name' => 'Test City',
            'name_locative' => 'Test City', 'name_genitive' => 'Test City',
        ]);
        Postcode::create([
            'postcode' => '00100', 'postcode_fi_name' => 'Test City', 'postcode_fi_name_slug' => 'test-city',
            'municipal_code' => '999', 'municipal_name_fi' => 'Test City', 'municipal_name_fi_slug' => 'test-city',
        ]);
        $regional = $this->copyContract($contract, 'regional-price', 'Regional Energia', false);
        $regional->availabilityPostcodes()->attach('00100');
        app(ContractListCacheService::class)->refresh(app(CompanyListCacheService::class));
        // This active contract has valid source prices but no retained metric.
        $missing = $this->copyContract($contract, 'new-not-cached', 'Test Energia');
        $historical = $this->copyContract($contract, 'historical-price', 'Test Energia');
        ActiveContract::where('id', $historical->id)->delete();
        $this->forbidCalculation($canonical);

        foreach (['GET', 'HEAD'] as $method) {
            $this->app->instance('request', Request::create('/public', $method));
            $local = app(LocalContractsService::class)->getLocalContracts($municipality, 5000, '00100');
            $this->assertSame([$contract->id], $local['local_companies']->pluck('id')->all());
            $this->assertSame([$regional->id], $local['regional_contracts']->pluck('id')->all());
            foreach ([2000, 5000, 10000, 18000] as $tier) {
                $detail = new ContractDetail;
                $detail->contractId = $contract->id;
                $detail->consumption = $tier;
                $this->assertEqualsWithDelta($tier * 0.06, $detail->getCalculatedCostProperty()['total_cost'], 0.000001);
            }
            foreach ([
                '/sahkosopimus/sopimus/'.$contract->id.'?kulutus=2000',
                '/sahkosopimus/sopimus/'.$contract->id.'?kulutus=5000',
                '/sahkosopimus/sopimus/'.$contract->id.'?kulutus=10000',
                '/sahkosopimus/sopimus/'.$contract->id.'?kulutus=18000',
                '/sahkosopimus/sopimus/'.$contract->id.'?kulutus=7654',
                '/sahkosopimus/sopimus/'.$missing->id,
                '/sahkosopimus/sopimus/'.$historical->id,
                '/sahkosopimus/sahkoyhtiot/test-energia',
                '/sahkosopimus/sahkoyhtiot/test-energia?consumption=7654',
                '/sahkosopimus/paikkakunnat/test-city?postcodeFilter=00100',
            ] as $uri) {
                $response = $this->call($method, $uri, server: ['HTTP_USER_AGENT' => 'Googlebot']);
                $response->assertOk();
                if ($method === 'GET' && str_contains($uri, 'new-not-cached')) {
                    $response->assertSee('Vuosihinta ei ole vielä saatavilla tällä kulutuksella')
                        ->assertDontSee('0,00 €/kk');
                }
                if ($method === 'GET' && str_contains($uri, 'historical-price')) {
                    $response->assertSee('noindex')->assertDontSee('0,00 €/kk');
                }
                if ($method === 'GET' && str_contains($uri, 'paikkakunnat')) {
                    $response->assertSee($contract->name)->assertSee($regional->name)
                        ->assertDontSee($missing->name);
                }
            }
            $company = new CompanyDetail;
            $company->mount('test-energia');
            $rows = $company->getContractsProperty();
            $this->assertSame([$contract->id, $missing->id], $rows->pluck('id')->all());
            $this->assertNull($rows->last()->calculated_cost['total_cost'] ?? null);
        }
    }

    private function copyContract(ElectricityContract $template, string $id, string $company, bool $national = true): ElectricityContract
    {
        $contract = ElectricityContract::create([
            'id' => $id, 'name' => $id, 'name_slug' => $id, 'company_name' => $company,
            'contract_type' => $template->contract_type, 'fixed_time_range' => $template->fixed_time_range,
            'pricing_model' => $template->pricing_model, 'metering' => $template->metering,
            'target_group' => 'Household', 'availability_is_national' => $national,
            'canonical_pricing' => $template->canonical_pricing,
            'canonical_calculation' => $template->canonical_calculation,
            'canonical_source_consistency' => $template->canonical_source_consistency,
        ]);
        ActiveContract::create(['id' => $id]);
        PriceComponent::create([
            'id' => $id.'-general', 'electricity_contract_id' => $id, 'price_component_type' => 'General',
            'price_date' => now()->toDateString(), 'price' => 6, 'payment_unit' => 'c/kWh',
        ]);

        return $contract;
    }

    public static function guardPlaceholderCases(): array
    {
        return ['new active contract' => [false], 'changed classification' => [true]];
    }

    #[DataProvider('guardPlaceholderCases')]
    public function test_explicit_detail_action_calculates_a_current_valid_guard_placeholder(bool $changedClassification): void
    {
        $template = $this->seedPrices(true);
        if ($changedClassification) {
            $template->update(['target_group' => 'Both']);
            $contract = $template->fresh();
        } else {
            $contract = $this->copyContract($template, 'new-action-price', 'Test Energia');
        }
        $metric = app(ContractListCacheService::class)->getCachedMetrics(5000)?->metric($contract->id);
        $this->assertContains('cached_source_evidence_is_not_current', $metric->pricing()->assumptions());
        $service = app(CanonicalContractPricingService::class);
        $evaluations = [];
        $mock = \Mockery::mock($service);
        $mock->shouldReceive('evaluate')->andReturnUsing(function (ElectricityContract $contract, EnergyUsage $usage) use ($service, &$evaluations): array {
            $evaluations[] = [$contract->id, $usage->total];

            return $service->evaluate($contract, $usage);
        });
        $mock->shouldNotReceive('metricsForContracts');
        $this->instance(CanonicalContractPricingService::class, $mock);
        $this->partialMock(ContractPriceCalculator::class)->shouldNotReceive('calculate');

        $this->get('/sahkosopimus/sopimus/'.$contract->id)->assertOk()
            ->assertSee('Vuosihinta ei ole vielä saatavilla tällä kulutuksella')
            ->assertDontSee('0,00 €/kk');
        $this->head('/sahkosopimus/sopimus/'.$contract->id)->assertOk();
        $this->assertSame([], $evaluations);
        $component = Livewire::test(ContractDetail::class, ['contractId' => $contract->id])
            ->assertSee('Vuosihinta ei ole vielä saatavilla tällä kulutuksella');
        $this->assertSame([], $evaluations);
        $component->call('setConsumption', 5000)
            ->assertSee('300 € vuodessa')
            ->assertDontSee('Vuosihinta ei ole vielä saatavilla tällä kulutuksella')
            ->assertDontSee('0,00 €/kk');
        $this->assertEqualsWithDelta(300.0, $component->instance()->getCalculatedCostProperty()['total_cost'], 0.000001);
        $this->assertSame(1, count(array_filter($evaluations, fn (array $evaluation): bool => $evaluation === [$contract->id, 5000])));
    }

    public function test_explicit_detail_action_keeps_a_genuine_cached_financial_exclusion(): void
    {
        $contract = $this->seedPrices(true);
        $contract->update(CanonicalPricingFixture::attributes(
            phases: [],
            calculationStatus: CalculationStatus::Unsupported,
        ));
        app(ContractListCacheService::class)->refresh(app(CompanyListCacheService::class));
        $this->forbidCalculation(true);

        $component = Livewire::test(ContractDetail::class, ['contractId' => $contract->id])
            ->assertSee('Vuosihintaa ei voi laskea luotettavasti')
            ->call('setConsumption', 5000)
            ->assertSee('Vuosihintaa ei voi laskea luotettavasti');
        $this->assertNull($component->instance()->getCalculatedCostProperty()['total_cost']);
        $this->assertSame('excluded_unknown_future', $component->instance()->getPricingComparabilityProperty());
    }

    public function test_cold_get_and_head_return_unavailable_without_calculation(): void
    {
        $contract = $this->seedPrices();
        Cache::flush();
        $this->forbidCalculation();
        foreach (['GET', 'HEAD'] as $method) {
            foreach ([
                '/sahkosopimus',
                '/sahkosopimus/sopimus/'.$contract->id,
                '/sahkosopimus/sahkoyhtiot/test-energia',
            ] as $uri) {
                $this->call($method, $uri)->assertStatus(503)->assertHeader('Retry-After', '30');
            }
        }
    }

    #[DataProvider('pricingModes')]
    public function test_livewire_custom_consumption_action_and_direct_hook_keep_exact_price(bool $canonical): void
    {
        $contract = $this->seedPrices($canonical);
        $component = Livewire::test(ContractDetail::class, ['contractId' => $contract->id])
            ->call('setConsumption', 7654)
            ->assertSet('consumption', 7654);

        $this->assertEqualsWithDelta(459.24, $component->instance()->getCalculatedCostProperty()['total_cost'], 0.000001);
        $component->set('directConsumption', 8765)->assertSet('consumption', 8765);
        $this->assertEqualsWithDelta(525.90, $component->instance()->getCalculatedCostProperty()['total_cost'], 0.000001);
        $component->set('directConsumption', '')->assertSet('consumption', 8765);
        $this->assertEqualsWithDelta(525.90, $component->instance()->getCalculatedCostProperty()['total_cost'], 0.000001);
        $company = Livewire::test(CompanyDetail::class, ['companySlug' => 'test-energia'])
            ->set('directConsumption', 7654)->assertSet('consumption', 7654);
        $this->assertEqualsWithDelta(459.24, $company->viewData('contracts')->first()->calculated_cost['total_cost'], 0.000001);
        $company->set('directConsumption', '')->assertSet('consumption', 7654);
        $this->assertEqualsWithDelta(459.24, $company->viewData('contracts')->first()->calculated_cost['total_cost'], 0.000001);
    }

    public function test_a_prepared_exact_custom_profile_is_available_on_get_without_calculation(): void
    {
        $contract = $this->seedPrices();
        $this->app->instance('request', Request::create('/livewire/update', 'POST'));
        app(PublicPriceCalculationPolicy::class)->allowUserAction();
        app(ContractListCacheService::class)->prepareComparisonForConsumption(7654);
        $this->forbidCalculation();
        $this->get('/sahkosopimus?consumption=7654')->assertOk()->assertSee($contract->name);
        $this->get('/sahkosopimus/sopimus/'.$contract->id.'?kulutus=7654')->assertOk()
            ->assertDontSee('Vuosihinta ei ole vielä saatavilla tällä kulutuksella');
        $detail = new ContractDetail;
        $detail->contractId = $contract->id;
        $detail->consumption = 7654;
        $this->assertEqualsWithDelta(459.24, $detail->getCalculatedCostProperty()['total_cost'], 0.000001);
    }

    public function test_explicit_custom_action_is_exact_and_permission_does_not_cross_requests(): void
    {
        $contract = $this->seedPrices();
        $this->app->instance('request', Request::create('/livewire/update', 'POST'));
        $policy = app(PublicPriceCalculationPolicy::class);
        $this->assertFalse($policy->allowsCalculation());
        $detail = new ContractDetail;
        $detail->contractId = $contract->id;
        $detail->setConsumption(7654);
        $this->assertTrue($policy->allowsCalculation());
        $this->assertEqualsWithDelta(459.24, $detail->getCalculatedCostProperty()['total_cost'], 0.000001);
        $this->app->instance('request', Request::create('/livewire/update', 'POST'));
        $this->assertFalse($policy->allowsCalculation());
        foreach (['GET', 'HEAD'] as $method) {
            $this->app->instance('request', Request::create('/public?consumption=7654', $method));
            $policy->allowUserAction();
            $this->assertFalse($policy->allowsCalculation());
        }
    }
}
