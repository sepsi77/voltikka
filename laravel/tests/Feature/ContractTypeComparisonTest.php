<?php

namespace Tests\Feature;

use App\Livewire\ContractTypeComparison;
use App\Models\ActiveContract;
use App\Models\Company;
use App\Models\ElectricityContract;
use App\Models\PriceComponent;
use App\Models\SpotPriceAverage;
use App\Services\CanonicalPricing\CanonicalContractPriceCalculator;
use App\Services\CanonicalPricing\CanonicalContractPricingService;
use App\Services\CanonicalPricing\CanonicalPricingParser;
use App\Services\CanonicalPricing\ContractPricingIntegrityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

class ContractTypeComparisonTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Company::create([
            'name' => 'Test Energia Oy',
            'name_slug' => 'test-energia-oy',
            'company_url' => 'https://example.test',
        ]);
    }

    public function test_initial_render_does_not_dump_all_contract_names(): void
    {
        $this->createContract('spot-cheapest', 'Halpa Spot', 'Spot', 0.2);
        $this->createContract('spot-extra', 'Crawler Dump Spot Name', 'Spot', 5.0);
        $this->createContract('fixed-cheapest', 'Halpa Kiinteä', 'FixedPrice', 4.0);
        $this->createContract('fixed-extra', 'Crawler Dump Fixed Name', 'FixedPrice', 25.0);

        Livewire::test(ContractTypeComparison::class)
            ->assertSee('Vaihda sopimus')
            ->assertSee('Halpa Spot')
            ->assertSee('Halpa Kiinteä')
            ->assertDontSee('Crawler Dump Spot Name')
            ->assertDontSee('Crawler Dump Fixed Name');
    }

    public function test_contract_search_renders_matching_results_only_after_interaction(): void
    {
        $this->createContract('spot-cheapest', 'Halpa Spot', 'Spot', 0.2);
        $this->createContract('spot-extra', 'Searchable Spot Name', 'Spot', 5.0);
        $this->createContract('fixed-cheapest', 'Halpa Kiinteä', 'FixedPrice', 4.0);

        Livewire::test(ContractTypeComparison::class)
            ->assertDontSee('Searchable Spot Name')
            ->call('openSelectorA')
            ->set('contractSearchA', 'Searchable')
            ->assertSee('Searchable Spot Name');
    }

    public function test_canonical_chart_winner_savings_and_display_rates_use_the_same_corrected_outcomes(): void
    {
        config()->set('canonical_pricing.enabled', true);

        $fixedTerm = $this->createCanonicalContract('canonical-varying', 'FixedTerm', 'FixedPrice', [
            $this->phase([$this->canonicalComponent('energy_general', 10.0, normalAmount: 20.0)], 'contract_start', 'after_months', '6'),
            $this->phase([$this->canonicalComponent('energy_general', 20.0)], 'after_months', 'none', null, '6'),
        ], attributes: ['fixed_time_range' => 'Fixed12']);
        $openEnded = $this->createCanonicalContract('canonical-open', 'OpenEnded', 'FixedPrice', [
            $this->phase([$this->canonicalComponent('energy_general', 12.0)]),
        ]);
        $this->createRelationalPrice($fixedTerm, 'General', 1.0);
        $this->createRelationalPrice($openEnded, 'General', 50.0);

        $component = Livewire::test(ContractTypeComparison::class, [
            'comparisonMode' => 'contract_term',
            'selectedContractA' => $fixedTerm->id,
            'selectedContractB' => $openEnded->id,
        ]);

        $projectedA = $component->viewData('projectedCostsA');
        $projectedB = $component->viewData('projectedCostsB');
        $result = $component->viewData('comparisonResult');
        $displayA = $component->instance()->getDisplayPrice($fixedTerm);
        $displayB = $component->instance()->getDisplayPrice($openEnded);

        $this->assertSame('canonical', $projectedA['pricingBasis']);
        $this->assertCount(12, $projectedA['monthly']);
        $this->assertGreaterThan($projectedA['monthly'][0], $projectedA['monthly'][6]);
        $this->assertEqualsWithDelta(750.0, array_sum($projectedA['monthly']), 0.05);
        $this->assertEqualsWithDelta(750.0, $projectedA['total'], 0.01);
        $this->assertEqualsWithDelta(600.0, $projectedB['total'], 0.01);
        $this->assertSame('B', $result['winner']);
        $this->assertSame(150.0, $result['savings']);
        $this->assertSame(10.0, $displayA['generalRate']);
        $this->assertSame(12.0, $displayB['generalRate']);
        $this->assertEqualsWithDelta(62.5, $displayA['avgMonthlyCost'], 0.01);
        $component->assertSee('Tarjousetu 250 € 12 kuukauden vertailujaksolta');
    }

    public function test_canonical_only_contract_is_priceable_and_missing_canonical_data_never_falls_back_to_rows(): void
    {
        config()->set('canonical_pricing.enabled', true);

        $canonicalOnly = $this->createCanonicalContract('canonical-only-widget', 'FixedTerm', 'FixedPrice', [
            $this->phase([$this->canonicalComponent('energy_general', 8.0)]),
        ], attributes: ['fixed_time_range' => 'Fixed12']);
        $missing = $this->createCanonicalContract('canonical-missing-widget', 'OpenEnded', 'FixedPrice', null);
        $this->createRelationalPrice($missing, 'General', 1.0);

        $component = Livewire::test(ContractTypeComparison::class, [
            'comparisonMode' => 'contract_term',
            'selectedContractA' => $canonicalOnly->id,
            'selectedContractB' => $missing->id,
        ]);

        $this->assertEqualsWithDelta(400.0, $component->viewData('projectedCostsA')['total'], 0.01);
        $this->assertFalse($component->viewData('projectedCostsB')['available']);
        $this->assertSame([], $component->viewData('projectedCostsB')['monthly']);
        $this->assertFalse($component->viewData('comparisonResult')['hasResult']);
        $this->assertNull($component->viewData('comparisonResult')['winner']);
        $component
            ->assertSee('Vertailua ei voi laskea luotettavasti')
            ->assertDontSee('1,00 c/kWh');
    }

    public function test_one_or_both_canonical_exclusions_never_get_a_zero_series_or_winner(): void
    {
        config()->set('canonical_pricing.enabled', true);

        $excludedA = $this->createExcludedContract('excluded-a', 'FixedTerm');
        $excludedB = $this->createExcludedContract('excluded-b', 'OpenEnded');
        $validB = $this->createCanonicalContract('valid-b', 'OpenEnded', 'FixedPrice', [
            $this->phase([$this->canonicalComponent('energy_general', 7.0)]),
        ]);
        $this->createRelationalPrice($excludedA, 'General', 0.1);
        $this->createRelationalPrice($excludedB, 'General', 0.2);

        $oneExcluded = Livewire::test(ContractTypeComparison::class, [
            'comparisonMode' => 'contract_term',
            'selectedContractA' => $excludedA->id,
            'selectedContractB' => $validB->id,
        ]);
        $this->assertSame([], $oneExcluded->viewData('projectedCostsA')['monthly']);
        $this->assertNull($oneExcluded->viewData('comparisonResult')['winner']);

        $bothExcluded = Livewire::test(ContractTypeComparison::class, [
            'comparisonMode' => 'contract_term',
            'selectedContractA' => $excludedA->id,
            'selectedContractB' => $excludedB->id,
        ]);
        $this->assertSame([], $bothExcluded->viewData('projectedCostsA')['monthly']);
        $this->assertSame([], $bothExcluded->viewData('projectedCostsB')['monthly']);
        $this->assertNull($bothExcluded->viewData('comparisonResult')['winner']);
    }

    public function test_package_uses_canonical_monthly_allowance_and_is_not_an_offer(): void
    {
        config()->set('canonical_pricing.enabled', true);

        $fixedTerm = $this->createCanonicalContract('package-baseline', 'FixedTerm', 'FixedPrice', [
            $this->phase([$this->canonicalComponent('energy_general', 20.0)]),
        ], attributes: ['fixed_time_range' => 'Fixed12']);
        $package = $this->createCanonicalContract('package-widget', 'OpenEnded', 'FixedPrice', [
            $this->phase([], package: [
                'monthly_fee_eur' => 21.0,
                'included_kwh' => 150.0,
                'allowance_cadence' => 'monthly',
                'excess_rate_cents_per_kwh' => 16.6,
            ]),
        ]);
        $this->createRelationalPrice($package, 'General', 99.0);

        $component = Livewire::test(ContractTypeComparison::class, [
            'comparisonMode' => 'contract_term',
            'selectedContractA' => $fixedTerm->id,
            'selectedContractB' => $package->id,
        ]);

        $projected = $component->viewData('projectedCostsB');
        $display = $component->instance()->getDisplayPrice($package);
        $this->assertEqualsWithDelta(783.2, $projected['total'], 0.01);
        $this->assertCount(12, $projected['monthly']);
        $this->assertEqualsWithDelta(783.2, array_sum($projected['monthly']), 0.05);
        $this->assertSame('package', $display['type']);
        $this->assertSame(150.0, $display['includedKwh']);
        $component
            ->assertSee('Kuukausipaketti')
            ->assertSee('150 kWh/kk')
            ->assertDontSee('Tarjousetu');
    }

    public function test_market_reset_keeps_the_canonical_monthly_estimate_and_disclosure(): void
    {
        config()->set('canonical_pricing.enabled', true);

        $fixedTerm = $this->createCanonicalContract('reset-baseline', 'FixedTerm', 'FixedPrice', [
            $this->phase([$this->canonicalComponent('energy_general', 9.0)]),
        ], attributes: ['fixed_time_range' => 'Fixed12']);
        $reset = $this->createCanonicalContract('reset-widget', 'OpenEnded', 'FixedPrice', [
            $this->phase([$this->canonicalComponent('energy_general', 7.0)], 'contract_start', 'after_months', '1'),
        ], calculationStatus: 'estimate_required', issues: ['recurring_reset_requires_estimate'], recurringCadence: 'quarterly');

        $component = Livewire::test(ContractTypeComparison::class, [
            'comparisonMode' => 'contract_term',
            'selectedContractA' => $fixedTerm->id,
            'selectedContractB' => $reset->id,
        ]);

        $projected = $component->viewData('projectedCostsB');
        $this->assertSame('comparable_estimate', $projected['comparability']);
        $this->assertSame('hold_current_recurring_price', $projected['estimateMethod']);
        $this->assertCount(12, $projected['monthly']);
        $component->assertSee('Arvio – tulevat hinnanmuutokset on arvioitu');
    }

    public function test_short_term_and_hybrid_keep_their_annualized_and_base_only_disclosures(): void
    {
        config()->set('canonical_pricing.enabled', true);

        $short = $this->createCanonicalContract('short-widget', 'FixedTerm', 'FixedPrice', [
            $this->phase([
                $this->canonicalComponent('energy_general', 5.0),
                $this->canonicalComponent('monthly_fee', 0.0, 'eur_per_month', 4.0),
            ], 'contract_start', 'after_months', '6'),
        ], calculationStatus: 'incomplete', misleading: 'detected', issues: ['future_price_unknown'], attributes: ['fixed_time_range' => 'Fixed6']);
        $hybrid = $this->createCanonicalContract('hybrid-widget', 'OpenEnded', 'Hybrid', [
            $this->phase([$this->canonicalComponent('energy_general', 8.0)]),
        ], calculationStatus: 'unsupported', consumptionEffect: true);

        $component = Livewire::test(ContractTypeComparison::class, [
            'comparisonMode' => 'contract_term',
            'selectedContractA' => $short->id,
            'selectedContractB' => $hybrid->id,
        ]);

        $this->assertSame('term_price_only', $component->viewData('projectedCostsA')['comparability']);
        $this->assertSame('base_only_hybrid', $component->viewData('projectedCostsB')['comparability']);
        $this->assertTrue($component->viewData('projectedCostsA')['isEstimate']);
        $this->assertTrue($component->viewData('projectedCostsB')['isEstimate']);
        $component
            ->assertSee('Vuositasolle muunnettu 6 kk vertailuhinta')
            ->assertSee('Tarjousetu 24 € 6 kk sopimusajalta')
            ->assertSee('Arvio – ei sisällä kulutusvaikutusta');
    }

    public function test_spot_article_keeps_spot_as_the_anchor_in_both_modes_with_canonical_results(): void
    {
        config()->set('canonical_pricing.enabled', true);
        $this->createSpotAverage();

        $spot = $this->createCanonicalContract('spot-anchor', 'OpenEnded', 'Spot', [
            $this->phase([
                $this->canonicalComponent('spot_margin', 0.5),
                $this->canonicalComponent('monthly_fee', 2.0, 'eur_per_month'),
            ]),
        ], calculationStatus: 'estimate_required');
        $fixed = $this->createCanonicalContract('fixed-anchor', 'FixedTerm', 'FixedPrice', [
            $this->phase([$this->canonicalComponent('energy_general', 8.0)]),
        ], attributes: ['fixed_time_range' => 'Fixed12']);

        $pricingMode = Livewire::test(ContractTypeComparison::class, [
            'comparisonMode' => 'pricing_model',
            'comparisonContext' => 'spot_article',
            'selectedContractA' => $spot->id,
        ]);
        $this->assertSame('Spot', $pricingMode->viewData('modeConfig')['typeA']);
        $this->assertTrue($pricingMode->viewData('projectedCostsA')['available']);

        $termMode = Livewire::test(ContractTypeComparison::class, [
            'comparisonMode' => 'contract_term',
            'comparisonContext' => 'spot_article',
            'selectedContractA' => $spot->id,
            'selectedContractB' => $fixed->id,
        ]);
        $this->assertSame('Spot', $termMode->viewData('modeConfig')['typeA']);
        $this->assertSame('FixedTerm', $termMode->viewData('modeConfig')['typeB']);
        $this->assertTrue($termMode->viewData('comparisonResult')['hasResult']);
        $termMode->assertSee('Arvio – pörssihinta perustuu 365 päivän keskiarvoon');
    }

    public function test_canonical_render_does_not_query_components_and_evaluates_each_candidate_once(): void
    {
        config()->set('canonical_pricing.enabled', true);

        $fixedTerm = $this->createCanonicalContract('memo-a', 'FixedTerm', 'FixedPrice', [
            $this->phase([$this->canonicalComponent('energy_general', 6.0)]),
        ], attributes: ['fixed_time_range' => 'Fixed12']);
        $openEnded = $this->createCanonicalContract('memo-b', 'OpenEnded', 'FixedPrice', [
            $this->phase([$this->canonicalComponent('energy_general', 7.0)]),
        ]);
        $this->createRelationalPrice($fixedTerm, 'General', 1.0);
        $this->createRelationalPrice($openEnded, 'General', 2.0);

        $service = \Mockery::mock(CanonicalContractPricingService::class, [
            app(CanonicalPricingParser::class),
            app(CanonicalContractPriceCalculator::class),
            app(ContractPricingIntegrityService::class),
        ])->makePartial();
        $service->shouldReceive('evaluate')->twice()->passthru();
        $this->app->instance(CanonicalContractPricingService::class, $service);

        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        Livewire::test(ContractTypeComparison::class, [
            'comparisonMode' => 'contract_term',
            'selectedContractA' => $fixedTerm->id,
            'selectedContractB' => $openEnded->id,
        ])->assertSee('Edullisempi');

        $this->assertSame([], array_values(array_filter(
            $queries,
            fn (string $sql): bool => str_contains($sql, 'price_components'),
        )));
    }

    public function test_feature_off_keeps_relational_chart_winner_and_display_rates(): void
    {
        config()->set('canonical_pricing.enabled', false);

        $fixedTerm = $this->createCanonicalContract('legacy-a', 'FixedTerm', 'FixedPrice', [
            $this->phase([$this->canonicalComponent('energy_general', 40.0)]),
        ], attributes: ['fixed_time_range' => 'Fixed12']);
        $openEnded = $this->createCanonicalContract('legacy-b', 'OpenEnded', 'FixedPrice', [
            $this->phase([$this->canonicalComponent('energy_general', 1.0)]),
        ]);
        $this->createRelationalPrice($fixedTerm, 'General', 3.0);
        $this->createRelationalPrice($openEnded, 'General', 6.0);

        $component = Livewire::test(ContractTypeComparison::class, [
            'comparisonMode' => 'contract_term',
            'selectedContractA' => $fixedTerm->id,
            'selectedContractB' => $openEnded->id,
        ]);

        $this->assertSame('legacy_relational', $component->viewData('projectedCostsA')['pricingBasis']);
        $this->assertSame('A', $component->viewData('comparisonResult')['winner']);
        $this->assertSame(3.0, $component->instance()->getDisplayPrice($fixedTerm)['generalRate']);
    }

    private function createContract(string $id, string $name, string $pricingModel, float $energyPrice): ElectricityContract
    {
        $contract = ElectricityContract::create([
            'id' => $id,
            'company_name' => 'Test Energia Oy',
            'name' => $name,
            'contract_type' => 'OpenEnded',
            'metering' => 'General',
            'pricing_model' => $pricingModel,
            'target_group' => 'Household',
            'availability_is_national' => true,
        ]);

        ActiveContract::create(['id' => $contract->id]);

        PriceComponent::create([
            'id' => 'pc-general-'.$id,
            'electricity_contract_id' => $id,
            'price_component_type' => 'General',
            'price_date' => now()->format('Y-m-d'),
            'price' => $energyPrice,
            'payment_unit' => 'c/kWh',
        ]);

        PriceComponent::create([
            'id' => 'pc-monthly-'.$id,
            'electricity_contract_id' => $id,
            'price_component_type' => 'Monthly',
            'price_date' => now()->format('Y-m-d'),
            'price' => 2.95,
            'payment_unit' => 'EUR/month',
        ]);

        return $contract;
    }

    /**
     * @param  list<array<string, mixed>>|null  $phases
     * @param  list<string>  $issues
     * @param  array<string, mixed>  $attributes
     */
    private function createCanonicalContract(
        string $id,
        string $contractType,
        string $pricingModel,
        ?array $phases,
        string $calculationStatus = 'exact',
        string $misleading = 'not_detected',
        array $issues = [],
        bool $consumptionEffect = false,
        array $attributes = [],
        string $recurringCadence = 'none',
    ): ElectricityContract {
        $contract = ElectricityContract::create(array_merge([
            'id' => $id,
            'company_name' => 'Test Energia Oy',
            'name' => 'Contract '.$id,
            'contract_type' => $contractType,
            'metering' => 'General',
            'pricing_model' => $pricingModel,
            'target_group' => 'Household',
            'availability_is_national' => true,
            'canonical_pricing' => $phases === null ? null : [
                'phases' => $phases,
                'recurring_schedule' => [
                    'present' => $recurringCadence !== 'none',
                    'cadence' => $recurringCadence,
                    'current_period_start' => null,
                    'current_period_end' => null,
                    'future_price_known' => null,
                    'description' => null,
                    'evidence' => [],
                ],
                'consumption_effect' => [
                    'present' => $consumptionEffect,
                    'applies_to' => $consumptionEffect ? 'base_contract' : 'unknown',
                    'cadence' => 'none',
                    'expected_cents_per_kwh' => null,
                    'typical_min_cents_per_kwh' => null,
                    'typical_max_cents_per_kwh' => null,
                    'hard_min_cents_per_kwh' => null,
                    'hard_max_cents_per_kwh' => null,
                    'uncapped' => null,
                    'description' => null,
                    'evidence' => [],
                ],
            ],
            'canonical_calculation' => $phases === null ? null : [
                'status' => $calculationStatus,
                'missing_facts' => [],
                'required_assumptions' => [],
            ],
            'canonical_source_consistency' => $phases === null ? null : [
                'misleading_first_12_months' => $misleading,
                'structured_pricing_status' => 'complete',
                'issue_codes' => $issues,
            ],
        ], $attributes));

        ActiveContract::create(['id' => $contract->id]);

        return $contract;
    }

    private function createExcludedContract(string $id, string $contractType): ElectricityContract
    {
        return $this->createCanonicalContract(
            $id,
            $contractType,
            'FixedPrice',
            [$this->phase([$this->canonicalComponent('energy_general', 2.0)], 'contract_start', 'after_months', '1')],
            calculationStatus: 'estimate_required',
            misleading: 'detected',
            issues: ['future_price_unknown'],
            attributes: $contractType === 'FixedTerm' ? ['fixed_time_range' => 'Fixed12'] : [],
        );
    }

    /**
     * @param  list<array<string, mixed>>  $components
     * @param  array<string, mixed>|null  $package
     * @return array<string, mixed>
     */
    private function phase(
        array $components,
        string $startKind = 'contract_start',
        string $endKind = 'none',
        ?string $endValue = null,
        ?string $startValue = null,
        ?array $package = null,
    ): array {
        return [
            'label' => 'phase',
            'phase_kind' => 'current_structured',
            'starts' => ['kind' => $startKind, 'value' => $startValue],
            'ends' => ['kind' => $endKind, 'value' => $endValue],
            'components' => $components,
            'package' => $package,
            'evidence' => [],
        ];
    }

    /** @return array<string, mixed> */
    private function canonicalComponent(
        string $type,
        float $amount,
        string $unit = 'cents_per_kwh',
        ?float $normalAmount = null,
    ): array {
        return [
            'component_type' => $type,
            'amount' => $amount,
            'normal_amount' => $normalAmount,
            'unit' => $unit,
            'vat_status' => 'included',
            'price_role' => 'current',
            'source_kind' => 'both',
            'evidence' => [],
        ];
    }

    private function createRelationalPrice(
        ElectricityContract $contract,
        string $type,
        float $price,
        string $unit = 'c/kWh',
    ): void {
        PriceComponent::create([
            'id' => 'rel-'.$contract->id.'-'.$type,
            'electricity_contract_id' => $contract->id,
            'price_component_type' => $type,
            'price_date' => now()->toDateString(),
            'price' => $price,
            'payment_unit' => $unit,
        ]);
    }

    private function createSpotAverage(): void
    {
        SpotPriceAverage::create([
            'region' => 'FI',
            'period_type' => SpotPriceAverage::PERIOD_ROLLING_365D,
            'period_start' => now()->subYear()->toDateString(),
            'period_end' => now()->toDateString(),
            'avg_price_without_tax' => 5.5,
            'avg_price_with_tax' => 7.0,
            'day_avg_without_tax' => 6.0,
            'day_avg_with_tax' => 7.5,
            'night_avg_without_tax' => 4.8,
            'night_avg_with_tax' => 6.0,
            'min_price_without_tax' => -1.0,
            'max_price_without_tax' => 20.0,
            'hours_count' => 8760,
        ]);
    }
}
