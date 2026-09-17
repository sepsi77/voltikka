<?php

namespace Tests\Unit;

use App\Enums\MeteringType;
use App\Http\Controllers\Api\ContractController;
use App\Livewire\ContractDetail;
use App\Models\ElectricityContract;
use App\Services\CanonicalPricing\CanonicalOfferFacts;
use App\Services\CanonicalPricing\CanonicalPricingParser;
use App\Services\CanonicalPricing\DTO\CanonicalPricingOutcome;
use App\Services\CanonicalPricing\DTO\ContractPricingIntegrity;
use App\Services\CanonicalPricing\DTO\EnergyRuleComparison;
use App\Services\CanonicalPricing\DTO\EnergyRulePlan;
use App\Services\CanonicalPricing\DTO\NormalEnergyProjection;
use App\Services\CanonicalPricing\DTO\OfferComponentData;
use App\Services\CanonicalPricing\DTO\OfferTermData;
use App\Services\CanonicalPricing\Enums\BoundaryKind;
use App\Services\CanonicalPricing\Enums\ComponentType;
use App\Services\CanonicalPricing\Enums\ComponentUnit;
use App\Services\CanonicalPricing\Enums\ContractComparability;
use App\Services\CanonicalPricing\Enums\EnergyPriceRuleKind;
use App\Services\CanonicalPricing\Enums\EstimateMethod;
use App\Services\CanonicalPricing\Enums\IntegrityReasonFamily;
use App\Services\CanonicalPricing\MarketReset\DTO\ResetEstimate;
use App\Services\CanonicalPricing\MarketReset\Enums\ResetEstimateBasis;
use App\Services\CanonicalPricing\SupplierAdjusted\DTO\PriceEpisodeAnchor;
use App\Services\CanonicalPricing\SupplierAdjusted\DTO\SupplierAdjustedEstimate;
use App\Services\CanonicalPricing\SupplierAdjusted\Enums\SupplierAdjustedEstimateBasis;
use App\Services\CanonicalPricing\Support\EnergyRuleOfferTerms;
use App\Services\CanonicalPricing\Support\PhaseTimelineBuilder;
use App\Services\ContractCard\ContractCardCopy;
use App\Services\ContractCard\ContractCardPresenter;
use App\Services\ContractCard\PricingCategoryResolver;
use App\Services\ContractPricing\CanonicalContractMetric;
use App\Services\ContractPricing\ContractPricingViewData;
use App\Services\WeeklyOffersPromptFormatter;
use App\Services\WeeklyOffersVideoService;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use Tests\Support\EnergyRulesFixture;
use Tests\TestCase;

class EnergyRulePublicOutputTest extends TestCase
{
    private function outcome(bool $normal = true, bool $short = false, bool $formula = false): CanonicalPricingOutcome
    {
        $differences = [5.0, -1.0, -1.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0];
        $start = CarbonImmutable::parse('2026-01-01', 'Europe/Helsinki');

        return new CanonicalPricingOutcome(
            comparability: $short ? ContractComparability::TermPriceOnly : ContractComparability::ComparableExact,
            estimateMethod: $short ? EstimateMethod::TermPriceAnnualized : EstimateMethod::None,
            totalCost: 48.0, monthlyCosts: array_fill(0, 12, 4.0),
            baseTotalCost: $normal ? 51.0 : null,
            baseMonthlyCosts: $normal ? array_map(fn ($difference) => 4.0 + $difference, $differences) : [],
            measuredDiscountSavingsTotal: $normal ? 3.0 : 0.0,
            monthlyDiscountSavings: $normal ? $differences : [], structuredOnlyTotal: null, isSpotContract: false,
            generalKwhPrice: 4.0, termMonths: $short ? 6 : null,
            contractTermTotalCost: $short ? 24.0 : null,
            contractTermBaseTotalCost: $short && $normal ? 25.5 : null,
            contractTermDiscountSavingsTotal: $short && $normal ? 1.5 : null,
            offerTerms: $normal ? [new OfferTermData(BoundaryKind::AfterMonths, $start, $start->addMonths(3)->subDay(), 3, 0, 3, true, [
                new OfferComponentData(ComponentType::EnergyGeneral, ComponentUnit::CentsPerKwh, 4, 9,
                    $formula ? EnergyPriceRuleKind::AbsoluteDiscount : EnergyPriceRuleKind::FixedPrice, $formula ? 5 : null),
            ])] : [],
            energyRuleComparison: new EnergyRuleComparison($normal ? $differences : [], $normal ? 3 : null, false, $normal, $normal, normalAvailable: $normal, annualEquivalentEnergyPrice: 4, currentNormalRates: $normal ? ['energy_general' => 9.0] : null),
        );
    }

