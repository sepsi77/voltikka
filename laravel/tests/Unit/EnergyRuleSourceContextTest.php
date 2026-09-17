<?php

namespace Tests\Unit;

use App\Services\ContractInterpretation\ContractInterpretationValidator;
use App\Services\ContractInterpretation\EnergyRuleSourceProof;
use Tests\Support\EnergyRulesFixture as F;
use Tests\TestCase;

class EnergyRuleSourceContextTest extends TestCase
{
    public function test_natural_general_clauses_survive_harmless_marketing_and_contact_sentences(): void
    {
        foreach (['Energiahinta on kiinteä 4 snt/kWh ensimmäiset 3 kuukautta', 'Energian hinta on kiinteä 4 snt/kWh ensimmäisen 3 kuukauden ajan', 'Kiinteä energiahinta on 4 snt/kWh ensimmäiset 3 kuukautta'] as $actual) {
            [$input, $output] = $this->natural($actual);
            $this->assertSame([], (new ContractInterpretationValidator)->validate($output, $input, F::profile()));
            $input['metering'] = 'Time';
            $this->assertNotEmpty((new EnergyRuleSourceProof)->validate($output, $input, new ContractInterpretationValidator));
            $input['metering'] = 'General';
            $input['components'][] = ['price_component_type' => 'Other', 'payment_unit' => 'CentPerKiwattHour', 'price' => 1];
            $this->assertNotEmpty((new EnergyRuleSourceProof)->validate($output, $input, new ContractInterpretationValidator));
        }
    }

    public function test_natural_general_discount_prose_does_not_prove_a_price_lock(): void
    {
        [$input, $output] = $this->natural('Energiahinta on normaali energiatariffi miinus 5 snt/kWh ensimmäiset 3 kuukautta');
        $rule = &$output['pricing']['phases'][0]['components'][0]['energy_rule'];
        $rule['kind'] = 'absolute_discount';
        $rule['discount_value'] = 5;
        $validator = new ContractInterpretationValidator;
        $this->assertSame([], $validator->validate($output, $input, F::profile()));
        $rule['kind'] = 'fixed_price';
        $rule['discount_value'] = null;
        $this->assertNotEmpty($validator->validate($output, $input, F::profile()));
    }

    public function test_detached_or_inline_pricing_conditions_cannot_be_quoted_away(): void
    {
        $actual = 'Energiahinta on kiinteä 4 snt/kWh ensimmäiset 3 kuukautta';
        foreach (['Hinta koskee vain uusia asiakkaita.', 'Only for new customers.', 'It may change.', 'Hinnat voivat vaihdella.', 'Kun maksat etukäteen.', 'Energiahinta ei ole kiinteä.'] as $qualification) {
            [$input, $output] = $this->natural($actual);
            $input['long_description'] = $qualification;
            $this->assertNotEmpty((new ContractInterpretationValidator)->validate($output, $input, F::profile()), $qualification);
        }
        [$input, $output] = $this->natural($actual);
        $input['short_description'] = str_replace($actual, $actual.', jos maksat etukäteen', $input['short_description']);
        $this->assertNotEmpty((new ContractInterpretationValidator)->validate($output, $input, F::profile()));
        [$input, $output] = $this->natural($actual);
        $input['short_description'] = str_replace($actual.'.', $actual.'?', $input['short_description']);
        $this->assertNotEmpty((new ContractInterpretationValidator)->validate($output, $input, F::profile()));
        [$input, $output] = $this->natural($actual);
        $output['pricing']['phases'][0]['components'][0]['energy_rule']['evidence'][2]['quote'] = 'kiinteä 4 snt/kWh';
        $this->assertNotEmpty((new ContractInterpretationValidator)->validate($output, $input, F::profile()));
    }

