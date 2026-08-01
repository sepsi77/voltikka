<?php

namespace Database\Factories;

use App\Models\ActiveContract;
use App\Models\Company;
use App\Models\ElectricityContract;
use App\Models\PriceComponent;
use Database\Factories\Support\CanonicalPricingFixture;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;

/**
 * @extends Factory<ElectricityContract>
 */
class ElectricityContractFactory extends Factory
{
    protected $model = ElectricityContract::class;

    public function configure(): static
    {
        return $this->afterMaking(function (ElectricityContract $contract): void {
            $companyName = trim((string) $contract->company_name);

            if ($companyName === '') {
                throw new LogicException('ElectricityContractFactory requires forCompany() before persistence.');
            }

            if (! Company::query()->whereKey($companyName)->exists()) {
                throw new LogicException("ElectricityContractFactory company [{$companyName}] does not exist. Create it before calling forCompany().");
            }
        });
    }

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'id' => 'factory-'.Str::uuid(),
            'company_name' => null,
            'name' => 'Vakaa Kotisähkö',
            'contract_type' => 'OpenEnded',
            'spot_price_selection' => null,
            'fixed_time_range' => null,
            'metering' => 'General',
            'pricing_model' => 'FixedPrice',
            'target_group' => 'Household',
            'short_description' => 'Selkeä kotitalouden sähkösopimus.',
            'long_description' => null,
            'pricing_name' => 'Kiinteä hinta',
            'pricing_has_discounts' => false,
            'consumption_control' => false,
            'consumption_limitation_min_x_kwh_per_y' => null,
            'consumption_limitation_max_x_kwh_per_y' => null,
            'pre_billing' => false,
            'available_for_existing_users' => true,
            'delivery_responsibility_product' => false,
            'order_link' => 'https://example.test/tilaa',
            'product_link' => 'https://example.test/sahkosopimus',
            'billing_frequency' => null,
            'time_period_definitions' => null,
            'transparency_index' => null,
            'availability_is_national' => true,
            'microproduction_buys' => false,
            ...CanonicalPricingFixture::fixedAttributes(),
        ];
    }

    public function forCompany(Company|string $company): static
    {
        if ($company instanceof Company && ! $company->exists) {
            throw new InvalidArgumentException('ElectricityContractFactory forCompany() requires a persisted Company model.');
        }

        $companyName = $company instanceof Company ? $company->getKey() : trim($company);

        if ($companyName === '') {
            throw new InvalidArgumentException('ElectricityContractFactory forCompany() requires a non-empty company name.');
        }

        return $this->state(fn () => ['company_name' => $companyName]);
    }

    public function active(): static
    {
        return $this->afterCreating(function (ElectricityContract $contract): void {
            ActiveContract::query()->firstOrCreate(['id' => $contract->id]);
        });
    }

    public function household(): static
    {
        return $this->state(fn () => [
            'target_group' => 'Household',
            'availability_is_national' => true,
        ]);
    }

    public function spot(): static
    {
        return $this->state(fn () => [
            'contract_type' => 'OpenEnded',
            'fixed_time_range' => null,
            'pricing_model' => 'Spot',
            'pricing_name' => 'Pörssisähkö',
            'pricing_has_discounts' => false,
            ...CanonicalPricingFixture::spotAttributes(),
        ]);
    }

    public function fixedTerm(): static
    {
        return $this->state(fn () => [
            'contract_type' => 'FixedTerm',
            'fixed_time_range' => 'Fixed12',
            'pricing_model' => 'FixedPrice',
            'pricing_name' => 'Kiinteä 12 kk',
            'pricing_has_discounts' => false,
            ...CanonicalPricingFixture::fixedAttributes(),
        ]);
    }

    public function hybrid(): static
    {
        return $this->state(fn () => [
            'contract_type' => 'OpenEnded',
            'fixed_time_range' => null,
            'pricing_model' => 'Hybrid',
            'pricing_name' => 'Kulutusvaikutus',
            'pricing_has_discounts' => false,
            'consumption_control' => true,
            ...CanonicalPricingFixture::hybridAttributes(),
        ]);
    }

    public function reset(): static
    {
        return $this->state(fn () => [
            'contract_type' => 'OpenEnded',
            'fixed_time_range' => null,
            'pricing_model' => 'FixedPrice',
            'pricing_name' => 'Kvartaalihinta',
            'pricing_has_discounts' => false,
            ...CanonicalPricingFixture::resetAttributes(),
        ]);
    }

    public function package(): static
    {
        return $this->state(fn () => [
            'contract_type' => 'OpenEnded',
            'fixed_time_range' => null,
            'pricing_model' => 'FixedPrice',
            'pricing_name' => 'Kuukausipaketti',
            'pricing_has_discounts' => false,
            ...CanonicalPricingFixture::packageAttributes(),
        ]);
    }

    public function canonicalOnly(): static
    {
        return $this->state(fn () => []);
    }

    public function legacy(): static
    {
        return $this->state(fn () => [
            'canonical_pricing' => null,
            'canonical_source_consistency' => null,
            'canonical_calculation' => null,
        ]);
    }

    public function withConsumptionLimits(float|int|null $minimum, float|int|null $maximum): static
    {
        if ($minimum === null && $maximum === null) {
            throw new InvalidArgumentException('withConsumptionLimits() requires a minimum or maximum value.');
        }

        if (($minimum !== null && $minimum < 0) || ($maximum !== null && $maximum < 0)) {
            throw new InvalidArgumentException('withConsumptionLimits() values must not be negative.');
        }

        if ($minimum !== null && $maximum !== null && $minimum > $maximum) {
            throw new InvalidArgumentException('withConsumptionLimits() minimum must not exceed maximum.');
        }

        return $this->state(fn () => [
            'consumption_control' => true,
            'consumption_limitation_min_x_kwh_per_y' => $minimum,
            'consumption_limitation_max_x_kwh_per_y' => $maximum,
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $components
     */
    public function withRelationalPrices(array $components): static
    {
        if ($components === []) {
            throw new InvalidArgumentException('withRelationalPrices() requires at least one explicit component.');
        }

        foreach ($components as $component) {
            foreach (['price_component_type', 'payment_unit', 'price', 'price_date'] as $required) {
                if (! array_key_exists($required, $component)) {
                    throw new InvalidArgumentException("Relational price component requires [{$required}].");
                }
            }
        }

        $hasDiscounts = collect($components)->contains(
            fn (array $component): bool => ($component['has_discount'] ?? false) === true,
        );

        return $this
            ->state(fn () => ['pricing_has_discounts' => $hasDiscounts])
            ->afterCreating(function (ElectricityContract $contract) use ($components): void {
                foreach (array_values($components) as $index => $component) {
                    PriceComponent::query()->create([
                        'id' => $component['id'] ?? "factory-price-{$index}-{$contract->id}",
                        'price_date' => $component['price_date'],
                        'price_component_type' => $component['price_component_type'],
                        'fuse_size' => $component['fuse_size'] ?? null,
                        'electricity_contract_id' => $contract->id,
                        'has_discount' => $component['has_discount'] ?? false,
                        'discount_value' => $component['discount_value'] ?? null,
                        'discount_is_percentage' => $component['discount_is_percentage'] ?? null,
                        'discount_type' => $component['discount_type'] ?? null,
                        'discount_discount_n_first_kwh' => $component['discount_discount_n_first_kwh'] ?? null,
                        'discount_discount_n_first_months' => $component['discount_discount_n_first_months'] ?? null,
                        'discount_discount_until_date' => $component['discount_discount_until_date'] ?? null,
                        'price' => $component['price'],
                        'payment_unit' => $component['payment_unit'],
                    ]);
                }
            });
    }
}
