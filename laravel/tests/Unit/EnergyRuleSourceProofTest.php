<?php

namespace Tests\Unit;

use App\Services\CanonicalPricing\CanonicalPricingParser;
use App\Services\CanonicalPricing\Enums\EnergyPriceRuleKind;
use App\Services\CanonicalPricing\Exceptions\CanonicalPricingParseException;
use App\Services\ContractInterpretation\ContractInterpretationProfile;
use App\Services\ContractInterpretation\ContractInterpretationValidator;
use App\Services\ContractInterpretation\EnergyRuleSourceProof;
use Tests\Support\EnergyRulesFixture as F;
use Tests\TestCase;

class EnergyRuleSourceProofTest extends TestCase
{
    public function test_identical_source_reductions_bill_four_in_both_profiles_but_do_not_prove_the_same_rule(): void
    {
        [$fixedInput, $fixedOutput, $fixedSource] = F::example('fixed_price');
        [$formulaInput, $formulaOutput, $formulaSource] = F::example('absolute_discount');
        $this->assertSame($fixedSource['Details']['Pricing'], $formulaSource['Details']['Pricing']);
        $this->assertSame($fixedInput['components'], $formulaInput['components']);
        $this->assertSame(9.0, (float) $fixedInput['components'][0]['price']);
        $this->assertSame(5.0, (float) $fixedInput['components'][0]['discount_value']);
        $this->assertFalse($fixedInput['components'][0]['discount_is_percentage']);
        $validator = new ContractInterpretationValidator;
        foreach ([[$fixedInput, $fixedOutput], [$formulaInput, $formulaOutput]] as [$input, $output]) {
            $this->assertSame(4.0, $output['pricing']['phases'][0]['components'][0]['amount']);
            $this->assertSame([], $validator->validate($output, $input, F::profile()));
            $wrong = $output;
            $wrong['pricing']['phases'][0]['components'][0]['amount'] = 5;
            $this->assertNotEmpty($validator->validate($wrong, $input, F::profile()));
            $output['schema_version'] = '1.1';
            foreach ($output['pricing']['phases'] as &$phase) {
                foreach ($phase['components'] as &$component) {
                    unset($component['energy_rule']);
                }
            }
            unset($phase, $component);
            $this->assertSame([], $validator->validate($output, $input, ContractInterpretationProfile::stored('schema-v4', 'prompt-v19', 'validator-v17')));
            $output['pricing']['phases'][0]['components'][0]['amount'] = 5;
            $this->assertNotEmpty($validator->validate($output, $input, ContractInterpretationProfile::stored('schema-v4', 'prompt-v19', 'validator-v17')));
        }
        $fixedRule = $fixedOutput['pricing']['phases'][0]['components'][0]['energy_rule'];
        $formulaRule = $formulaOutput['pricing']['phases'][0]['components'][0]['energy_rule'];
        $this->assertSame('fixed_price', $fixedRule['kind']);
        $this->assertNull($fixedRule['discount_value']);
        $this->assertSame('absolute_discount', $formulaRule['kind']);
        $this->assertSame(5.0, $formulaRule['discount_value']);
        $this->assertSame('adjustable_tariff', $formulaRule['normal_basis']['kind']);
        $formulaOutput['pricing']['phases'][0]['components'][0]['energy_rule'] = $fixedRule;
        $this->assertNotEmpty($validator->validate($formulaOutput, $formulaInput, F::profile()));
    }

    public function test_fixed_actual_and_adjustable_formula_have_distinct_proven_facts(): void
    {
        foreach (['fixed_price', 'absolute_discount', 'percentage_discount'] as $kind) {
            [$input, $output] = F::example($kind);
            $this->assertSame([], (new ContractInterpretationValidator)->validate($output, $input, F::profile()));
            $data = (new CanonicalPricingParser)->parse($output['pricing'], $output['calculation'], $output['source_consistency'], withEnergyRules: true);
            $rule = $data->phases[0]->components[0]->energyRule;
            $this->assertSame($kind, $rule->kind->value);
            $this->assertSame(EnergyPriceRuleKind::AdjustableTariff, $rule->normalBasis->kind);
        }
    }