    public function test_current_normal_tariff_can_support_the_exact_normal_continuation_without_a_guarantee(): void
    {
        [$input, $output] = $this->natural('Energiahinta on kiinteä 4 snt/kWh ensimmäiset 3 kuukautta');
        $rule = $output['pricing']['phases'][0]['components'][0]['energy_rule']['normal_basis'];
        $component = &$output['pricing']['phases'][1]['components'][0];
        $component['energy_rule'] = $rule + ['discount_value' => null, 'floor_amount' => null, 'normal_basis' => null];
        foreach (['has_discount', 'discount_type', 'discount_n_first_months'] as $field) {
            $component['evidence'][] = F::citation('components[0].'.$field, $input['components'][0][$field]);
        }
        $validator = new ContractInterpretationValidator;
        $this->assertSame([], $validator->validate($output, $input, F::profile()));
        $this->assertSame('adjustable_tariff', $component['energy_rule']['kind']);
        $this->assertSame(['kind' => 'none', 'value' => null], $component['energy_rule']['ends']);
        $this->assertSame(['kind' => 'contract_start', 'value' => null], $component['energy_rule']['starts']);
        $output['pricing']['phases'][1]['starts']['value'] = '4';
        $this->assertNotEmpty((new EnergyRuleSourceProof)->validate($output, $input, $validator));
        $output['pricing']['phases'][1]['starts']['value'] = '3';
        $component['amount'] = 8;
        $this->assertNotEmpty((new EnergyRuleSourceProof)->validate($output, $input, $validator));
        $component['amount'] = 9;
        $component['energy_rule']['kind'] = 'fixed_price';
        $component['energy_rule']['ends'] = ['kind' => 'date', 'value' => '2026-12-31'];
        $this->assertNotEmpty((new EnergyRuleSourceProof)->validate($output, $input, $validator));
    }

    public function test_until_date_normal_continuation_starts_on_the_next_calendar_date(): void
    {
        [$input, $output] = $this->natural('Energiahinta on kiinteä 4 snt/kWh ajalla 2026-09-15 – 2026-10-31');
        $input['components'][0]['discount_type'] = 'UntilDate';
        $input['components'][0]['discount_until_date'] = '2026-10-31T00:00:00';
        $first = &$output['pricing']['phases'][0];
        $first['starts'] = $first['components'][0]['energy_rule']['starts'] = ['kind' => 'date', 'value' => '2026-09-15'];
        $first['ends'] = $first['components'][0]['energy_rule']['ends'] = ['kind' => 'date', 'value' => '2026-10-31'];
        $first['components'][0]['evidence'] = [];
        foreach (['price', 'has_discount', 'discount_value', 'discount_is_percentage', 'discount_type', 'discount_until_date'] as $field) {
            $first['components'][0]['evidence'][] = F::citation('components[0].'.$field, $input['components'][0][$field]);
        }
        $normal = &$output['pricing']['phases'][1];
        $normal['starts'] = ['kind' => 'date', 'value' => '2026-11-01'];
        $normal['components'][0]['energy_rule'] = $first['components'][0]['energy_rule']['normal_basis'] + ['discount_value' => null, 'floor_amount' => null, 'normal_basis' => null];
        foreach (['has_discount', 'discount_type', 'discount_until_date'] as $field) {
            $normal['components'][0]['evidence'][] = F::citation('components[0].'.$field, $input['components'][0][$field]);
        }
        $validator = new ContractInterpretationValidator;
        $this->assertSame([], $validator->validate($output, $input, F::profile()));
        $input['analysis_date'] = '2026-11-01';
        $this->assertNotEmpty($validator->validate($output, $input, F::profile()));
        $input['analysis_date'] = '2026-09-15';
        $normal['starts']['value'] = '2026-10-31';
        $this->assertNotEmpty((new EnergyRuleSourceProof)->validate($output, $input, $validator));
    }

    public function test_normal_continuation_requires_its_own_source_timing_citations(): void
    {
        [$input, $output] = $this->natural('Energiahinta on kiinteä 4 snt/kWh ensimmäiset 3 kuukautta');
        $basis = $output['pricing']['phases'][0]['components'][0]['energy_rule']['normal_basis'];
        $output['pricing']['phases'][1]['components'][0]['energy_rule'] = $basis + ['discount_value' => null, 'floor_amount' => null, 'normal_basis' => null];
        $this->assertNotEmpty((new EnergyRuleSourceProof)->validate($output, $input, new ContractInterpretationValidator));
    }

