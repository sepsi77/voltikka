<?php

namespace Tests\Support;

use App\Models\ContractSourceSnapshot;
use App\Services\ContractInterpretation\ContractInterpretationInputBuilder;

final class FullSourceEnergyFixture
{
    public static function real(string $name): array
    {
        $row = json_decode(file_get_contents(__DIR__.'/../Fixtures/energy-rule-sources/'.$name.'.json'), true, 512, JSON_THROW_ON_ERROR);
        $input = (new ContractInterpretationInputBuilder)->build(new ContractSourceSnapshot($row), $row['analysis_date'], EnergyRulesFixture::profile());

        return [$input, $row['source_payload']];
    }

    public static function cheap(): array
    {
        [$input, $source] = self::real('cheap');
        [, $output] = EnergyRulesFixture::example();
        $output['contract_id'] = $input['contract_id'];
        $quote = 'Cheap Kvartaalisähkö -sopimuksessa on kiinteä energiahinta 7,49 snt/kWh + perusmaksu 0 €/kk ensimmäisen kuukauden ajan sopimuksen aloituspäivästä eteenpäin.';
        $first = &$output['pricing']['phases'][0];
        $first['ends']['value'] = '1';
        $energy = &$first['components'][0];
        $energy['amount'] = 7.49;
        $energy['normal_amount'] = null;
        $energy['evidence'] = [EnergyRulesFixture::citation('components[0].price', 7.49)];
        $energy['energy_rule']['ends']['value'] = '1';
        $energy['energy_rule']['normal_basis'] = null;
        $energy['energy_rule']['evidence'] = [...EnergyRulesFixture::scope(), EnergyRulesFixture::citation('extra_information_fi', $quote)];
        $normal = &$output['pricing']['phases'][1];
        $normal['starts']['value'] = '1';
        $normal['components'][0]['amount'] = 9.95;
        $normal['components'][0]['evidence'] = [EnergyRulesFixture::citation('extra_information_fi', 'Energianhinta 9,95 snt/kWh + perusmaksu 4,90€/kk.')];
        $fee = ['component_type' => 'monthly_fee', 'amount' => 0, 'normal_amount' => 4.9, 'unit' => 'eur_per_month', 'vat_status' => 'included', 'price_role' => 'introductory', 'source_kind' => 'structured', 'energy_rule' => null, 'evidence' => []];
        foreach (['price', 'has_discount', 'discount_value', 'discount_is_percentage', 'discount_type', 'discount_n_first_months'] as $field) {
            $fee['evidence'][] = EnergyRulesFixture::citation('components[1].'.$field, $input['components'][1][$field]);
        }
        $first['components'][] = $fee;
        $fee['amount'] = 4.9;
        $fee['normal_amount'] = null;
        $fee['price_role'] = 'normal';
        $normal['components'][] = $fee;
        $output['classification']['pricing_mechanisms'] = ['fixed', 'periodic_market_reset'];
        $output['classification']['periodic_reset_cadence'] = 'quarterly';
        $output['classification']['schedule_kinds'] = ['introductory_promotion', 'recurring_market_reset'];
        $output['pricing']['recurring_schedule'] = ['present' => true, 'cadence' => 'quarterly', 'current_period_start' => null, 'current_period_end' => '2026-09-30', 'future_price_known' => false, 'description' => 'Quarterly reset after the introductory month.', 'evidence' => [EnergyRulesFixture::citation('extra_information_fi', 'Hinta on lukittu 30.9.2026 asti.')]];
        $output['source_consistency']['misleading_first_12_months'] = 'uncertain';
        $output['source_consistency']['issue_codes'] = ['structured_matches_intro_only', 'recurring_reset_requires_estimate'];
        $output['calculation'] = ['status' => 'estimate_required', 'missing_facts' => ['Future quarterly rates'], 'required_assumptions' => ['Future quarterly estimate']];

        return [$input, $output, $source];
    }

    /** Faithful unconditional wording, not a shortened or edited real seller source. */
    public static function wholeTerm(): array
    {
        [, $output, $source] = EnergyRulesFixture::example();
        $source['Name'] = 'Tuuli 24 kk';
        $source['Details']['ContractType'] = 'FixedTerm';
        $source['Details']['FixedTimeRange'] = 'Fixed24';
        $source['Details']['ShortDescription'] = 'Hinta määräytyy ostohetken perusteella ja pysyy samana koko määräaikaisen sopimuskauden ajan.';
        $source['Details']['LongDescription'] = 'Sopimus sitoo asiakasta ja myyjää koko 24 kk:n sopimusajan. Sähkömme on 100 % uusiutuvaa energiaa.';
        $source['Details']['ExtraInformation'] = ['FI' => 'Asiakaspalvelu palvelee arkisin klo 9–17.', 'Default' => 'Contact: support@example.fi.'];
        $source['Details']['Pricing']['Name'] = 'Tuuli 24 kk';
        $source['Details']['Pricing']['HasDiscount'] = false;
        $source['Details']['Pricing']['PriceComponents'][0]['HasDiscount'] = false;
        $input = (new ContractInterpretationInputBuilder)->build(new ContractSourceSnapshot(['contract_id' => 'energy-test', 'source_payload' => $source]), '2026-09-15', EnergyRulesFixture::profile());
        $output['classification']['term_type'] = 'FixedTerm';
        $output['classification']['fixed_duration_months'] = 24;
        $output['source_consistency']['recommended_contract_type'] = 'FixedTerm';
        $output['pricing']['phases'] = [$output['pricing']['phases'][0]];
        $phase = &$output['pricing']['phases'][0];
        $phase['phase_kind'] = 'current_structured';
        $phase['ends']['value'] = '24';
        $component = &$phase['components'][0];
        $component['amount'] = 9;
        $component['normal_amount'] = null;
        $component['price_role'] = 'current';
        $component['evidence'] = [EnergyRulesFixture::citation('components[0].price', 9)];
        $component['energy_rule']['ends']['value'] = '24';
        $component['energy_rule']['normal_basis'] = null;
        $component['energy_rule']['evidence'] = [...EnergyRulesFixture::scope(), EnergyRulesFixture::citation('short_description', $input['short_description']), EnergyRulesFixture::citation('contract_type', 'FixedTerm'), EnergyRulesFixture::citation('fixed_time_range', 'Fixed24'), EnergyRulesFixture::citation('components[0].price', 9), EnergyRulesFixture::citation('components[0].has_discount', false)];

        return [$input, $output, $source];
    }
}