    public function test_zero_normal_and_source_floor_do_not_erase_the_operand(): void
    {
        foreach ([['percentage_discount', 0, 50, null], ['absolute_discount', 5, 7, 0]] as $case) {
            [$input, $output] = F::example(...$case);
            $this->assertSame([], (new EnergyRuleSourceProof)->validate($output, $input, new ContractInterpretationValidator));
            $this->assertSame([], (new ContractInterpretationValidator)->validate($output, $input, F::profile()));
            $this->assertSame((float) $case[2], $output['pricing']['phases'][0]['components'][0]['energy_rule']['discount_value']);
            $output['pricing']['phases'][0]['components'][0]['energy_rule']['discount_value'] = 0;
            $this->assertNotEmpty((new EnergyRuleSourceProof)->validate($output, $input, new ContractInterpretationValidator));
        }
    }

    public function test_api_clipping_does_not_prove_a_continuing_source_floor(): void
    {
        [$input, $output] = F::example('absolute_discount', 5, 7, 0);
        $this->assertSame(7.0, (float) $input['components'][0]['discount_value']);
        $this->assertSame(0.0, $output['pricing']['phases'][0]['components'][0]['amount']);
        $validator = new ContractInterpretationValidator;
        $this->assertSame([], $validator->validate($output, $input, F::profile()));
        $input['short_description'] = str_replace(', with a minimum price of 0 cents/kWh', '', $input['short_description']);
        $rule = &$output['pricing']['phases'][0]['components'][0]['energy_rule'];
        $rule['floor_amount'] = null;
        $rule['evidence'][2]['quote'] = str_replace(', with a minimum price of 0 cents/kWh', '', $rule['evidence'][2]['quote']);
        $this->assertNotEmpty($validator->validate($output, $input, F::profile()));
        unset($rule);
        $output['pricing']['phases'][0]['components'][0]['energy_rule'] = F::unknown();
        $this->assertSame([], $validator->validate($output, $input, F::profile()));
    }

    public function test_zero_discount_operands_remain_explicit_and_percentages_above_one_hundred_fail(): void
    {
        foreach (['absolute_discount', 'percentage_discount'] as $kind) {
            [$input, $output] = F::example($kind, 9, 0);
            $this->assertSame([], (new ContractInterpretationValidator)->validate($output, $input, F::profile()));
        }
        [$input, $output] = F::example('percentage_discount', 9, 50);
        $output['pricing']['phases'][0]['components'][0]['energy_rule']['discount_value'] = 101;
        $this->assertNotEmpty((new ContractInterpretationValidator)->validate($output, $input, F::profile()));
    }

    public function test_invented_floor_and_changed_operand_fail_even_when_arithmetic_still_matches(): void
    {
        [$input, $output] = F::example('absolute_discount', 5, 7, 0);
        foreach (['discount_value' => 5, 'floor_amount' => null] as $key => $bad) {
            $changed = $output;
            $changed['pricing']['phases'][0]['components'][0]['energy_rule'][$key] = $bad;
            $this->assertNotEmpty((new EnergyRuleSourceProof)->validate($changed, $input, new ContractInterpretationValidator));
        }
        [$input, $output] = F::example('absolute_discount');
        $output['pricing']['phases'][0]['components'][0]['energy_rule']['floor_amount'] = 0;
        $this->assertNotEmpty((new EnergyRuleSourceProof)->validate($output, $input, new ContractInterpretationValidator));
    }

    public function test_complete_context_rejects_negation_conditions_optional_terms_and_quote_omission(): void
    {
        [$input, $output] = F::example();
        $original = $input['short_description'];
        foreach (['Not '.$original, 'If you qualify, '.$original, 'Optional fixing: '.$original, $original.' unless separately agreed', $original.'; The guarantee is optional', str_replace('General energy', 'Day energy', $original), str_replace('for the first 3 months', '', $original)] as $text) {
            $input['short_description'] = $text;
            $this->assertNotEmpty((new EnergyRuleSourceProof)->validate($output, $input, new ContractInterpretationValidator), $text);
        }
        $input['short_description'] = $original;
        $output['pricing']['phases'][0]['components'][0]['energy_rule']['evidence'][2]['quote'] = 'fixed at 4';
        $this->assertNotEmpty((new EnergyRuleSourceProof)->validate($output, $input, new ContractInterpretationValidator));
    }

