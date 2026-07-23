<?php

namespace App\Services\ContractInterpretation;

use App\Models\ContractSourceSnapshot;

class ContractInterpretationInputBuilder
{
    /**
     * Build the compact, stable input shape used by the tested prompt.
     *
     * @return array<string, mixed>
     */
    public function build(ContractSourceSnapshot $snapshot): array
    {
        $source = $snapshot->source_payload;
        $details = $source['Details'] ?? [];
        $pricing = $details['Pricing'] ?? [];
        $extra = $details['ExtraInformation'] ?? [];

        return [
            'analysis_date' => $snapshot->first_observed_at->toDateString(),
            'contract_id' => $snapshot->contract_id,
            'api_id' => $source['Id'] ?? null,
            'company_name' => $source['Company']['Name'] ?? null,
            'contract_name' => $source['Name'] ?? null,
            'pricing_model' => $details['PricingModel'] ?? null,
            'contract_type' => $details['ContractType'] ?? null,
            'fixed_time_range' => $details['FixedTimeRange'] ?? null,
            'metering' => $details['Metering'] ?? null,
            'target_group' => $details['TargetGroup'] ?? null,
            'spot_price_selection' => $details['SpotPriceSelection'] ?? null,
            'pricing_name' => $pricing['Name'] ?? null,
            'pricing_has_discounts' => $pricing['HasDiscount'] ?? false,
            'short_description' => $this->normalizeText($details['ShortDescription'] ?? null),
            'long_description' => $this->normalizeText($details['LongDescription'] ?? null),
            'extra_information_fi' => $this->normalizeText($extra['FI'] ?? null),
            'extra_information_default' => $this->normalizeText($extra['Default'] ?? null),
            'time_period_definitions' => $details['TimePeriodDefinitions'] ?? null,
            'billing_frequency' => $details['BillingFrequency'] ?? null,
            'consumption_limitation' => $details['ConsumptionLimitation'] ?? null,
            'components' => array_map(
                fn (array $component): array => $this->mapComponent($component),
                $pricing['PriceComponents'] ?? []
            ),
        ];
    }

    private function normalizeText(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $text = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/<br\s*\/?\s*>/iu', ' ', $text) ?? $text;
        $text = strip_tags($text);
        $text = preg_replace('/[\s\x{00A0}\x{202F}]+/u', ' ', $text) ?? $text;

        return trim($text);
    }

    /**
     * @return array<string, mixed>
     */
    private function mapComponent(array $component): array
    {
        $discount = $component['Discount'] ?? [];
        $payment = $component['OriginalPayment'] ?? [];

        return [
            'id' => $component['Id'] ?? null,
            'price_component_type' => $component['PriceComponentType'] ?? null,
            'fuse_size' => $component['FuseSize'] ?? null,
            'price' => $payment['Price'] ?? null,
            'payment_unit' => $payment['PaymentUnit'] ?? null,
            'has_discount' => $component['HasDiscount'] ?? false,
            'discount_value' => $discount['DiscountValue'] ?? null,
            'discount_is_percentage' => $discount['IsPercentage'] ?? null,
            'discount_type' => $discount['DiscountType'] ?? null,
            'discount_n_first_kwh' => $discount['NFirstKwh'] ?? null,
            'discount_n_first_months' => $discount['NfirstMonths'] ?? null,
            'discount_until_date' => $discount['UntilDate'] ?? null,
        ];
    }
}