    public function test_exact_actual_and_estimated_normal_round_trip_with_signed_losses_and_fixed_copy(): void
    {
        $outcome = $this->outcome();
        $pricing = ContractPricingViewData::fromCanonicalOutcome($outcome);
        $this->assertSame($outcome->toCalculatedCostArray(), $pricing->toArray());
        $this->assertFalse($pricing->isEstimate());
        $this->assertTrue($pricing->benefitIsEstimate());
        $this->assertSame(-1.0, $pricing->monthlyDiscountSavings()[1]);
        $offer = CanonicalOfferFacts::fromPricing($pricing);
        $this->assertSame(3.0, $offer['benefit_eur']);
        $this->assertStringContainsString('4,00 c/kWh kiinteänä', $offer['label']);
        $this->assertStringContainsString('Arvioitu säästö', $offer['description']);
        $this->assertStringContainsString('Säästö ei ole taattu', $offer['description']);
    }

    public function test_actual_only_fixed6_preserves_real_cost_and_null_normal_facts(): void
    {
        $pricing = ContractPricingViewData::fromCanonicalOutcome($this->outcome(false, true));
        $this->assertSame(24.0, $pricing->contractTerm()->number('total_cost'));
        $this->assertNull($pricing->contractTerm()->number('base_total_cost'));
        $this->assertNull($pricing->discountSaving());
        $this->assertNull(CanonicalOfferFacts::fromPricing($pricing));
        $metric = CanonicalContractMetric::fromEvaluation($this->outcome(false, true), ContractPricingIntegrity::none());
        $weekly = (new \ReflectionMethod(WeeklyOffersVideoService::class, 'canonicalConsumptionOutput'))
            ->invoke(app(WeeklyOffersVideoService::class), $metric, 1200);
        $this->assertSame('annualized_contract_term', $weekly['total_basis']);
        $this->assertNull($weekly['customer_benefit_eur']);
        $this->assertSame($pricing->toArray(), ContractPricingViewData::fromArray($pricing->toArray())->toArray());
    }

    public function test_normal_projection_provenance_round_trips_and_rejects_partial_tariffs(): void
    {
        $estimate = new SupplierAdjustedEstimate(
            SupplierAdjustedEstimateBasis::ForwardCurveShift, [], 1, 9, 5, null, null, null, null, null, '2026-02', PriceEpisodeAnchor::missing(),
        );
        $payload = $this->outcome()->toCalculatedCostArray();
        $record = $payload['energy_rule_comparison'];
        $payload['energy_rule_comparison'] = (new EnergyRuleComparison(
            $record['signed_monthly_differences'], 3, false, true, false,
            new NormalEnergyProjection(['energy_general' => 9.0], $estimate), annualEquivalentEnergyPrice: 4,
        ))->toArray();
        $pricing = ContractPricingViewData::fromArray($payload);
        $this->assertSame($payload, $pricing->toArray());
        $this->assertSame(['energy_general' => 9.0], $pricing->energyRuleComparison()->toArray()['current_normal_rates']);
        $this->assertStringNotContainsString('quote', json_encode($payload));
        $payload['energy_rule_comparison']['current_normal_rates'] = ['energy_day' => 9.0];
        $this->expectException(InvalidArgumentException::class);
        ContractPricingViewData::fromArray($payload);
    }