    public function test_actual_lock_does_not_prove_a_normal_lock_or_an_indefinite_actual_lock(): void
    {
        [$input, $output] = F::example();
        $output['pricing']['phases'][0]['components'][0]['energy_rule']['normal_basis']['kind'] = 'fixed_price';
        $output['pricing']['phases'][0]['components'][0]['energy_rule']['normal_basis']['ends'] = ['kind' => 'after_months', 'value' => '3'];
        $this->assertNotEmpty((new EnergyRuleSourceProof)->validate($output, $input, new ContractInterpretationValidator));
        [$input, $output] = F::example();
        $output['pricing']['phases'][0]['components'][0]['energy_rule']['ends'] = ['kind' => 'none', 'value' => null];
        $this->assertNotEmpty((new EnergyRuleSourceProof)->validate($output, $input, new ContractInterpretationValidator));
    }

    public function test_finnish_clauses_and_each_tariff_scope_have_exact_identity_proof(): void
    {
        foreach (['General' => ['Yleissähkön', 'energy_general'], 'DayTime' => ['Päiväsähkön', 'energy_day'], 'NightTime' => ['Yösähkön', 'energy_night'], 'SeasonalWinterDay' => ['Talvisähkön', 'energy_seasonal_winter'], 'SeasonalOther' => ['Muun ajan sähkön', 'energy_seasonal_other']] as $sourceType => [$name, $type]) {
            [$input, $output] = F::example();
            $actual = "{$name} energiahinta on kiinteä 4 snt/kWh ensimmäiset 3 kuukautta";
            $normal = "{$name} normaali energiatariffi on muuttuva ja on nyt 9 snt/kWh";
            $input['short_description'] = $actual.'; '.$normal;
            $input['components'][0]['price_component_type'] = $sourceType;
            $component = &$output['pricing']['phases'][0]['components'][0];
            $component['component_type'] = $type;
            $component['energy_rule']['evidence'] = [...F::scope(0, $sourceType), F::citation('short_description', $actual)];
            $component['energy_rule']['normal_basis']['evidence'] = [...F::scope(0, $sourceType), F::citation('short_description', $normal)];
            $this->assertSame([], (new EnergyRuleSourceProof)->validate($output, $input, new ContractInterpretationValidator));
            $input['components'][0]['price_component_type'] = 'Monthly';
            $this->assertNotEmpty((new EnergyRuleSourceProof)->validate($output, $input, new ContractInterpretationValidator));
            unset($component);
        }
    }

    public function test_finnish_percentage_formula_and_floor_need_the_complete_positive_clause(): void
    {
        [$input, $output] = F::example('percentage_discount', 9, 50, 0);
        $actual = 'Yleissähkön energiahinta on normaali energiatariffi miinus 50 prosenttia ensimmäiset 3 kuukautta, vähimmäishinta 0 snt/kWh';
        $normal = 'Yleissähkön normaali energiatariffi on muuttuva ja on nyt 9 snt/kWh';
        $input['short_description'] = $actual.'; '.$normal;
        $rule = &$output['pricing']['phases'][0]['components'][0]['energy_rule'];
        $rule['evidence'] = [...F::scope(), F::citation('short_description', $actual)];
        $rule['normal_basis']['evidence'] = [...F::scope(), F::citation('short_description', $normal)];
        $this->assertSame([], (new ContractInterpretationValidator)->validate($output, $input, F::profile()));
        $input['short_description'] = str_replace('energiahinta on', 'energiahinta ei ole', $input['short_description']);
        $this->assertNotEmpty((new ContractInterpretationValidator)->validate($output, $input, F::profile()));
    }

    public function test_observed_adjustable_tariff_is_not_a_fixed_price_guarantee(): void
    {
        [$input, $output] = F::example();
        $text = 'General energy tariff is adjustable and is currently 9 cents/kWh';
        $input['short_description'] = $text;
        $input['components'][0]['has_discount'] = false;
        $output['pricing']['phases'] = [$output['pricing']['phases'][0]];
        $output['pricing']['phases'][0]['phase_kind'] = 'current_structured';
        $output['pricing']['phases'][0]['ends'] = ['kind' => 'none', 'value' => null];
        $output['pricing']['phases'][0]['components'][0]['price_role'] = 'current';
        $component = &$output['pricing']['phases'][0]['components'][0];
        $component['amount'] = 9;
        $component['normal_amount'] = null;
        $component['evidence'] = [F::citation('components[0].price', 9)];
        $component['energy_rule'] = ['kind' => 'adjustable_tariff', 'starts' => ['kind' => 'contract_start', 'value' => null], 'ends' => ['kind' => 'none', 'value' => null], 'discount_value' => null, 'floor_amount' => null, 'normal_basis' => null, 'evidence' => [...F::scope(), F::citation('short_description', $text)]];
        $this->assertSame([], (new ContractInterpretationValidator)->validate($output, $input, F::profile()));
        $component['energy_rule']['kind'] = 'fixed_price';
        $this->assertNotEmpty((new ContractInterpretationValidator)->validate($output, $input, F::profile()));
    }