    public function test_disjoint_finite_prices_and_compatible_overlap_pass_but_conflicting_overlap_fails(): void
    {
        [$input, $output] = F::example('fixed_price', 8, 4);
        $first = 'General energy price is fixed at 4 cents/kWh from 2026-09-15 through 2026-10-31';
        $second = 'General energy price is fixed at 8 cents/kWh from 2026-11-01 through 2026-11-30';
        $normal = 'General normal energy price is fixed at 8 cents/kWh from 2026-11-01 through 2026-11-30';
        $input['short_description'] = 'Reliable energy for your home. '.$first.'. '.$second.'. '.$normal.'. Contact our support team.';
        $input['components'][0]['discount_type'] = 'UntilDate';
        $input['components'][0]['discount_until_date'] = '2026-10-31';
        foreach ([['2026-09-15', '2026-10-31', $first], ['2026-11-01', '2026-11-30', $second]] as $index => [$start, $end, $quote]) {
            $phase = &$output['pricing']['phases'][$index];
            $phase['starts'] = ['kind' => 'date', 'value' => $start];
            $phase['ends'] = ['kind' => 'date', 'value' => $end];
            $component = &$phase['components'][0];
            $component['energy_rule'] = ['kind' => 'fixed_price', 'starts' => $phase['starts'], 'ends' => $phase['ends'], 'discount_value' => null, 'floor_amount' => null, 'normal_basis' => null, 'evidence' => [...F::scope(), F::citation('short_description', $quote)]];
            $component['evidence'] = [];
            foreach (['price', 'has_discount', 'discount_value', 'discount_is_percentage', 'discount_type', 'discount_until_date'] as $field) {
                $component['evidence'][] = F::citation('components[0].'.$field, $input['components'][0][$field]);
            }
            unset($phase, $component);
        }
        $output['pricing']['phases'][0]['components'][0]['energy_rule']['normal_basis'] = ['kind' => 'fixed_price', 'starts' => ['kind' => 'date', 'value' => '2026-11-01'], 'ends' => ['kind' => 'date', 'value' => '2026-11-30'], 'evidence' => [...F::scope(), F::citation('short_description', $normal)]];
        $validator = new ContractInterpretationValidator;
        $this->assertSame([], $validator->validate($output, $input, F::profile()));
        $input['short_description'] .= ' General energy price is fixed at 4 cents/kWh from 2026-09-20 through 2026-10-15.';
        $this->assertSame([], $validator->validate($output, $input, F::profile()));
        $input['short_description'] .= ' General energy price is fixed at 6 cents/kWh from 2026-10-20 through 2026-11-10.';
        $this->assertNotEmpty((new EnergyRuleSourceProof)->validate($output, $input, $validator));
    }

    public function test_names_use_complete_claim_proof_and_allow_ordinary_product_names(): void
    {
        $validator = new ContractInterpretationValidator;
        foreach (['contract_name', 'pricing_name', 'short_description', 'long_description', 'extra_information_fi', 'extra_information_default'] as $field) {
            foreach (['General energy price is fixed at 6 cents/kWh for the first 3 months', 'No price guarantee', 'Hinta voi muuttua', 'Vain uusille asiakkaille'] as $claim) {
                [$input, $output] = F::example();
                $input[$field] = $claim;
                $this->assertNotEmpty($validator->validate($output, $input, F::profile()), $field.': '.$claim);
            }
        }
        foreach (['contract_name', 'pricing_name'] as $field) {
            [$input, $output] = F::example();
            $input[$field] = 'Tuuli Sähkö';
            $this->assertSame([], $validator->validate($output, $input, F::profile()));
            $quote = $output['pricing']['phases'][0]['components'][0]['energy_rule']['evidence'][2]['quote'];
            $input[$field] = $quote;
            $output['pricing']['phases'][0]['components'][0]['energy_rule']['evidence'][2]['source'] = $field;
            $this->assertSame([], $validator->validate($output, $input, F::profile()));
        }
    }

    public function test_current_quote_bounds_are_stable_but_never_accept_arbitrary_dates(): void
    {
        [$input, $output] = F::example('absolute_discount');
        $validator = new ContractInterpretationValidator;
        foreach (['2026-09-15', '2026-09-17'] as $date) {
            $input['analysis_date'] = $date;
            $this->assertSame([], $validator->validate($output, $input, F::profile()));
        }
        foreach (['2026-09-15', '2026-09-17', '2020-01-01'] as $date) {
            $output['pricing']['phases'][0]['components'][0]['energy_rule']['normal_basis']['starts'] = ['kind' => 'date', 'value' => $date];
            $this->assertNotEmpty($validator->validate($output, $input, F::profile()));
        }
    }

    public function test_adjustable_quote_conflicts_only_at_its_internal_observation_date(): void
    {
        [$input, $output] = F::example();
        $input['long_description'] = 'General normal energy price is fixed at 8 cents/kWh from 2026-09-16 through 2026-09-18';
        $validator = new ContractInterpretationValidator;
        $this->assertSame([], $validator->validate($output, $input, F::profile()));
        $input['analysis_date'] = '2026-09-17';
        $this->assertNotEmpty($validator->validate($output, $input, F::profile()));
        $input['analysis_date'] = '2026-09-19';
        $this->assertSame([], $validator->validate($output, $input, F::profile()));
    }

