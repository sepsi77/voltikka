<?php

namespace App\Services\ContractInterpretation;

use App\Models\ElectricityContract;
use App\Models\PriceComponent;
use Carbon\Carbon;

class CanonicalPriceComponentWriter
{
    private const NULL_UUID = '00000000-0000-0000-0000-000000000000';

    /**
     * Replace one date's component rows from complete source payloads.
     *
     * @param  list<array<string, mixed>>  $sourcePayloads
     * @param  list<string>|null  $allowedContractIds
     */
    public function write(array $sourcePayloads, string $date, ?array $allowedContractIds = null): int
    {
        $apiIds = array_column($sourcePayloads, 'Id');
        $contractIdMap = ElectricityContract::whereIn('api_id', $apiIds)
            ->pluck('id', 'api_id')
            ->toArray();
        $allowed = $allowedContractIds === null ? null : array_flip($allowedContractIds);
        $targetContractIds = array_values(array_filter(
            $contractIdMap,
            fn (string $contractId): bool => $allowed === null || isset($allowed[$contractId])
        ));

        PriceComponent::whereIn('electricity_contract_id', $targetContractIds)
            ->where('price_date', $date)
            ->delete();

        $rows = [];
        foreach ($sourcePayloads as $sourcePayload) {
            $sourcePayload = $this->trimStrings($sourcePayload);
            $pricing = $sourcePayload['Details']['Pricing'] ?? [];
            $apiContractId = $pricing['ElectricitySupplyProductId'] ?? $sourcePayload['Id'];
            $contractId = $contractIdMap[$apiContractId] ?? $contractIdMap[$sourcePayload['Id']] ?? null;
            if ($contractId === null || ($allowed !== null && ! isset($allowed[$contractId]))) {
                continue;
            }

            foreach ($pricing['PriceComponents'] ?? [] as $component) {
                $rows[] = $this->mapComponent($this->trimStrings($component), $contractId, $date);
            }
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            PriceComponent::upsert(
                $chunk,
                ['id', 'price_date'],
                [
                    'price_component_type',
                    'fuse_size',
                    'electricity_contract_id',
                    'has_discount',
                    'discount_value',
                    'discount_is_percentage',
                    'discount_type',
                    'discount_discount_n_first_kwh',
                    'discount_discount_n_first_months',
                    'discount_discount_until_date',
                    'price',
                    'payment_unit',
                ]
            );
        }

        return count($rows);
    }

    /**
     * @param  array<string, mixed>  $component
     * @return array<string, mixed>
     */
    private function mapComponent(array $component, string $contractId, string $date): array
    {
        $discount = $component['Discount'] ?? [];
        $discountUntilDate = null;
        $untilDate = $discount['UntilDate'] ?? '0001-01-01T00:00:00';
        if ($untilDate !== '0001-01-01T00:00:00') {
            try {
                $discountUntilDate = Carbon::parse($untilDate);
            } catch (\Throwable) {
                // Keep invalid upstream dates unset.
            }
        }

        $componentId = $component['Id'] ?? self::NULL_UUID;
        if ($componentId === self::NULL_UUID) {
            $type = $component['PriceComponentType'];
            $fuseSize = $component['FuseSize'] ?? 'null';
            $componentId = md5("{$contractId}:{$type}:{$fuseSize}");
        }

        return [
            'id' => $componentId,
            'price_date' => $date,
            'price_component_type' => $component['PriceComponentType'],
            'fuse_size' => $component['FuseSize'] ?? null,
            'electricity_contract_id' => $contractId,
            'has_discount' => $component['HasDiscount'] ?? false,
            'discount_value' => $discount['DiscountValue'] ?? null,
            'discount_is_percentage' => $discount['IsPercentage'] ?? false,
            'discount_type' => $discount['DiscountType'] ?? null,
            'discount_discount_n_first_kwh' => $discount['NFirstKwh'] ?? null,
            'discount_discount_n_first_months' => $discount['NfirstMonths'] ?? null,
            'discount_discount_until_date' => $discountUntilDate,
            'price' => $component['OriginalPayment']['Price'] ?? 0,
            'payment_unit' => $component['OriginalPayment']['PaymentUnit'] ?? null,
        ];
    }

    private function trimStrings(mixed $value): mixed
    {
        if (is_string($value)) {
            return trim($value);
        }

        if (! is_array($value)) {
            return $value;
        }

        return array_map(fn (mixed $child): mixed => $this->trimStrings($child), $value);
    }
}
