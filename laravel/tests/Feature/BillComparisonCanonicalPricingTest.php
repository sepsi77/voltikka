<?php

namespace Tests\Feature;

use App\Livewire\SahkosopimusIndex;
use App\Models\ActiveContract;
use App\Models\Company;
use App\Models\ElectricityContract;
use App\Models\PriceComponent;
use App\Services\BillComparison\BillComparisonService;
use App\Services\DTO\BillComparisonRequest;
use Carbon\Carbon;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

class BillComparisonCanonicalPricingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('canonical_pricing.enabled', true);
        app()->forgetScopedInstances();

        Company::create([
            'name' => 'Canonical Energia Oy',
            'name_slug' => 'canonical-energia-oy',
            'company_url' => 'https://canonical.example',
        ]);
    }

    public function test_all_three_surfaces_use_the_same_corrected_canonical_period_cost(): void
    {
        $contract = $this->createContract('canonical-conflict', [
            $this->phase([
                $this->canonicalComponent('energy_general', 5),
                $this->canonicalComponent('monthly_fee', 3, 'eur_per_month'),
            ]),
        ]);
        $this->addRelationalPrices($contract, 99, 99);

        $serviceData = app(BillComparisonService::class)->periodRowsForContracts([$contract->load('company')], $this->request());
        $this->assertEqualsWithDelta(18.0, $serviceData['rows'][$contract->id]->periodCostEur, 0.001);
        $this->assertSame('canonical', $serviceData['rows'][$contract->id]->pricingBasis);

        $standalone = Livewire::test('bill-comparison')
            ->set('periodPreset', 'custom')
            ->set('startDate', '2026-05-01')
            ->set('endDate', '2026-05-30')
            ->set('kwh', 300)
            ->set('totalEur', 40)
            ->set('annualKwh', 5000)
            ->viewData('resultArray');
        $standaloneRow = collect($standalone['rows'])->firstWhere('contract_id', $contract->id);
        $this->assertSame(18.0, $standaloneRow['period_cost_eur']);

        $listing = Livewire::test(SahkosopimusIndex::class)
            ->set('billPeriodPreset', 'custom')
            ->set('billStartDate', '2026-05-01')
            ->set('billEndDate', '2026-05-30')
            ->set('billKwh', 300)
            ->set('billTotalEur', 40);
        $listingContract = $listing->viewData('contracts')->firstWhere('id', $contract->id);
        $this->assertEqualsWithDelta(18.0, $listingContract->period_comparison['period_cost'], 0.001);

        $detail = Livewire::test('contract-detail', ['contractId' => $contract->id])
            ->set('billPeriodPreset', 'custom')
            ->set('billStartDate', '2026-05-01')
            ->set('billEndDate', '2026-05-30')
            ->set('billKwh', 300)
            ->set('billTotalEur', 40)
            ->instance()
            ->billComparison;
        $this->assertTrue($detail['available']);
        $this->assertEqualsWithDelta(18.0, $detail['contract_cost'], 0.001);
    }

    public function test_canonical_only_missing_excluded_and_consumption_capped_contracts_fail_or_price_honestly(): void
    {
        $canonicalOnly = $this->createContract('canonical-only-bill', [
            $this->phase([$this->canonicalComponent('energy_general', 6)]),
        ]);
        $missing = $this->createContract('canonical-missing-bill', [], canonicalPricing: null, status: null);
        $this->addRelationalPrices($missing, 1, 0);
        $excluded = $this->createContract('canonical-excluded-bill', [
            $this->phase([$this->canonicalComponent('energy_general', 1)], endKind: 'after_months', endValue: '1'),
        ], misleading: 'detected');
        $this->addRelationalPrices($excluded, 1, 0);
        $capped = $this->createContract('canonical-capped-bill', [
            $this->phase([$this->canonicalComponent('energy_general', 2)]),
        ], maxKwh: 2000);

        $contracts = collect([$canonicalOnly, $missing, $excluded, $capped])->each->load('company');
        $data = app(BillComparisonService::class)->periodRowsForContracts($contracts, $this->request(annualKwh: 5000));

        $this->assertEqualsWithDelta(18.0, $data['rows'][$canonicalOnly->id]->periodCostEur, 0.001);
        $this->assertArrayNotHasKey($missing->id, $data['rows']);
        $this->assertSame('not_comparable', $data['unavailable'][$missing->id]);
        $this->assertSame('not_comparable', $data['unavailable'][$excluded->id]);
        $this->assertSame('consumption_cap', $data['unavailable'][$capped->id]);
    }

    public function test_promo_membership_comes_from_measured_canonical_period_savings(): void
    {
        $contract = $this->createContract('canonical-period-offer', [
            $this->phase([$this->canonicalComponent('energy_general', 5, 'cents_per_kwh', 10)]),
        ]);
        $this->addRelationalPrices($contract, 5, 0);

        $data = app(BillComparisonService::class)->periodRowsForContracts([$contract->load('company')], $this->request());
        $row = $data['rows'][$contract->id];

        $this->assertTrue($row->hasPromo);
        $this->assertSame('canonical', $row->pricingBasis);
    }

    public function test_feature_off_keeps_the_relational_period_calculation(): void
    {
        config()->set('canonical_pricing.enabled', false);
        app()->forgetScopedInstances();
        $contract = $this->createContract('legacy-bill', [
            $this->phase([
                $this->canonicalComponent('energy_general', 5),
                $this->canonicalComponent('monthly_fee', 3, 'eur_per_month'),
            ]),
        ]);
        $this->addRelationalPrices($contract, 9, 6);

        $data = app(BillComparisonService::class)->periodRowsForContracts([$contract->load('company')], $this->request());

        $this->assertEqualsWithDelta(33.0, $data['rows'][$contract->id]->periodCostEur, 0.001);
        $this->assertSame('legacy', $data['rows'][$contract->id]->pricingBasis);
    }

    public function test_feature_off_row_uses_measured_period_discount_and_bill_start_for_annual_price(): void
    {
        config()->set('canonical_pricing.enabled', false);
        app()->forgetScopedInstances();
        $contract = $this->createContract('legacy-period-offer', []);
        $this->addRelationalPrices($contract, 9, 6);
        PriceComponent::query()
            ->where('electricity_contract_id', $contract->id)
            ->where('price_component_type', 'General')
            ->update([
                'has_discount' => true,
                'discount_value' => 3,
                'discount_is_percentage' => false,
                'discount_type' => 'UntilDate',
                'discount_discount_until_date' => '2026-05-15',
            ]);

        $data = app(BillComparisonService::class)->periodRowsForContracts(
            [$contract->load('company')],
            $this->request(annualKwh: 1200),
        );
        $row = $data['rows'][$contract->id];

        $this->assertEqualsWithDelta(28.5, $row->periodCostEur, 0.0001);
        $this->assertEqualsWithDelta(180 - (3 * 100 / 100 * 15 / 31), $row->annualCostEur, 0.0001);
        $this->assertTrue($row->hasPromo);
        $this->assertSame('legacy', $row->pricingBasis);
    }

    public function test_canonical_period_batch_does_not_query_price_components_and_has_bounded_queries(): void
    {
        $contracts = collect();
        for ($i = 1; $i <= 8; $i++) {
            $contracts->push($this->createContract('canonical-batch-'.$i, [
                $this->phase([$this->canonicalComponent('energy_general', 5 + $i / 10)]),
            ]));
        }
        $contracts->each->load('company');

        $queries = [];
        DB::listen(static function (QueryExecuted $query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $data = app(BillComparisonService::class)->periodRowsForContracts($contracts, $this->request());

        $this->assertCount(8, $data['rows']);
        $this->assertFalse(collect($queries)->contains(
            static fn (string $sql): bool => str_contains(strtolower($sql), 'price_components')
        ));
        $this->assertLessThanOrEqual(3, count($queries), 'The canonical period batch must not add one query per contract.');
    }

    private function request(int $annualKwh = 5000): BillComparisonRequest
    {
        return new BillComparisonRequest(
            startDate: Carbon::parse('2026-05-01', 'Europe/Helsinki'),
            endDate: Carbon::parse('2026-05-30', 'Europe/Helsinki'),
            kwh: 300,
            userTotalEur: 40,
            annualKwhOverride: $annualKwh,
        );
    }

    /** @param list<array<string, mixed>>|null $canonicalPricing */
    private function createContract(
        string $id,
        array $phases,
        ?array $canonicalPricing = [],
        ?string $status = 'exact',
        string $misleading = 'not_detected',
        ?int $maxKwh = null,
    ): ElectricityContract {
        $pricing = $canonicalPricing === null ? null : [
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
        ];

        $contract = ElectricityContract::create([
            'id' => $id,
            'company_name' => 'Canonical Energia Oy',
            'name' => $id,
            'name_slug' => $id,
            'contract_type' => 'OpenEnded',
            'pricing_model' => 'FixedPrice',
            'metering' => 'General',
            'target_group' => 'Household',
            'availability_is_national' => true,
            'consumption_limitation_max_x_kwh_per_y' => $maxKwh,
            'canonical_pricing' => $pricing,
            'canonical_calculation' => $status === null ? null : [
                'status' => $status,
                'missing_facts' => [],
                'required_assumptions' => [],
            ],
            'canonical_source_consistency' => $pricing === null ? null : [
                'misleading_first_12_months' => $misleading,
                'structured_pricing_status' => 'complete',
                'issue_codes' => $misleading === 'detected' ? ['future_price_omitted'] : [],
            ],
        ]);
        ActiveContract::create(['id' => $id]);

        return $contract;
    }

    private function addRelationalPrices(ElectricityContract $contract, float $general, float $monthly): void
    {
        PriceComponent::create([
            'id' => 'rel-general-'.$contract->id,
            'electricity_contract_id' => $contract->id,
            'price_component_type' => 'General',
            'price_date' => '2026-05-01',
            'price' => $general,
            'payment_unit' => 'c/kWh',
        ]);
        PriceComponent::create([
            'id' => 'rel-monthly-'.$contract->id,
            'electricity_contract_id' => $contract->id,
            'price_component_type' => 'Monthly',
            'price_date' => '2026-05-01',
            'price' => $monthly,
            'payment_unit' => 'EUR/month',
        ]);
    }

    private function canonicalComponent(string $type, float $amount, string $unit = 'cents_per_kwh', ?float $normalAmount = null): array
    {
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

    private function phase(array $components, string $endKind = 'none', ?string $endValue = null): array
    {
        return [
            'label' => 'phase',
            'phase_kind' => 'normal',
            'starts' => ['kind' => 'contract_start', 'value' => null],
            'ends' => ['kind' => $endKind, 'value' => $endValue],
            'components' => $components,
            'package' => null,
            'evidence' => [],
        ];
    }
}