    public function test_structured_arithmetic_alone_or_duplicate_source_slots_cannot_prove_a_rule(): void
    {
        [$input, $output] = F::example('absolute_discount');
        $input['short_description'] = null;
        $this->assertNotEmpty((new EnergyRuleSourceProof)->validate($output, $input, new ContractInterpretationValidator));
        [$input, $output] = F::example('absolute_discount');
        $input['components'][] = $input['components'][0];
        $this->assertNotEmpty((new EnergyRuleSourceProof)->validate($output, $input, new ContractInterpretationValidator));
    }

    public function test_finite_future_actual_and_independent_normal_lock_require_separate_clauses(): void
    {
        [$input, $output] = F::example();
        $actual = 'General energy price is fixed at 4 cents/kWh from 2026-10-01 through 2026-12-31';
        $normal = 'General normal energy price is fixed at 9 cents/kWh from 2026-10-01 through 2026-12-31';
        $input['short_description'] = $actual.'; '.$normal;
        $rule = &$output['pricing']['phases'][0]['components'][0]['energy_rule'];
        $rule['starts'] = $rule['normal_basis']['starts'] = ['kind' => 'date', 'value' => '2026-10-01'];
        $rule['ends'] = $rule['normal_basis']['ends'] = ['kind' => 'date', 'value' => '2026-12-31'];
        $rule['normal_basis']['kind'] = 'fixed_price';
        $rule['evidence'] = [...F::scope(), F::citation('short_description', $actual)];
        $rule['normal_basis']['evidence'] = [...F::scope(), F::citation('short_description', $normal)];
        $this->assertSame([], (new EnergyRuleSourceProof)->validate($output, $input, new ContractInterpretationValidator));
        $input['short_description'] = $actual;
        $this->assertNotEmpty((new EnergyRuleSourceProof)->validate($output, $input, new ContractInterpretationValidator));
        $input['short_description'] = $actual.'; '.$normal.'; General energy price is fixed at 5 cents/kWh from 2026-10-01 through 2026-12-31';
        $this->assertNotEmpty((new EnergyRuleSourceProof)->validate($output, $input, new ContractInterpretationValidator));
    }

    public function test_full_schema_requires_unknown_energy_object_and_null_nonenergy_rule(): void
    {
        [$input, $output] = F::example();
        $validator = new ContractInterpretationValidator;
        $output['pricing']['phases'][0]['components'][0]['energy_rule'] = F::unknown();
        $this->assertSame([], $validator->validate($output, $input, F::profile()));
        $output['pricing']['phases'][0]['components'][0]['energy_rule'] = null;
        $this->assertNotEmpty($validator->validate($output, $input, F::profile()));
        unset($output['pricing']['phases'][0]['components'][0]['energy_rule']);
        $this->assertNotEmpty($validator->validate($output, $input, F::profile()));
        [, $output] = F::example();
        foreach (['monthly_fee', 'spot_margin', 'consumption_effect', 'flat_fee'] as $type) {
            $output['pricing']['phases'][0]['components'][0]['component_type'] = $type;
            $this->assertNotEmpty((new EnergyRuleSourceProof)->validate($output, $input, $validator));
        }
    }

