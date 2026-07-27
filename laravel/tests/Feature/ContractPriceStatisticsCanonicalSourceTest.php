<?php

namespace Tests\Feature;

use App\Models\ActiveContract;
use App\Models\Company;
use App\Models\ContractPriceSnapshot;
use App\Models\ElectricityContract;
use App\Models\PriceComponent;
use App\Services\ContractStatistics\ContractPriceStatisticsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `/sahkosopimus/tilastot` must keep counting the contracts whose source price
 * components the interpretation gate withheld.
 *
 * Those are not contracts Voltikka cannot price — they are the ones whose raw
 * structured price was found untrustworthy (promo-only rows, an omitted later
 * price), which is exactly when canonical pricing is the more reliable figure.
 */
class ContractPriceStatisticsCanonicalSourceTest extends TestCase
{
    use RefreshDatabase;

    private const DATE = '2026-07-27';

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('canonical_pricing.enabled', true);
        Company::create(['name' => 'Tyyni Energia Oy', 'name_slug' => 'tyyni-energia-oy']);
    }

    public function test_a_gated_contract_is_priced_from_canonical_phases_instead_of_being_dropped(): void
    {
        // A promo contract: the structured rows held only the intro price, so the gate
        // withheld them. The canonical phases carry both prices.
        $contract = $this->createContract('promo-1', [
            $this->phase('Aloitushinta', 'introductory', 4.0, $this->boundary('contract_start'), $this->boundary('after_months', '6')),
            $this->phase('Normaalihinta', 'normal', 12.0, $this->boundary('after_months', '6'), $this->boundary('none')),
        ]);

        $result = $this->calculate();

        $this->assertSame(1, $result['snapshots']);
        $snapshot = ContractPriceSnapshot::sole();
        $this->assertSame($contract->id, $snapshot->contract_id);
        $this->assertNotNull($snapshot->annual_cost_5000_kwh);

        // Half a year at 4 c/kWh and half at 12 averages well above the intro price, so the
        // recorded year cannot be the promo price alone.
        $this->assertGreaterThan(5000 * 0.04, (float) $snapshot->annual_cost_5000_kwh);

        // Nothing relational exists, so the per-component c/kWh fields stay empty rather
        // than being invented. `cleanValues()` drops them from the aggregate metrics.
        $this->assertNull($snapshot->energy_price_cents_per_kwh);
        $this->assertNull($snapshot->monthly_fee_eur);
    }

    public function test_a_contract_canonical_pricing_refuses_to_total_is_still_skipped(): void
    {
        // Vimpelin Voima's shape: the pre-discount price list is undisclosed, so the
        // continuation phase has no components and canonical declines to price the year.
        $this->createContract('incomplete-1', [
            $this->phase('Alennettu hinnasto', 'introductory', 5.0, $this->boundary('contract_start'), $this->boundary('after_months', '3')),
            [
                'label' => 'Alennusta edeltänyt hinnasto',
                'phase_kind' => 'continuation',
                'starts' => $this->boundary('after_months', '3'),
                'ends' => $this->boundary('none'),
                'components' => [],
                'evidence' => [],
            ],
        ], calculationStatus: 'incomplete');

        $result = $this->calculate();

        $this->assertSame(0, $result['snapshots'], 'An all-null row helps nobody.');
        $this->assertSame(0, ContractPriceSnapshot::count());
    }

    public function test_legacy_calculation_still_requires_relational_components(): void
    {
        // With canonical pricing off there is nothing else to read, and historical
        // backfills always take this path.
        config()->set('canonical_pricing.enabled', false);
        $this->createContract('promo-2', [
            $this->phase('Aloitushinta', 'introductory', 4.0, $this->boundary('contract_start'), $this->boundary('after_months', '6')),
            $this->phase('Normaalihinta', 'normal', 12.0, $this->boundary('after_months', '6'), $this->boundary('none')),
        ]);

        $result = $this->calculate();

        $this->assertSame(0, $result['snapshots']);
    }

    public function test_a_contract_with_components_is_unaffected(): void
    {
        $contract = $this->createContract('normal-1', [
            $this->phase('Nykyinen', 'current_structured', 7.0, $this->boundary('contract_start'), $this->boundary('none')),
        ]);
        PriceComponent::create([
            'id' => 'pc-normal-1',
            'electricity_contract_id' => $contract->id,
            'price_component_type' => 'General',
            'price_date' => self::DATE,
            'price' => 7.0,
            'payment_unit' => 'c/kWh',
        ]);

        $this->calculate();

        $this->assertSame(7.0, (float) ContractPriceSnapshot::sole()->energy_price_cents_per_kwh);
    }

    /**
     * @return array{snapshots:int, statistics:int}
     */
    private function calculate(): array
    {
        return app(ContractPriceStatisticsService::class)->calculateForDate(
            self::DATE,
            ActiveContract::query()->pluck('id'),
        );
    }

    /**
     * @param  list<array<string, mixed>>  $phases
     */
    private function createContract(string $id, array $phases, string $calculationStatus = 'exact'): ElectricityContract
    {
        $contract = ElectricityContract::create([
            'id' => $id,
            'company_name' => 'Tyyni Energia Oy',
            'name' => 'Tyyni '.$id,
            'contract_type' => 'OpenEnded',
            'pricing_model' => 'FixedPrice',
            'metering' => 'General',
            'target_group' => 'Household',
            'availability_is_national' => true,
            'canonical_pricing' => [
                'phases' => $phases,
                'recurring_schedule' => [
                    'present' => false, 'cadence' => 'none', 'current_period_start' => null,
                    'current_period_end' => null, 'future_price_known' => null,
                    'description' => null, 'evidence' => [],
                ],
                'consumption_effect' => [
                    'present' => false, 'applies_to' => 'unknown', 'cadence' => 'none',
                    'expected_cents_per_kwh' => null, 'typical_min_cents_per_kwh' => null,
                    'typical_max_cents_per_kwh' => null, 'hard_min_cents_per_kwh' => null,
                    'hard_max_cents_per_kwh' => null, 'uncapped' => null,
                    'description' => null, 'evidence' => [],
                ],
            ],
            'canonical_calculation' => ['status' => $calculationStatus, 'missing_facts' => [], 'required_assumptions' => []],
            'canonical_source_consistency' => [
                'misleading_first_12_months' => 'not_detected',
                'structured_pricing_status' => 'complete',
                'issue_codes' => [],
            ],
        ]);

        ActiveContract::create(['id' => $id]);

        return $contract;
    }

    /**
     * @param  array<string, mixed>  $starts
     * @param  array<string, mixed>  $ends
     * @return array<string, mixed>
     */
    private function phase(string $label, string $kind, float $cents, array $starts, array $ends): array
    {
        return [
            'label' => $label,
            'phase_kind' => $kind,
            'starts' => $starts,
            'ends' => $ends,
            'components' => [[
                'component_type' => 'energy_general',
                'amount' => $cents,
                'normal_amount' => null,
                'unit' => 'cents_per_kwh',
                'vat_status' => 'included',
                'price_role' => 'current',
                'source_kind' => 'both',
                'evidence' => [],
            ]],
            'evidence' => [],
        ];
    }

    /**
     * @return array{kind:string, value:?string}
     */
    private function boundary(string $kind, ?string $value = null): array
    {
        return ['kind' => $kind, 'value' => $value];
    }
}
