<?php

namespace App\Services\ContractInterpretation;

use Carbon\CarbonImmutable;

class HistoricalEvidenceNormalizer
{
    public function __construct(
        private readonly HistoricalInterpretationFingerprint $fingerprints,
    ) {}

    /** @return array<string, mixed> */
    public function identity(array|object $row): array
    {
        return [
            'company_name' => $this->nullableString($this->value($row, 'company_name')),
            'contract_name' => $this->nullableString($this->value($row, 'contract_name')),
            'pricing_model' => $this->nullableString($this->value($row, 'pricing_model')),
            'contract_type' => $this->nullableString($this->value($row, 'contract_type')),
            'fixed_time_range' => $this->nullableString($this->value($row, 'fixed_time_range')),
            'metering' => $this->nullableString($this->value($row, 'metering')),
            'segment_key' => $this->nullableString($this->value($row, 'segment_key')),
            'pricing_basis' => $this->nullableString($this->value($row, 'pricing_basis', 'observed_seller_data')),
            'has_discount' => (bool) $this->value($row, 'has_discount', false),
            'includes_spot_price' => (bool) $this->value($row, 'includes_spot_price', false),
        ];
    }

    /** @return array<string, mixed> */
    public function component(array|object $row): array
    {
        return [
            'id' => $this->nullableString($this->value($row, 'id')),
            'price_date' => CarbonImmutable::parse((string) $this->value($row, 'price_date'))->toDateString(),
            'price_component_type' => $this->nullableString($this->value($row, 'price_component_type')),
            'fuse_size' => $this->nullableString($this->value($row, 'fuse_size')),
            'price' => $this->decimal($this->value($row, 'price')),
            'payment_unit' => $this->nullableString($this->value($row, 'payment_unit')),
            'has_discount' => $this->nullableBoolean($row, 'has_discount'),
            'discount_value' => $this->decimal($this->value($row, 'discount_value')),
            'discount_is_percentage' => $this->nullableBoolean($row, 'discount_is_percentage'),
            'discount_type' => $this->nullableString($this->value($row, 'discount_type')),
            'discount_n_first_kwh' => $this->decimal(
                $this->value($row, 'discount_discount_n_first_kwh') ?? $this->value($row, 'discount_n_first_kwh'),
            ),
            'discount_n_first_months' => $this->decimal(
                $this->value($row, 'discount_discount_n_first_months') ?? $this->value($row, 'discount_n_first_months'),
            ),
            'discount_until_date' => $this->nullableDate(
                $this->value($row, 'discount_discount_until_date') ?? $this->value($row, 'discount_until_date'),
            ),
        ];
    }

    /**
     * @param  iterable<array|object>  $rows
     * @return list<array<string, mixed>>
     */
    public function components(iterable $rows): array
    {
        $components = [];
        foreach ($rows as $row) {
            $components[] = $this->component($row);
        }

        usort($components, function (array $left, array $right): int {
            return [
                $this->fingerprints->hash($this->economicComponent($left)),
                (string) $left['id'],
            ] <=> [
                $this->fingerprints->hash($this->economicComponent($right)),
                (string) $right['id'],
            ];
        });

        return $components;
    }

    /** @param list<array<string, mixed>> $normalizedComponents */
    public function economicDigestFromNormalized(array $identity, array $normalizedComponents): string
    {
        $components = array_map(
            fn (array $component): array => $this->economicComponent($component),
            $normalizedComponents,
        );
        usort($components, fn (array $left, array $right): int => $this->fingerprints->hash($left) <=> $this->fingerprints->hash($right));

        return $this->fingerprints->hash([
            'identity' => $identity,
            'components' => $components,
        ]);
    }

    /** @param iterable<array|object> $components */
    public function targetEconomicDigest(array|object $snapshot, iterable $components): string
    {
        return $this->economicDigestFromNormalized(
            $this->identity($snapshot),
            $this->components($components),
        );
    }

    /** @return array<string, mixed> */
    private function economicComponent(array $component): array
    {
        return array_diff_key($component, ['id' => true, 'price_date' => true]);
    }

    private function value(array|object $row, string $key, mixed $default = null): mixed
    {
        if (is_array($row)) {
            return array_key_exists($key, $row) ? $row[$key] : $default;
        }

        return property_exists($row, $key) ? $row->{$key} : $default;
    }

    private function nullableBoolean(array|object $row, string $key): ?bool
    {
        $value = $this->value($row, $key);

        return $value === null ? null : (bool) $value;
    }

    private function decimal(mixed $value): ?string
    {
        if ($value === null || $value === '' || ! is_numeric($value)) {
            return null;
        }

        $normalized = rtrim(rtrim(sprintf('%.12F', (float) $value), '0'), '.');

        return $normalized === '' || $normalized === '-0' ? '0' : $normalized;
    }

    private function nullableString(mixed $value): ?string
    {
        return $value === null || $value === '' ? null : (string) $value;
    }

    private function nullableDate(mixed $value): ?string
    {
        return $value === null || $value === '' ? null : CarbonImmutable::parse((string) $value)->toDateString();
    }
}
