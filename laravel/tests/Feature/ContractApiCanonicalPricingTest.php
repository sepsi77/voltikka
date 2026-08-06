<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\ElectricityContract;
use App\Services\CanonicalPricing\Enums\AllowanceCadence;
use App\Services\CanonicalPricing\Enums\BoundaryKind;
use App\Services\CanonicalPricing\Enums\CalculationStatus;
use App\Services\CanonicalPricing\Enums\ComponentType;
use App\Services\CanonicalPricing\Enums\ComponentUnit;
use App\Services\CanonicalPricing\Enums\MisleadingState;
use App\Services\CanonicalPricing\Enums\PhaseKind;
use App\Services\CanonicalPricing\Enums\PriceRole;
use Database\Factories\Support\CanonicalPricingFixture;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ContractApiCanonicalPricingTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('canonical_pricing.enabled', true);
        app()->forgetScopedInstances();
        $this->company = Company::create([
            'name' => 'Canonical Energy Oy',
            'name_slug' => 'canonical-energy-oy',
            'company_url' => 'https://example.test',
        ]);
    }

    public function test_list_and_show_publish_corrected_canonical_prices_without_relational_rows(): void
    {
        $this->createCanonicalContract(
            'corrected-api',
            [CanonicalPricingFixture::phase(
                label: 'current',
                kind: PhaseKind::CurrentStructured,
                starts: CanonicalPricingFixture::boundary(BoundaryKind::ContractStart),
                ends: CanonicalPricingFixture::boundary(BoundaryKind::None),
                components: [
                    CanonicalPricingFixture::component(ComponentType::EnergyGeneral, 9.0, ComponentUnit::CentsPerKwh),
                    CanonicalPricingFixture::component(ComponentType::MonthlyFee, 4.0, ComponentUnit::EurPerMonth),
                ],
            )],
            relationalPrices: [
                [
                    'id' => 'pc-corrected-api-General',
                    'price_component_type' => 'General',
                    'price_date' => now()->toDateString(),
                    'price' => 1.0,
                    'payment_unit' => 'c/kWh',
                ],
                [
                    'id' => 'pc-corrected-api-Monthly',
                    'price_component_type' => 'Monthly',
                    'price_date' => now()->toDateString(),
                    'price' => 99.0,
                    'payment_unit' => 'EUR/month',
                ],
            ],
        );

        $list = $this->getJson('/api/contracts?consumption=5000');
        $list->assertOk()
            ->assertJsonMissingPath('data.0.price_components')
            ->assertJsonPath('data.0.current_pricing.pricing_basis', 'canonical')
            ->assertJsonPath('data.0.current_pricing.general_kwh_price', 9)
            ->assertJsonPath('data.0.current_pricing.monthly_fixed_fee', 4)
            ->assertJsonPath('data.0.current_pricing.is_estimate', true)
            ->assertJsonPath('data.0.current_pricing.estimate_method', 'hold_current_supplier_price')
            ->assertJsonPath('data.0.current_pricing.supplier_adjusted_estimate.basis', 'hold_flat')
            ->assertJsonPath('data.0.calculated_cost.pricing_basis', 'canonical')
            ->assertJsonPath('data.0.calculated_cost.supplier_adjusted_estimate.monthly_fee_assumption', 'held_flat');
        $this->assertEqualsWithDelta(498.0, $list->json('data.0.calculated_cost.total_cost'), 0.01);

        $show = $this->getJson('/api/contracts/corrected-api?consumption=5000');
        $show->assertOk()
            ->assertJsonMissingPath('data.price_components')
            ->assertJsonPath('data.current_pricing.general_kwh_price', 9)
            ->assertJsonPath('data.current_pricing.monthly_fixed_fee', 4);
        $this->assertEqualsWithDelta(498.0, $show->json('data.calculated_cost.total_cost'), 0.01);
    }

    public function test_canonical_missing_rate_is_not_filled_from_a_relational_row(): void
    {
        $this->createCanonicalContract(
            'missing-rate-api',
            [CanonicalPricingFixture::phase(
                label: 'current',
                kind: PhaseKind::CurrentStructured,
                starts: CanonicalPricingFixture::boundary(BoundaryKind::ContractStart),
                ends: CanonicalPricingFixture::boundary(BoundaryKind::None),
                components: [
                    CanonicalPricingFixture::component(ComponentType::MonthlyFee, 3.0, ComponentUnit::EurPerMonth),
                ],
            )],
            relationalPrices: [[
                'id' => 'pc-missing-rate-api-General',
                'price_component_type' => 'General',
                'price_date' => now()->toDateString(),
                'price' => 2.0,
                'payment_unit' => 'c/kWh',
            ]],
        );

        $response = $this->getJson('/api/contracts/missing-rate-api?consumption=5000');

        $response->assertOk()
            ->assertJsonMissingPath('data.price_components')
            ->assertJsonPath('data.current_pricing.availability', 'available')
            ->assertJsonPath('data.current_pricing.general_kwh_price', null)
            ->assertJsonPath('data.calculated_cost.general_kwh_price', null);
        $this->assertEqualsWithDelta(36.0, $response->json('data.calculated_cost.total_cost'), 0.01);
    }

    public function test_canonical_only_contract_exposes_unit_price_and_total_in_the_list(): void
    {
        $this->createCanonicalContract('canonical-only-api', [
            CanonicalPricingFixture::phase(
                label: 'current',
                kind: PhaseKind::CurrentStructured,
                starts: CanonicalPricingFixture::boundary(BoundaryKind::ContractStart),
                ends: CanonicalPricingFixture::boundary(BoundaryKind::None),
                components: [
                    CanonicalPricingFixture::component(ComponentType::EnergyGeneral, 7.5, ComponentUnit::CentsPerKwh),
                ],
            ),
        ]);

        $withoutCalculation = $this->getJson('/api/contracts');
        $withoutCalculation->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonMissingPath('data.0.price_components')
            ->assertJsonMissingPath('data.0.calculated_cost')
            ->assertJsonPath('data.0.current_pricing.general_kwh_price', 7.5);

        $response = $this->getJson('/api/contracts?consumption=5000');
        $response->assertOk()
            ->assertJsonPath('data.0.current_pricing.general_kwh_price', 7.5);
        $this->assertEqualsWithDelta(375.0, $response->json('data.0.calculated_cost.total_cost'), 0.01);
    }

    public function test_excluded_contract_has_typed_unavailable_state_and_no_raw_price_in_list_or_show(): void
    {
        $this->createCanonicalContract(
            'excluded-api',
            [CanonicalPricingFixture::phase(
                label: 'intro',
                kind: PhaseKind::Introductory,
                starts: CanonicalPricingFixture::boundary(BoundaryKind::ContractStart),
                ends: CanonicalPricingFixture::boundary(BoundaryKind::AfterMonths, '1'),
                components: [
                    CanonicalPricingFixture::component(ComponentType::EnergyGeneral, 2.0, ComponentUnit::CentsPerKwh),
                ],
            )],
            calculationStatus: CalculationStatus::EstimateRequired,
            misleading: MisleadingState::Detected,
            issues: ['future_price_unknown'],
            relationalPrices: [[
                'id' => 'pc-excluded-api-General',
                'price_component_type' => 'General',
                'price_date' => now()->toDateString(),
                'price' => 0.5,
                'payment_unit' => 'c/kWh',
            ]],
        );

        foreach (['/api/contracts?consumption=5000' => 'data.0', '/api/contracts/excluded-api?consumption=5000' => 'data'] as $url => $path) {
            $response = $this->getJson($url);
            $response->assertOk()
                ->assertJsonMissingPath($path.'.price_components')
                ->assertJsonPath($path.'.current_pricing.availability', 'unavailable')
                ->assertJsonPath($path.'.current_pricing.comparability', 'excluded_unknown_future')
                ->assertJsonPath($path.'.current_pricing.exclusion_reason', 'excluded_unknown_future')
                ->assertJsonPath($path.'.current_pricing.general_kwh_price', null)
                ->assertJsonMissingPath($path.'.current_pricing.integrity.promo_rate_cents')
                ->assertJsonMissingPath($path.'.current_pricing.integrity.first_year_impact_eur')
                ->assertJsonPath($path.'.calculated_cost.total_cost', null);
        }
    }

    public function test_package_exposes_typed_facts_without_promotion_state(): void
    {
        $this->createCanonicalContract('package-api', [
            CanonicalPricingFixture::packagePhase(
                label: 'current',
                kind: PhaseKind::CurrentStructured,
                starts: CanonicalPricingFixture::boundary(BoundaryKind::ContractStart),
                ends: CanonicalPricingFixture::boundary(BoundaryKind::None),
                monthlyFeeEur: 21.0,
                includedKwh: 150.0,
                allowanceCadence: AllowanceCadence::Monthly,
                excessRateCentsPerKwh: 16.6,
            ),
        ]);

        $response = $this->getJson('/api/contracts?consumption=5000');

        $response->assertOk()
            ->assertJsonPath('data.0.pricing_has_discounts', false)
            ->assertJsonPath('data.0.current_pricing.includes_discounts', false)
            ->assertJsonPath('data.0.current_pricing.energy_package.monthly_fee_eur', 21)
            ->assertJsonPath('data.0.current_pricing.energy_package.included_kwh', 150)
            ->assertJsonPath('data.0.current_pricing.energy_package.allowance_cadence', 'monthly')
            ->assertJsonPath('data.0.current_pricing.energy_package.excess_rate_cents_per_kwh', 16.6)
            ->assertJsonPath('data.0.calculated_cost.includes_discounts', false);
    }

    public function test_short_fixed_term_exposes_annualized_and_real_term_benefits(): void
    {
        $this->travelTo('2026-08-01 12:00:00');

        $this->createCanonicalContract(
            'short-term-api',
            [CanonicalPricingFixture::phase(
                label: 'term',
                kind: PhaseKind::Introductory,
                starts: CanonicalPricingFixture::boundary(BoundaryKind::ContractStart),
                ends: CanonicalPricingFixture::boundary(BoundaryKind::AfterMonths, '6'),
                components: [
                    CanonicalPricingFixture::component(ComponentType::EnergyGeneral, 5.0, ComponentUnit::CentsPerKwh),
                    CanonicalPricingFixture::component(
                        ComponentType::MonthlyFee,
                        0.0,
                        ComponentUnit::EurPerMonth,
                        PriceRole::Current,
                        normalAmount: 5.0,
                    ),
                ],
            )],
            calculationStatus: CalculationStatus::Incomplete,
            misleading: MisleadingState::Detected,
            issues: ['future_price_omitted', 'future_price_unknown'],
            attributes: [
                'contract_type' => 'FixedTerm',
                'fixed_time_range' => 'Fixed6',
            ],
        );

        $response = $this->getJson('/api/contracts/short-term-api?consumption=5000');

        $response->assertOk()
            ->assertJsonPath('data.current_pricing.comparability', 'term_price_only')
            ->assertJsonPath('data.calculated_cost.term_months', 6)
            ->assertJsonPath('data.calculated_cost.contract_term.months', 6);

        $annualizedSaving = $response->json('data.calculated_cost.discount_savings_total');
        $termSaving = $response->json('data.calculated_cost.contract_term.discount_savings_total');
        $this->assertEqualsWithDelta(60.0, $annualizedSaving, 0.01);
        $this->assertEqualsWithDelta(30.0, $termSaving, 0.01);
    }

    public function test_feature_off_keeps_relational_component_resources_and_legacy_cost(): void
    {
        config()->set('canonical_pricing.enabled', false);
        ElectricityContract::factory()
            ->forCompany($this->company)
            ->active()
            ->legacy()
            ->withRelationalPrices([[
                'id' => 'pc-legacy-api-General',
                'price_component_type' => 'General',
                'price_date' => now()->toDateString(),
                'price' => 4.0,
                'payment_unit' => 'c/kWh',
            ]])
            ->create([
                'id' => 'legacy-api',
                'name' => 'Contract legacy-api',
            ]);

        $response = $this->getJson('/api/contracts/legacy-api?consumption=5000');

        $response->assertOk()
            ->assertJsonMissingPath('data.current_pricing')
            ->assertJsonPath('data.price_components.0.price_component_type', 'General')
            ->assertJsonPath('data.price_components.0.price', 4);
        $this->assertEqualsWithDelta(200.0, $response->json('data.calculated_cost.total_cost'), 0.01);
    }

    public function test_canonical_list_uses_a_bounded_number_of_queries(): void
    {
        for ($i = 1; $i <= 8; $i++) {
            $this->createCanonicalContract('bounded-api-'.$i, [
                CanonicalPricingFixture::phase(
                    label: 'current',
                    kind: PhaseKind::CurrentStructured,
                    starts: CanonicalPricingFixture::boundary(BoundaryKind::ContractStart),
                    ends: CanonicalPricingFixture::boundary(BoundaryKind::None),
                    components: [
                        CanonicalPricingFixture::component(
                            ComponentType::EnergyGeneral,
                            5.0 + $i,
                            ComponentUnit::CentsPerKwh,
                        ),
                    ],
                ),
            ]);
        }

        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $this->getJson('/api/contracts?consumption=5000&per_page=100')->assertOk()->assertJsonCount(8, 'data');

        $this->assertLessThanOrEqual(7, count($queries), implode("\n", $queries));
        $this->assertSame([], array_values(array_filter(
            $queries,
            fn (string $sql): bool => str_contains($sql, 'price_components'),
        )));
    }

    /**
     * @param  list<array<string, mixed>>  $phases
     * @param  list<string>  $issues
     * @param  array<string, mixed>  $attributes
     * @param  list<array<string, mixed>>  $relationalPrices
     */
    private function createCanonicalContract(
        string $id,
        array $phases,
        CalculationStatus $calculationStatus = CalculationStatus::Exact,
        MisleadingState $misleading = MisleadingState::NotDetected,
        array $issues = [],
        array $attributes = [],
        array $relationalPrices = [],
    ): ElectricityContract {
        $factory = ElectricityContract::factory()
            ->forCompany($this->company)
            ->active()
            ->canonicalOnly();

        if ($relationalPrices !== []) {
            $factory = $factory->withRelationalPrices($relationalPrices);
        }

        return $factory->create([
            'id' => $id,
            'name' => 'Contract '.$id,
            ...CanonicalPricingFixture::attributes(
                phases: $phases,
                calculationStatus: $calculationStatus,
                misleading: $misleading,
                issueCodes: $issues,
            ),
            ...$attributes,
        ]);
    }
}