    public function test_normal_projection_scalar_must_match_its_current_tariff_map(): void
    {
        $cases = [
            ['supplier_adjusted', ['energy_general' => 9.0], 9.0],
            ['supplier_adjusted', ['energy_day' => 10.0, 'energy_night' => 6.0], 8.5],
            ['supplier_adjusted', ['energy_seasonal_winter' => 12.0, 'energy_seasonal_other' => 6.0], 8.5],
            ['reset', ['energy_general' => 9.0], 9.0],
            ['reset', ['energy_day' => 10.0, 'energy_night' => 6.0], 9.0],
            ['reset', ['energy_seasonal_winter' => 12.0, 'energy_seasonal_other' => 6.0], 10.0],
        ];
        foreach ($cases as [$kind, $rates, $current]) {
            $estimate = $kind === 'reset'
                ? new ResetEstimate(ResetEstimateBasis::ForwardCurveShift, [], 1, 'quarterly', $current)
                : new SupplierAdjustedEstimate(SupplierAdjustedEstimateBasis::ForwardCurveShift, [], 1, $current, 5, null, null, null, null, null, '2026-02', PriceEpisodeAnchor::missing());
            $payload = $this->outcome()->toCalculatedCostArray();
            $payload['energy_rule_comparison'] = (new EnergyRuleComparison(
                $payload['monthly_discount_savings'], 3, false, true, false,
                new NormalEnergyProjection($rates, $estimate), annualEquivalentEnergyPrice: 4,
            ))->toArray();
            $this->assertSame($payload, ContractPricingViewData::fromArray($payload)->toArray());
            foreach (['scalar', 'map'] as $mutation) {
                $tampered = $payload;
                if ($mutation === 'scalar') {
                    $key = $kind === 'reset' ? 'current_period_energy_price' : 'current_energy_price';
                    $tampered['energy_rule_comparison']['projection']['estimate'][$key] += 10;
                } else {
                    $tampered['energy_rule_comparison']['current_normal_rates'] = array_map(fn ($rate) => $rate + 10, $rates);
                }
                try {
                    ContractPricingViewData::fromArray($tampered);
                    $this->fail('Accepted mismatched '.$kind.' '.$mutation);
                } catch (InvalidArgumentException) {
                    $this->addToAssertionCount(1);
                }
            }
        }
    }

    public function test_actual_only_estimated_continuation_never_claims_a_current_normal_tariff(): void
    {
        $estimate = new SupplierAdjustedEstimate(
            SupplierAdjustedEstimateBasis::HoldFlat,
            [], 1, 9.95, 0, null, null, null, null, null, '2026-02',
            PriceEpisodeAnchor::missing(),
        );
        $comparison = new EnergyRuleComparison([], null, true, false, false, normalAvailable: false, annualEquivalentEnergyPrice: 4, actualProjection: $estimate);
        $payload = $this->outcome(false, true)->toCalculatedCostArray();
        $payload['estimate_method'] = EstimateMethod::SourceEnergyRules->value;
        $payload['energy_rule_comparison'] = $comparison->toArray();
        $pricing = ContractPricingViewData::fromArray($payload);
        $this->assertSame($payload, $pricing->toArray());
        $this->assertNull($payload['energy_rule_comparison']['current_normal_rates']);
        $this->assertNull($payload['energy_rule_comparison']['projection']);
        $this->assertSame(9.95, $payload['energy_rule_comparison']['actual_projection']['estimate']['current_energy_price']);
        $this->assertNull(CanonicalOfferFacts::fromPricing($pricing));
        $this->assertFalse($pricing->includesDiscounts());
        $this->assertNull($pricing->baseTotal());
        $payload['energy_rule_comparison']['actual_projection']['kind'] = 'normal_tariff';
        $this->expectException(InvalidArgumentException::class);
        ContractPricingViewData::fromArray($payload);
    }

    public function test_formula_copy_and_short_term_benefit_use_distinct_facts(): void
    {
        $offer = CanonicalOfferFacts::fromPricing(ContractPricingViewData::fromCanonicalOutcome($this->outcome(true, true, true)));
        $this->assertSame(1.5, $offer['benefit_eur']);
        $this->assertStringContainsString('alennus normaalihinnasta 5,00 c/kWh', $offer['label']);
        $this->assertStringNotContainsString('kiinteänä', $offer['label']);
    }

