<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\ElectricityContract;
use App\Models\PriceComponent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ContractApiCanonicalPricingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('canonical_pricing.enabled', true);
        Company::create([
            'name' => 'Canonical Energy Oy',
            'name_slug' => 'canonical-energy-oy',
            'company_url' => 'https://example.test',
        ]);
    }

    public function test_list_and_show_publish_corrected_canonical_prices_without_relational_rows(): void
    {
        $contract = $this->createCanonicalContract('corrected-api', [
            $this->phase([
                $this->canonicalComponent('energy_general', 9.0),
                $this->canonicalComponent('monthly_fee', 4.0, 'eur_per_month'),
            ]),
        ]);
        $this->createRelationalPrice($contract, 'General', 1.0);
        $this->createRelationalPrice($contract, 'Monthly', 99.0, 'EUR/month');

        $list = $this->getJson('/api/contracts?consumption=5000');
        $list->assertOk()
            ->assertJsonMissingPath('data.0.price_components')
            ->assertJsonPath('data.0.current_pricing.pricing_basis', 'canonical')
            ->assertJsonPath('data.0.current_pricing.general_kwh_price', 9)
            ->assertJsonPath('data.0.current_pricing.monthly_fixed_fee', 4)
            ->assertJsonPath('data.0.calculated_cost.pricing_basis', 'canonical');
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
        $contract = $this->createCanonicalContract('missing-rate-api', [
            $this->phase([$this->canonicalComponent('monthly_fee', 3.0, 'eur_per_month')]),
        ]);
        $this->createRelationalPrice($contract, 'General', 2.0);

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
            $this->phase([$this->canonicalComponent('energy_general', 7.5)]),
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
        $contract = $this->createCanonicalContract(
            'excluded-api',
            [[
                'label' => 'intro',
                'phase_kind' => 'introductory',
                'starts' => $this->boundary('contract_start'),
                'ends' => $this->boundary('after_months', '1'),
                'components' => [$this->canonicalComponent('energy_general', 2.0)],
                'package' => null,
                'evidence' => [],
            ]],
            calculationStatus: 'estimate_required',
            misleading: 'detected',
            issues: ['future_price_unknown'],
        );
        $this->createRelationalPrice($contract, 'General', 0.5);

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
            $this->phase([], [
                'monthly_fee_eur' => 21.0,
                'included_kwh' => 150.0,
                'allowance_cadence' => 'monthly',
                'excess_rate_cents_per_kwh' => 16.6,
            ]),
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
        $this->createCanonicalContract(
            'short-term-api',
            [[
                'label' => 'term',
                'phase_kind' => 'introductory',
                'starts' => $this->boundary('contract_start'),
                'ends' => $this->boundary('after_months', '6'),
                'components' => [
                    $this->canonicalComponent('energy_general', 5.0),
                    $this->canonicalComponent('monthly_fee', 0.0, 'eur_per_month', normalAmount: 5.0),
                ],
                'package' => null,
                'evidence' => [],
            ]],
            calculationStatus: 'incomplete',
            misleading: 'detected',
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
        $contract = $this->createCanonicalContract('legacy-api', [
            $this->phase([$this->canonicalComponent('energy_general', 9.0)]),
        ]);
        $this->createRelationalPrice($contract, 'General', 4.0);

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
                $this->phase([$this->canonicalComponent('energy_general', 5.0 + $i)]),
            ]);
        }

        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $this->getJson('/api/contracts?consumption=5000&per_page=100')->assertOk()->assertJsonCount(8, 'data');

        $this->assertLessThanOrEqual(6, count($queries), implode("\n", $queries));
        $this->assertSame([], array_values(array_filter(
            $queries,
            fn (string $sql): bool => str_contains($sql, 'price_components'),
        )));
    }

    /**
     * @param  list<array<string, mixed>>  $phases
     * @param  list<string>  $issues
     * @param  array<string, mixed>  $attributes
     */
    private function createCanonicalContract(
        string $id,
        array $phases,
        string $calculationStatus = 'exact',
        string $misleading = 'not_detected',
        array $issues = [],
        array $attributes = [],
    ): ElectricityContract {
        return ElectricityContract::create(array_merge([
            'id' => $id,
            'company_name' => 'Canonical Energy Oy',
            'name' => 'Contract '.$id,
            'contract_type' => 'OpenEnded',
            'pricing_model' => 'FixedPrice',
            'metering' => 'General',
            'target_group' => 'Household',
            'availability_is_national' => true,
            'canonical_pricing' => [
                'phases' => $phases,
                'recurring_schedule' => [
                    'present' => false,
                    'cadence' => 'none',
                    'current_period_start' => null,
                    'current_period_end' => null,
                    'future_price_known' => null,
                    'description' => null,
                    'evidence' => [],
                ],
                'consumption_effect' => [
                    'present' => false,
                    'applies_to' => 'unknown',
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
            'canonical_calculation' => [
                'status' => $calculationStatus,
                'missing_facts' => [],
                'required_assumptions' => [],
            ],
            'canonical_source_consistency' => [
                'misleading_first_12_months' => $misleading,
                'structured_pricing_status' => 'complete',
                'issue_codes' => $issues,
            ],
        ], $attributes));
    }

    /**
     * @param  list<array<string, mixed>>  $components
     * @param  array<string, mixed>|null  $package
     * @return array<string, mixed>
     */
    private function phase(array $components, ?array $package = null): array
    {
        return [
            'label' => 'current',
            'phase_kind' => 'current_structured',
            'starts' => $this->boundary('contract_start'),
            'ends' => $this->boundary('none'),
            'components' => $components,
            'package' => $package,
            'evidence' => [],
        ];
    }

    /** @return array<string, mixed> */
    private function canonicalComponent(
        string $type,
        ?float $amount,
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

    /** @return array{kind: string, value: string|null} */
    private function boundary(string $kind, ?string $value = null): array
    {
        return ['kind' => $kind, 'value' => $value];
    }

    private function createRelationalPrice(
        ElectricityContract $contract,
        string $type,
        float $price,
        string $paymentUnit = 'c/kWh',
    ): void {
        PriceComponent::create([
            'id' => 'pc-'.$contract->id.'-'.$type,
            'electricity_contract_id' => $contract->id,
            'price_component_type' => $type,
            'price_date' => now()->toDateString(),
            'price' => $price,
            'payment_unit' => $paymentUnit,
        ]);
    }
}
