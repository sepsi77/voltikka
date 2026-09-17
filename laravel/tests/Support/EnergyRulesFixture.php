<?php

namespace Tests\Support;

use App\Models\ContractSourceSnapshot;
use App\Services\ContractInterpretation\ContractInterpretationInputBuilder;
use App\Services\ContractInterpretation\ContractInterpretationProfile;

final class EnergyRulesFixture
{
    public static function profile(): ContractInterpretationProfile
    {
        return ContractInterpretationProfile::stored('schema-v5', 'prompt-v20', 'validator-v18');
    }

    public static function unknown(): array
    {
        return ['kind' => 'unknown', 'starts' => null, 'ends' => null, 'discount_value' => null, 'floor_amount' => null, 'normal_basis' => null, 'evidence' => []];
    }

    public static function citation(string $source, mixed $value): array
    {
        return ['source' => $source, 'quote' => is_string($value) ? $value : json_encode($value)];
    }

    public static function scope(int $index = 0, string $type = 'General'): array
    {
        return [self::citation("components[{$index}].price_component_type", $type), self::citation("components[{$index}].payment_unit", 'CentPerKiwattHour')];
    }

    public static function source(string $actual, string $normal, float $normalAmount = 9, float $discountValue = 5): array
    {
        return ['Id' => 'source-id', 'Name' => 'Energy test', 'Company' => ['Name' => 'Energy test company'], 'Details' => [
            'PricingModel' => 'FixedPrice', 'ContractType' => 'OpenEnded', 'Metering' => 'General', 'TargetGroup' => 'Household',
            'ShortDescription' => $actual.'; '.$normal,
            'Pricing' => ['HasDiscount' => true, 'PriceComponents' => [[
                'Id' => 'energy-source', 'PriceComponentType' => 'General', 'HasDiscount' => true,
                'OriginalPayment' => ['Price' => $normalAmount, 'PaymentUnit' => 'CentPerKiwattHour'],
                'Discount' => ['DiscountValue' => $discountValue, 'IsPercentage' => false, 'DiscountType' => 'NFirstMonth', 'NfirstMonths' => 3],
            ]]],
        ]];
    }

    public static function example(string $kind = 'fixed_price', float $normalAmount = 9, float $operand = 5, ?float $floor = null): array
    {
        $amount = $kind === 'fixed_price' ? 4.0 : ($kind === 'percentage_discount' ? $normalAmount * (1 - $operand / 100) : $normalAmount - $operand);
        if ($floor !== null) {
            $amount = max($floor, $amount);
        }
        $actual = $kind === 'fixed_price' ? 'General energy price is fixed at 4 cents/kWh for the first 3 months'
            : "General energy price is the normal tariff minus {$operand} ".($kind === 'percentage_discount' ? 'percent' : 'cents/kWh').' for the first 3 months'.($floor === null ? '' : ", with a minimum price of {$floor} cents/kWh");
        $normal = "General normal energy tariff is adjustable and is currently {$normalAmount} cents/kWh";
        $source = self::source($actual, $normal, $normalAmount, $operand);
        if ($kind === 'percentage_discount') {
            $source['Details']['Pricing']['PriceComponents'][0]['Discount']['IsPercentage'] = true;
            $source['Details']['Pricing']['PriceComponents'][0]['Discount']['DiscountValue'] = $operand;
        }
        $snapshot = new ContractSourceSnapshot(['contract_id' => 'energy-test', 'source_payload' => $source, 'first_observed_at' => '2026-09-15']);
        $input = (new ContractInterpretationInputBuilder)->build($snapshot, '2026-09-15', self::profile());
        $evidence = [];
        foreach (['price', 'has_discount', 'discount_value', 'discount_is_percentage', 'discount_type', 'discount_n_first_months'] as $field) {
            $evidence[] = self::citation('components[0].'.$field, $input['components'][0][$field]);
        }
        $rule = ['kind' => $kind, 'starts' => ['kind' => 'contract_start', 'value' => null], 'ends' => ['kind' => 'after_months', 'value' => '3'],
            'discount_value' => $kind === 'fixed_price' ? null : $operand, 'floor_amount' => $floor,
            'normal_basis' => ['kind' => 'adjustable_tariff', 'starts' => ['kind' => 'contract_start', 'value' => null], 'ends' => ['kind' => 'none', 'value' => null],
                'evidence' => [...self::scope(), self::citation('short_description', $normal)]],
            'evidence' => [...self::scope(), self::citation('short_description', $actual)],
        ];
        $component = ['component_type' => 'energy_general', 'amount' => $amount, 'normal_amount' => $normalAmount,
            'unit' => 'cents_per_kwh', 'vat_status' => 'included', 'price_role' => 'introductory', 'source_kind' => 'both', 'evidence' => $evidence, 'energy_rule' => $rule];
        $continuation = $component;
        $continuation['amount'] = $normalAmount;
        $continuation['normal_amount'] = null;
        $continuation['price_role'] = 'normal';
        $continuation['energy_rule'] = self::unknown();
        $continuation['evidence'] = [self::citation('components[0].price', $normalAmount)];
        $output = [
            'schema_version' => '1.2', 'contract_id' => 'energy-test',
            'classification' => ['term_type' => 'OpenEnded', 'fixed_duration_months' => null, 'primary_pricing_model' => 'FixedPrice', 'pricing_mechanisms' => ['fixed'], 'metering' => 'General', 'spot_settlement_interval' => 'unknown', 'periodic_reset_cadence' => 'none', 'schedule_kinds' => ['standard']],
            'pricing' => [
                'phases' => [
                    ['label' => 'Intro', 'phase_kind' => 'introductory', 'starts' => ['kind' => 'contract_start', 'value' => null], 'ends' => ['kind' => 'after_months', 'value' => '3'], 'package' => null, 'components' => [$component], 'evidence' => []],
                    ['label' => 'Normal', 'phase_kind' => 'normal', 'starts' => ['kind' => 'after_months', 'value' => '3'], 'ends' => ['kind' => 'none', 'value' => null], 'package' => null, 'components' => [$continuation], 'evidence' => []],
                ],
                'recurring_schedule' => ['present' => false, 'cadence' => 'none', 'current_period_start' => null, 'current_period_end' => null, 'future_price_known' => null, 'description' => null, 'evidence' => []],
                'consumption_effect' => ['present' => false, 'applies_to' => 'unknown', 'cadence' => 'none', 'expected_cents_per_kwh' => null, 'typical_min_cents_per_kwh' => null, 'typical_max_cents_per_kwh' => null, 'hard_min_cents_per_kwh' => null, 'hard_max_cents_per_kwh' => null, 'uncapped' => null, 'description' => null, 'evidence' => []],
            ],
            'source_consistency' => ['pricing_model_status' => 'match', 'recommended_pricing_model' => 'FixedPrice', 'contract_type_status' => 'match', 'recommended_contract_type' => 'OpenEnded', 'metering_status' => 'match', 'recommended_metering' => 'General', 'structured_pricing_status' => 'complete', 'misleading_first_12_months' => 'not_detected', 'issue_codes' => ['structured_matches_description'], 'summary' => 'Structured discount.', 'evidence' => []],
            'calculation' => ['status' => 'exact', 'missing_facts' => [], 'required_assumptions' => []],
            'confidence' => ['classification' => 'high', 'pricing' => 'high', 'integrity' => 'high'],
        ];

        return [$input, $output, $source];
    }
}