    public function test_cards_api_and_weekly_prompt_share_the_estimated_benefit(): void
    {
        config()->set('canonical_pricing.enabled', true);
        app()->forgetScopedInstances();
        $outcome = $this->outcome();
        $contract = new ElectricityContract(['id' => 'public-energy-rule', 'name' => 'Energy Rule', 'pricing_model' => 'FixedPrice', 'contract_type' => 'FixedTerm', 'fixed_time_range' => 'Fixed12', 'metering' => 'General']);
        $contract->calculated_cost = $outcome->toCalculatedCostArray();
        $contract->setRelation('company', null);
        $card = app(ContractCardPresenter::class)->present($contract, detailed: true);
        $this->assertFalse($card->isEstimate());
        $this->assertTrue($card->benefitIsEstimate);
        $this->assertStringContainsString('Säästö ei ole taattu', $card->offerDescription);
        $this->assertSame('Energia nyt', $card->receiptLines[0]->label);

        $metric = CanonicalContractMetric::fromEvaluation($outcome, ContractPricingIntegrity::none());
        $method = new \ReflectionMethod(ContractController::class, 'canonicalCurrentPricing');
        $api = $method->invoke(app(ContractController::class), $metric);
        $this->assertFalse($api['is_estimate']);
        $this->assertTrue($api['benefit_is_estimate']);
        $this->assertSame(3.0, $api['offer']['benefit_eur']);
        $method = new \ReflectionMethod(WeeklyOffersVideoService::class, 'canonicalConsumptionOutput');
        $weekly = $method->invoke(app(WeeklyOffersVideoService::class), $metric, 1200);
        $this->assertTrue($weekly['benefit_is_estimate']);
        $this->assertSame($api['energy_rule_comparison'], $weekly['energy_rule_comparison']);
        $prompt = (new WeeklyOffersPromptFormatter)->formatPrompt([
            'pricing_basis' => 'canonical',
            'offers' => [['pricing_basis' => 'canonical', 'name' => 'Energy Rule', 'offer' => $api['offer'], 'consumptions' => array_fill_keys(['apartment', 'townhouse', 'house'], $weekly)]],
        ]);
        $this->assertStringContainsString('Arvioitu tarjousetu, ei taattu säästö', $prompt);
        $this->assertStringContainsString('Älä esitä säästöä varmana', $prompt);
    }

    public function test_both_cards_replace_projected_increase_with_the_proved_offer_end(): void
    {
        config()->set('canonical_pricing.enabled', true);
        app()->forgetScopedInstances();
        $contract = new ElectricityContract(['id' => 'source-offer-end', 'name' => 'Source offer end', 'pricing_model' => 'FixedPrice', 'contract_type' => 'OpenEnded', 'metering' => 'General']);
        $contract->calculated_cost = $this->outcome()->toCalculatedCostArray();
        $contract->setRelation('company', null);
        $contract->pricing_integrity = (new ContractPricingIntegrity(
            true, IntegrityReasonFamily::Promo,
            cardLabel: 'Hinta nousee 1.4.2026', detailHeading: 'Hinta nousee', detailFacts: ['Tuleva hinta 9 c/kWh'],
            changeDate: '2026-04-01', promoRateCents: 4, normalRateCents: 9,
        ))->toArray();
        $card = app(ContractCardPresenter::class)->present($contract);
        $this->assertSame('Tarjousjakso päättyy 31.3.2026', $card->promotionEndNotice);
        $this->assertSame($card->promotionEndNotice, $card->warnings[0]->text);
        foreach (['contract-card', 'featured-contract-card'] as $component) {
            $html = $this->blade('<x-'.$component.' :contract="$contract" :consumption="1200" />', ['contract' => $contract]);
            $html->assertSee('Tarjousjakso päättyy 31.3.2026');
            $html->assertDontSee('Hinta nousee');
            $html->assertDontSee('Energia 1.4. alkaen');
        }
    }