    public function test_marketing_prefix_cannot_hide_index_linkage(): void
    {
        $this->assertUnresolvedContext('long_description', 'Reliable energy is indexed to Nord Pool.');
    }

    public function test_marketing_prefix_cannot_hide_a_consumption_effect(): void
    {
        $this->assertUnresolvedContext('long_description', 'Reliable energy has a consumption effect.');
    }

    public function test_product_names_cannot_hide_mechanism_claims(): void
    {
        foreach (['pricing_name', 'contract_name'] as $field) {
            foreach (['Nord Pool indexed', 'Consumption effect', 'Kulutusvaikutus', 'Spot linked'] as $claim) {
                $this->assertUnresolvedContext($field, $claim);
            }
        }
    }

    public function test_harmless_context_requires_bounded_whole_clauses(): void
    {
        foreach (['Reliable energy for your home.', 'Luotettavaa sähköä kotiisi.', 'Asiakaspalvelu auttaa arkisin.', 'Welcome to our service.', '100 % renewable energy.', 'Asiakaspalvelu palvelee arkisin klo 9–17.'] as $text) {
            [$input, $output] = F::example();
            $input['long_description'] = $text;
            $this->assertSame([], (new ContractInterpretationValidator)->validate($output, $input, F::profile()), $text);
        }
        foreach (['Reliable energy follows the exchange.', 'Luotettavaa sähköä seuraa pörssiä.', 'Asiakaspalvelu auttaa arkisin ja seuraa pörssiä.'] as $text) {
            $this->assertUnresolvedContext('long_description', $text);
        }
    }

    public function test_contacts_require_contact_syntax_without_appended_claims(): void
    {
        foreach (['Contact our support team.', 'Contact: support@example.fi.', 'Email: support2@example.fi.', 'Ota yhteyttä: asiakaspalvelu@example.fi.', 'Telephone: +358 10 1234567.', 'Puh. 010 1234567.'] as $text) {
            [$input, $output] = F::example();
            $input['long_description'] = $text;
            $this->assertSame([], (new ContractInterpretationValidator)->validate($output, $input, F::profile()), $text);
        }
        foreach (['Contact Nord Pool indexed.', 'Contact: not-an-email.', 'Contact: support@example.fi and energy follows the exchange.', 'Contact our support team and energy follows the exchange.', 'Contact: support@example.fi. Energy follows the exchange.', 'Telephone: +358 10 1234567 and energy follows the exchange.'] as $text) {
            $this->assertUnresolvedContext('long_description', $text);
        }
    }

    private function assertUnresolvedContext(string $field, string $text): void
    {
        [$input, $output] = F::example();
        $input[$field] = $text;
        $validator = new ContractInterpretationValidator;
        $errors = $validator->validate($output, $input, F::profile());
        $this->assertStringContainsString('lacks complete source-clause', implode(' ', $errors), $field.': '.$text);
        $output['pricing']['phases'][0]['components'][0]['energy_rule'] = F::unknown();
        $this->assertSame([], $validator->validate($output, $input, F::profile()), $field.': '.$text);
        $this->assertSame($text, $input[$field]);
    }

    private function natural(string $actual): array
    {
        [$input, $output] = F::example();
        $normal = 'Normaali energiahinta on tällä hetkellä 9 snt/kWh ja voi muuttua';
        $input['short_description'] = 'Luotettavaa sähköä kotiisi. '.$actual.'. '.$normal.'. Tilaa helposti verkossa. Ota yhteyttä: asiakaspalvelu@example.fi.';
        $input['long_description'] = 'Tervetuloa asiakkaaksemme! Asiakaspalvelu auttaa arkisin. Asiakaspalvelu palvelee arkisin klo 9–17. Sähkömme on 100 % uusiutuvaa energiaa. Puh. 010 1234567.';
        $output['pricing']['phases'][0]['components'][0]['energy_rule']['evidence'] = [...F::scope(), F::citation('short_description', $actual)];
        $output['pricing']['phases'][0]['components'][0]['energy_rule']['normal_basis']['evidence'] = [...F::scope(), F::citation('short_description', $normal)];

        return [$input, $output];
    }
}
