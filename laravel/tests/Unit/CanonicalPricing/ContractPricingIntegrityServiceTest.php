<?php

namespace Tests\Unit\CanonicalPricing;

use App\Services\CanonicalPricing\CanonicalContractPriceCalculator;
use App\Services\CanonicalPricing\CanonicalPricingParser;
use App\Services\CanonicalPricing\ContractPricingIntegrityService;
use App\Services\CanonicalPricing\DTO\ContractContext;
use App\Services\CanonicalPricing\DTO\SpotAssumptions;
use App\Services\CanonicalPricing\Enums\IntegrityReasonFamily;
use App\Services\DTO\EnergyUsage;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;
use Tests\Unit\CanonicalPricing\Support\HoldFlatCanonicalCalculator;

class ContractPricingIntegrityServiceTest extends TestCase
{
    private CanonicalPricingParser $parser;

    private CanonicalContractPriceCalculator $calculator;

    private ContractPricingIntegrityService $integrity;

    private CarbonImmutable $start;

    private EnergyUsage $usage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new CanonicalPricingParser;
        $this->calculator = HoldFlatCanonicalCalculator::make();
        $this->integrity = new ContractPricingIntegrityService;
        $this->start = CarbonImmutable::parse('2026-07-24', 'Europe/Helsinki');
        $this->usage = new EnergyUsage(total: 5000, basicLiving: 5000);
    }

    private function component(string $type, ?float $amount, string $unit = 'cents_per_kwh', string $role = 'current'): array
    {
        return [
            'component_type' => $type, 'amount' => $amount, 'normal_amount' => null, 'unit' => $unit,
            'vat_status' => 'included', 'price_role' => $role, 'source_kind' => 'both', 'evidence' => [],
        ];
    }

    private function phase(string $kind, array $starts, array $ends, array $components): array
    {
        return ['label' => $kind, 'phase_kind' => $kind, 'starts' => $starts, 'ends' => $ends, 'components' => $components, 'evidence' => []];
    }

    private function pricing(array $phases): array
    {
        return [
            'phases' => $phases,
            'recurring_schedule' => ['present' => false, 'cadence' => 'none', 'current_period_start' => null, 'current_period_end' => null, 'future_price_known' => null, 'description' => null, 'evidence' => []],
            'consumption_effect' => ['present' => false, 'applies_to' => 'unknown', 'cadence' => 'none', 'expected_cents_per_kwh' => null, 'typical_min_cents_per_kwh' => null, 'typical_max_cents_per_kwh' => null, 'hard_min_cents_per_kwh' => null, 'hard_max_cents_per_kwh' => null, 'uncapped' => null, 'description' => null, 'evidence' => []],
        ];
    }

    private function assess(array $pricing, string $status, string $misleading, array $issues, ContractContext $context, ?string $fixedRange = null)
    {
        $data = $this->parser->parse(
            $pricing,
            ['status' => $status, 'missing_facts' => [], 'required_assumptions' => []],
            ['misleading_first_12_months' => $misleading, 'structured_pricing_status' => 'complete', 'issue_codes' => $issues],
        );
        $outcome = $this->calculator->calculate($data, $context, $this->usage, new SpotAssumptions(8.0, 5.0), $this->start);

        return $this->integrity->assess($data, $outcome, $context);
    }

    private function context(string $model = 'FixedPrice', string $type = 'OpenEnded', ?string $fixedRange = null): ContractContext
    {
        return new ContractContext($model, $type, 'General', $fixedRange, 'Household');
    }

    private function tyyni(): array
    {
        return $this->pricing([
            $this->phase('introductory', ['kind' => 'contract_start', 'value' => null], ['kind' => 'date', 'value' => '2026-07-31'], [
                $this->component('energy_general', 5.49, 'cents_per_kwh', 'introductory'),
                $this->component('monthly_fee', 2.99, 'eur_per_month', 'introductory'),
            ]),
            $this->phase('normal', ['kind' => 'date', 'value' => '2026-08-01'], ['kind' => 'none', 'value' => null], [
                $this->component('energy_general', 13.65, 'cents_per_kwh', 'normal'),
                $this->component('monthly_fee', 5.99, 'eur_per_month', 'normal'),
            ]),
        ]);
    }

    public function test_detected_promo_produces_label_with_date_and_impact(): void
    {
        $result = $this->assess($this->tyyni(), 'exact', 'detected', ['structured_matches_intro_only', 'future_price_omitted'], $this->context());

        $this->assertTrue($result->detected);
        $this->assertSame(IntegrityReasonFamily::Promo, $result->reasonFamily);
        $this->assertSame('Hinta nousee 1.8.2026', $result->cardLabel);
        $this->assertSame('2026-08-01', $result->changeDate);
        $this->assertGreaterThan(0, $result->firstYearImpactEur);
        $this->assertNotEmpty($result->detailFacts);
    }

    public function test_not_detected_produces_no_label(): void
    {
        $result = $this->assess($this->tyyni(), 'exact', 'not_detected', [], $this->context());

        $this->assertFalse($result->detected);
        $this->assertSame(IntegrityReasonFamily::None, $result->reasonFamily);
        $this->assertNull($result->cardLabel);
    }

    public function test_uncertain_produces_no_label(): void
    {
        $result = $this->assess($this->tyyni(), 'exact', 'uncertain', ['future_price_omitted'], $this->context());

        $this->assertFalse($result->detected);
    }

    public function test_not_assessable_produces_no_label(): void
    {
        $result = $this->assess($this->tyyni(), 'exact', 'not_assessable', [], $this->context());

        $this->assertFalse($result->detected);
    }

    public function test_fixed_term_continuation_gets_no_accusatory_label(): void
    {
        $pricing = $this->pricing([
            $this->phase('current_structured', ['kind' => 'contract_start', 'value' => null], ['kind' => 'after_months', 'value' => '6'], [
                $this->component('energy_general', 5.0),
            ]),
        ]);

        $result = $this->assess($pricing, 'incomplete', 'detected', ['future_price_omitted', 'future_price_unknown'], $this->context('FixedPrice', 'FixedTerm', 'Fixed6'), 'Fixed6');

        $this->assertFalse($result->detected);
        $this->assertSame(IntegrityReasonFamily::None, $result->reasonFamily);
    }

    public function test_recurring_market_product_gets_no_deceptive_label(): void
    {
        // A quarterly product flagged detected (small first-period intro) is not deceptive.
        $pricing = $this->pricing([
            $this->phase('introductory', ['kind' => 'contract_start', 'value' => null], ['kind' => 'after_months', 'value' => '1'], [
                $this->component('energy_general', 7.49, 'cents_per_kwh', 'introductory'),
                $this->component('monthly_fee', 0.0, 'eur_per_month', 'introductory'),
            ]),
            $this->phase('continuation', ['kind' => 'after_months', 'value' => '1'], ['kind' => 'date', 'value' => now()->addMonths(3)->format('Y-m-d')], [
                $this->component('energy_general', 9.95, 'cents_per_kwh', 'normal'),
                $this->component('monthly_fee', 4.9, 'eur_per_month', 'normal'),
            ]),
        ]);
        $pricing['recurring_schedule']['present'] = true;
        $pricing['recurring_schedule']['cadence'] = 'quarterly';

        $result = $this->assess($pricing, 'estimate_required', 'detected', ['structured_matches_intro_only', 'future_price_omitted', 'recurring_reset_requires_estimate'], $this->context());

        $this->assertFalse($result->detected);
        $this->assertSame(IntegrityReasonFamily::None, $result->reasonFamily);
    }

    public function test_modest_impact_promo_is_not_labelled(): void
    {
        // A 6-month fixed (10,99) that continues at a similar spot price: the structured price
        // understates the year only modestly, so it is not a deceptive promo.
        $pricing = $this->pricing([
            $this->phase('current_structured', ['kind' => 'contract_start', 'value' => null], ['kind' => 'after_months', 'value' => '6'], [
                $this->component('energy_general', 10.99),
                $this->component('monthly_fee', 4.9, 'eur_per_month'),
            ]),
            $this->phase('continuation', ['kind' => 'after_months', 'value' => '6'], ['kind' => 'none', 'value' => null], [
                $this->component('spot_margin', 1.85),
                $this->component('monthly_fee', 5.9, 'eur_per_month'),
            ]),
        ]);

        $result = $this->assess($pricing, 'estimate_required', 'detected', ['future_price_omitted'], $this->context('FixedPrice', 'FixedTerm', 'Fixed6'), 'Fixed6');

        $this->assertFalse($result->detected);
        $this->assertSame(IntegrityReasonFamily::None, $result->reasonFamily);
    }

    public function test_data_conflict_family_is_detail_only(): void
    {
        $pricing = $this->pricing([
            $this->phase('current_structured', ['kind' => 'contract_start', 'value' => null], ['kind' => 'none', 'value' => null], [
                $this->component('spot_margin', 0.4),
                $this->component('monthly_fee', 3.0, 'eur_per_month'),
            ]),
        ]);

        $result = $this->assess($pricing, 'estimate_required', 'detected', ['component_mismatch'], $this->context('Spot'));

        $this->assertTrue($result->detected);
        $this->assertSame(IntegrityReasonFamily::DataConflict, $result->reasonFamily);
        $this->assertNull($result->cardLabel);
        $this->assertNotEmpty($result->detailFacts);
    }
}