    public function test_guaranteed_actual_transition_survives_an_estimated_normal_comparison(): void
    {
        config()->set('canonical_pricing.enabled', true);
        app()->forgetScopedInstances();
        $payload = $this->outcome()->toCalculatedCostArray();
        $payload['monthly_costs'] = [...array_fill(0, 3, 4.0), ...array_fill(0, 9, 9.0)];
        $payload['total_cost'] = 93.0;
        $payload['avg_monthly_cost'] = 93.0 / 12;
        $payload['base_monthly_costs'] = [9.0, ...array_fill(0, 11, 12.0)];
        $payload['base_total_cost'] = 141.0;
        $payload['base_avg_monthly_cost'] = 141.0 / 12;
        $payload['monthly_discount_savings'] = [5.0, 8.0, 8.0, ...array_fill(0, 9, 3.0)];
        $payload['discount_savings_total'] = 48.0;
        $payload['energy_rule_comparison']['signed_monthly_differences'] = $payload['monthly_discount_savings'];
        $payload['energy_rule_comparison']['net_difference'] = 48.0;
        $payload['energy_rule_comparison']['annual_equivalent_energy_price'] = 7.75;
        $payload['energy_rule_comparison']['normal_held'] = false;
        $payload['energy_rule_comparison']['projection'] = ['kind' => 'supplier_adjusted', 'estimate' => (new SupplierAdjustedEstimate(SupplierAdjustedEstimateBasis::ForwardCurveShift, [], 1, 9, 0, null, null, null, null, null, '2026-02', PriceEpisodeAnchor::missing()))->toArray()];
        $phase = ['label' => '', 'phase_kind' => 'introductory', 'starts' => 'contract_start', 'ends' => 'after_months', 'ends_value' => '3',
            'window_start' => '2026-01-01', 'window_end' => '2026-03-31', 'uses_spot' => false, 'energy_cents' => 4.0, 'spot_margin_cents' => null, 'monthly_fee' => 0.0, 'energy_package' => null, 'energy_price_guaranteed' => true];
        $payload['phase_breakdown'] = [$phase, array_replace($phase, ['phase_kind' => 'normal', 'starts' => 'after_months', 'ends_value' => '12', 'window_start' => '2026-04-01', 'window_end' => '2026-12-31', 'energy_cents' => 9.0])];
        $contract = new ElectricityContract(['id' => 'guaranteed-transition', 'name' => 'Guaranteed transition', 'pricing_model' => 'FixedPrice', 'contract_type' => 'FixedTerm', 'fixed_time_range' => 'Fixed12', 'metering' => 'General']);
        $contract->calculated_cost = $payload;
        $contract->setRelation('company', null);
        $contract->pricing_integrity = (new ContractPricingIntegrity(true, IntegrityReasonFamily::Promo, cardLabel: 'Hinta nousee 1.4.2026', changeDate: '2026-04-01', promoRateCents: 4, normalRateCents: 9))->toArray();
        $card = app(ContractCardPresenter::class)->present($contract);
        $this->assertFalse($card->suppressPriceChangeNotice);
        $this->assertNull($card->promotionEndNotice);
        $this->assertSame('Hinta nousee 1.4.2026', $card->warnings[0]->text);
        $this->assertStringContainsString('alkaen', $card->receiptLines[1]->label);
        foreach (['contract-card', 'featured-contract-card'] as $component) {
            $this->blade('<x-'.$component.' :contract="$contract" :consumption="1200" />', ['contract' => $contract])->assertSee('Hinta nousee 1.4.2026');
        }
        $payload['phase_breakdown'][1]['energy_price_guaranteed'] = 'true';
        $this->expectException(InvalidArgumentException::class);
        ContractPricingViewData::fromArray($payload);
    }