    public function test_full_day_night_and_seasonal_outputs_bind_each_rule_to_its_own_tariff(): void
    {
        foreach ([['Time', 'time_of_use', ['Day', 'Night'], ['DayTime', 'NightTime'], ['energy_day', 'energy_night']], ['Season', 'seasonal', ['Winter', 'Other-season'], ['SeasonalWinterDay', 'SeasonalOther'], ['energy_seasonal_winter', 'energy_seasonal_other']]] as [$metering, $mechanism, $names, $sourceTypes, $types]) {
            [$input, $output] = F::example();
            $source = $input['components'][0];
            $first = $output['pricing']['phases'][0]['components'][0];
            $continuation = $output['pricing']['phases'][1]['components'][0];
            $input['metering'] = $output['classification']['metering'] = $output['source_consistency']['recommended_metering'] = $metering;
            $output['classification']['pricing_mechanisms'] = ['fixed', $mechanism];
            $clauses = [];
            foreach ($types as $i => $type) {
                $input['components'][$i] = array_replace($source, ['price_component_type' => $sourceTypes[$i]]);
                $actual = $names[$i].' energy price is fixed at 4 cents/kWh for the first 3 months';
                $normal = $names[$i].' normal energy tariff is adjustable and is currently 9 cents/kWh';
                $clauses = [...$clauses, $actual, $normal];
                $component = $first;
                $component['component_type'] = $type;
                foreach ($component['evidence'] as &$citation) {
                    $citation['source'] = str_replace('[0]', '['.$i.']', $citation['source']);
                }
                unset($citation);
                $component['energy_rule']['evidence'] = [...F::scope($i, $sourceTypes[$i]), F::citation('short_description', $actual)];
                $component['energy_rule']['normal_basis']['evidence'] = [...F::scope($i, $sourceTypes[$i]), F::citation('short_description', $normal)];
                $output['pricing']['phases'][0]['components'][$i] = $component;
                $normalComponent = $continuation;
                $normalComponent['component_type'] = $type;
                $normalComponent['evidence'] = [F::citation("components[{$i}].price", 9)];
                $output['pricing']['phases'][1]['components'][$i] = $normalComponent;
            }
            $input['short_description'] = implode('; ', $clauses);
            $this->assertSame([], (new ContractInterpretationValidator)->validate($output, $input, F::profile()));
            $output['pricing']['phases'][0]['components'][0]['energy_rule'] = $output['pricing']['phases'][0]['components'][1]['energy_rule'];
            $this->assertNotEmpty((new ContractInterpretationValidator)->validate($output, $input, F::profile()));
        }
    }

    public function test_new_parser_rejects_malformed_rule_values_and_preserves_missing_old_rules(): void
    {
        [, $original] = F::example('absolute_discount');
        $parser = new CanonicalPricingParser;
        foreach (['discount_value' => INF, 'floor_amount' => -1, 'starts' => ['kind' => 'date', 'value' => '2026-02-30'], 'normal_basis' => ['kind' => 'other'], 'evidence' => 'quote'] as $key => $value) {
            $output = $original;
            $output['pricing']['phases'][0]['components'][0]['energy_rule'][$key] = $value;
            try {
                $parser->parse($output['pricing'], $output['calculation'], $output['source_consistency'], true);
                $this->fail('Malformed '.$key.' was accepted.');
            } catch (CanonicalPricingParseException) {
                $this->addToAssertionCount(1);
            }
        }
        unset($original['pricing']['phases'][0]['components'][0]['energy_rule']);
        $data = $parser->parse($original['pricing'], $original['calculation'], $original['source_consistency'], true);
        $this->assertSame(EnergyPriceRuleKind::Unknown, $data->phases[0]->components[0]->energyRule->kind);
    }

    public function test_old_parser_ignores_rule_fields_while_new_parser_fails_closed(): void
    {
        [$input, $output] = F::example();
        $parser = new CanonicalPricingParser;
        $output['pricing']['phases'][0]['components'][0]['energy_rule']['kind'] = 'future_unknown_kind';
        $old = $parser->parse($output['pricing'], $output['calculation'], $output['source_consistency']);
        $this->assertSame(EnergyPriceRuleKind::Unknown, $old->phases[0]->components[0]->energyRule->kind);
        $this->expectException(CanonicalPricingParseException::class);
        $parser->parse($output['pricing'], $output['calculation'], $output['source_consistency'], true);
    }

    public function test_vat_copy_scales_absolute_operand_and_floor_once_not_percentage(): void
    {
        foreach ([['absolute_discount', 5, 7, 2], ['percentage_discount', 9, 50, 2]] as $case) {
            [, $output] = F::example(...$case);
            $data = (new CanonicalPricingParser)->parse($output['pricing'], $output['calculation'], $output['source_consistency'], true);
            $component = $data->phases[0]->components[0];
            $copy = $component->withVatBasis(false, 1.255);
            $this->assertEqualsWithDelta($case[0] === 'absolute_discount' ? 7 / 1.255 : 50, $copy->energyRule->discountValue, 0.000001);
            $this->assertEqualsWithDelta(2 / 1.255, $copy->energyRule->floorAmount, 0.000001);
            $this->assertEquals($copy, $copy->withVatBasis(false, 1.255));
            $this->assertEquals($component, $copy->withVatBasis(true, 1.255));
        }
    }
}