    public function test_detail_faq_never_extends_current_source_rates_into_a_guarantee(): void
    {
        $payload = $this->outcome()->toCalculatedCostArray();
        $payload['comparability'] = ContractComparability::ComparableEstimate->value;
        $payload['is_estimate'] = true;
        $payload['estimate_method'] = EstimateMethod::SourceEnergyRules->value;
        $payload['energy_rule_comparison']['actual_estimated'] = true;
        $pricing = ContractPricingViewData::fromArray($payload);
        $contract = new ElectricityContract(['pricing_model' => 'FixedPrice', 'contract_type' => 'FixedTerm', 'fixed_time_range' => 'Fixed12', 'metering' => 'General']);
        $facts = (new PricingCategoryResolver)->resolve($contract);
        $detail = new ContractDetail;
        $faq = (new \ReflectionMethod($detail, 'faqMechanismItem'))->invoke($detail, $contract, $pricing, $facts);
        $cost = (new \ReflectionMethod($detail, 'faqCostBasisSentence'))->invoke($detail, $pricing, $facts);
        $this->assertSame('Miten tämän sopimuksen hinta määräytyy?', $faq['question']);
        $this->assertStringContainsString('Tuntemattomien jaksojen energiahinnat on arvioitu', $faq['answer']);
        $this->assertStringContainsString('Arvio ei ole hintalupaus', $cost);
        $this->assertStringNotContainsString('voimassa koko', $faq['answer']);
        $this->assertStringNotContainsString('luku muuttuu vain', $cost);
    }

    public function test_model_floor_notice_is_scoped_and_not_duplicated_in_popover_or_detail(): void
    {
        foreach ([[], ['estimated_energy_nonnegative_model_floor_applied'], ['energy_rule_nonnegative_model_floor_applied'], ['estimated_energy_nonnegative_model_floor_applied', 'energy_rule_nonnegative_model_floor_applied']] as $flags) {
            $payload = $this->outcome()->toCalculatedCostArray();
            $payload['estimate_method'] = EstimateMethod::SourceEnergyRules->value;
            $payload['is_estimate'] = true;
            $payload['comparability'] = ContractComparability::ComparableEstimate->value;
            $payload['energy_rule_comparison']['actual_estimated'] = true;
            $payload['assumptions'] = $flags;
            $pricing = ContractPricingViewData::fromArray($payload);
            $before = $pricing->toArray();
            $contract = new ElectricityContract(['pricing_model' => 'FixedPrice', 'contract_type' => 'OpenEnded', 'metering' => 'General']);
            $facts = (new PricingCategoryResolver)->resolve($contract);
            $popover = ContractCardCopy::estimate($pricing, $facts);
            $detail = new class($pricing, $contract) extends ContractDetail
            {
                public function __construct(private ContractPricingViewData $testPricing, private ElectricityContract $testContract) {}

                public function getContractProperty(): ?ElectricityContract
                {
                    return $this->testContract;
                }

                public function getIsPricingExcludedProperty(): bool
                {
                    return false;
                }

                protected function pricingViewDataFor(int $consumption): ?ContractPricingViewData
                {
                    return $this->testPricing;
                }
            };
            $notes = implode(' ', $detail->getReceiptNotesProperty());
            foreach ([$popover->body, $notes] as $copy) {
                $this->assertSame($flags === [] ? 0 : 1, substr_count($copy, 'Voltikan laskentamalli rajasi'));
                if ($flags !== []) {
                    $this->assertStringContainsString('arvoon 0 c/kWh', $copy);
                    $this->assertStringContainsString('ei ole myyjän asettama vähimmäishinta tai hintatakuu', $copy);
                }
            }
            $this->assertStringContainsString('Ilmoitetut hinnat ja alennusehdot on huomioitu omilta voimassaoloajoiltaan', $popover->body);
            $this->assertSame($before, $pricing->toArray());
        }
    }

    public function test_malformed_comparisons_are_rejected(): void
    {
        $valid = $this->outcome()->toCalculatedCostArray();
        $mutations = [
            ['energy_rule_comparison.method', 'unknown'],
            ['energy_rule_comparison.normal_estimated', 1],
            ['energy_rule_comparison.net_difference', 5],
            ['energy_rule_comparison.annual_equivalent_energy_price', INF],
            ['energy_rule_comparison.signed_monthly_differences.1', 1],
            ['energy_rule_comparison.normal_available', false],
            ['monthly_costs.0', 7],
            ['base_monthly_costs.0', 7],
            ['includes_discounts', false],
            ['offer_terms.0.components.0.rule_kind', 'unknown'],
        ];
        foreach ($mutations as [$key, $value]) {
            $payload = $valid;
            data_set($payload, $key, $value);
            try {
                ContractPricingViewData::fromArray($payload);
                $this->fail('Accepted malformed '.$key);
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_fee_only_benefit_stays_exact_when_shared_energy_estimates_cancel(): void
    {
        $payload = $this->outcome()->toCalculatedCostArray();
        $payload['comparability'] = ContractComparability::ComparableEstimate->value;
        $payload['is_estimate'] = true;
        $payload['estimate_method'] = EstimateMethod::SourceEnergyRules->value;
        $payload['base_total_cost'] = 60.0;
        $payload['base_avg_monthly_cost'] = 5.0;
        $payload['base_monthly_costs'] = array_fill(0, 12, 5.0);
        $payload['discount_savings_total'] = 12.0;
        $payload['monthly_discount_savings'] = array_fill(0, 12, 1.0);
        $payload['energy_rule_comparison'] = (new EnergyRuleComparison(array_fill(0, 12, 1.0), 12, true, true, true, annualEquivalentEnergyPrice: 4, currentNormalRates: ['energy_general' => 4.0]))->toArray();
        $start = CarbonImmutable::parse('2026-01-01', 'Europe/Helsinki');
        $payload['offer_terms'] = [(new OfferTermData(BoundaryKind::AfterMonths, $start, $start->addYear()->subDay(), 12, 0, 12, true, [new OfferComponentData(ComponentType::MonthlyFee, ComponentUnit::EurPerMonth, 0, 1)]))->toArray()];
        $pricing = ContractPricingViewData::fromArray($payload);
        $this->assertTrue($pricing->isEstimate());
        $this->assertFalse($pricing->benefitIsEstimate());
        $offer = CanonicalOfferFacts::fromPricing($pricing);
        $this->assertFalse($offer['benefit_is_estimate']);
        $this->assertSame(12.0, $offer['benefit_eur']);
        $this->assertStringContainsString('Säästö 12 €', $offer['description']);
        $this->assertStringNotContainsString('Arvioitu säästö', $offer['description']);
    }

    public function test_net_loss_is_not_an_offer_even_when_some_months_save_money(): void
    {
        $payload = $this->outcome()->toCalculatedCostArray();
        $payload['base_monthly_costs'][3] = 0.0;
        $payload['base_total_cost'] = 47.0;
        $payload['base_avg_monthly_cost'] = 47.0 / 12;
        $payload['discount_savings_total'] = -1.0;
        $payload['monthly_discount_savings'][3] = -4.0;
        $payload['energy_rule_comparison']['signed_monthly_differences'][3] = -4.0;
        $payload['energy_rule_comparison']['net_difference'] = -1.0;
        $payload['includes_discounts'] = false;
        $pricing = ContractPricingViewData::fromArray($payload);
        $this->assertSame(-1.0, $pricing->discountSaving());
        $this->assertNull(CanonicalOfferFacts::fromPricing($pricing));
    }

    public function test_zero_operator_and_equal_fixed_metadata_do_not_create_energy_offers(): void
    {
        foreach (['percentage_discount', 'fixed_price'] as $kind) {
            [, $raw] = EnergyRulesFixture::example($kind, 4, 0);
            $data = (new CanonicalPricingParser)->parse($raw['pricing'], $raw['calculation'], $raw['source_consistency'], withEnergyRules: true);
            $start = CarbonImmutable::parse('2026-01-01', 'Europe/Helsinki');
            $plan = EnergyRulePlan::build($data, MeteringType::General, $start, $start->addYear(), new PhaseTimelineBuilder, null);
            $this->assertNotNull($plan);
            $this->assertSame([], EnergyRuleOfferTerms::build($data, $plan, $start, $start->addYear()));
        }
    }

    public function test_absolute_offer_end_stays_a_date_even_on_a_month_anniversary(): void
    {
        [, $raw] = EnergyRulesFixture::example();
        $raw['pricing']['phases'][0]['components'][0]['energy_rule']['starts'] = ['kind' => 'date', 'value' => '2026-01-01'];
        $raw['pricing']['phases'][0]['components'][0]['energy_rule']['ends'] = ['kind' => 'date', 'value' => '2026-03-31'];
        $data = (new CanonicalPricingParser)->parse($raw['pricing'], $raw['calculation'], $raw['source_consistency'], withEnergyRules: true);
        $start = CarbonImmutable::parse('2026-01-01', 'Europe/Helsinki');
        $plan = EnergyRulePlan::build($data, MeteringType::General, $start, $start->addYear(), new PhaseTimelineBuilder, null);
        $terms = EnergyRuleOfferTerms::build($data, $plan, $start, $start->addYear());
        $this->assertSame(BoundaryKind::Date, $terms[0]->endKind);
        $payload = $this->outcome()->toCalculatedCostArray();
        $payload['offer_terms'] = array_map(fn ($term) => $term->toArray(), $terms);
        $this->assertStringContainsString('31.3.2026 asti', CanonicalOfferFacts::fromArray($payload)['label']);
    }

    public function test_helper_keeps_positive_percentage_at_zero_normal_and_fee_timing(): void
    {
        [, $raw] = EnergyRulesFixture::example('percentage_discount', 0, 25);
        $raw['pricing']['phases'][0]['ends']['value'] = '1';
        $raw['pricing']['phases'][1]['starts']['value'] = '1';
        $raw['pricing']['phases'][0]['components'][] = ['component_type' => 'monthly_fee', 'amount' => 0, 'normal_amount' => 5, 'unit' => 'eur_per_month', 'price_role' => 'introductory', 'vat_status' => 'included'];
        $data = (new CanonicalPricingParser)->parse($raw['pricing'], $raw['calculation'], $raw['source_consistency'], withEnergyRules: true);
        $start = CarbonImmutable::parse('2026-01-01', 'Europe/Helsinki');
        $end = $start->addYear();
        $plan = EnergyRulePlan::build($data, MeteringType::General, $start, $end, new PhaseTimelineBuilder, null);
        $this->assertNotNull($plan);
        $terms = EnergyRuleOfferTerms::build($data, $plan, $start, $end);
        $this->assertCount(2, $terms);
        $this->assertSame(25.0, $terms[0]->components[0]->discountValue);
        $this->assertSame(0.0, $terms[0]->components[0]->normalAmount);
        $this->assertSame(3, $terms[0]->durationMonths);
        $this->assertSame(1, $terms[1]->durationMonths);
        $payload = $this->outcome()->toCalculatedCostArray();
        $payload['offer_terms'] = array_map(fn ($term) => $term->toArray(), $terms);
        $offer = CanonicalOfferFacts::fromArray($payload);
        $this->assertStringContainsString('alennus normaalihinnasta 25 %', $offer['label']);
        $this->assertStringContainsString('Perusmaksu 0 €/kk ensimmäisen kuukauden', $offer['label']);

        // The fee's own introductory role and exact continuation are equivalent proof.
        $raw['pricing']['phases'][0]['components'][1]['normal_amount'] = null;
        $raw['pricing']['phases'][1]['components'][] = ['component_type' => 'monthly_fee', 'amount' => 5, 'normal_amount' => null, 'unit' => 'eur_per_month', 'price_role' => 'normal', 'vat_status' => 'included'];
        $data = (new CanonicalPricingParser)->parse($raw['pricing'], $raw['calculation'], $raw['source_consistency'], withEnergyRules: true);
        $plan = EnergyRulePlan::build($data, MeteringType::General, $start, $end, new PhaseTimelineBuilder, null);
        $this->assertEquals($terms, EnergyRuleOfferTerms::build($data, $plan, $start, $end));

        // An unsupported changed fee must not leave only the attractive energy sentence.
        $raw['pricing']['phases'][0]['components'][1] = ['component_type' => 'flat_fee', 'amount' => 1, 'normal_amount' => 5, 'unit' => 'eur_flat', 'price_role' => 'introductory', 'vat_status' => 'included'];
        $data = (new CanonicalPricingParser)->parse($raw['pricing'], $raw['calculation'], $raw['source_consistency'], withEnergyRules: true);
        $plan = EnergyRulePlan::build($data, MeteringType::General, $start, $end, new PhaseTimelineBuilder, null);
        $this->assertNotNull($plan);
        $this->assertSame([], EnergyRuleOfferTerms::build($data, $plan, $start, $end));
    }
}
